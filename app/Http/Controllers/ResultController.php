<?php

namespace App\Http\Controllers;
use App\Models\Result;
use App\Models\Student;
use App\Models\StudentSession;
use App\Models\GroupSubjectMapping;
use App\Models\GradingSystem;
use App\Models\Examination;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ResultController extends Controller
{
    /**
     * Result module access.
     */
    private function authorizeAccess(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (in_array($user->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access results.',
            ], 403);
        }

        return null;
    }

    /**
     * Display results and students with their academic sessions.
     */
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $perPage = max(
            1,
            min((int) $request->query('per_page', 20), 100)
        );

        $studentSearch = $request->query('student_search');

        /*
        |--------------------------------------------------------------------------
        | Results Query
        |--------------------------------------------------------------------------
        */

        $resultsQuery = Result::with([
            'student.section',
            'student.classInfo',
            'student.classGroup',
            'studentSession.academicSession',
            'studentSession.classInfo',
            'studentSession.section',
            'studentSession.shift',
            'studentSession.classGroup',
            'resultSubjects.subject',
        ]);

        if ($user->role !== 'Manager') {
            $resultsQuery->where('branch_id', $user->branch_id);
        }

        if ($studentSearch) {
            $resultsQuery->whereHas('student', function ($query) use (
                $studentSearch,
                $user
            ) {
                $query->where(function ($q) use ($studentSearch) {
                    $q->where('full_name', 'like', "%{$studentSearch}%")
                        ->orWhere('student_id', 'like', "%{$studentSearch}%");
                });

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            });
        }

        $results = $resultsQuery
            ->latest()
            ->paginate(
                $perPage,
                ['*'],
                'results_page',
                max(1, (int) $request->query('results_page', 1))
            );

        /*
        |--------------------------------------------------------------------------
        | Students With Historical Academic Sessions
        |--------------------------------------------------------------------------
        */

        $studentsQuery = Student::with([
            'section',
            'classInfo.subjects',
            'classGroup.subjects',
            'studentSessions.academicSession',
            'studentSessions.classInfo.subjects',
            'studentSessions.classGroup.subjects',
            'studentSessions.section',
            'studentSessions.shift',
        ]);

        if ($user->role !== 'Manager') {
            $studentsQuery->where('branch_id', $user->branch_id);
        }

        if ($studentSearch) {
            $studentsQuery->where(function ($query) use ($studentSearch) {
                $query->where('full_name', 'like', "%{$studentSearch}%")
                    ->orWhere('student_id', 'like', "%{$studentSearch}%");
            });
        }

        $students = $studentsQuery->get();

        /*
        |--------------------------------------------------------------------------
        | Add Assigned Subjects To Each Student Session
        |--------------------------------------------------------------------------
        */

        foreach ($students as $student) {
            foreach ($student->studentSessions as $studentSession) {
                $subjects = collect();

                if ($studentSession->classInfo) {
                    $subjects = $subjects->merge(
                        $studentSession->classInfo->subjects
                    );
                }

                if ($studentSession->classGroup) {
                    $subjects = $subjects->merge(
                        $studentSession->classGroup->subjects
                    );

                    $mappedSubjectIds = GroupSubjectMapping::where(
                        'class_group_id',
                        $studentSession->class_group_id
                    )->pluck('subject_id');

                    if ($mappedSubjectIds->isNotEmpty()) {
                        $mappedSubjects = Subject::whereIn(
                            'id',
                            $mappedSubjectIds
                        )
                            ->where('branch_id', $studentSession->branch_id)
                            ->get();

                        $subjects = $subjects->merge($mappedSubjects);
                    }
                }

                $studentSession->setAttribute(
                    'assigned_subjects',
                    $subjects->unique('id')->values()
                );
            }

            /*
             * Keep the existing current-student assigned_subjects field
             * for compatibility with the existing Vue page.
             */

            $subjects = collect();

            if ($student->classInfo) {
                $subjects = $subjects->merge(
                    $student->classInfo->subjects
                );
            }

            if ($student->classGroup) {
                $subjects = $subjects->merge(
                    $student->classGroup->subjects
                );

                $mappedSubjectIds = GroupSubjectMapping::where(
                    'class_group_id',
                    $student->classGroup->id
                )->pluck('subject_id');

                if ($mappedSubjectIds->isNotEmpty()) {
                    $mappedSubjects = Subject::whereIn(
                        'id',
                        $mappedSubjectIds
                    )
                        ->where('branch_id', $student->branch_id)
                        ->get();

                    $subjects = $subjects->merge($mappedSubjects);
                }
            }

            $student->setAttribute(
                'assigned_subjects',
                $subjects->unique('id')->values()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate GPA
        |--------------------------------------------------------------------------
        */

        foreach ($results->getCollection() as $result) {
            $student = $result->student;

            if (
                !$student ||
                (int) $result->branch_id !== (int) $student->branch_id
            ) {
                $result->gpa = 0;
                continue;
            }

            $examination = Examination::where(
                'examination_type',
                $result->exam_type
            )
                ->where('examination_year', $result->exam_year)
                ->where('branch_id', $result->branch_id)
                ->first();

            $totalPoint = 0;
            $subjectCount = 0;

            foreach ($result->resultSubjects as $resultSubject) {
                $subject = $resultSubject->subject;

                if (
                    !$subject ||
                    (int) $subject->branch_id !== (int) $result->branch_id
                ) {
                    continue;
                }

                $fullMark = $examination?->exam_mark
                    ?? $subject->full_mark
                    ?? 100;

                if ((float) $fullMark <= 0) {
                    continue;
                }

                $percentage = (
                    (float) $resultSubject->marks / (float) $fullMark
                ) * 100;

                $grading = GradingSystem::where(
                    'min_percentage',
                    '<=',
                    $percentage
                )
                    ->orderBy('min_percentage', 'desc')
                    ->first();

                $totalPoint += $grading
                    ? (float) $grading->grade_point
                    : 0;

                $subjectCount++;
            }

            $result->gpa = $subjectCount > 0
                ? round($totalPoint / $subjectCount, 2)
                : 0;
        }

        return response()->json([
            'status' => true,
            'results' => $results,
            'students' => $students,
            'results_pagination' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem(),
            ],
            'total_students' => $students->count(),
        ]);
    }

    /**
     * Store a result for the explicitly selected student academic session.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'student_session_id' => [
                'required',
                'integer',
                'exists:student_sessions,id',
            ],
            'exam_year' => [
                'required',
                'string',
                'max:255',
            ],
            'exam_type' => [
                'required',
                'string',
                'max:255',
            ],
            'subjects' => [
                'required',
                'array',
                'min:1',
            ],
            'subjects.*.subject_id' => [
                'required',
                'integer',
                'distinct',
                'exists:subjects,id',
            ],
            'subjects.*.marks' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999.99',
            ],
            'subjects.*.is_additional' => [
                'nullable',
                'boolean',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Student
        |--------------------------------------------------------------------------
        */

        $student = Student::find($validated['student_id']);

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation
        |--------------------------------------------------------------------------
        */

        if (
            $user->role !== 'Manager' &&
            (int) $student->branch_id !== (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this student.',
            ], 403);
        }

        $resultBranchId = (int) $student->branch_id;

        /*
        |--------------------------------------------------------------------------
        | Selected Student Session
        |--------------------------------------------------------------------------
        */

        $studentSession = StudentSession::with([
            'academicSession',
            'classInfo.subjects',
            'classGroup.subjects',
            'section',
            'shift',
        ])
            ->where('id', $validated['student_session_id'])
            ->where('student_id', $student->id)
            ->where('branch_id', $resultBranchId)
            ->first();

        if (!$studentSession) {
            return response()->json([
                'status' => false,
                'message' => 'The selected session does not belong to this student and branch.',
            ], 422);
        }

        if (!$studentSession->academicSession) {
            return response()->json([
                'status' => false,
                'message' => 'The selected academic session could not be found.',
            ], 422);
        }

        if (
            (int) $studentSession->academicSession->branch_id
            !== $resultBranchId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'The academic session does not belong to this branch.',
            ], 422);
        }

        if (
            (string) $studentSession->academicSession->name
            !== (string) $validated['exam_year']
        ) {
            return response()->json([
                'status' => false,
                'message' => 'The selected academic session does not match the examination year.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Result
        |--------------------------------------------------------------------------
        */

        $duplicate = Result::where('student_id', $student->id)
            ->where('exam_year', $validated['exam_year'])
            ->where('exam_type', $validated['exam_type'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'A result already exists for this student, exam year and exam type.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Examination
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $validated['exam_type']
        )
            ->where('examination_year', $validated['exam_year'])
            ->where('branch_id', $resultBranchId)
            ->first();

        if (!$examination) {
            return response()->json([
                'status' => false,
                'message' => 'Examination not found for this branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Assigned Subjects From The Selected Session
        |--------------------------------------------------------------------------
        */

        $assignedSubjectIds = collect();

        if ($studentSession->classInfo) {
            $assignedSubjectIds = $assignedSubjectIds->merge(
                $studentSession->classInfo->subjects->pluck('id')
            );
        }

        if ($studentSession->classGroup) {
            $assignedSubjectIds = $assignedSubjectIds->merge(
                $studentSession->classGroup->subjects->pluck('id')
            );

            $mappedSubjectIds = GroupSubjectMapping::where(
                'class_group_id',
                $studentSession->class_group_id
            )->pluck('subject_id');

            $assignedSubjectIds = $assignedSubjectIds->merge(
                $mappedSubjectIds
            );
        }

        $assignedSubjectIds = $assignedSubjectIds
            ->unique()
            ->values();

        $submittedSubjectIds = collect($validated['subjects'])
            ->pluck('subject_id')
            ->unique()
            ->values();

        $subjects = Subject::whereIn('id', $submittedSubjectIds)
            ->where('branch_id', $resultBranchId)
            ->get()
            ->keyBy('id');

        if ($subjects->count() !== $submittedSubjectIds->count()) {
            return response()->json([
                'status' => false,
                'message' => 'One or more selected subjects do not belong to the student branch.',
            ], 422);
        }

        foreach ($submittedSubjectIds as $subjectId) {
            if (!$assignedSubjectIds->contains($subjectId)) {
                return response()->json([
                    'status' => false,
                    'message' => "Subject ID {$subjectId} is not assigned to the selected session's class or group.",
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Marks
        |--------------------------------------------------------------------------
        */

        foreach ($validated['subjects'] as $subjectData) {
            $subject = $subjects->get($subjectData['subject_id']);

            $fullMark = $examination->exam_mark !== null
                ? (float) $examination->exam_mark
                : (float) ($subject->full_mark ?? 100);

            $marks = $subjectData['marks'] ?? null;

            if ($marks !== null && (float) $marks > $fullMark) {
                return response()->json([
                    'status' => false,
                    'message' => "Marks for {$subject->subject_name} cannot exceed {$fullMark}.",
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create Result With Historical Snapshots
        |--------------------------------------------------------------------------
        */

        $result = Result::create([
            'student_id' => $student->id,
            'student_session_id' => $studentSession->id,
            'exam_year' => $validated['exam_year'],
            'exam_type' => $validated['exam_type'],
            'branch_id' => $resultBranchId,

            'class_name_snapshot' =>
                $studentSession->classInfo?->class_name
                ?? $studentSession->classInfo?->name,

            'section_name_snapshot' =>
                $studentSession->section?->section_name
                ?? $studentSession->section?->name,

            'shift_name_snapshot' =>
                $studentSession->shift?->shift_name
                ?? $studentSession->shift?->name,

            'class_group_name_snapshot' =>
                $studentSession->classGroup?->class_group_name
                ?? $studentSession->classGroup?->group_name
                ?? $studentSession->classGroup?->name,
        ]);

        foreach ($validated['subjects'] as $subjectData) {
            $result->resultSubjects()->create([
                'subject_id' => $subjectData['subject_id'],
                'marks' => $subjectData['marks'] ?? null,
            ]);
        }

        $result->load([
            'student',
            'studentSession.academicSession',
            'studentSession.classInfo',
            'studentSession.section',
            'studentSession.shift',
            'studentSession.classGroup',
            'resultSubjects.subject',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Result created successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * Display a specific result.
     */
    public function show($id): JsonResponse
    {
        $request = request();

        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $result = Result::with([
            'student.section',
            'student.classInfo',
            'student.classGroup.subjects',
            'studentSession.academicSession',
            'studentSession.classInfo',
            'studentSession.section',
            'studentSession.shift',
            'studentSession.classGroup.subjects',
            'resultSubjects.subject',
        ])->find($id);

        if (!$result) {
            return response()->json([
                'status' => false,
                'message' => 'Result not found.',
            ], 404);
        }

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !== (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this result.',
            ], 403);
        }

        if (
            !$result->student ||
            (int) $result->student->branch_id !== (int) $result->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Result branch does not match student branch.',
            ], 403);
        }

        if (
            $result->studentSession &&
            (
                (int) $result->studentSession->student_id
                    !== (int) $result->student_id ||
                (int) $result->studentSession->branch_id
                    !== (int) $result->branch_id
            )
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Result session does not match the student or branch.',
            ], 403);
        }

        $examination = Examination::where(
            'examination_type',
            $result->exam_type
        )
            ->where('examination_year', $result->exam_year)
            ->where('branch_id', $result->branch_id)
            ->first();

        $subjects = [];
        $totalPoint = 0;
        $subjectCount = 0;

        foreach ($result->resultSubjects as $resultSubject) {
            $subject = $resultSubject->subject;

            if (
                !$subject ||
                (int) $subject->branch_id !== (int) $result->branch_id
            ) {
                continue;
            }

            $fullMark = $examination?->exam_mark
                ?? $subject->full_mark
                ?? 100;

            if ((float) $fullMark <= 0) {
                continue;
            }

            $marks = $resultSubject->marks ?? 0;

            $percentage = (
                (float) $marks / (float) $fullMark
            ) * 100;

            $grading = GradingSystem::where(
                'min_percentage',
                '<=',
                $percentage
            )
                ->orderBy('min_percentage', 'desc')
                ->first();

            $grade = $grading?->grade ?? 'F';
            $point = $grading ? (float) $grading->grade_point : 0;

            $totalPoint += $point;
            $subjectCount++;

            $isAdditional = false;

            if ($result->studentSession?->classGroup) {
                $isAdditional = $result->studentSession
                    ->classGroup
                    ->subjects
                    ->contains('id', $subject->id);
            }

            $subjects[] = [
                'id' => $subject->id,
                'name' => $subject->subject_name
                    ?? $subject->name
                    ?? 'Unknown Subject',
                'marks' => (float) $marks,
                'full_mark' => (float) $fullMark,
                'percentage' => round($percentage, 2),
                'grade' => $grade,
                'grade_point' => $point,
                'is_additional' => $isAdditional,
            ];
        }

        $gpa = $subjectCount > 0
            ? round($totalPoint / $subjectCount, 2)
            : 0;

        $gpa = min(5, $gpa);

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $result->id,
                'student' => $result->student,
                'student_session' => $result->studentSession,

                'class_name_snapshot' =>
                    $result->class_name_snapshot
                    ?? $result->studentSession?->classInfo?->class_name
                    ?? $result->studentSession?->classInfo?->name,

                'section_name_snapshot' =>
                    $result->section_name_snapshot
                    ?? $result->studentSession?->section?->section_name
                    ?? $result->studentSession?->section?->name,

                'shift_name_snapshot' =>
                    $result->shift_name_snapshot
                    ?? $result->studentSession?->shift?->shift_name
                    ?? $result->studentSession?->shift?->name,

                'class_group_name_snapshot' =>
                    $result->class_group_name_snapshot
                    ?? $result->studentSession?->classGroup?->class_group_name
                    ?? $result->studentSession?->classGroup?->group_name
                    ?? $result->studentSession?->classGroup?->name,

                'exam_year' => $result->exam_year,
                'exam_type' => $result->exam_type,
                'branch_id' => $result->branch_id,
                'examination' => $examination,
                'subjects' => $subjects,
                'gpa' => $gpa,
            ],
        ]);
    }

    /**
     * Edit result.
     */
    public function edit(Result $result)
    {
        //
    }

    /**
     * Update result.
     */
    public function update(Request $request, Result $result)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !== (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this result.',
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' => 'Result update is not implemented.',
        ], 501);
    }

    /**
     * Delete result.
     */
    public function destroy(Result $result)
    {
        $request = request();

        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !== (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this result.',
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' => 'Result deletion is not implemented.',
        ], 501);
    }
}
