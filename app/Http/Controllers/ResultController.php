<?php

namespace App\Http\Controllers;

use App\Models\Result;
use App\Models\Student;
use App\Models\GroupSubjectMapping;
use App\Models\GradingSystem;
use App\Models\Examination;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ResultController extends Controller
{
    /**
     * Result module access.
     *
     * Manager            → All branches
     * Branch Manager     → Own branch
     * Admin              → Own branch
     * Branch Admin       → Own branch
     * Accountant         → No access
     * Branch Accountant  → No access
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
     * Display results.
     */
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $studentSearch = $request->query('student_search');

        /*
        |--------------------------------------------------------------------------
        | Results Query
        |--------------------------------------------------------------------------
        */

        $resultsQuery = Result::with([
            'student.classInfo',
            'student.classGroup',
            'resultSubjects.subject',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation
        |--------------------------------------------------------------------------
        */

        if ($user->role !== 'Manager') {
            $resultsQuery->where(
                'branch_id',
                $user->branch_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Student Search
        |--------------------------------------------------------------------------
        */

        if ($studentSearch) {
            $resultsQuery->whereHas(
                'student',
                function ($query) use ($studentSearch, $user) {

                    $query->where(
                        function ($q) use ($studentSearch) {
                            $q->where(
                                'full_name',
                                'like',
                                "%{$studentSearch}%"
                            )
                                ->orWhere(
                                    'student_id',
                                    'like',
                                    "%{$studentSearch}%"
                                );
                        }
                    );

                    if ($user->role !== 'Manager') {
                        $query->where(
                            'branch_id',
                            $user->branch_id
                        );
                    }
                }
            );
        }

        $results = $resultsQuery
    ->latest()
    ->paginate(
        $perPage,
        ['*'],
        'results_page',
        (int) $request->query('results_page', 1)
    );

        /*
        |--------------------------------------------------------------------------
        | Students
        |--------------------------------------------------------------------------
        */

        $studentsQuery = Student::with([
            'classInfo.subjects',
            'classGroup.subjects',
        ]);

        if ($user->role !== 'Manager') {
            $studentsQuery->where(
                'branch_id',
                $user->branch_id
            );
        }

        if ($studentSearch) {
            $studentsQuery->where(
                function ($query) use ($studentSearch) {
                    $query->where(
                        'full_name',
                        'like',
                        "%{$studentSearch}%"
                    )
                        ->orWhere(
                            'student_id',
                            'like',
                            "%{$studentSearch}%"
                        );
                }
            );
        }

        $students = $studentsQuery->get();

        /*
        |--------------------------------------------------------------------------
        | Group Subject Mapping
        |--------------------------------------------------------------------------
        */

        $allMappedSubjectIds = GroupSubjectMapping::query()
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | Additional Subjects
        |--------------------------------------------------------------------------
        */

        $allAdditionalSubjectIds = [];

        foreach ($students as $student) {

            if (!$student->classGroup) {
                continue;
            }

            foreach ($student->classGroup->subjects as $subject) {
                $allAdditionalSubjectIds[] = $subject->id;
            }
        }

        $allAdditionalSubjectIds = array_values(
            array_unique($allAdditionalSubjectIds)
        );

        /*
        |--------------------------------------------------------------------------
        | Calculate GPA For Current Result Page
        |--------------------------------------------------------------------------
        */

        foreach ($results->getCollection() as $result) {

            $student = $result->student;

            if (!$student) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Result Branch Integrity
            |--------------------------------------------------------------------------
            */

            if (
                (int) $result->branch_id !==
                (int) $student->branch_id
            ) {
                $result->gpa = 0;
                continue;
            }

            $examination = Examination::where(
                'examination_type',
                $result->exam_type
            )
                ->where(
                    'examination_year',
                    $result->exam_year
                )
                ->where(
                    'branch_id',
                    $result->branch_id
                )
                ->first();

            $totalPoint = 0;
            $subjectCount = 0;

            foreach ($result->resultSubjects as $resultSubject) {

                $subject = $resultSubject->subject;

                if (!$subject) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Subject Branch Integrity
                |--------------------------------------------------------------------------
                */

                if (
                    (int) $subject->branch_id !==
                    (int) $result->branch_id
                ) {
                    continue;
                }

                $fullMark =
                    $examination?->exam_mark
                    ?? $subject->full_mark
                    ?? 100;

                if ((float) $fullMark <= 0) {
                    continue;
                }

                $percentage =
                    ((float) $resultSubject->marks / (float) $fullMark)
                    * 100;

                $grading = GradingSystem::where(
                    'min_percentage',
                    '<=',
                    $percentage
                )
                    ->orderBy(
                        'min_percentage',
                        'desc'
                    )
                    ->first();

                $point = $grading
                    ? (float) $grading->grade_point
                    : 0;

                $totalPoint += $point;
                $subjectCount++;
            }

            $result->gpa = $subjectCount > 0
                ? round($totalPoint / $subjectCount, 2)
                : 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Add Assigned Subjects To Students
        |--------------------------------------------------------------------------
        */

        foreach ($students as $student) {

            $subjects = collect();

            /*
            |--------------------------------------------------------------------------
            | Class Subjects
            |--------------------------------------------------------------------------
            */

            if ($student->classInfo) {
                $subjects = $subjects->merge(
                    $student->classInfo->subjects
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Group Subjects
            |--------------------------------------------------------------------------
            */

            if ($student->classGroup) {
                $subjects = $subjects->merge(
                    $student->classGroup->subjects
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Group Subject Mapping
            |--------------------------------------------------------------------------
            */

            if ($student->classGroup) {

                $mappedSubjectIds = GroupSubjectMapping::where(
                    'class_group_id',
                    $student->classGroup->id
                )
                    ->pluck('subject_id');

                if ($mappedSubjectIds->isNotEmpty()) {

                    $mappedSubjects = Subject::whereIn(
                        'id',
                        $mappedSubjectIds
                    )
                        ->where(
                            'branch_id',
                            $student->branch_id
                        )
                        ->get();

                    $subjects = $subjects->merge(
                        $mappedSubjects
                    );
                }
            }

            $student->assigned_subjects = $subjects
                ->unique('id')
                ->values();
        }

        return response()->json([
            'status' => true,
            'results' => $results,
            'students' => $students,
            'pagination' => [
                'current_page' =>
                    $results->currentPage(),

                'last_page' =>
                    $results->lastPage(),

                'per_page' =>
                    $results->perPage(),

                'total' =>
                    $results->total(),
            ],
            'total_students' =>
                $students->count(),
        ]);
    }

    /**
     * Store a new result.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'student_id' => [
                'required',
                'exists:students,id',
                Rule::unique('results')->where(
                    function ($query) use ($request) {
                        return $query
                            ->where(
                                'exam_year',
                                $request->exam_year
                            )
                            ->where(
                                'exam_type',
                                $request->exam_type
                            );
                    }
                ),
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
        | Get Student
        |--------------------------------------------------------------------------
        */

        $student = Student::with([
            'classInfo.subjects',
            'classGroup.subjects',
        ])->find($validated['student_id']);

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Student Branch Isolation
        |--------------------------------------------------------------------------
        */

        if (
            $user->role !== 'Manager' &&
            (int) $student->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this student.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Result Branch Always Comes From Student
        |--------------------------------------------------------------------------
        */

        $resultBranchId = $student->branch_id;

        /*
        |--------------------------------------------------------------------------
        | Get Examination
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $validated['exam_type']
        )
            ->where(
                'examination_year',
                $validated['exam_year']
            )
            ->where(
                'branch_id',
                $resultBranchId
            )
            ->first();

        if (!$examination) {
            return response()->json([
                'status' => false,
                'message' => 'Examination not found for this branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Build Assigned Subject IDs
        |--------------------------------------------------------------------------
        */

        $assignedSubjectIds = collect();

        /*
        |--------------------------------------------------------------------------
        | Class Subjects
        |--------------------------------------------------------------------------
        */

        if ($student->classInfo) {

            $classSubjectIds =
                $student->classInfo->subjects
                    ->pluck('id');

            $assignedSubjectIds =
                $assignedSubjectIds->merge(
                    $classSubjectIds
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Group Subjects
        |--------------------------------------------------------------------------
        */

        if ($student->classGroup) {

            $groupSubjectIds =
                $student->classGroup->subjects
                    ->pluck('id');

            $assignedSubjectIds =
                $assignedSubjectIds->merge(
                    $groupSubjectIds
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Group Subject Mapping
        |--------------------------------------------------------------------------
        */

        if ($student->classGroup) {

            $mappedSubjectIds =
                GroupSubjectMapping::where(
                    'class_group_id',
                    $student->classGroup->id
                )
                    ->pluck('subject_id');

            $assignedSubjectIds =
                $assignedSubjectIds->merge(
                    $mappedSubjectIds
                );
        }

        $assignedSubjectIds =
            $assignedSubjectIds
                ->unique()
                ->values();

        /*
        |--------------------------------------------------------------------------
        | Explicit Subject Branch Validation
        |--------------------------------------------------------------------------
        |
        | Every submitted subject must:
        | 1. Exist
        | 2. Belong to student's branch
        | 3. Be assigned to this student
        |
        |--------------------------------------------------------------------------
        */

        $submittedSubjectIds = collect(
            $validated['subjects']
        )
            ->pluck('subject_id')
            ->unique()
            ->values();

        $invalidBranchSubjectExists = Subject::whereIn(
            'id',
            $submittedSubjectIds
        )
            ->where(
                'branch_id',
                '!=',
                $resultBranchId
            )
            ->exists();

        if ($invalidBranchSubjectExists) {
            return response()->json([
                'status' => false,
                'message' =>
                    'One or more selected subjects do not belong to the student branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Assigned Subject Validation
        |--------------------------------------------------------------------------
        */

        foreach ($submittedSubjectIds as $subjectId) {

            if (!$assignedSubjectIds->contains($subjectId)) {

                return response()->json([
                    'status' => false,
                    'message' =>
                        "Subject ID {$subjectId} is not assigned to this student.",
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Marks Against Examination Mark / Subject Full Mark
        |--------------------------------------------------------------------------
        */

        foreach ($validated['subjects'] as $subjectData) {

            $subject = Subject::where(
                'id',
                $subjectData['subject_id']
            )
                ->where(
                    'branch_id',
                    $resultBranchId
                )
                ->first();

            if (!$subject) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Selected subject does not belong to the student branch.',
                ], 422);
            }

            $fullMark =
                $examination->exam_mark !== null
                    ? (float) $examination->exam_mark
                    : (float) ($subject->full_mark ?? 100);

            $marks =
                $subjectData['marks'] ?? null;

            if (
                $marks !== null &&
                (float) $marks > $fullMark
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        "Marks for {$subject->subject_name} cannot exceed {$fullMark}.",
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create Result
        |--------------------------------------------------------------------------
        */

        $result = Result::create([
            'student_id' =>
                $student->id,

            'exam_year' =>
                $validated['exam_year'],

            'exam_type' =>
                $validated['exam_type'],

            'branch_id' =>
                $resultBranchId,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Create Result Subjects
        |--------------------------------------------------------------------------
        */

        foreach ($validated['subjects'] as $subjectData) {

            $result->resultSubjects()->create([
                'subject_id' =>
                    $subjectData['subject_id'],

                'marks' =>
                    $subjectData['marks'] ?? null,
            ]);
        }

        $result->load([
            'student.classInfo',
            'student.classGroup',
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

        /*
        |--------------------------------------------------------------------------
        | Load Result
        |--------------------------------------------------------------------------
        */

        $result = Result::with([
            'student.classInfo',
            'student.classGroup.subjects',
            'resultSubjects.subject',
        ])->find($id);

        if (!$result) {
            return response()->json([
                'status' => false,
                'message' => 'Result not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Result Branch Isolation / IDOR
        |--------------------------------------------------------------------------
        */

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this result.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Student Branch Integrity
        |--------------------------------------------------------------------------
        */

        if (
            !$result->student ||
            (int) $result->student->branch_id !==
            (int) $result->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Result branch does not match student branch.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Examination
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $result->exam_type
        )
            ->where(
                'examination_year',
                $result->exam_year
            )
            ->where(
                'branch_id',
                $result->branch_id
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Build Subjects
        |--------------------------------------------------------------------------
        */

        $subjects = [];

        $totalPoint = 0;
        $subjectCount = 0;

        foreach ($result->resultSubjects as $resultSubject) {

            $subject = $resultSubject->subject;

            if (!$subject) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Subject Branch Integrity
            |--------------------------------------------------------------------------
            */

            if (
                (int) $subject->branch_id !==
                (int) $result->branch_id
            ) {
                continue;
            }

            $fullMark =
                $examination?->exam_mark
                ?? $subject->full_mark
                ?? 100;

            if ((float) $fullMark <= 0) {
                continue;
            }

            $marks =
                $resultSubject->marks ?? 0;

            $percentage =
                ((float) $marks / (float) $fullMark) * 100;

            $grading = GradingSystem::where(
                'min_percentage',
                '<=',
                $percentage
            )
                ->orderBy(
                    'min_percentage',
                    'desc'
                )
                ->first();

            $grade =
                $grading?->grade
                ?? 'F';

            $point =
                $grading
                    ? (float) $grading->grade_point
                    : 0;

            $totalPoint += $point;
            $subjectCount++;

            $isAdditional = false;

            if ($result->student?->classGroup) {

                $isAdditional =
                    $result->student
                        ->classGroup
                        ->subjects
                        ->contains(
                            'id',
                            $subject->id
                        );
            }

            $subjects[] = [
                'id' =>
                    $subject->id,

                'name' =>
                    $subject->subject_name
                    ?? $subject->name
                    ?? 'Unknown Subject',

                'marks' =>
                    (float) $marks,

                'full_mark' =>
                    (float) $fullMark,

                'percentage' =>
                    round($percentage, 2),

                'grade' =>
                    $grade,

                'grade_point' =>
                    $point,

                'is_additional' =>
                    $isAdditional,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | GPA
        |--------------------------------------------------------------------------
        */

        $gpa = $subjectCount > 0
            ? round(
                $totalPoint / $subjectCount,
                2
            )
            : 0;

        $gpa = min(5, $gpa);

        return response()->json([
            'status' => true,
            'data' => [
                'id' =>
                    $result->id,

                'student' =>
                    $result->student,

                'exam_year' =>
                    $result->exam_year,

                'exam_type' =>
                    $result->exam_type,

                'branch_id' =>
                    $result->branch_id,

                'examination' =>
                    $examination,

                'subjects' =>
                    $subjects,

                'gpa' =>
                    $gpa,
            ],
        ]);
    }

    /**
     * Edit result.
     *
     * Currently not implemented.
     */
    public function edit(Result $result)
    {
        //
    }

    /**
     * Update result.
     *
     * Currently not implemented.
     */
    public function update(
        Request $request,
        Result $result
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | IDOR Protection
        |--------------------------------------------------------------------------
        */

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this result.',
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' =>
                'Result update is not implemented.',
        ], 501);
    }

    /**
     * Delete result.
     *
     * Currently not implemented.
     */
    public function destroy(Result $result)
    {
        $request = request();

        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | IDOR Protection
        |--------------------------------------------------------------------------
        */

        if (
            $user->role !== 'Manager' &&
            (int) $result->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthorized access to this result.',
            ], 403);
        }

        return response()->json([
            'status' => false,
            'message' =>
                'Result deletion is not implemented.',
        ], 501);
    }
}
