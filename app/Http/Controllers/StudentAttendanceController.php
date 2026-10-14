<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentAttendanceController extends Controller
{
    private function authUser(Request $request)
    {
        return $request->user();
    }

    private function currentBranchId(): ?int
    {
        $id = app(BranchContext::class)->id();

        return $id === null ? null : (int) $id;
    }

    private function canAccessModule($authUser): bool
    {
        return $authUser && in_array(
            $authUser->role,
            ['Manager', 'Branch Manager', 'Admin', 'Branch Admin'],
            true
        );
    }

    private function forbidden()
    {
        return response()->json([
            'message' => 'You are not authorized to access student attendance.',
        ], 403);
    }

    private function unauthenticated()
    {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    private function canAccessStudent($authUser, Student $student): bool
    {
        if (!$authUser) {
            return false;
        }

        if ($authUser->role === 'Manager' && $this->currentBranchId() === null) {
            return true;
        }

        $branchId = $this->currentBranchId();

        return $branchId !== null
            && (int) $student->branch_id === $branchId;
    }

    /**
     * Check whether the requested academic session is accessible
     * within the current branch context.
     */
    private function accessibleSession(int $sessionId): ?AcademicSession
    {
        $query = AcademicSession::query()
            ->whereKey($sessionId);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    /**
     * Check whether an enrollment belongs to the current branch context.
     */
    private function accessibleStudentSession(
        int $studentSessionId
    ): ?StudentSession {
        $query = StudentSession::query()
            ->with([
                'student',
                'academicSession',
                'classInfo',
                'classGroup',
                'section',
                'shift',
                'branch',
            ])
            ->whereKey($studentSessionId);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    /**
     * Keep the existing flat student response shape for the Vue UI,
     * while attaching the session-specific enrollment details.
     */
    private function formatStudentSession(
        StudentSession $enrollment,
        ?StudentAttendance $attendance = null
    ): Student {
        $student = $enrollment->student;

        $student->setAttribute(
            'student_session_id',
            $enrollment->id
        );

        $student->setAttribute(
            'academic_session_id',
            $enrollment->academic_session_id
        );

        $student->setAttribute(
            'enrollment_status',
            $enrollment->status
        );

        $student->setAttribute(
            'session_monthly_fee',
            $enrollment->monthly_fee
        );

        $student->setRelation('classInfo', $enrollment->classInfo);
        $student->setRelation('classGroup', $enrollment->classGroup);
        $student->setRelation('section', $enrollment->section);
        $student->setRelation('shift', $enrollment->shift);
        $student->setRelation('branch', $enrollment->branch);
        $student->setRelation('academicSession', $enrollment->academicSession);

        $student->setRelation('attendance', $attendance);

        $student->setAttribute('attendance_id', $attendance?->id);
        $student->setAttribute('attendance_status', $attendance?->status);
        $student->setAttribute('attendance_remarks', $attendance?->remarks);

        return $student;
    }

    /**
     * List students enrolled in a particular academic session.
     */
    public function index(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
            ],
            'date' => ['nullable', 'date'],
            'class_id' => ['nullable', 'integer'],
            'class_group_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
        ]);

        $academicSession = $this->accessibleSession(
            (int) $validated['academic_session_id']
        );

        if (!$academicSession) {
            return $this->forbidden();
        }

        $date = $validated['date'] ?? now()->toDateString();

        $query = StudentSession::query()
            ->with([
                'student',
                'academicSession',
                'classInfo',
                'classGroup',
                'section',
                'shift',
                'branch',
            ])
            ->where('academic_session_id', $academicSession->id);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        foreach ([
            'class_id',
            'class_group_id',
            'section_id',
            'shift_id',
        ] as $filter) {
            if (
                isset($validated[$filter])
                && $validated[$filter] !== ''
            ) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $enrollments = $query
            ->whereHas('student')
            ->get();

        $sessionIds = $enrollments->pluck('id');

        $attendanceQuery = StudentAttendance::query()
            ->whereDate('date', $date)
            ->whereIn('student_session_id', $sessionIds);

        if ($branchId !== null) {
            $attendanceQuery->where('branch_id', $branchId);
        }

        $attendanceBySession = $attendanceQuery
            ->get()
            ->keyBy('student_session_id');

        $students = $enrollments->map(function ($enrollment) use (
            $attendanceBySession
        ) {
            return $this->formatStudentSession(
                $enrollment,
                $attendanceBySession->get($enrollment->id)
            );
        })->values();

        return response()->json([
            'academic_session' => $academicSession,
            'academic_session_id' => $academicSession->id,
            'date' => $date,
            'students' => $students,
            'total_students' => $students->count(),
        ]);
    }

    /**
     * Create or update attendance for a student's session enrollment.
     */
    public function store(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
            ],
            'date' => ['required', 'date'],
            'status' => [
                'required',
                Rule::in(['present', 'absent', 'late', 'leave']),
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $academicSession = $this->accessibleSession(
            (int) $validated['academic_session_id']
        );

        if (!$academicSession) {
            return $this->forbidden();
        }

        $enrollmentQuery = StudentSession::query()
            ->with(['student', 'academicSession', 'branch'])
            ->where('student_id', $validated['student_id'])
            ->where('academic_session_id', $academicSession->id);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $enrollmentQuery->where('branch_id', $branchId);
        }

        $enrollment = $enrollmentQuery->first();

        if (!$enrollment || !$enrollment->student) {
            return response()->json([
                'message' => 'Student is not enrolled in the selected academic session.',
            ], 422);
        }

        if (!$this->canAccessStudent($authUser, $enrollment->student)) {
            return $this->forbidden();
        }

        $attendance = StudentAttendance::updateOrCreate(
            [
                'student_session_id' => $enrollment->id,
                'date' => $validated['date'],
            ],
            [
                'student_id' => $enrollment->student_id,
                'branch_id' => $enrollment->branch_id,
                'status' => $validated['status'],
                'remarks' => $validated['remarks'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Attendance saved successfully.',
            'attendance' => $attendance->load([
                'student',
                'studentSession.academicSession',
                'branch',
            ]),
        ]);
    }

    /**
     * QR scan attendance.
     *
     * The frontend must send academic_session_id along with student_id.
     */
    public function scan(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:255'],
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
            ],
        ]);

        $academicSession = $this->accessibleSession(
            (int) $validated['academic_session_id']
        );

        if (!$academicSession) {
            return $this->forbidden();
        }

        $student = Student::query()
            ->where('student_id', $validated['student_id'])
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        if (!$this->canAccessStudent($authUser, $student)) {
            return $this->forbidden();
        }

        $enrollmentQuery = StudentSession::query()
            ->where('student_id', $student->id)
            ->where('academic_session_id', $academicSession->id);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $enrollmentQuery->where('branch_id', $branchId);
        }

        $enrollment = $enrollmentQuery->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Student is not enrolled in the selected academic session.',
            ], 422);
        }

        $today = now()->toDateString();

        $existing = StudentAttendance::query()
            ->where('student_session_id', $enrollment->id)
            ->whereDate('date', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Attendance already recorded for this session today.',
                'already_attended' => true,
                'attendance' => $existing->load([
                    'student',
                    'studentSession.academicSession',
                    'branch',
                ]),
            ], 409);
        }

        $attendance = StudentAttendance::create([
            'student_session_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'branch_id' => $enrollment->branch_id,
            'date' => $today,
            'status' => 'present',
            'remarks' => 'QR Scan',
        ]);

        return response()->json([
            'message' => 'Attendance recorded successfully.',
            'already_attended' => false,
            'attendance' => $attendance->load([
                'student',
                'studentSession.academicSession',
                'branch',
            ]),
        ], 201);
    }

    /**
     * Show a specific attendance record.
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
            return $this->forbidden();
        }

        $studentAttendance->load([
            'student',
            'studentSession.academicSession',
            'branch',
        ]);

        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden();
        }

        return response()->json([
            'attendance' => $studentAttendance,
        ]);
    }

    /**
     * Update the status or remarks of an existing attendance record.
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
            return $this->forbidden();
        }

        $studentAttendance->load('student');

        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['present', 'absent', 'late', 'leave']),
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $studentAttendance->update([
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Attendance updated successfully.',
            'attendance' => $studentAttendance->fresh()->load([
                'student',
                'studentSession.academicSession',
                'branch',
            ]),
        ]);
    }

    /**
     * Monthly summary for one academic session.
     */
    public function monthlySummary(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
            ],
            'month' => [
                'nullable',
                'date_format:Y-m',
            ],
        ]);

        $academicSession = $this->accessibleSession(
            (int) $validated['academic_session_id']
        );

        if (!$academicSession) {
            return $this->forbidden();
        }

        $month = $validated['month'] ?? now()->format('Y-m');
        [$year, $monthNumber] = array_map(
            'intval',
            explode('-', $month)
        );

        $query = StudentAttendance::query()
            ->whereYear('date', $year)
            ->whereMonth('date', $monthNumber)
            ->whereHas('studentSession', function ($query) use (
                $academicSession
            ) {
                $query->where(
                    'academic_session_id',
                    $academicSession->id
                );
            });

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $records = $query->get();

        $totalRecords = $records->count();
        $present = $records->whereIn('status', ['present', 'late'])->count();
        $absent = $records->where('status', 'absent')->count();
        $leave = $records->where('status', 'leave')->count();

        return response()->json([
            'academic_session' => $academicSession,
            'month' => $month,
            'total_records' => $totalRecords,
            'present' => $present,
            'absent' => $absent,
            'leave' => $leave,
            'present_percentage' => $totalRecords > 0
                ? round(($present / $totalRecords) * 100, 2)
                : 0,
            'absent_percentage' => $totalRecords > 0
                ? round(($absent / $totalRecords) * 100, 2)
                : 0,
        ]);
    }

    /**
     * Yearly summary for one academic session.
     *
     * Here "year" means the calendar year of attendance dates;
     * academic-session filtering is applied separately.
     */
    public function yearlySummary(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'academic_session_id' => [
                'required',
                'integer',
                'exists:academic_sessions,id',
            ],
            'year' => [
                'nullable',
                'integer',
                'min:2000',
                'max:2100',
            ],
        ]);

        $academicSession = $this->accessibleSession(
            (int) $validated['academic_session_id']
        );

        if (!$academicSession) {
            return $this->forbidden();
        }

        $year = (int) ($validated['year'] ?? now()->year);

        $enrollmentQuery = StudentSession::query()
            ->with([
                'student',
                'academicSession',
                'classInfo',
                'classGroup',
                'section',
                'shift',
                'branch',
            ])
            ->where('academic_session_id', $academicSession->id);

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $enrollmentQuery->where('branch_id', $branchId);
        }

        $enrollments = $enrollmentQuery
            ->whereHas('student')
            ->get();

        $sessionIds = $enrollments->pluck('id');

        $attendanceQuery = StudentAttendance::query()
            ->whereIn('student_session_id', $sessionIds)
            ->whereYear('date', $year);

        if ($branchId !== null) {
            $attendanceQuery->where('branch_id', $branchId);
        }

        $attendanceBySession = $attendanceQuery
            ->orderBy('date')
            ->get()
            ->groupBy('student_session_id');

        $students = $enrollments->map(function ($enrollment) use (
            $attendanceBySession
        ) {
            $records = $attendanceBySession->get(
                $enrollment->id,
                collect()
            );

            $present = $records->whereIn(
                'status',
                ['present', 'late']
            )->count();

            $absent = $records->where('status', 'absent')->count();
            $leave = $records->where('status', 'leave')->count();

            $student = $this->formatStudentSession($enrollment);

            $student->setAttribute('present', $present);
            $student->setAttribute('absent', $absent);
            $student->setAttribute('leave', $leave);
            $student->setAttribute('total_records', $records->count());

            $student->setAttribute(
                'attendance_records',
                $records->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'date' => $record->date,
                        'status' => $record->status,
                        'remarks' => $record->remarks,
                    ];
                })->values()
            );

            return $student;
        })->values();

        return response()->json([
            'academic_session' => $academicSession,
            'academic_session_id' => $academicSession->id,
            'year' => $year,
            'students' => $students,
        ]);
    }

    /**
     * Delete an attendance record.
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
            return $this->forbidden();
        }

        $studentAttendance->load('student');

        if (
            !$studentAttendance->student
            || !$this->canAccessStudent(
                $authUser,
                $studentAttendance->student
            )
        ) {
            return $this->forbidden();
        }

        $studentAttendance->delete();

        return response()->json([
            'message' => 'Attendance deleted successfully.',
        ]);
    }
}
