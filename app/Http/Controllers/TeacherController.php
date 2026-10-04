<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\Shift;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

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

        $currentBranchId = $this->currentBranchId();

        if (
            $authUser->role === 'Manager'
            && $currentBranchId === null
        ) {
            return true;
        }

        if ($currentBranchId === null) {
            return false;
        }

        return $teacher->branch_id !== null
            && (int) $teacher->branch_id === (int) $currentBranchId;
    }

    private function forbidden(
        $message = 'Access denied.'
    ) {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    private function unauthenticated()
    {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

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
                    'One or more selected shifts do not belong to the selected branch.',
            ], 422);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | TEACHER IMAGE
    |--------------------------------------------------------------------------
    */

    public function image($filename)
    {
        $path = storage_path(
            'app/public/teachers/' . $filename
        );

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Access-Control-Allow-Origin' => 'http://localhost:5173',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TEACHER LIST
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access teachers.'
            );
        }

        $currentBranchId = $this->currentBranchId();

        if (
            $authUser->role !== 'Manager'
            && $currentBranchId === null
        ) {
            return $this->forbidden(
                'Your account is not assigned to any branch.'
            );
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
            'branch',
        ]);

        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        if ($request->filled('search')) {
            $search = $request->input('search');

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

        if ($request->filled('department')) {
            $query->where(
                'department',
                $request->input('department')
            );
        }

        $teachers = $query
            ->latest()
            ->paginate($perPage);

        $teachers
            ->getCollection()
            ->transform(function ($teacher) {

                if (
                    $teacher->image
                    &&
                    !str_starts_with(
                        $teacher->image,
                        'http'
                    )
                ) {
                    $filename = basename(
                        $teacher->image
                    );

                    $teacher->image =
                        url(
                            '/api/teacher-image/' .
                            $filename
                        );
                }

                return $teacher;
            });

        return response()->json([
            'status' => true,
            'data' => $teachers->items(),
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

    /*
    |--------------------------------------------------------------------------
    | CREATE TEACHER
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to create teachers.'
            );
        }

        $currentBranchId = $this->currentBranchId();

        if (
            $authUser->role !== 'Manager'
            && $currentBranchId === null
        ) {
            return $this->forbidden(
                'Your account is not assigned to any branch.'
            );
        }

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

        if ($currentBranchId !== null) {
            $branchId = $currentBranchId;
        } else {
            if ($authUser->role !== 'Manager') {
                return $this->forbidden(
                    'Your account is not assigned to any branch.'
                );
            }

            if (!$request->branch_id) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required when All Branches is selected.',
                ], 422);
            }

            $branchId = $request->branch_id;
        }

        $shiftError =
            $this->validateShiftBranch(
                $request->shift_ids ?? [],
                $branchId
            );

        if ($shiftError) {
            return $shiftError;
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

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

        $teacher->shifts()->sync(
            $request->shift_ids ?? []
        );

        $teacher->load([
            'shifts',
            'branch',
        ]);

        if ($teacher->image) {
            $filename = basename(
                $teacher->image
            );

            $teacher->image =
                url(
                    '/api/teacher-image/' .
                    $filename
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

    /*
    |--------------------------------------------------------------------------
    | SHOW TEACHER
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

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
            'branch',
        ]);

        if (
            $teacher->image
            &&
            !str_starts_with(
                $teacher->image,
                'http'
            )
        ) {
            $filename = basename(
                $teacher->image
            );

            $teacher->image =
                url(
                    '/api/teacher-image/' .
                    $filename
                );
        }

        return response()->json([
            'status' => true,
            'data' => $teacher,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE TEACHER
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

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

        $currentBranchId =
            $this->currentBranchId();

        if ($currentBranchId !== null) {
            $branchId = $currentBranchId;
        } else {
            if ($authUser->role !== 'Manager') {
                return $this->forbidden(
                    'Your account is not assigned to any branch.'
                );
            }

            $branchId =
                $request->branch_id
                ??
                $teacher->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required.',
                ], 422);
            }
        }

        $shiftError =
            $this->validateShiftBranch(
                $request->shift_ids ?? [],
                $branchId
            );

        if ($shiftError) {
            return $shiftError;
        }

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

        $teacher->shifts()->sync(
            $request->shift_ids ?? []
        );

        $teacher->load([
            'shifts',
            'branch',
        ]);

        if (
            $teacher->image
            &&
            !str_starts_with(
                $teacher->image,
                'http'
            )
        ) {
            $filename = basename(
                $teacher->image
            );

            $teacher->image =
                url(
                    '/api/teacher-image/' .
                    $filename
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

    /*
    |--------------------------------------------------------------------------
    | DELETE TEACHER
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        Teacher $teacher
    ) {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

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

        $teacher->shifts()->detach();

        $teacher->delete();

        return response()->json([
            'status' => true,
            'message' =>
                'Teacher deleted successfully!',
        ], 200);
    }
}
