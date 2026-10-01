<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * Check whether authenticated user can access Teacher module.
     * --------------------------------------------------------------------------
     *
     * Allowed:
     * - Manager
     * - Branch Manager
     * - Admin
     * - Branch Admin
     *
     * Not allowed:
     * - Accountant
     * - Branch Accountant
     * --------------------------------------------------------------------------
     */
    private function canAccessModule($authUser): bool
    {
        if (!$authUser) {
            return false;
        }

        return in_array($authUser->role, [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Check whether authenticated user can access this teacher.
     * --------------------------------------------------------------------------
     *
     * Manager:
     * - All branches
     *
     * Other allowed roles:
     * - Own branch only
     * --------------------------------------------------------------------------
     */
    private function canAccessTeacher(
        $authUser,
        Teacher $teacher
    ): bool {
        if (!$authUser) {
            return false;
        }

        if (!$this->canAccessModule($authUser)) {
            return false;
        }

        /**
         * Manager can access all branches.
         */
        if ($authUser->role === 'Manager') {
            return true;
        }

        /**
         * Other allowed roles must have a branch.
         */
        if (!$authUser->branch_id) {
            return false;
        }

        /**
         * Teacher must belong to logged-in user's branch.
         */
        return $teacher->branch_id !== null
            && (int) $teacher->branch_id === (int) $authUser->branch_id;
    }

    /**
     * --------------------------------------------------------------------------
     * Forbidden response.
     * --------------------------------------------------------------------------
     */
    private function forbidden(
        $message = 'Access denied.'
    ) {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * --------------------------------------------------------------------------
     * Authentication response.
     * --------------------------------------------------------------------------
     */
    private function unauthenticated()
    {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    /**
     * --------------------------------------------------------------------------
     * Verify all selected shifts belong to the given branch.
     * --------------------------------------------------------------------------
     */
    private function validateShiftBranch(
        $shiftIds,
        $branchId
    ) {
        if (empty($shiftIds)) {
            return null;
        }

        $shiftIds = array_values(
            array_unique($shiftIds)
        );

        $invalidShiftExists = Shift::whereIn(
            'id',
            $shiftIds
        )
            ->where(function ($query) use ($branchId) {

                $query
                    ->whereNull('branch_id')
                    ->orWhere(
                        'branch_id',
                        '!=',
                        $branchId
                    );
            })
            ->exists();

        if ($invalidShiftExists) {
            return response()->json([
                'status' => false,
                'message' =>
                    'One or more selected shifts do not belong to the selected branch.'
            ], 422);
        }

        return null;
    }

    /**
     * --------------------------------------------------------------------------
     * ============================
     * TEACHER LIST
     * ============================
     * --------------------------------------------------------------------------
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Role authorization.
         */
        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access teachers.'
            );
        }

        /**
         * Non-Manager roles must have a branch.
         */
        if (
            $authUser->role !== 'Manager'
            && !$authUser->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Your account is not assigned to any branch.'
            ], 403);
        }

        $perPage = (int) $request->input(
            'per_page',
            10
        );

        $perPage = min(
            max($perPage, 1),
            100
        );

        $query = Teacher::with([
            'shifts',
            'branch'
        ]);

        /**
         * ----------------------------------------------------------------------
         * Branch Isolation
         * ----------------------------------------------------------------------
         *
         * Manager -> All branches
         *
         * Other allowed roles -> Own branch only
         */
        if ($authUser->role !== 'Manager') {

            $query->where(
                'branch_id',
                $authUser->branch_id
            );
        }

        /**
         * ----------------------------------------------------------------------
         * Search
         * ----------------------------------------------------------------------
         */
        if ($request->filled('search')) {

            $search = $request->input(
                'search'
            );

            $query->where(function ($q) use ($search) {

                $q->where(
                    'full_name',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'teacher_id',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /**
         * ----------------------------------------------------------------------
         * Department Filter
         * ----------------------------------------------------------------------
         */
        if ($request->filled('department')) {

            $query->where(
                'department',
                $request->input('department')
            );
        }

        /**
         * ----------------------------------------------------------------------
         * Branch Filter
         * ----------------------------------------------------------------------
         *
         * Only Manager can manually filter branch.
         * Non-manager branch is always forced above.
         */
        if (
            $request->filled('branch_id')
            &&
            $authUser->role === 'Manager'
        ) {

            $query->where(
                'branch_id',
                $request->input('branch_id')
            );
        }

        $teachers = $query
            ->latest()
            ->paginate($perPage);

        /**
         * ----------------------------------------------------------------------
         * Teacher Image URL
         * ----------------------------------------------------------------------
         */
        $teachers
            ->getCollection()
            ->transform(
                function ($teacher) {

                    if (
                        $teacher->image
                        &&
                        !str_starts_with(
                            $teacher->image,
                            'http'
                        )
                    ) {
                        $teacher->image =
                            asset(
                                'storage/' .
                                $teacher->image
                            );
                    }

                    return $teacher;
                }
            );

        return response()->json([
            'status' => true,

            'data' =>
                $teachers->items(),

            'pagination' => [
                'current_page' =>
                    $teachers->currentPage(),

                'last_page' =>
                    $teachers->lastPage(),

                'per_page' =>
                    $teachers->perPage(),

                'total' =>
                    $teachers->total(),

                'from' =>
                    $teachers->firstItem(),

                'to' =>
                    $teachers->lastItem(),
            ],
        ], 200);
    }

    /**
     * --------------------------------------------------------------------------
     * ============================
     * CREATE TEACHER
     * ============================
     * --------------------------------------------------------------------------
     */
    public function store(Request $request)
    {
        $authUser = $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Role authorization.
         */
        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to create teachers.'
            );
        }

        /**
         * Non-Manager must have branch.
         */
        if (
            $authUser->role !== 'Manager'
            && !$authUser->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Your account is not assigned to any branch.'
            ], 403);
        }

        /**
         * Validation.
         */
        $request->validate([
            'full_name' =>
                'required|string|max:255',

            'designation' =>
                'required|string|max:255',

            'department' =>
                'required|string|max:255',

            'qualification' =>
                'nullable|string|max:255',

            'phone' =>
                'nullable|string|max:20',

            'email' =>
                'required|email|unique:teachers,email',

            'joining_date' =>
                'required|date',

            'salary' =>
                'nullable|numeric',

            'branch_id' =>
                'nullable|exists:branches,id',

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',

            'shift_ids' =>
                'nullable|array',

            'shift_ids.*' =>
                'exists:shifts,id',
        ]);

        /**
         * ----------------------------------------------------------------------
         * Branch Assignment
         * ----------------------------------------------------------------------
         *
         * Manager:
         *     Uses selected branch.
         *
         * Non-manager:
         *     Backend forces logged-in user's branch.
         */
        if ($authUser->role !== 'Manager') {

            $branchId =
                $authUser->branch_id;

        } else {

            $branchId =
                $request->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required.'
                ], 422);
            }
        }

        /**
         * ----------------------------------------------------------------------
         * Verify Shift Branch
         * ----------------------------------------------------------------------
         */
        $shiftError =
            $this->validateShiftBranch(
                $request->shift_ids ?? [],
                $branchId
            );

        if ($shiftError) {
            return $shiftError;
        }

        /**
         * ----------------------------------------------------------------------
         * Upload Image
         * ----------------------------------------------------------------------
         */
        $imagePath = null;

        if ($request->hasFile('image')) {

            $file =
                $request->file('image');

            $filename =
                time() .
                '_' .
                Str::random(10) .
                '.' .
                $file->getClientOriginalExtension();

            $imagePath =
                $file->storeAs(
                    'teachers',
                    $filename,
                    'public'
                );
        }

        /**
         * ----------------------------------------------------------------------
         * Generate Teacher ID
         * ----------------------------------------------------------------------
         */
        $lastTeacher =
            Teacher::latest('id')->first();

        if (
            $lastTeacher
            &&
            $lastTeacher->teacher_id
        ) {

            $lastNumber =
                (int) str_replace(
                    'TCH-',
                    '',
                    $lastTeacher->teacher_id
                );

            $teacherId =
                'TCH-' .
                ($lastNumber + 1);

        } else {

            $teacherId =
                'TCH-1001';
        }

        /**
         * ----------------------------------------------------------------------
         * Create Teacher
         * ----------------------------------------------------------------------
         */
        $teacher = Teacher::create([
            'teacher_id' =>
                $teacherId,

            'full_name' =>
                $request->full_name,

            'designation' =>
                $request->designation,

            'department' =>
                $request->department,

            'qualification' =>
                $request->qualification,

            'phone' =>
                $request->phone,

            'email' =>
                $request->email,

            'join_date' =>
                $request->joining_date ??
                now()->toDateString(),

            'salary' =>
                $request->salary ?? 0,

            'image' =>
                $imagePath,

            'branch_id' =>
                $branchId,
        ]);

        /**
         * ----------------------------------------------------------------------
         * Assign Shifts
         * ----------------------------------------------------------------------
         */
        $teacher->shifts()->sync(
            $request->shift_ids ?? []
        );

        /**
         * ----------------------------------------------------------------------
         * Load Relations
         * ----------------------------------------------------------------------
         */
        $teacher->load([
            'shifts',
            'branch'
        ]);

        /**
         * ----------------------------------------------------------------------
         * Image URL
         * ----------------------------------------------------------------------
         */
        if ($teacher->image) {

            $teacher->image =
                asset(
                    'storage/' .
                    $teacher->image
                );
        }

        return response()->json([
            'status' => true,

            'message' =>
                'Teacher added successfully!',

            'data' =>
                $teacher,

        ], 201);
    }

    /**
     * --------------------------------------------------------------------------
     * ============================
     * SHOW TEACHER
     * ============================
     * --------------------------------------------------------------------------
     */
    public function show(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Branch + role access check.
         */
        if (
            !$this->canAccessTeacher(
                $authUser,
                $teacher
            )
        ) {
            return $this->forbidden(
                'You are not authorized to access this teacher.'
            );
        }

        $teacher->load([
            'shifts',
            'branch'
        ]);

        /**
         * Image URL.
         */
        if (
            $teacher->image
            &&
            !str_starts_with(
                $teacher->image,
                'http'
            )
        ) {
            $teacher->image =
                asset(
                    'storage/' .
                    $teacher->image
                );
        }

        return response()->json([
            'status' => true,
            'data' => $teacher,
        ], 200);
    }

    /**
     * --------------------------------------------------------------------------
     * ============================
     * UPDATE TEACHER
     * ============================
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Role + branch access check.
         */
        if (
            !$this->canAccessTeacher(
                $authUser,
                $teacher
            )
        ) {
            return $this->forbidden(
                'You are not authorized to update this teacher.'
            );
        }

        /**
         * Validation.
         */
        $request->validate([
            'full_name' =>
                'required|string|max:255',

            'designation' =>
                'required|string|max:255',

            'department' =>
                'required|string|max:255',

            'qualification' =>
                'nullable|string|max:255',

            'phone' =>
                'nullable|string|max:20',

            'email' =>
                'required|email|unique:teachers,email,' .
                $teacher->id,

            'joining_date' =>
                'nullable|date',

            'join_date' =>
                'nullable|date',

            'salary' =>
                'nullable|numeric',

            'branch_id' =>
                'nullable|exists:branches,id',

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'shift_ids' =>
                'nullable|array',

            'shift_ids.*' =>
                'exists:shifts,id',
        ]);

        /**
         * ----------------------------------------------------------------------
         * Branch Assignment
         * ----------------------------------------------------------------------
         *
         * Manager:
         *     Can change teacher branch.
         *
         * Non-manager:
         *     Teacher remains in logged-in user's branch.
         */
        if ($authUser->role !== 'Manager') {

            $branchId =
                $authUser->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Your account is not assigned to any branch.'
                ], 403);
            }

            /**
             * Extra protection:
             *
             * Existing teacher must already belong
             * to authenticated user's branch.
             */
            if (
                (int) $teacher->branch_id
                !==
                (int) $authUser->branch_id
            ) {
                return $this->forbidden(
                    'You are not authorized to update this teacher.'
                );
            }

        } else {

            $branchId =
                $request->branch_id
                ??
                $teacher->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required.'
                ], 422);
            }
        }

        /**
         * ----------------------------------------------------------------------
         * Verify Shift Branch
         * ----------------------------------------------------------------------
         */
        $shiftError =
            $this->validateShiftBranch(
                $request->shift_ids ?? [],
                $branchId
            );

        if ($shiftError) {
            return $shiftError;
        }

        /**
         * ----------------------------------------------------------------------
         * Image Handling
         * ----------------------------------------------------------------------
         */
        $imagePath =
            $teacher->image;

        if ($request->hasFile('image')) {

            if (
                $teacher->image
                &&
                Storage::disk('public')->exists(
                    $teacher->image
                )
            ) {
                Storage::disk('public')->delete(
                    $teacher->image
                );
            }

            $file =
                $request->file('image');

            $filename =
                time() .
                '_' .
                Str::random(10) .
                '.' .
                $file->getClientOriginalExtension();

            $imagePath =
                $file->storeAs(
                    'teachers',
                    $filename,
                    'public'
                );
        }

        /**
         * ----------------------------------------------------------------------
         * Update Teacher
         * ----------------------------------------------------------------------
         */
        $teacher->update([
            'full_name' =>
                $request->full_name,

            'designation' =>
                $request->designation,

            'department' =>
                $request->department,

            'qualification' =>
                $request->qualification,

            'phone' =>
                $request->phone,

            'email' =>
                $request->email,

            'join_date' =>
                $request->joining_date
                ??
                $request->join_date
                ??
                $teacher->join_date,

            'salary' =>
                $request->salary
                ??
                $teacher->salary,

            'image' =>
                $imagePath,

            'branch_id' =>
                $branchId,
        ]);

        /**
         * ----------------------------------------------------------------------
         * Update Shifts
         * ----------------------------------------------------------------------
         */
        $teacher->shifts()->sync(
            $request->shift_ids ?? []
        );

        /**
         * ----------------------------------------------------------------------
         * Reload Relations
         * ----------------------------------------------------------------------
         */
        $teacher->load([
            'shifts',
            'branch'
        ]);

        /**
         * ----------------------------------------------------------------------
         * Image URL
         * ----------------------------------------------------------------------
         */
        if (
            $teacher->image
            &&
            !str_starts_with(
                $teacher->image,
                'http'
            )
        ) {
            $teacher->image =
                asset(
                    'storage/' .
                    $teacher->image
                );
        }

        return response()->json([
            'status' => true,

            'message' =>
                'Teacher updated successfully!',

            'data' =>
                $teacher,

        ], 200);
    }

    /**
     * --------------------------------------------------------------------------
     * ============================
     * DELETE TEACHER
     * ============================
     * --------------------------------------------------------------------------
     */
    public function destroy(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Role + branch access check.
         */
        if (
            !$this->canAccessTeacher(
                $authUser,
                $teacher
            )
        ) {
            return $this->forbidden(
                'You are not authorized to delete this teacher.'
            );
        }

        /**
         * Delete Image.
         */
        if (
            $teacher->image
            &&
            Storage::disk('public')->exists(
                $teacher->image
            )
        ) {
            Storage::disk('public')->delete(
                $teacher->image
            );
        }

        /**
         * Remove Teacher Shifts.
         */
        $teacher->shifts()->detach();

        /**
         * Delete Teacher.
         */
        $teacher->delete();

        return response()->json([
            'status' => true,

            'message' =>
                'Teacher deleted successfully!'
        ], 200);
    }
}
