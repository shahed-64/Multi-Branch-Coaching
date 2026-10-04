<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentAttendanceController extends Controller
{
    /**
     * Get authenticated user.
     */
    private function authUser(Request $request)
    {
        return $request->user();
    }

    /**
     * Get current branch from BranchContext.
     *
     * null = Manager + All Branches
     * branch id = selected/own branch
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * Student Attendance Module Access
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
     * Forbidden response.
     */
    private function forbidden(
        string $message = 'You are not authorized to perform this action.'
    ) {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * Unauthenticated response.
     */
    private function unauthenticated()
    {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    /**
     * Check whether authenticated user can access
     * the selected student.
     */
    private function canAccessStudent(
        $authUser,
        Student $student
    ): bool {
        if (!$authUser) {
            return false;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Manager + All Branches
         */
        if (
            $authUser->role === 'Manager'
            && $currentBranchId === null
        ) {
            return true;
        }

        /**
         * Everyone except Manager must have
         * a current branch.
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
     * =========================================================
     * INDEX
     * =========================================================
     *
     * Get students with attendance for selected date.
     *
     * Filters:
     * - date
     * - class_id
     * - class_group_id
     * - section_id
     * - shift_id
     */
    public function index(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student attendance.'
            );
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Non-Manager must have a branch.
         */
        if (
            $authUser->role !== 'Manager'
            && $currentBranchId === null
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not assigned to any branch.',
            ], 403);
        }

        /**
         * Date
         */
        $date = $request->input(
            'date',
            now()->toDateString()
        );

        /**
         * Validate filters.
         */
        $request->validate([
            'date' => [
                'nullable',
                'date',
            ],
            'class_id' => [
                'nullable',
                'integer',
            ],
            'class_group_id' => [
                'nullable',
                'integer',
            ],
            'section_id' => [
                'nullable',
                'integer',
            ],
            'shift_id' => [
                'nullable',
                'integer',
            ],
        ]);

        /**
         * =========================================================
         * STUDENT QUERY
         * =========================================================
         */
        $studentQuery = Student::with([
            'section',
            'classInfo',
            'classGroup',
            'shift',
            'branch',
        ]);

        /**
         * Manager + All Branches
         *
         * No branch filter.
         *
         * Manager + selected branch
         *
         * BranchContext will provide branch id.
         */
        if ($currentBranchId !== null) {
            $studentQuery->where(
                'branch_id',
                $currentBranchId
            );
        }

        /**
         * Class filter.
         */
        if ($request->filled('class_id')) {
            $studentQuery->where(
                'class_id',
                $request->class_id
            );
        }

        /**
         * Class Group filter.
         */
        if ($request->filled('class_group_id')) {
            $studentQuery->where(
                'class_group_id',
                $request->class_group_id
            );
        }

        /**
         * Section filter.
         */
        if ($request->filled('section_id')) {
            $studentQuery->where(
                'section_id',
                $request->section_id
            );
        }

        /**
         * Shift filter.
         */
        if ($request->filled('shift_id')) {
            $studentQuery->where(
                'shift_id',
                $request->shift_id
            );
        }

        /**
         * Get students.
         */
        $students = $studentQuery
            ->orderBy('id', 'desc')
            ->get();

        /**
         * =========================================================
         * ATTENDANCE QUERY
         * =========================================================
         */
        $studentIds = $students
            ->pluck('id')
            ->toArray();

        $attendanceQuery = StudentAttendance::where(
            'date',
            $date
        )->whereIn(
            'student_id',
            $studentIds
        );

        /**
         * Branch isolation for attendance.
         */
        if ($currentBranchId !== null) {
            $attendanceQuery->where(
                'branch_id',
                $currentBranchId
            );
        }

        /**
         * Key attendance by student_id.
         */
        $attendances = $attendanceQuery
            ->get()
            ->keyBy('student_id');

        /**
         * =========================================================
         * ATTACH ATTENDANCE TO STUDENTS
         * =========================================================
         */
        $students->each(function ($student) use ($attendances) {
            $attendance = $attendances->get(
                $student->id
            );

            /**
             * Full attendance object.
             */
            $student->attendance = $attendance;

            /**
             * Easy fields for Vue.
             */
            $student->attendance_id =
                $attendance?->id;

            $student->attendance_status =
                $attendance?->status;

            $student->attendance_remarks =
                $attendance?->remarks;
        });

        return response()->json([
            'status' => true,
            'date' => $date,
            'students' => $students,
            'total_students' => $students->count(),
        ]);
    }

    /**
     * =========================================================
     * STORE / CREATE OR UPDATE
     * =========================================================
     *
     * One student attendance at a time.
     *
     * Payload:
     *
     * {
     *     "student_id": 1,
     *     "date": "2026-10-03",
     *     "status": "present",
     *     "remarks": "..."
     * }
     */
    public function store(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to manage student attendance.'
            );
        }

        /**
         * Validation.
         */
        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'date' => [
                'required',
                'date',
            ],
            'status' => [
                'required',
                Rule::in([
                    'present',
                    'absent',
                    'late',
                    'leave',
                ]),
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        /**
         * Find student.
         */
        $student = Student::findOrFail(
            $validated['student_id']
        );

        /**
         * Branch security.
         */
        if (!$this->canAccessStudent(
            $authUser,
            $student
        )) {
            return $this->forbidden(
                'You cannot manage attendance for a student from another branch.'
            );
        }

        /**
         * Student must have branch.
         */
        if (!$student->branch_id) {
            return response()->json([
                'status' => false,
                'message' => 'Student is not assigned to any branch.',
            ], 422);
        }

        /**
         * Branch is always taken from student.
         *
         * Frontend cannot choose branch_id.
         */
        $branchId = $student->branch_id;

        /**
         * Create or update attendance for
         * same student + same date.
         */
        $attendance = StudentAttendance::updateOrCreate(
            [
                'student_id' => $student->id,
                'date' => $validated['date'],
            ],
            [
                'branch_id' => $branchId,
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Student attendance saved successfully.',
            'attendance' => $attendance->load([
                'student',
                'branch',
            ]),
        ], 200);
    }

    /**
     * =========================================================
     * QR SCAN ATTENDANCE
     * =========================================================
     *
     * QR payload:
     *
     * {
     *     "student_id": "STD-1005"
     * }
     *
     * QR scan always marks the student as PRESENT.
     *
     * If today's attendance already exists,
     * no existing attendance will be changed.
     */
    public function scan(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to use QR attendance.'
            );
        }

        /**
         * Validate QR student ID.
         *
         * Example:
         * STD-1005
         */
        $validated = $request->validate([
            'student_id' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        /**
         * Find student by generated Student ID.
         */
        $student = Student::where(
            'student_id',
            $validated['student_id']
        )->first();

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found.',
            ], 404);
        }

        /**
         * Branch security.
         *
         * This prevents a scanner in one branch
         * from marking another branch's student.
         */
        if (!$this->canAccessStudent(
            $authUser,
            $student
        )) {
            return $this->forbidden(
                'You cannot scan attendance for a student from another branch.'
            );
        }

        /**
         * Student must have branch.
         */
        if (!$student->branch_id) {
            return response()->json([
                'status' => false,
                'message' => 'Student is not assigned to any branch.',
            ], 422);
        }

        /**
         * Today.
         */
        $today = now()->toDateString();

        /**
         * Check whether today's attendance
         * already exists.
         */
        $existingAttendance = StudentAttendance::where(
            'student_id',
            $student->id
        )
            ->where(
                'date',
                $today
            )
            ->first();

        /**
         * Do not modify an existing attendance.
         *
         * This is important because:
         *
         * Manual Absent/Leave/Late
         * should not become Present
         * just because the QR was scanned again.
         */
        if ($existingAttendance) {
            return response()->json([
                'status' => false,
                'already_attended' => true,
                'message' => 'Attendance already recorded for today.',
                'student' => [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'name' => $student->full_name,
                    'branch_id' => $student->branch_id,
                ],
                'attendance' => $existingAttendance,
            ], 409);
        }

        /**
         * Create today's attendance.
         *
         * Branch is always taken from the student.
         */
        $attendance = StudentAttendance::create([
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
            'date' => $today,
            'status' => 'present',
            'remarks' => 'QR Scan',
        ]);

        return response()->json([
            'status' => true,
            'already_attended' => false,
            'message' => 'Attendance marked successfully.',
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name' => $student->full_name,
                'branch_id' => $student->branch_id,
            ],
            'attendance' => $attendance,
        ], 201);
    }

    /**
     * =========================================================
     * SHOW
     * =========================================================
     */
    public function show(
        StudentAttendance $studentAttendance,
        Request $request
    ) {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student attendance.'
            );
        }

        /**
         * Student relation.
         */
        $studentAttendance->load([
            'student',
            'branch',
        ]);

        /**
         * Check student branch access.
         */
        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden(
                'You cannot access attendance from another branch.'
            );
        }

        return response()->json([
            'status' => true,
            'attendance' => $studentAttendance,
        ]);
    }

    /**
     * =========================================================
     * UPDATE
     * =========================================================
     */
    public function update(
        Request $request,
        StudentAttendance $studentAttendance
    ) {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to update student attendance.'
            );
        }

        /**
         * Load student.
         */
        $studentAttendance->load('student');

        /**
         * Branch security.
         */
        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden(
                'You cannot update attendance from another branch.'
            );
        }

        /**
         * Validation.
         */
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'present',
                    'absent',
                    'late',
                    'leave',
                ]),
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        /**
         * Update only allowed fields.
         */
        $studentAttendance->update([
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Student attendance updated successfully.',
            'attendance' => $studentAttendance->fresh()->load([
                'student',
                'branch',
            ]),
        ]);
    }

    /**
     * =========================================================
     * MONTHLY ATTENDANCE SUMMARY
     * =========================================================
     */
    public function monthlySummary(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student attendance.'
            );
        }

        $currentBranchId = $this->currentBranchId();

        if (
            $authUser->role !== 'Manager'
            && $currentBranchId === null
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not assigned to any branch.',
            ], 403);
        }

        $month = $request->input(
            'month',
            now()->format('Y-m')
        );

        $request->validate([
            'month' => [
                'nullable',
                'date_format:Y-m',
            ],
        ]);

        $query = StudentAttendance::query()
            ->whereYear('date', substr($month, 0, 4))
            ->whereMonth('date', substr($month, 5, 2));

        /**
         * Branch isolation
         */
        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        $totalRecords = (clone $query)->count();

        $present = (clone $query)
            ->whereIn('status', ['present', 'late'])
            ->count();

        $absent = (clone $query)
            ->where('status', 'absent')
            ->count();

        $leave = (clone $query)
            ->where('status', 'leave')
            ->count();

        $applicable = $present + $absent + $leave;

        $presentPercentage = $applicable > 0
            ? round(($present / $applicable) * 100)
            : 0;

        $absentPercentage = $applicable > 0
            ? round(($absent / $applicable) * 100)
            : 0;

        $leavePercentage = $applicable > 0
            ? 100 - $presentPercentage - $absentPercentage
            : 0;

        return response()->json([
            'status' => true,
            'month' => $month,
            'total_records' => $totalRecords,
            'present' => $present,
            'absent' => $absent,
            'leave' => $leave,
            'present_percentage' => $presentPercentage,
            'absent_percentage' => $absentPercentage,
            'leave_percentage' => $leavePercentage,
        ]);
    }

    /**
     * =========================================================
     * YEARLY ATTENDANCE SUMMARY
     * =========================================================
     *
     * Yearly attendance summary for all students.
     */
    public function yearlySummary(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student attendance.'
            );
        }

        $validated = $request->validate([
            'year' => [
                'nullable',
                'integer',
                'min:2000',
                'max:2100',
            ],
        ]);

        $year = $validated['year'] ?? now()->year;

        $branchId = $this->currentBranchId();

        /**
         * Students
         */
        $studentQuery = Student::with([
            'classInfo',
        ]);

        /**
         * Branch Isolation
         */
        if ($branchId !== null) {
            $studentQuery->where(
                'branch_id',
                $branchId
            );
        }

        $students = $studentQuery
            ->orderBy('id', 'desc')
            ->get();

        if ($students->isEmpty()) {
            return response()->json([
                'status' => true,
                'year' => $year,
                'students' => [],
            ]);
        }

        /**
         * Attendance Records
         */
        $studentIds = $students->pluck('id');

        $attendanceQuery = StudentAttendance::whereIn(
            'student_id',
            $studentIds
        )
            ->whereYear('date', $year)
            ->orderBy('date', 'asc');

        if ($branchId !== null) {
            $attendanceQuery->where(
                'branch_id',
                $branchId
            );
        }

        $attendances = $attendanceQuery->get();

        /**
         * Group attendance by student
         */
        $attendanceByStudent = $attendances->groupBy(
            'student_id'
        );

        /**
         * Build yearly summary
         */
        $result = $students->map(
            function ($student) use ($attendanceByStudent) {
                $studentAttendances =
                    $attendanceByStudent->get(
                        $student->id,
                        collect()
                    );

                $present = $studentAttendances
                    ->where('status', 'present')
                    ->count();

                $late = $studentAttendances
                    ->where('status', 'late')
                    ->count();

                $absent = $studentAttendances
                    ->where('status', 'absent')
                    ->count();

                $leave = $studentAttendances
                    ->where('status', 'leave')
                    ->count();

                $totalRecords =
                    $studentAttendances->count();

                return [
                    'id' => $student->id,

                    'student_code' =>
                        $student->student_id
                        ?? ('STD-' . $student->id),

                    'name' =>
                        $student->full_name
                        ?? 'Unknown',

                    'class_name' =>
                        optional(
                            $student->classInfo
                        )->class_name,

                    'present' => $present,

                    'late' => $late,

                    'absent' => $absent,

                    'leave' => $leave,

                    'total_records' =>
                        $totalRecords,

                    /**
                     * Only required data for the
                     * yearly/monthly report.
                     *
                     * Group, Section and Shift
                     * are intentionally excluded.
                     */
                    'attendances' =>
                        $studentAttendances
                            ->map(function ($attendance) {
                                return [
                                    'date' =>
                                        $attendance->date,

                                    'status' =>
                                        $attendance->status,
                                ];
                            })
                            ->values(),
                ];
            }
        )->values();

        return response()->json([
            'status' => true,
            'year' => $year,
            'students' => $result,
        ]);
    }

    /**
     * =========================================================
     * DELETE
     * =========================================================
     */
    public function destroy(
        StudentAttendance $studentAttendance,
        Request $request
    ) {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to delete student attendance.'
            );
        }

        /**
         * Load student.
         */
        $studentAttendance->load('student');

        /**
         * Branch security.
         */
        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden(
                'You cannot delete attendance from another branch.'
            );
        }

        $studentAttendance->delete();

        return response()->json([
            'status' => true,
            'message' => 'Student attendance deleted successfully.',
        ]);
    }
}
