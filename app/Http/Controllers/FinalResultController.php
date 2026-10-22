<?php

namespace App\Http\Controllers;
use App\Models\FinalResult;
use App\Models\Student;
use App\Models\Result;
use App\Models\Examination;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\StudentSession;

class FinalResultController extends Controller
{
    /**
     * Check whether user can access Final Result module.
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

        if (in_array($user->role, [
            'Branch Accountant',
            'Accountant',
        ])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access final results.',
            ], 403);
        }

        return null;
    }

    /**
     * Display all final result configurations.
     */
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();
        $year = $request->query('year');

        $finalResultsQuery = FinalResult::with('examination');

        if ($user->role !== 'Manager') {
            $finalResultsQuery->whereHas(
                'examination',
                function ($query) use ($user) {
                    $query->where('branch_id', $user->branch_id);
                }
            );
        }

        $finalResults = $finalResultsQuery->latest()->get();

        $yearTotals = $finalResults
            ->groupBy(function ($item) {
                return $item->examination?->examination_year;
            })
            ->map(function ($items) {
                return round(
                    $items->sum(function ($item) {
                        return (float) $item->percentage;
                    }),
                    2
                );
            });

        $totalPercentage = 0;

        if ($year !== null && $year !== '') {
            $totalPercentage = (float) (
                $yearTotals[(string) $year] ?? 0
            );
        }

        return response()->json([
            'status' => true,
            'data' => $finalResults,
            'total_percentage' => round($totalPercentage, 2),
            'remaining_percentage' => round(
                max(0, 100 - $totalPercentage),
                2
            ),
            'is_complete' => round($totalPercentage, 2) === 100.00,
            'year_totals' => $yearTotals,
        ]);
    }

    /**
     * Store a new final result examination configuration.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $validated = $request->validate([
            'examination_id' => [
                'required',
                'integer',
                'exists:examinations,id',
                'unique:final_results,examination_id',
            ],
            'percentage' => [
                'required',
                'numeric',
                'min:0.01',
                'max:100',
            ],
        ], [
            'examination_id.required' => 'Please select an examination.',
            'examination_id.exists' => 'The selected examination does not exist.',
            'examination_id.unique' =>
                'This examination has already been added to the final result configuration.',
            'percentage.required' => 'Percentage is required.',
            'percentage.numeric' => 'Percentage must be a number.',
            'percentage.min' => 'Percentage must be greater than 0.',
            'percentage.max' => 'Percentage cannot be greater than 100.',
        ]);

        $examination = Examination::find(
            $validated['examination_id']
        );

        if (!$examination) {
            return response()->json([
                'status' => false,
                'message' => 'The selected examination does not exist.',
            ], 404);
        }

        if (
            $user->role !== 'Manager' &&
            $examination->branch_id != $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this examination.',
            ], 403);
        }

        $year = $examination->examination_year;

        $currentPercentage = (float) FinalResult::whereHas(
            'examination',
            function ($query) use ($year, $user) {
                $query->where('examination_year', $year);

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            }
        )->sum('percentage');

        $newTotal = $currentPercentage
            + (float) $validated['percentage'];

        if ($newTotal > 100) {
            return response()->json([
                'status' => false,
                'message' =>
                    "Total percentage for {$year} cannot exceed 100%.",
                'year' => $year,
                'current_percentage' => round($currentPercentage, 2),
                'requested_percentage' => (float) $validated['percentage'],
                'remaining_percentage' => round(
                    max(0, 100 - $currentPercentage),
                    2
                ),
            ], 422);
        }

        $finalResult = FinalResult::create([
            'examination_id' => $validated['examination_id'],
            'percentage' => $validated['percentage'],
        ]);

        $finalResult->load('examination');

        $totalPercentage = (float) FinalResult::whereHas(
            'examination',
            function ($query) use ($year, $user) {
                $query->where('examination_year', $year);

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            }
        )->sum('percentage');

        $totalPercentage = round($totalPercentage, 2);

        return response()->json([
            'status' => true,
            'message' =>
                'Final result examination configuration added successfully.',
            'data' => $finalResult,
            'year' => $year,
            'total_percentage' => $totalPercentage,
            'remaining_percentage' => round(
                max(0, 100 - $totalPercentage),
                2
            ),
            'is_complete' => $totalPercentage === 100.00,
        ], 201);
    }

    /**
     * Display a specific final result configuration.
     */
    public function show(
        Request $request,
        FinalResult $finalResult
    ): JsonResponse {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $finalResult->load('examination');

        if (
            $user->role !== 'Manager' &&
            $finalResult->examination?->branch_id != $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this final result configuration.',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'data' => $finalResult,
        ]);
    }

    /**
     * Update final result examination percentage.
     */
    public function update(
        Request $request,
        FinalResult $finalResult
    ): JsonResponse {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $finalResult->load('examination');

        if (
            $user->role !== 'Manager' &&
            $finalResult->examination?->branch_id != $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this final result configuration.',
            ], 403);
        }

        $validated = $request->validate([
            'percentage' => [
                'required',
                'numeric',
                'min:0.01',
                'max:100',
            ],
        ], [
            'percentage.required' => 'Percentage is required.',
            'percentage.numeric' => 'Percentage must be a number.',
            'percentage.min' => 'Percentage must be greater than 0.',
            'percentage.max' => 'Percentage cannot be greater than 100.',
        ]);

        $year = $finalResult->examination?->examination_year;

        $currentTotal = (float) FinalResult::whereHas(
            'examination',
            function ($query) use ($year, $user) {
                $query->where('examination_year', $year);

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            }
        )
            ->where('id', '!=', $finalResult->id)
            ->sum('percentage');

        $newTotal = $currentTotal
            + (float) $validated['percentage'];

        if ($newTotal > 100) {
            return response()->json([
                'status' => false,
                'message' =>
                    "Total percentage for {$year} cannot exceed 100%.",
                'year' => $year,
                'current_percentage' => round($currentTotal, 2),
                'requested_percentage' => (float) $validated['percentage'],
                'remaining_percentage' => round(
                    max(0, 100 - $currentTotal),
                    2
                ),
            ], 422);
        }

        $finalResult->update([
            'percentage' => $validated['percentage'],
        ]);

        $finalResult->load('examination');

        $totalPercentage = (float) FinalResult::whereHas(
            'examination',
            function ($query) use ($year, $user) {
                $query->where('examination_year', $year);

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            }
        )->sum('percentage');

        $totalPercentage = round($totalPercentage, 2);

        return response()->json([
            'status' => true,
            'message' => 'Final result percentage updated successfully.',
            'data' => $finalResult,
            'year' => $year,
            'total_percentage' => $totalPercentage,
            'remaining_percentage' => round(
                max(0, 100 - $totalPercentage),
                2
            ),
            'is_complete' => $totalPercentage === 100.00,
        ]);
    }

    /**
     * Remove an examination from final result configuration.
     */
    public function destroy(
        Request $request,
        FinalResult $finalResult
    ): JsonResponse {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $finalResult->load('examination');

        if (
            $user->role !== 'Manager' &&
            $finalResult->examination?->branch_id != $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this final result configuration.',
            ], 403);
        }

        $year = $finalResult->examination?->examination_year;

        $finalResult->delete();

        $totalPercentage = (float) FinalResult::whereHas(
            'examination',
            function ($query) use ($year, $user) {
                $query->where('examination_year', $year);

                if ($user->role !== 'Manager') {
                    $query->where('branch_id', $user->branch_id);
                }
            }
        )->sum('percentage');

        $totalPercentage = round($totalPercentage, 2);

        return response()->json([
            'status' => true,
            'message' => 'Final result examination removed successfully.',
            'year' => $year,
            'total_percentage' => $totalPercentage,
            'remaining_percentage' => round(
                max(0, 100 - $totalPercentage),
                2
            ),
            'is_complete' => $totalPercentage === 100.00,
        ]);
    }

    /**
     * Generate Final Result For A Single Student.
     *
     * GET /api/final-results/student/{studentId}
     *     ?year=2026&student_session_id=123
     */
    public function studentFinalResult(
        Request $request,
        $studentId
    ): JsonResponse {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'year' => [
                'required',
                'integer',
            ],
            'student_session_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $year = (string) $validated['year'];

        $requestedSessionId = $request->query(
            'student_session_id'
        );

        /*
        |--------------------------------------------------------------------------
        | Find Student
        |--------------------------------------------------------------------------
        */

        $studentQuery = Student::query()
            ->where('id', $studentId);

        if ($user->role !== 'Manager') {
            $studentQuery->where(
                'branch_id',
                $user->branch_id
            );
        }

        $student = $studentQuery->first();

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Final Result Configuration
        |--------------------------------------------------------------------------
        */

        $finalResults = FinalResult::with('examination')
            ->whereHas(
                'examination',
                function ($query) use ($year, $student) {
                    $query->where(
                        'examination_year',
                        $year
                    );

                    $query->where(
                        'branch_id',
                        $student->branch_id
                    );
                }
            )
            ->get();

        if ($finalResults->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' =>
                    "No final result configuration found for {$year}.",
            ], 404);
        }

        $totalPercentage = round(
            (float) $finalResults->sum('percentage'),
            2
        );

        if ($totalPercentage !== 100.00) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Final result configuration must total exactly 100%.',
                'total_percentage' => $totalPercentage,
                'remaining_percentage' => round(
                    100 - $totalPercentage,
                    2
                ),
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Student Results With Session Relations
        |--------------------------------------------------------------------------
        */

        $resultsQuery = Result::with([
            'resultSubjects.subject',
            'studentSession.academicSession',
            'studentSession.classInfo',
            'studentSession.classGroup',
            'studentSession.section',
            'studentSession.shift',
        ])
            ->where('student_id', $studentId)
            ->where('exam_year', $year)
            ->where('branch_id', $student->branch_id);

        /*
        |--------------------------------------------------------------------------
        | Optional Explicit Session Filter
        |--------------------------------------------------------------------------
        */

        if (
            $requestedSessionId !== null &&
            $requestedSessionId !== ''
        ) {
            $resultsQuery->where(
                'student_session_id',
                $requestedSessionId
            );
        }

        $results = $resultsQuery->get();

        if ($results->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' =>
                    "No examination results found for this student in {$year} and the selected academic session.",
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Ensure All Results Belong To One Session
        |--------------------------------------------------------------------------
        */

        $sessionIds = $results
            ->pluck('student_session_id')
            ->filter()
            ->unique()
            ->values();

        if ($sessionIds->count() > 1) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Results belong to multiple academic sessions. Please select a specific session.',
                'session_ids' => $sessionIds,
            ], 422);
        }

        if ($sessionIds->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' =>
                    'No academic session is linked to these results.',
            ], 422);
        }

        $studentSessionId = $sessionIds->first();

        /*
        |--------------------------------------------------------------------------
        | Validate Student Session Ownership And Branch
        |--------------------------------------------------------------------------
        */

        $studentSession = $results
            ->first()
            ->studentSession;

        if (!$studentSession) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Student session information could not be found.',
            ], 422);
        }

        if (
            (int) $studentSession->student_id !== (int) $student->id ||
            (int) $studentSession->branch_id !== (int) $student->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'The selected academic session does not belong to this student or branch.',
            ], 403);
        }

        if (
            $requestedSessionId !== null &&
            $requestedSessionId !== '' &&
            (int) $studentSessionId !== (int) $requestedSessionId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'The selected academic session does not match the result.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Build Exam Data
        |--------------------------------------------------------------------------
        */

        $examResults = [];

        foreach ($finalResults as $configuration) {
            $exam = $configuration->examination;

            if (!$exam) {
                continue;
            }

            $result = $results->first(
                function ($item) use ($exam) {
                    return (string) $item->exam_type ===
                        (string) $exam->examination_type;
                }
            );

            $examResults[] = [
                'id' => $exam->id,
                'name' => $exam->examination_type,
                'year' => $exam->examination_year,
                'exam_mark' => $exam->exam_mark !== null
                    ? (float) $exam->exam_mark
                    : null,
                'percentage' => (float) $configuration->percentage,
                'result_id' => $result?->id,
                'subjects' => $result
                    ? $result->resultSubjects
                    : [],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Build Subject Collection
        |--------------------------------------------------------------------------
        */

        $subjects = [];

        foreach ($examResults as $examData) {
            foreach ($examData['subjects'] as $resultSubject) {
                $subject = $resultSubject->subject;

                if (!$subject) {
                    continue;
                }

                $subjectId = $subject->id;

                if (!isset($subjects[$subjectId])) {
                    $subjects[$subjectId] = [
                        'id' => $subjectId,
                        'name' => $subject->subject_name
                            ?? $subject->name
                            ?? 'Unknown Subject',
                        'full_mark' => $subject->full_mark !== null
                            ? (float) $subject->full_mark
                            : null,
                        'exams' => [],
                    ];
                }

                $marks = $resultSubject->marks ?? 0;

                $fullMark = $examData['exam_mark']
                    ?? $subject->full_mark
                    ?? 100;

                $subjects[$subjectId]['exams'][
                    $examData['id']
                ] = [
                    'marks' => is_numeric($marks)
                        ? (float) $marks
                        : 0,
                    'full_mark' => (float) $fullMark,
                    'percentage' => (float) $examData['percentage'],
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Weighted Subject Results
        |--------------------------------------------------------------------------
        */

        $processedSubjects = [];

        foreach ($subjects as $subject) {
            $weightedPercentage = 0;

            foreach ($examResults as $examData) {
                $examId = $examData['id'];

                $examSubject = $subject['exams'][$examId]
                    ?? null;

                if (!$examSubject) {
                    continue;
                }

                $marks = (float) $examSubject['marks'];
                $fullMark = (float) $examSubject['full_mark'];

                if ($fullMark <= 0) {
                    continue;
                }

                $examPercentage = ($marks / $fullMark) * 100;

                $weightedPercentage += $examPercentage *
                    ((float) $examData['percentage'] / 100);
            }

            $weightedPercentage = round(
                $weightedPercentage,
                2
            );

            if ($weightedPercentage >= 80) {
                $grade = 'A+';
                $point = 5.00;
            } elseif ($weightedPercentage >= 70) {
                $grade = 'A';
                $point = 4.00;
            } elseif ($weightedPercentage >= 60) {
                $grade = 'A-';
                $point = 3.50;
            } elseif ($weightedPercentage >= 50) {
                $grade = 'B';
                $point = 3.00;
            } elseif ($weightedPercentage >= 40) {
                $grade = 'C';
                $point = 2.00;
            } elseif ($weightedPercentage >= 33) {
                $grade = 'D';
                $point = 1.00;
            } else {
                $grade = 'F';
                $point = 0.00;
            }

            $examMarks = [];

            foreach ($examResults as $examData) {
                $examId = $examData['id'];

                $examSubject = $subject['exams'][$examId]
                    ?? null;

                $examMarks[$examId] = [
                    'marks' => $examSubject
                        ? $examSubject['marks']
                        : null,
                    'full_mark' => $examSubject
                        ? $examSubject['full_mark']
                        : null,
                    'percentage' => $examData['percentage'],
                ];
            }

            $processedSubjects[] = [
                'id' => $subject['id'],
                'name' => $subject['name'],
                'full_mark' => $subject['full_mark'],
                'exams' => $examMarks,
                'obtained_total' => $weightedPercentage,
                'letter_grade' => $grade,
                'grade_point' => $point,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Overall GPA
        |--------------------------------------------------------------------------
        */

        $overallGpa = 0;

        if (count($processedSubjects) > 0) {
            $totalPoint = 0;
            $subjectCount = 0;

            foreach ($processedSubjects as $subject) {
                $totalPoint += (float) $subject['grade_point'];
                $subjectCount++;
            }

            if ($subjectCount > 0) {
                $overallGpa = $totalPoint / $subjectCount;
            }
        }

        $overallGpa = min(5, $overallGpa);

        /*
        |--------------------------------------------------------------------------
        | Student Information
        |--------------------------------------------------------------------------
        */

        $academicSession = $studentSession->academicSession;

        // Prefer saved snapshots over the mutable StudentSession fields.
        $snapshotResult = $results->first(
            fn ($item) => !empty($item->class_name_snapshot)
        ) ?? $results->first();

        $studentData = [
            'id' => $student->id,
            'name' => $student->full_name,
            'student_id' => $student->student_id,
            'image' => $student->image,
            'campus' => $student->campus ?? null,

            'shift' =>
                $snapshotResult?->shift_name_snapshot
                ?? $studentSession->shift?->shift_name
                ?? $studentSession->shift?->name
                ?? null,

            'version' => $student->version ?? null,

            'session' => $academicSession?->name
                ?? $academicSession?->year
                ?? $studentSession->academic_session_id,

            'student_session_id' => $studentSession->id,

            'class' =>
                $snapshotResult?->class_name_snapshot
                ?? $studentSession->classInfo?->class_name
                ?? null,

            'group' =>
                $snapshotResult?->class_group_name_snapshot
                ?? $studentSession->classGroup?->class_group_name
                ?? $studentSession->classGroup?->group_name
                ?? null,

            'section' =>
                $snapshotResult?->section_name_snapshot
                ?? $studentSession->section?->section_name
                ?? null,

            'roll' => $student->roll ?? null,
        ];

        /*
        |--------------------------------------------------------------------------
        | Final Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'status' => true,
            'year' => $year,
            'student' => $studentData,
            'exams' => $examResults,
            'subjects' => $processedSubjects,
            'total_percentage' => $totalPercentage,
            'overall_gpa' => round($overallGpa, 2),
        ]);
    }

/**
 * Average Result Merit List based on Total Weighted Score.
 */
public function averageMeritList(Request $request): JsonResponse
{
    if ($response = $this->authorizeAccess($request)) {
        return $response;
    }

    $user = $request->user();

    $validated = $request->validate([
        'academic_session_id' => [
            'required',
            'integer',
            'exists:academic_sessions,id',
        ],
        'class_id' => [
            'required',
            'integer',
            'exists:clss_m_s,id',
        ],
        'section_id' => [
            'required',
            'integer',
            'exists:sections,id',
        ],
        'shift_id' => [
            'required',
            'integer',
            'exists:shifts,id',
        ],
        'class_group_id' => [
            'nullable',
            'integer',
            'exists:class_groups,id',
        ],
    ]);

    /*
    |--------------------------------------------------------------------------
    | Selected Academic Session
    |--------------------------------------------------------------------------
    */

    $academicSession = \App\Models\AcademicSession::query()
        ->whereKey($validated['academic_session_id'])
        ->when(
            $user->role !== 'Manager',
            fn ($query) => $query->where('branch_id', $user->branch_id)
        )
        ->first();

    if (!$academicSession) {
        return response()->json([
            'status' => false,
            'message' => 'Academic session not found for this branch.',
        ], 422);
    }

    $year = (string) $academicSession->name;

    /*
    |--------------------------------------------------------------------------
    | Final Result Configuration
    |--------------------------------------------------------------------------
    */

    $configurations = \App\Models\FinalResult::with('examination')
        ->whereHas('examination', function ($query) use ($year, $user) {
            $query->where('examination_year', $year);

            if ($user->role !== 'Manager') {
                $query->where('branch_id', $user->branch_id);
            }
        })
        ->get()
        ->filter(fn ($item) => $item->examination !== null)
        ->values();

    if ($configurations->isEmpty()) {
        return response()->json([
            'status' => false,
            'message' => 'No Final Average Result configuration found for this academic session.',
        ], 422);
    }

    $totalPercentage = round(
        (float) $configurations->sum('percentage'),
        2
    );

    if ($totalPercentage !== 100.0) {
        return response()->json([
            'status' => false,
            'message' => "Final Result examination percentages must total 100%. Current total: {$totalPercentage}%.",
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Students from Their Historical Student Session
    |--------------------------------------------------------------------------
    */

    $studentSessions = StudentSession::with([
        'student',
        'academicSession',
        'classInfo',
        'section',
        'shift',
        'classGroup',
    ])
        ->where('academic_session_id', $validated['academic_session_id'])
        ->where('class_id', $validated['class_id'])
        ->where('section_id', $validated['section_id'])
        ->where('shift_id', $validated['shift_id'])
        ->when(
            !empty($validated['class_group_id']),
            fn ($query) => $query->where(
                'class_group_id',
                $validated['class_group_id']
            ),
            fn ($query) => $query->whereNull('class_group_id')
        )
        ->when(
            $user->role !== 'Manager',
            fn ($query) => $query->where('branch_id', $user->branch_id)
        )
        ->get();

    /*
    |--------------------------------------------------------------------------
    | Calculate Each Student's Total Weighted Score and GPA
    |--------------------------------------------------------------------------
    */

    $rows = $studentSessions->map(function ($studentSession) use (
        $configurations,
        $year
    ) {
        $student = $studentSession->student;

        if (
            !$student ||
            (int) $student->id !== (int) $studentSession->student_id ||
            (int) $student->branch_id !== (int) $studentSession->branch_id
        ) {
            return null;
        }

        $results = \App\Models\Result::with('resultSubjects.subject')
            ->where('student_id', $student->id)
            ->where('student_session_id', $studentSession->id)
            ->where('exam_year', $year)
            ->where('branch_id', $studentSession->branch_id)
            ->get()
            ->keyBy('exam_type');

        $subjectScores = [];

        foreach ($configurations as $configuration) {
            $examination = $configuration->examination;

            if (!$examination) {
                continue;
            }

            $result = $results->get($examination->examination_type);

            if (!$result) {
                continue;
            }

            foreach ($result->resultSubjects as $resultSubject) {
                $subject = $resultSubject->subject;

                if (
                    !$subject ||
                    (int) $subject->branch_id !== (int) $studentSession->branch_id
                ) {
                    continue;
                }

                $fullMark = $examination->exam_mark !== null
                    ? (float) $examination->exam_mark
                    : (float) ($subject->full_mark ?? 100);

                if ($fullMark <= 0) {
                    continue;
                }

                $marks = (float) ($resultSubject->marks ?? 0);

                $weightedPercentage = (
                    ($marks / $fullMark) * 100
                ) * ((float) $configuration->percentage / 100);

                if (!isset($subjectScores[$subject->id])) {
                    $subjectScores[$subject->id] = [
                        'subject_id' => $subject->id,
                        'subject_name' => $subject->subject_name
                            ?? $subject->name
                            ?? 'Unknown Subject',
                        'weighted_score' => 0,
                    ];
                }

                $subjectScores[$subject->id]['weighted_score'] +=
                    $weightedPercentage;
            }
        }

        if (empty($subjectScores)) {
            return null;
        }

        $totalWeightedScore = 0;
        $totalGradePoints = 0;
        $subjectCount = 0;
        $hasFailedSubject = false;

        foreach ($subjectScores as $subjectScore) {
            $score = round((float) $subjectScore['weighted_score'], 2);

            $totalWeightedScore += $score;

            $grading = \App\Models\GradingSystem::where(
                'min_percentage',
                '<=',
                $score
            )
                ->orderByDesc('min_percentage')
                ->first();

            $gradePoint = $grading
                ? (float) $grading->grade_point
                : 0;

            $totalGradePoints += $gradePoint;
            $subjectCount++;

            if (!$grading || $gradePoint <= 0) {
                $hasFailedSubject = true;
            }
        }

        $gpa = $subjectCount > 0
            ? min(5, round($totalGradePoints / $subjectCount, 2))
            : 0;

        return [
            'student_session_id' => $studentSession->id,
            'student_id' => $student->student_id,
            'student_name' => $student->full_name,
            'total_marks' => round($subjectCount * 100, 2),
            'obtained_marks' => round($totalWeightedScore, 2),
            'total_weighted_score' => round($totalWeightedScore, 2),
            'gpa' => $hasFailedSubject ? 0 : $gpa,
            'status' => $hasFailedSubject ? 'Fail' : 'Pass',
            'merit_rank' => null,
            '_rank_score' => round($totalWeightedScore, 2),
        ];
    })->filter()->values();

    /*
    |--------------------------------------------------------------------------
    | Merit Rank: Equal Scores Share Rank (1, 2, 2, 4)
    |--------------------------------------------------------------------------
    */

    $previousScore = null;
    $rank = 0;

    $rankedRows = $rows
        ->sortByDesc('_rank_score')
        ->values()
        ->map(function ($row, $index) use (&$previousScore, &$rank) {
            $currentScore = (float) $row['_rank_score'];

            if (
                $previousScore === null ||
                $currentScore !== $previousScore
            ) {
                $rank = $index + 1;
            }

            $row['merit_rank'] = $rank;

            unset($row['_rank_score']);

            $previousScore = $currentScore;

            return $row;
        });

    return response()->json([
        'status' => true,
        'academic_session' => $academicSession,
        'filters' => $validated,
        'total_students' => $rankedRows->count(),
        'results' => $rankedRows,
    ]);
}
}
