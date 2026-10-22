<?php

namespace App\Http\Controllers;
use App\Models\Student;
use App\Models\Payment;
use App\Models\AcademicSession;
use App\Models\StudentSession;
use App\Models\Section;
use App\Models\ClssM;
use App\Models\ClassGroup;
use App\Models\Shift;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class StudentController extends Controller
{
    private function canAccessModule($authUser): bool
    {
        return $authUser && in_array($authUser->role, [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ]);
    }

    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    private function canAccessStudent($authUser, Student $student): bool
    {
        if (!$authUser || !$this->canAccessModule($authUser)) {
            return false;
        }

        $currentBranchId = $this->currentBranchId();

        if (
            $currentBranchId === null &&
            $authUser->role === 'Manager'
        ) {
            return true;
        }

        if ($currentBranchId === null) {
            return false;
        }

        return $student->branch_id !== null
            && (int) $student->branch_id === (int) $currentBranchId;
    }

    private function forbidden($message = 'Access denied.')
    {
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

    /**
     * Validate the selected academic session belongs to the branch.
     */
    private function getAcademicSessionForBranch(
        $sessionId,
        $branchId
    ): ?AcademicSession {
        return AcademicSession::where('id', $sessionId)
            ->where('branch_id', $branchId)
            ->first();
    }

    /**
     * Validate academic records belong to the selected branch.
     */
    private function validateAcademicDetails(
        Request $request,
        $branchId
    ) {
        $checks = [
            [
                'model' => Section::class,
                'id' => $request->section_id,
                'message' => 'Selected section does not belong to this branch.',
            ],
            [
                'model' => ClssM::class,
                'id' => $request->class_id,
                'message' => 'Selected class does not belong to this branch.',
            ],
            [
                'model' => ClassGroup::class,
                'id' => $request->class_group_id,
                'message' => 'Selected class group does not belong to this branch.',
            ],
            [
                'model' => Shift::class,
                'id' => $request->shift_id,
                'message' => 'Selected shift does not belong to this branch.',
            ],
        ];

        foreach ($checks as $check) {
            $record = $check['model']::find($check['id']);

            if (
                !$record ||
                (int) $record->branch_id !== (int) $branchId
            ) {
                return response()->json([
                    'status' => false,
                    'message' => $check['message'],
                ], 422);
            }
        }

        return null;
    }

    /**
     * Determine the branch for a new student.
     */
    private function resolveCreateBranch(Request $request, $authUser)
    {
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {
            return $currentBranchId;
        }

        if ($authUser->role !== 'Manager') {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not assigned to any branch.',
            ], 403);
        }

        if (!$request->branch_id) {
            return response()->json([
                'status' => false,
                'message' => 'Branch is required when All Branches is selected.',
            ], 422);
        }

        return (int) $request->branch_id;
    }

    /**
     * Display students with session-wise academic and payment information.
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access students.'
            );
        }

        $currentBranchId = $this->currentBranchId();

        if (
            $authUser->role !== 'Manager' &&
            $currentBranchId === null
        ) {
            return $this->forbidden(
                'Your account is not assigned to any branch.'
            );
        }

        $perPage = min(
            max((int) $request->get('per_page', 10), 1),
            100
        );

        $search = trim($request->get('search', ''));
        $classId = $request->get('class_id');

        /*
         * Load student information and legacy student-level payments.
         */
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
            },
        ])->orderBy('id', 'desc');

        if ($currentBranchId !== null) {
            $query->where('branch_id', $currentBranchId);
        }

       if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('student_id', 'like', '%' . $search . '%')
                    ->orWhere('student_roll', 'like', '%' . $search . '%');
            });
        }

        /*
         * IMPORTANT:
         * Filter by the latest student-session enrollment first,
         * instead of always filtering the old students.class_id.
         *
         * This uses the project's existing rule that the greatest
         * academic_session_id represents the latest session.
         * Students without any enrollment retain the legacy filter.
         */
        if (!empty($classId)) {
            $query->where(function ($q) use ($classId) {
                $q->whereHas('studentSessions', function ($enrollmentQuery) use ($classId) {
                    $enrollmentQuery
                        ->where('class_id', $classId)
                        ->whereRaw(
                            'student_sessions.academic_session_id = (
                                SELECT MAX(ss_latest.academic_session_id)
                                FROM student_sessions AS ss_latest
                                WHERE ss_latest.student_id = student_sessions.student_id
                            )'
                        );
                })->orWhere(function ($legacyQuery) use ($classId) {
                    $legacyQuery
                        ->whereDoesntHave('studentSessions')
                        ->where('class_id', $classId);
                });
            });
        }

        $students = $query->paginate($perPage);

        $studentIds = collect($students->items())
            ->pluck('id')
            ->all();

        /*
         * Load every enrollment with its own academic details and payments.
         */
        $enrollments = StudentSession::with([
            'academicSession',
            'classInfo',
            'section',
            'shift',
            'classGroup',
            'payments' => function ($q) {
                $q->select(
                    'id',
                    'student_id',
                    'student_session_id',
                    'month',
                    'paid_amount',
                    'due_amount',
                    'status'
                );
            },
        ])
            ->whereIn('student_id', $studentIds)
            ->orderBy('academic_session_id', 'desc')
            ->get()
            ->groupBy('student_id');

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
            'December',
        ];

        $currentMonth = Carbon::now()->month;

        foreach ($students->items() as $student) {
            /*
             * Preserve existing student-level month calculations.
             */
            $paidMonths = $student->payments
                ->pluck('month')
                ->toArray();

            $admissionMonth = $student->admission_date
                ? Carbon::parse($student->admission_date)->month
                : 1;

            $monthsTillNow = array_slice(
                $allMonths,
                $admissionMonth - 1,
                max(0, $currentMonth - $admissionMonth + 1)
            );

            $dueMonths = array_values(
                array_diff($monthsTillNow, $paidMonths)
            );

            $monthsTillDecember = array_slice(
                $allMonths,
                $admissionMonth - 1
            );

            $availableMonths = array_values(
                array_diff($monthsTillDecember, $paidMonths)
            );

            /*
             * Attach session-wise payment month information.
             */
            $studentEnrollments = $enrollments
                ->get($student->id, collect())
                ->values();

            foreach ($studentEnrollments as $enrollment) {
                $sessionMonths = array_slice(
                    $allMonths,
                    $admissionMonth - 1
                );

                $sessionPaidMonths = $enrollment->payments
                    ->pluck('month')
                    ->toArray();

                $sessionAvailableMonths = array_values(
                    array_diff(
                        $sessionMonths,
                        $sessionPaidMonths
                    )
                );

                $enrollment->setAttribute(
                    'available_months',
                    $sessionAvailableMonths
                );
            }

            $student->setAttribute('due_months', $dueMonths);

            $student->setAttribute(
                'available_months',
                $availableMonths
            );

            $student->setAttribute(
                'student_sessions',
                $studentEnrollments
            );
        }

        /*
         * Preserve the existing total-student count and branch scope.
         */
        $totalStudentsQuery = Student::query();

        if ($currentBranchId !== null) {
            $totalStudentsQuery->where(
                'branch_id',
                $currentBranchId
            );
        }

        return response()->json([
            'status' => true,
            'students' => $students->items(),
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ],
            'total_students' => $totalStudentsQuery->count(),
        ]);
    }

    /**
     * Create student and first academic-session enrollment.
     */
    public function store(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to create students.'
            );
        }

        $request->validate([
            'full_name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'fathers_name' => 'required|string|max:255',
            'mothers_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20',
            'student_roll' => 'nullable|string|max:50',
            'academic_session_id' => 'required|integer|exists:academic_sessions,id',
            'section_id' => 'required|integer|exists:sections,id',
            'class_id' => 'required|integer|exists:clss_m_s,id',
            'class_group_id' => 'required|integer|exists:class_groups,id',
            'shift_id' => 'required|integer|exists:shifts,id',
            'course_name' => 'nullable|string|max:100',
            'admission_date' => 'nullable|date',
            'email' => 'required|email|unique:students,email',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'monthly_fee' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $branchId = $this->resolveCreateBranch($request, $authUser);

        if ($branchId instanceof \Illuminate\Http\JsonResponse) {
            return $branchId;
        }

        $academicSession = $this->getAcademicSessionForBranch(
            $request->academic_session_id,
            $branchId
        );

        if (!$academicSession) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session does not belong to this branch.',
            ], 422);
        }

        $academicError = $this->validateAcademicDetails(
            $request,
            $branchId
        );

        if ($academicError) {
            return $academicError;
        }

        $imagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $filename = time() . '_' . Str::random(10) . '.' .
                $file->getClientOriginalExtension();

            $imagePath = $file->storeAs(
                'students',
                $filename,
                'public'
            );
        }

        $student = null;

        try {
            DB::transaction(function () use (
                $request,
                $branchId,
                $academicSession,
                $imagePath,
                &$student
            ) {
                $lastStudent = Student::latest('id')->first();

                if ($lastStudent && $lastStudent->student_id) {
                    $lastNumber = (int) str_replace(
                        'STD-',
                        '',
                        $lastStudent->student_id
                    );

                    $studentId = 'STD-' . ($lastNumber + 1);
                } else {
                    $studentId = 'STD-1001';
                }

                $monthlyFee = $request->monthly_fee ?? 0;

                $student = Student::create([
                    'full_name' => $request->full_name,
                    'version' => $request->version,
                    'fathers_name' => $request->fathers_name,
                    'mothers_name' => $request->mothers_name,
                    'student_id' => $studentId,
                    'phone' => $request->phone,
                    'second_phone' => $request->second_phone,
                    'student_roll' => $request->student_roll,
                    'section_id' => $request->section_id,
                    'class_id' => $request->class_id,
                    'class_group_id' => $request->class_group_id,
                    'shift_id' => $request->shift_id,
                    'course_name' => $request->course_name,
                    'admission_date' => $request->admission_date
                        ?? now()->toDateString(),
                    'email' => $request->email,
                    'image' => $imagePath,
                    'monthly_fee' => $monthlyFee,
                    'branch_id' => $branchId,
                ]);

                StudentSession::create([
                    'student_id' => $student->id,
                    'academic_session_id' => $academicSession->id,
                    'branch_id' => $branchId,
                    'class_id' => $request->class_id,
                    'section_id' => $request->section_id,
                    'shift_id' => $request->shift_id,
                    'class_group_id' => $request->class_group_id,
                    'monthly_fee' => $monthlyFee,
                    'status' => 'active',
                ]);
            });
        } catch (\Throwable $e) {
            if (
                $imagePath &&
                Storage::disk('public')->exists($imagePath)
            ) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $e;
        }

        return response()->json([
            'status' => true,
            'message' => 'Student Created Successfully',
            'student' => $student->load([
                'section',
                'classInfo',
                'classGroup',
                'shift',
                'branch',
            ]),
        ], 201);
    }

    /**
     * Display one student with all session enrollment details.
     */
    public function show(Request $request, Student $student)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessStudent($authUser, $student)) {
            return $this->forbidden('You cannot access this student.');
        }

        $student->load([
            'payments',
            'section',
            'classInfo',
            'classGroup',
            'shift',
            'branch',
        ]);

        $student->setAttribute(
            'student_sessions',
            StudentSession::with([
                'academicSession',
                'classInfo',
                'section',
                'shift',
                'classGroup',
                'payments',
            ])
                ->where('student_id', $student->id)
                ->orderBy('academic_session_id', 'desc')
                ->get()
        );

        return response()->json([
            'status' => true,
            'student' => $student,
        ]);
    }

    /**
     * Edit data.
     */
    public function edit(Request $request, Student $student)
    {
        return $this->show($request, $student);
    }


/**
 * Update student personal data and selected session enrollment.
 *
 * Editing an older session must not overwrite the student's
 * latest class, section, shift, group, or monthly fee.
 */
public function update(Request $request, Student $student)
{
    $authUser = $request->user();

    if (!$authUser) {
        return $this->unauthenticated();
    }

    if (!$this->canAccessStudent($authUser, $student)) {
        return $this->forbidden('You cannot update this student.');
    }

    // Validate student and selected academic-session data.
    $request->validate([
        'full_name' => 'required|string|max:255',
        'version' => 'required|string|max:50',
        'phone' => 'required|string|max:20',
        'student_roll' => 'nullable|string|max:50',
        'second_phone' => 'nullable|string|max:20',

        'academic_session_id' => 'required|integer|exists:academic_sessions,id',
        'section_id' => 'required|integer|exists:sections,id',
        'class_id' => 'required|integer|exists:clss_m_s,id',
        'class_group_id' => 'required|integer|exists:class_groups,id',
        'shift_id' => 'required|integer|exists:shifts,id',

        'course_name' => 'nullable|string|max:100',
        'admission_date' => 'required|date',

        'email' => [
            'required',
            'email',
            Rule::unique('students', 'email')->ignore($student->id),
        ],

        'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'monthly_fee' => 'nullable|numeric|min:0',
    ]);

    $branchId = $student->branch_id;

    if (!$branchId) {
        return response()->json([
            'status' => false,
            'message' => 'Student is not assigned to any branch.',
        ], 422);
    }

    // Ensure the selected academic session belongs to the student's branch.
    $academicSession = $this->getAcademicSessionForBranch(
        $request->academic_session_id,
        $branchId
    );

    if (!$academicSession) {
        return response()->json([
            'status' => false,
            'message' => 'Selected academic session does not belong to this student branch.',
        ], 422);
    }

    // Validate class, section, group, and shift branch ownership.
    $academicError = $this->validateAcademicDetails(
        $request,
        $branchId
    );

    if ($academicError) {
        return $academicError;
    }

    $oldImagePath = $student->image;
    $imagePath = $oldImagePath;

    if ($request->hasFile('image')) {
        $file = $request->file('image');

        $filename = time() . '_' . Str::random(10) . '.' .
            $file->getClientOriginalExtension();

        $imagePath = $file->storeAs(
            'students',
            $filename,
            'public'
        );
    }

    try {
        DB::transaction(function () use (
            $request,
            $student,
            $branchId,
            $academicSession,
            $imagePath
        ) {
            // Find the enrollment for the selected academic session.
            $enrollment = StudentSession::where(
                'student_id',
                $student->id
            )
                ->where(
                    'academic_session_id',
                    $academicSession->id
                )
                ->lockForUpdate()
                ->first();

            // Preserve the existing latest-session rule.
            $latestSessionId = StudentSession::where(
                'student_id',
                $student->id
            )->max('academic_session_id');

            $isLatestSession = $latestSessionId === null
                || (int) $academicSession->id >= (int) $latestSessionId;

            $monthlyFee = $request->monthly_fee
                ?? (
                    $enrollment?->monthly_fee
                    ?? $student->monthly_fee
                    ?? 0
                );

            /*
             * Update student personal information.
             * Roll and second phone are included here.
             */
            $studentData = [
                'full_name' => $request->full_name,
                'version' => $request->version,
                'phone' => $request->phone,
                'student_roll' => $request->student_roll,
                'second_phone' => $request->second_phone,
                'course_name' => $request->course_name,
                'admission_date' => $request->admission_date,
                'email' => $request->email,
                'image' => $imagePath,
            ];

            /*
             * Only update the legacy current-class fields when
             * the selected enrollment is the latest session.
             */
            if ($isLatestSession) {
                $studentData['section_id'] = $request->section_id;
                $studentData['class_id'] = $request->class_id;
                $studentData['class_group_id'] = $request->class_group_id;
                $studentData['shift_id'] = $request->shift_id;
                $studentData['monthly_fee'] = $monthlyFee;
            }

            $student->update($studentData);

            /*
             * Update the selected session enrollment.
             * If it does not exist, create it.
             */
            $enrollmentData = [
                'student_id' => $student->id,
                'academic_session_id' => $academicSession->id,
                'branch_id' => $branchId,
                'class_id' => $request->class_id,
                'section_id' => $request->section_id,
                'shift_id' => $request->shift_id,
                'class_group_id' => $request->class_group_id,
                'monthly_fee' => $monthlyFee,
            ];

            if ($enrollment) {
                $enrollment->update($enrollmentData);
            } else {
                $enrollmentData['status'] = 'active';

                StudentSession::create($enrollmentData);
            }
        });
    } catch (\Throwable $e) {
        // Delete the newly uploaded image if the database update fails.
        if (
            $request->hasFile('image') &&
            $imagePath &&
            $imagePath !== $oldImagePath &&
            Storage::disk('public')->exists($imagePath)
        ) {
            Storage::disk('public')->delete($imagePath);
        }

        throw $e;
    }

    // Delete the previous image only after a successful update.
    if (
        $request->hasFile('image') &&
        $oldImagePath &&
        $oldImagePath !== $imagePath &&
        Storage::disk('public')->exists($oldImagePath)
    ) {
        Storage::disk('public')->delete($oldImagePath);
    }

    $student->refresh();

    return response()->json([
        'status' => true,
        'message' => 'Student Updated Successfully',
        'student' => $student->load([
            'section',
            'classInfo',
            'classGroup',
            'shift',
            'branch',
        ]),
    ]);
}


    /**
     * Delete student, payments, and session enrollments.
     */
    public function destroy(Request $request, Student $student)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessStudent($authUser, $student)) {
            return $this->forbidden('You cannot delete this student.');
        }

        DB::transaction(function () use ($student) {
            $student->payments()->delete();

            StudentSession::where(
                'student_id',
                $student->id
            )->delete();

            $student->delete();
        });

        if (
            $student->image &&
            Storage::disk('public')->exists($student->image)
        ) {
            Storage::disk('public')->delete($student->image);
        }

        return response()->json([
            'status' => true,
            'message' => 'Student, related payments, and session enrollments deleted successfully.',
        ]);
    }

    /**
     * Get payments of a student.
     */
    public function studentPayments(Request $request, $id)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return $this->unauthenticated();
        }

        if (!$this->canAccessModule($authUser)) {
            return $this->forbidden(
                'You are not authorized to access student payments.'
            );
        }

        $student = Student::findOrFail($id);

        if (!$this->canAccessStudent($authUser, $student)) {
            return $this->forbidden(
                'You cannot access payments of a student from another branch.'
            );
        }

        $payments = Payment::with('student')
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'payments' => $payments,
        ]);
    }

    /**
     * Student image endpoint.
     */
    public function image($filename)
    {
        $path = storage_path(
            'app/public/students/' . basename($filename)
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
}
