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
use Illuminate\Support\Facades\DB;


class ResultController extends Controller
{
    /**
     * Result module access.
     */
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

        $allowedRoles = [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
            'Result Entry',
        ];

        if (!in_array($user->role, $allowedRoles, true)) {
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
                'student_id' => ['required', 'integer', 'exists:students,id'],
                'student_session_id' => ['required', 'integer', 'exists:student_sessions,id'],
                'exam_year' => ['required', 'string', 'max:255'],
                'exam_type' => ['required', 'string', 'max:255'],
                'subjects' => ['required', 'array', 'min:1'],
                'subjects.*.subject_id' => [
                    'required',
                    'integer',
                    'distinct',
                    'exists:subjects,id',
                ],
                'subjects.*.marks' => [
                    'required',
                    'numeric',
                    'min:0',
                    'max:999.99',
                ],
                'subjects.*.is_additional' => ['nullable', 'boolean'],
            ]);

            $student = Student::find($validated['student_id']);

            if (!$student) {
                return response()->json([
                    'status' => false,
                    'message' => 'Student not found.',
                ], 404);
            }

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

            if (
                !$studentSession->academicSession ||
                (int) $studentSession->academicSession->branch_id !== $resultBranchId
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'The selected academic session does not belong to this branch.',
                ], 422);
            }

            if (
                (string) $studentSession->academicSession->name !==
                (string) $validated['exam_year']
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'The selected academic session does not match the examination year.',
                ], 422);
            }

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

            // Get every subject assigned to this historical session.
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
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $submittedSubjectIds = collect($validated['subjects'])
                ->pluck('subject_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            // Reject the request if any assigned subject has been omitted.
            $missingSubjectIds = $assignedSubjectIds->diff($submittedSubjectIds);

            if ($missingSubjectIds->isNotEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Marks are required for every subject assigned to this session.',
                    'missing_subject_ids' => $missingSubjectIds->values(),
                ], 422);
            }

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

            // Reject subjects not assigned to this session.
            foreach ($submittedSubjectIds as $subjectId) {
                if (!$assignedSubjectIds->contains($subjectId)) {
                    return response()->json([
                        'status' => false,
                        'message' => "Subject ID {$subjectId} is not assigned to the selected session's class or group.",
                    ], 422);
                }
            }

            // Validate marks against the examination's full marks.
            foreach ($validated['subjects'] as $subjectData) {
                $subject = $subjects->get((int) $subjectData['subject_id']);

                $fullMark = $examination->exam_mark !== null
                    ? (float) $examination->exam_mark
                    : (float) ($subject->full_mark ?? 100);

                if ((float) $subjectData['marks'] > $fullMark) {
                    return response()->json([
                        'status' => false,
                        'message' => "Marks for {$subject->subject_name} cannot exceed {$fullMark}.",
                    ], 422);
                }
            }

            $result = DB::transaction(function () use (
                $student,
                $studentSession,
                $validated,
                $resultBranchId
            ) {
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
                        'marks' => $subjectData['marks'],
                    ]);
                }

                return $result;
            });

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
/**
 * Update an existing result.
 */

public function update(Request $request, Result $result): JsonResponse
{
    if ($response = $this->authorizeAccess($request)) {
    return $response;
    }

    $user = $request->user();

    if ($user->role === 'Result Entry') {
        return response()->json([
            'status' => false,
            'message' => 'You are not allowed to modify existing results.',
        ], 403);
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

    $validated = $request->validate([
        'student_id' => ['required', 'integer', 'exists:students,id'],
        'student_session_id' => ['required', 'integer', 'exists:student_sessions,id'],
        'exam_year' => ['required', 'string', 'max:255'],
        'exam_type' => ['required', 'string', 'max:255'],
        'subjects' => ['required', 'array', 'min:1'],
        'subjects.*.subject_id' => [
            'required',
            'integer',
            'distinct',
            'exists:subjects,id',
        ],
        'subjects.*.marks' => [
            'required',
            'numeric',
            'min:0',
            'max:999.99',
        ],
        'subjects.*.is_additional' => ['nullable', 'boolean'],
    ]);

    $student = Student::find($validated['student_id']);

    if (
        !$student ||
        (int) $student->branch_id !== (int) $result->branch_id
    ) {
        return response()->json([
            'status' => false,
            'message' => 'The student must belong to the result branch.',
        ], 422);
    }

    $resultBranchId = (int) $result->branch_id;

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

    if (
        !$studentSession->academicSession ||
        (int) $studentSession->academicSession->branch_id !== $resultBranchId
    ) {
        return response()->json([
            'status' => false,
            'message' => 'The selected academic session does not belong to this branch.',
        ], 422);
    }

    if (
        (string) $studentSession->academicSession->name !==
        (string) $validated['exam_year']
    ) {
        return response()->json([
            'status' => false,
            'message' => 'The selected academic session does not match the examination year.',
        ], 422);
    }

    $duplicate = Result::where('student_id', $student->id)
        ->where('exam_year', $validated['exam_year'])
        ->where('exam_type', $validated['exam_type'])
        ->where('id', '!=', $result->id)
        ->exists();

    if ($duplicate) {
        return response()->json([
            'status' => false,
            'message' => 'Another result already exists for this student, exam year and exam type.',
        ], 422);
    }

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

    // Use the subjects assigned to the selected historical session.
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
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values();

    $submittedSubjectIds = collect($validated['subjects'])
        ->pluck('subject_id')
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values();

    $missingSubjectIds = $assignedSubjectIds->diff($submittedSubjectIds);

    if ($missingSubjectIds->isNotEmpty()) {
        return response()->json([
            'status' => false,
            'message' => 'Marks are required for every subject assigned to this session.',
            'missing_subject_ids' => $missingSubjectIds->values(),
        ], 422);
    }

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

    foreach ($validated['subjects'] as $subjectData) {
        $subject = $subjects->get((int) $subjectData['subject_id']);

        $fullMark = $examination->exam_mark !== null
            ? (float) $examination->exam_mark
            : (float) ($subject->full_mark ?? 100);

        if ((float) $subjectData['marks'] > $fullMark) {
            return response()->json([
                'status' => false,
                'message' => "Marks for {$subject->subject_name} cannot exceed {$fullMark}.",
            ], 422);
        }
    }

    DB::transaction(function () use (
        $result,
        $student,
        $studentSession,
        $validated
    ) {
        $result->update([
            'student_id' => $student->id,
            'student_session_id' => $studentSession->id,
            'exam_year' => $validated['exam_year'],
            'exam_type' => $validated['exam_type'],

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

        // Replace this result's marks only; other results remain untouched.
        $result->resultSubjects()->delete();

        foreach ($validated['subjects'] as $subjectData) {
            $result->resultSubjects()->create([
                'subject_id' => $subjectData['subject_id'],
                'marks' => $subjectData['marks'],
            ]);
        }
    });

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
        'message' => 'Result updated successfully.',
        'data' => $result,
    ]);
}
    /**
     * Delete result.
     */
    public function destroy(Result $result)
    {
        $request = request();
        if ($request->user()->role === 'Result Entry') {
        return response()->json([
            'message' => 'You are not allowed to modify existing results.',
        ], 403);
        }


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

    /**
     * Institute Result with class filters, total marks, GPA and merit rank.
     */
    public function instituteResult(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'class_id' => ['required', 'integer', 'exists:clss_m_s,id'],
            'section_id'          => ['required', 'integer', 'exists:sections,id'],
            'shift_id'             => ['required', 'integer', 'exists:shifts,id'],
            'class_group_id'      => ['nullable', 'integer', 'exists:class_groups,id'],
            'exam_year'            => ['required', 'string', 'max:255'],
            'exam_type'            => ['required', 'string', 'max:255'],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Examination
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where('examination_year', $validated['exam_year'])
            ->where('examination_type', $validated['exam_type'])
            ->when(
                $user->role !== 'Manager',
                fn ($query) => $query->where('branch_id', $user->branch_id)
            )
            ->first();

        if (!$examination) {
            return response()->json([
                'status' => false,
                'message' => 'Examination not found for the selected branch and exam.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Results from the exact historical student session
        |--------------------------------------------------------------------------
        */

        $resultsQuery = Result::with([
            'student',
            'studentSession.academicSession',
            'studentSession.classInfo',
            'studentSession.section',
            'studentSession.shift',
            'studentSession.classGroup',
            'resultSubjects.subject',
        ])
            ->where('exam_year', $validated['exam_year'])
            ->where('exam_type', $validated['exam_type'])
            ->whereHas('studentSession', function ($query) use ($validated, $user) {
                $query
                    ->where('academic_session_id', $validated['academic_session_id'])
                    ->where('class_id', $validated['class_id'])
                    ->where('section_id', $validated['section_id'])
                    ->where('shift_id', $validated['shift_id']);

                if (!empty($validated['class_group_id'])) {
                    $query->where('class_group_id', $validated['class_group_id']);
                } else {
                    $query->whereNull('class_group_id');
                }

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            });

        if ($user->role !== 'Manager') {
            $resultsQuery->where('branch_id', $user->branch_id);
        }

        $results = $resultsQuery->get();

        /*
        |--------------------------------------------------------------------------
        | Calculate marks, GPA and pass/fail
        |--------------------------------------------------------------------------
        */

        $rows = $results->map(function ($result) use ($examination) {
            $student = $result->student;
            $session = $result->studentSession;

            if (
                !$student ||
                !$session ||
                (int) $student->id !== (int) $session->student_id ||
                (int) $student->branch_id !== (int) $result->branch_id ||
                (int) $session->branch_id !== (int) $result->branch_id
            ) {
                return null;
            }

            $validSubjects = $result->resultSubjects->filter(function ($item) use ($result) {
                return $item->subject
                    && (int) $item->subject->branch_id === (int) $result->branch_id;
            });

            $totalMarks = 0;
            $obtainedMarks = 0;
            $totalPoint = 0;
            $subjectCount = 0;
            $hasMissingMarks = false;
            $hasFailedSubject = false;

            foreach ($validSubjects as $resultSubject) {
                $subject = $resultSubject->subject;

                $fullMark = $examination->exam_mark !== null
                    ? (float) $examination->exam_mark
                    : (float) ($subject->full_mark ?? 100);

                if ($fullMark <= 0) {
                    continue;
                }

                $totalMarks += $fullMark;

                if ($resultSubject->marks === null) {
                    $hasMissingMarks = true;
                    $marks = 0;
                } else {
                    $marks = (float) $resultSubject->marks;
                }

                $obtainedMarks += $marks;

                $percentage = ($marks / $fullMark) * 100;

                $grading = GradingSystem::where('min_percentage', '<=', $percentage)
                    ->orderByDesc('min_percentage')
                    ->first();

                $point = $grading ? (float) $grading->grade_point : 0;

                $totalPoint += $point;
                $subjectCount++;

                if (!$grading || $point <= 0) {
                    $hasFailedSubject = true;
                }
            }

            $gpa = $subjectCount > 0
                ? min(5, round($totalPoint / $subjectCount, 2))
                : 0;

            $isFail = $hasMissingMarks || $hasFailedSubject || $subjectCount === 0;

            return [
                'result_id' => $result->id,
                'student_id' => $student->student_id,
                'student_name' => $student->full_name,
                'total_marks' => round($totalMarks, 2),
                'obtained_marks' => round($obtainedMarks, 2),
                'gpa' => $isFail ? 0 : $gpa,
                'status' => $isFail ? 'Fail' : 'Pass',
                'merit_rank' => null,
                '_rank_marks' => $obtainedMarks,
            ];
        })->filter()->values();

    /*
    |--------------------------------------------------------------------------
    | Merit rank: equal marks share rank (1, 2, 2, 4)
    |--------------------------------------------------------------------------
    */

    $previousMarks = null;
    $rank = 0;

    $rankedRows = $rows
        ->sortByDesc('_rank_marks')
        ->values()
        ->map(function ($row, $index) use (&$previousMarks, &$rank) {
            $currentMarks = (float) $row['_rank_marks'];

            if (
                $previousMarks === null ||
                $currentMarks != $previousMarks
            ) {
                $rank = $index + 1;
            }

            $row['merit_rank'] = $rank;

            $previousMarks = $currentMarks;

            unset($row['_rank_marks']);

            return $row;
        });
        return response()->json([
        'status' => true,
        'examination' => $examination,
        'filters' => $validated,
        'total_students' => $rankedRows->count(),
        'results' => $rankedRows,
    ]);
    }
}
