<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Payment;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class StudentController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * Check whether authenticated user can access Student module.
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
     * Get current branch context.
     * --------------------------------------------------------------------------
     *
     * Manager:
     * - Selected branch from sidebar => that branch
     * - All Branches => null
     *
     * Non-Manager:
     * - Their own assigned branch
     *
     * BranchContextMiddleware already determines this.
     * --------------------------------------------------------------------------
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * --------------------------------------------------------------------------
     * Check whether authenticated user can access this student.
     * --------------------------------------------------------------------------
     *
     * Current branch context is respected.
     *
     * Manager:
     * - All Branches => can access all branches.
     * - Selected Branch => only selected branch.
     *
     * Other authorized roles:
     * - Only their current/assigned branch.
     * --------------------------------------------------------------------------
     */
    private function canAccessStudent(
        $authUser,
        Student $student
    ): bool {
        if (!$authUser) {
            return false;
        }

        if (!$this->canAccessModule($authUser)) {
            return false;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * All Branches context.
         *
         * Only Manager can have an unrestricted
         * All Branches context.
         */
        if (
            $currentBranchId === null
            && $authUser->role === 'Manager'
        ) {
            return true;
        }

        /**
         * Current branch must exist for every
         * branch-scoped access.
         */
        if ($currentBranchId === null) {
            return false;
        }

        /**
         * Student must belong to current branch.
         */
        return $student->branch_id !== null
            && (int) $student->branch_id === (int) $currentBranchId;
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
     * Display a listing of students.
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
         *
         * Accountant / Branch Accountant are blocked here.
         */
        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access students.'
            );
        }

        /**
         * Current branch comes from central BranchContext.
         */
        $currentBranchId = $this->currentBranchId();

        /**
         * Non-Manager must have a branch context.
         */
        if (
            $authUser->role !== 'Manager'
            && $currentBranchId === null
        ) {
            return $this->forbidden(
                'Your account is not assigned to any branch.'
            );
        }

        $perPage = (int) $request->get(
            'per_page',
            10
        );

        $perPage = min(
            max($perPage, 1),
            100
        );

        $search = trim(
            $request->get('search', '')
        );

        $classId = $request->get('class_id');

        $query = Student::with([
            'section',
            'classInfo',
            'classGroup',
            'shift',
            'branch',
            'payments' => function ($q) {
                $q->select(
                    'id',
                    'student_id',
                    'month',
                    'paid_amount',
                    'due_amount',
                    'status'
                );
            }
        ])->orderBy('id', 'desc');

        /**
         * ----------------------------------------------------------------------
         * BRANCH FILTER
         * ----------------------------------------------------------------------
         *
         * Manager + All Branches:
         * - currentBranchId = null
         * - No branch filter.
         *
         * Manager + Selected Branch:
         * - Filter by selected branch.
         *
         * Non-Manager:
         * - BranchContext contains their own branch.
         */
        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        /**
         * ----------------------------------------------------------------------
         * SEARCH
         * ----------------------------------------------------------------------
         */
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where(
                    'full_name',
                    'like',
                    '%' . $search . '%'
                )
                    ->orWhere(
                        'email',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'student_id',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        /**
         * ----------------------------------------------------------------------
         * CLASS FILTER
         * ----------------------------------------------------------------------
         */
        if (!empty($classId)) {
            $query->where(
                'class_id',
                $classId
            );
        }

        $students = $query->paginate($perPage);

        /**
         * ----------------------------------------------------------------------
         * MONTH CALCULATION
         * ----------------------------------------------------------------------
         */
        $allMonths = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $currentMonth = Carbon::now()->month;

        foreach ($students->items() as $student) {

            $paidMonths =
                $student->payments
                    ->pluck('month')
                    ->toArray();

            $admissionMonth =
                $student->admission_date
                    ? Carbon::parse(
                        $student->admission_date
                    )->month
                    : 1;

            $monthsTillNow =
                array_slice(
                    $allMonths,
                    $admissionMonth - 1,
                    max(
                        0,
                        $currentMonth -
                        $admissionMonth +
                        1
                    )
                );

            $dueMonths =
                array_values(
                    array_diff(
                        $monthsTillNow,
                        $paidMonths
                    )
                );

            $monthsTillDecember =
                array_slice(
                    $allMonths,
                    $admissionMonth - 1
                );

            $availableMonths =
                array_values(
                    array_diff(
                        $monthsTillDecember,
                        $paidMonths
                    )
                );

            $student->setAttribute(
                'due_months',
                $dueMonths
            );

            $student->setAttribute(
                'available_months',
                $availableMonths
            );
        }

        /**
         * ----------------------------------------------------------------------
         * TOTAL STUDENTS
         * ----------------------------------------------------------------------
         */
        $totalStudentsQuery =
            Student::query();

        /**
         * Same current branch context as the main list.
         */
        if ($currentBranchId !== null) {
            $totalStudentsQuery->where(
                'branch_id',
                $currentBranchId
            );
        }

        $totalStudents =
            $totalStudentsQuery->count();

        return response()->json([
            'status' => true,

            'students' =>
                $students->items(),

            'pagination' => [
                'current_page' =>
                    $students->currentPage(),

                'last_page' =>
                    $students->lastPage(),

                'per_page' =>
                    $students->perPage(),

                'total' =>
                    $students->total(),

                'from' =>
                    $students->firstItem(),

                'to' =>
                    $students->lastItem(),
            ],

            'total_students' =>
                $totalStudents,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Store a newly created student.
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
                'You are not authorized to create students.'
            );
        }

        $request->validate([
            'full_name' =>
                'required|string|max:255',

            'version' =>
                'required|string|max:50',

            'fathers_name' =>
                'required|string|max:255',

            'mothers_name' =>
                'required|string|max:255',

            'phone' =>
                'required|string|max:20',

            'section_id' =>
                'required|exists:sections,id',

            'class_id' =>
                'required|exists:clss_m_s,id',

            'class_group_id' =>
                'required|exists:class_groups,id',

            'shift_id' =>
                'required|exists:shifts,id',

            'course_name' =>
                'nullable|string|max:100',

            'admission_date' =>
                'nullable|date',

            'email' =>
                'required|email|unique:students,email',

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'monthly_fee' =>
                'nullable|numeric',

            'branch_id' =>
                'nullable|exists:branches,id',
        ]);

        /**
         * ----------------------------------------------------------------------
         * DETERMINE BRANCH FROM CURRENT CONTEXT
         * ----------------------------------------------------------------------
         *
         * Manager + selected branch:
         * - Student MUST be created in selected branch.
         * - Frontend branch_id cannot override current context.
         *
         * Manager + All Branches:
         * - Manager must select branch_id from request.
         *
         * Non-Manager:
         * - Current BranchContext is their own branch.
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {

            $branchId = $currentBranchId;

        } else {

            /**
             * All Branches is only possible for Manager.
             */
            if ($authUser->role !== 'Manager') {
                return $this->forbidden(
                    'Your account is not assigned to any branch.'
                );
            }

            if (!$request->branch_id) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required when All Branches is selected.'
                ], 422);
            }

            $branchId =
                $request->branch_id;
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY SECTION BRANCH
         * ----------------------------------------------------------------------
         */
        $section =
            \App\Models\Section::find(
                $request->section_id
            );

        if (
            !$section ||
            (int) $section->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected section does not belong to the selected branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY CLASS BRANCH
         * ----------------------------------------------------------------------
         */
        $class =
            \App\Models\ClssM::find(
                $request->class_id
            );

        if (
            !$class ||
            (int) $class->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected class does not belong to the selected branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY CLASS GROUP BRANCH
         * ----------------------------------------------------------------------
         */
        $classGroup =
            \App\Models\ClassGroup::find(
                $request->class_group_id
            );

        if (
            !$classGroup ||
            (int) $classGroup->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected class group does not belong to the selected branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY SHIFT BRANCH
         * ----------------------------------------------------------------------
         */
        $shift =
            \App\Models\Shift::find(
                $request->shift_id
            );

        if (
            !$shift ||
            (int) $shift->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to the selected branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * IMAGE
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
                    'students',
                    $filename,
                    'public'
                );
        }

        /**
         * ----------------------------------------------------------------------
         * GENERATE STUDENT ID
         * ----------------------------------------------------------------------
         */
        $lastStudent =
            Student::latest('id')->first();

        if (
            $lastStudent &&
            $lastStudent->student_id
        ) {

            $lastNumber =
                (int) str_replace(
                    'STD-',
                    '',
                    $lastStudent->student_id
                );

            $studentId =
                'STD-' .
                ($lastNumber + 1);

        } else {

            $studentId =
                'STD-1001';
        }

        /**
         * ----------------------------------------------------------------------
         * CREATE STUDENT
         * ----------------------------------------------------------------------
         */
        $student = Student::create([

            'full_name' =>
                $request->full_name,

            'version' =>
                $request->version,

            'fathers_name' =>
                $request->fathers_name,

            'mothers_name' =>
                $request->mothers_name,

            'student_id' =>
                $studentId,

            'phone' =>
                $request->phone,

            'section_id' =>
                $request->section_id,

            'class_id' =>
                $request->class_id,

            'class_group_id' =>
                $request->class_group_id,

            'shift_id' =>
                $request->shift_id,

            'course_name' =>
                $request->course_name,

            'admission_date' =>
                $request->admission_date
                ?? now()->toDateString(),

            'email' =>
                $request->email,

            'image' =>
                $imagePath,

            'monthly_fee' =>
                $request->monthly_fee,

            'branch_id' =>
                $branchId,
        ]);

        return response()->json([
            'status' => true,

            'message' =>
                'Student Created Successfully',

            'student' =>
                $student->load([
                    'section',
                    'classInfo',
                    'classGroup',
                    'shift',
                    'branch'
                ])
        ], 201);
    }

    /**
     * --------------------------------------------------------------------------
     * Display the specified student.
     * --------------------------------------------------------------------------
     */
    public function show(
        Request $request,
        Student $student
    ) {
        $authUser =
            $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Current branch context access check.
         */
        if (
            !$this->canAccessStudent(
                $authUser,
                $student
            )
        ) {
            return $this->forbidden(
                'You cannot access this student.'
            );
        }

        return response()->json([
            'status' => true,

            'student' =>
                $student->load([
                    'payments',
                    'section',
                    'classInfo',
                    'classGroup',
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Show the form data for editing.
     * --------------------------------------------------------------------------
     */
    public function edit(
        Request $request,
        Student $student
    ) {
        $authUser =
            $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Current branch context access check.
         */
        if (
            !$this->canAccessStudent(
                $authUser,
                $student
            )
        ) {
            return $this->forbidden(
                'You cannot edit this student.'
            );
        }

        return response()->json([
            'status' => true,

            'student' =>
                $student->load([
                    'payments',
                    'section',
                    'classInfo',
                    'classGroup',
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Update the specified student.
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        Student $student
    ) {
        $authUser =
            $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Current branch context access check.
         */
        if (
            !$this->canAccessStudent(
                $authUser,
                $student
            )
        ) {
            return $this->forbidden(
                'You cannot update this student.'
            );
        }

        $request->validate([
            'full_name' =>
                'required|string|max:255',

            'version' =>
                'required|string|max:50',

            'phone' =>
                'required|string|max:20',

            'section_id' =>
                'required|exists:sections,id',

            'class_id' =>
                'required|exists:clss_m_s,id',

            'class_group_id' =>
                'required|exists:class_groups,id',

            'shift_id' =>
                'required|exists:shifts,id',

            'course_name' =>
                'nullable|string|max:100',

            'admission_date' =>
                'required|date',

            'email' => [
                'required',
                'email',
                Rule::unique(
                    'students',
                    'email'
                )->ignore($student->id)
            ],

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'monthly_fee' =>
                'nullable|numeric',
        ]);

        /**
         * ----------------------------------------------------------------------
         * IMPORTANT
         * ----------------------------------------------------------------------
         *
         * Student branch can NEVER be changed here.
         *
         * It is taken from the existing database record.
         */
        $branchId =
            $student->branch_id;

        /**
         * Student must already have a valid branch.
         */
        if (!$branchId) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Student is not assigned to any branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY SECTION BRANCH
         * ----------------------------------------------------------------------
         */
        $section =
            \App\Models\Section::find(
                $request->section_id
            );

        if (
            !$section ||
            (int) $section->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected section does not belong to this student branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY CLASS BRANCH
         * ----------------------------------------------------------------------
         */
        $class =
            \App\Models\ClssM::find(
                $request->class_id
            );

        if (
            !$class ||
            (int) $class->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected class does not belong to this student branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY CLASS GROUP BRANCH
         * ----------------------------------------------------------------------
         */
        $classGroup =
            \App\Models\ClassGroup::find(
                $request->class_group_id
            );

        if (
            !$classGroup ||
            (int) $classGroup->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected class group does not belong to this student branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * VERIFY SHIFT BRANCH
         * ----------------------------------------------------------------------
         */
        $shift =
            \App\Models\Shift::find(
                $request->shift_id
            );

        if (
            !$shift ||
            (int) $shift->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to this student branch.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * IMAGE
         * ----------------------------------------------------------------------
         */
        $imagePath =
            $student->image;

        if ($request->hasFile('image')) {

            if (
                $student->image &&
                Storage::disk('public')->exists(
                    $student->image
                )
            ) {
                Storage::disk('public')->delete(
                    $student->image
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
                    'students',
                    $filename,
                    'public'
                );
        }

        /**
         * ----------------------------------------------------------------------
         * UPDATE
         * ----------------------------------------------------------------------
         *
         * branch_id intentionally NOT included.
         *
         * This prevents branch manipulation.
         */
        $student->update([

            'full_name' =>
                $request->full_name,

            'version' =>
                $request->version,

            'phone' =>
                $request->phone,

            'section_id' =>
                $request->section_id,

            'class_id' =>
                $request->class_id,

            'class_group_id' =>
                $request->class_group_id,

            'shift_id' =>
                $request->shift_id,

            'course_name' =>
                $request->course_name,

            'admission_date' =>
                $request->admission_date,

            'email' =>
                $request->email,

            'image' =>
                $imagePath,

            'monthly_fee' =>
                $request->monthly_fee
                ?? $student->monthly_fee,
        ]);

        return response()->json([
            'status' => true,

            'message' =>
                'Student Updated Successfully',

            'student' =>
                $student->load([
                    'section',
                    'classInfo',
                    'classGroup',
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Remove the specified student.
     * --------------------------------------------------------------------------
     */
    public function destroy(
        Request $request,
        Student $student
    ) {
        $authUser =
            $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Current branch context access check.
         */
        if (
            !$this->canAccessStudent(
                $authUser,
                $student
            )
        ) {
            return $this->forbidden(
                'You cannot delete this student.'
            );
        }

        /**
         * ----------------------------------------------------------------------
         * DELETE IMAGE
         * ----------------------------------------------------------------------
         */
        if (
            $student->image &&
            Storage::disk('public')->exists(
                $student->image
            )
        ) {
            Storage::disk('public')->delete(
                $student->image
            );
        }

        /**
         * ----------------------------------------------------------------------
         * DELETE RELATED PAYMENTS
         * ----------------------------------------------------------------------
         */
        $student->payments()->delete();

        /**
         * ----------------------------------------------------------------------
         * DELETE STUDENT
         * ----------------------------------------------------------------------
         */
        $student->delete();

        return response()->json([
            'status' => true,
            'message' =>
                'Student and related payments deleted successfully'
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Get payments of a student.
     * --------------------------------------------------------------------------
     */
    public function studentPayments(
        Request $request,
        $id
    ) {
        $authUser =
            $request->user();

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return $this->unauthenticated();
        }

        /**
         * Role authorization before querying student.
         */
        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student payments.'
            );
        }

        /**
         * Find student.
         */
        $student =
            Student::findOrFail($id);

        /**
         * ----------------------------------------------------------------------
         * CURRENT BRANCH ACCESS CHECK
         * ----------------------------------------------------------------------
         */
        if (
            !$this->canAccessStudent(
                $authUser,
                $student
            )
        ) {
            return $this->forbidden(
                'You cannot access payments of a student from another branch.'
            );
        }

        /**
         * ----------------------------------------------------------------------
         * GET PAYMENTS
         * ----------------------------------------------------------------------
         *
         * Payments are retrieved through the already authorized student.
         */
        $payments =
            Payment::with('student')
                ->where(
                    'student_id',
                    $student->id
                )
                ->latest()
                ->get();

        return response()->json([
            'status' =>
                true,

            'payments' =>
                $payments
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Student image.
     * --------------------------------------------------------------------------
     *
     * NOTE:
     * This endpoint may intentionally remain public because student image
     * URLs are used directly by the frontend.
     *
     * If this route is protected with auth:sanctum later, frontend image
     * loading must also be changed accordingly.
     * --------------------------------------------------------------------------
     */
    public function image($filename)
    {
        $path =
            storage_path(
                'app/public/students/' .
                $filename
            );

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Access-Control-Allow-Origin' =>
                'http://localhost:5173',

            'Access-Control-Allow-Methods' =>
                'GET, OPTIONS',

            'Access-Control-Allow-Headers' =>
                'Content-Type',
        ]);
    }
}
