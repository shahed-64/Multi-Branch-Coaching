<?php

namespace App\Http\Controllers;

use App\Models\OtherPayment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\AcademicSession;
class PaymentController extends Controller
{
    private function staff()
    {
        return request()->user();
    }

    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    private function authorizeAccess()
    {
        $staff = $this->staff();

        if (!$staff) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($staff->role, [
            'Manager',
            'Branch Manager',
            'Branch Accountant',
        ])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access payments.',
            ], 403);
        }

        if (
            in_array($staff->role, [
                'Branch Manager',
                'Branch Accountant',
            ]) && empty($staff->branch_id)
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your branch is not assigned.',
            ], 403);
        }

        return null;
    }

    private function isManager(): bool
    {
        return $this->staff()?->role === 'Manager';
    }

    /**
     * Payment query with branch isolation.
     */
    private function branchPaymentQuery()
    {
        $query = Payment::query();
        $staff = $this->staff();

        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        if ($staff->role === 'Manager') {
            $branchId = $this->currentBranchId();

            if ($branchId === null) {
                return $query;
            }

            return $query->whereHas('student', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        if (in_array($staff->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Student query with branch isolation.
     */
    private function branchStudentQuery()
    {
        $query = Student::query();
        $staff = $this->staff();

        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        if ($staff->role === 'Manager') {
            $branchId = $this->currentBranchId();

            return $branchId === null
                ? $query
                : $query->where('branch_id', $branchId);
        }

        if (in_array($staff->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('branch_id', $staff->branch_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Session enrollment query with branch isolation.
     */
    private function branchSessionQuery()
    {
        $query = StudentSession::query();
        $staff = $this->staff();

        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        if ($staff->role === 'Manager') {
            $branchId = $this->currentBranchId();

            return $branchId === null
                ? $query
                : $query->where('branch_id', $branchId);
        }

        if (in_array($staff->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('branch_id', $staff->branch_id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function branchOtherPaymentQuery()
    {
        $query = OtherPayment::query();
        $staff = $this->staff();

        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        if ($staff->role === 'Manager') {
            $branchId = $this->currentBranchId();

            if ($branchId === null) {
                return $query;
            }

            return $query->whereHas('student', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        if (in_array($staff->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    private function studentAllowed(Student $student): bool
    {
        $staff = $this->staff();

        if (!$staff) {
            return false;
        }

        if ($staff->role === 'Manager') {
            $branchId = $this->currentBranchId();

            return $branchId === null
                || (int) $student->branch_id === (int) $branchId;
        }

        if (in_array($staff->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            return !empty($staff->branch_id)
                && (int) $student->branch_id === (int) $staff->branch_id;
        }

        return false;
    }

    /**
     * Verify the session belongs to the selected student
     * and is accessible in the current branch context.
     */
    private function findAllowedStudentSession(
        int $studentId,
        int $sessionId
    ): ?StudentSession {
        return $this->branchSessionQuery()
            ->where('id', $sessionId)
            ->where('student_id', $studentId)
            ->first();
    }

    /**
     * PAYMENT DASHBOARD AND LIST
     */
    public function index(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $paymentQuery = $this->branchPaymentQuery();

        $totalPaidAmount = (clone $paymentQuery)->sum('paid_amount');
        $totalDueAmount = (clone $paymentQuery)->sum('due_amount');

        $totalStudents = $this->branchStudentQuery()->count();

        $dueStudents = (clone $paymentQuery)
            ->where('due_amount', '>', 0)
            ->distinct()
            ->count('student_id');

        $todayPayments = (clone $paymentQuery)
            ->whereDate('payment_date', today());

        $todayCollection = (clone $todayPayments)->sum('paid_amount');
        $todayExamFee = (clone $todayPayments)->sum('exam_fee');
        $todayAdmissionFee = (clone $todayPayments)->sum('admission_fee');

        $todayOtherCollection = (clone $this->branchOtherPaymentQuery())
            ->whereDate('payment_date', today())
            ->sum('total_amount');

        $todayCollection +=
            $todayExamFee +
            $todayAdmissionFee +
            $todayOtherCollection;

        $thisMonthCollection = (clone $paymentQuery)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->select(DB::raw(
                'SUM(
                    COALESCE(paid_amount, 0) +
                    COALESCE(admission_fee, 0) +
                    COALESCE(exam_fee, 0)
                ) as total'
            ))
            ->value('total');

        $otherPaymentCollection = (clone $this->branchOtherPaymentQuery())
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('total_amount');

        $thisMonthCollection =
            (float) $thisMonthCollection + $otherPaymentCollection;

        $thisMonthDue = (clone $paymentQuery)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('due_amount');

        $monthlyPayments = (clone $paymentQuery)
            ->select(
                DB::raw('MONTH(payment_date) as month'),
                DB::raw('SUM(paid_amount) as total')
            )
            ->whereYear('payment_date', now()->year)
            ->groupBy(DB::raw('MONTH(payment_date)'))
            ->orderBy('month')
            ->get();

        $recentPayments = (clone $paymentQuery)
            ->with(['student', 'studentSession'])
            ->whereDate(
                'payment_date',
                '>=',
                Carbon::today()->subDays(6)
            )
            ->orderByDesc('payment_date')
            ->get();

        $currentMonth = now()->format('F');

        /*
         * Count current-month unpaid session enrollments.
         */
        $sessions = $this->branchSessionQuery();

        $runningMonthUnpaidStudents = (clone $sessions)
            ->whereNotExists(function ($query) use ($currentMonth) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_session_id',
                        'student_sessions.id'
                    )
                    ->where('payments.month', $currentMonth);
            })
            ->count();

        $totalUnpaidStudents = (clone $sessions)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_session_id',
                        'student_sessions.id'
                    );
            })
            ->count();

        $payments = (clone $paymentQuery)
            ->with([
                'student' => function ($query) {
                    $query->select(
                        'id',
                        'full_name',
                        'student_id',
                        'class_id',
                        'branch_id'
                    );
                },
                'studentSession',
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Payments fetched successfully',
            'total_paid_amount' => $totalPaidAmount,
            'total_due_amount' => $totalDueAmount,
            'total_students' => $totalStudents,
            'due_students' => $dueStudents,
            'today_collection' => $todayCollection,
            'this_month_collection' => $thisMonthCollection,
            'this_month_due' => $thisMonthDue,
            'running_month_unpaid_students' => $runningMonthUnpaidStudents,
            'total_unpaid_students' => $totalUnpaidStudents,
            'monthly_payments' => $monthlyPayments,
            'recent_payments' => $recentPayments,
            'payments' => $payments,
        ]);
    }

    /**
     * CREATE SESSION-WISE PAYMENT
     */
    public function store(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'student_session_id' => 'required|integer|exists:student_sessions,id',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'month' => 'required|string|max:30',
            'admission_fee' => 'nullable|numeric|min:0',
            'exam_fee' => 'nullable|numeric|min:0',
        ]);

        $student = $this->branchStudentQuery()
            ->where('id', $validated['student_id'])
            ->first();

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found in your allowed branch.',
            ], 404);
        }

        $studentSession = $this->findAllowedStudentSession(
            (int) $student->id,
            (int) $validated['student_session_id']
        );

        if (!$studentSession) {
            return response()->json([
                'status' => false,
                'message' => 'The selected session does not belong to this student or branch.',
            ], 422);
        }

        return DB::transaction(function () use ($validated, $student, $studentSession) {
            $exists = Payment::query()
                ->where('student_session_id', $studentSession->id)
                ->where('month', $validated['month'])
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment for this month already exists in this academic session.',
                ], 422);
            }

            $amount = (float) $studentSession->monthly_fee;
            $paidAmount = (float) $validated['paid_amount'];
            $dueAmount = $amount - $paidAmount;

            $payment = Payment::create([
                'student_id' => $student->id,
                'student_session_id' => $studentSession->id,
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
                'month' => $validated['month'],
                'admission_fee' => $validated['admission_fee'] ?? 0,
                'exam_fee' => $validated['exam_fee'] ?? 0,
                'status' => $dueAmount <= 0 ? 'paid' : 'due',
            ]);

            $payment->load(['student', 'studentSession']);

            return response()->json([
                'status' => true,
                'message' => 'Payment created successfully.',
                'payment' => $payment,
            ]);
        });
    }

    /**
     * SHOW SINGLE PAYMENT
     */
/**
 * SHOW SINGLE PAYMENT WITH ITS ORIGINAL ACADEMIC SESSION
 */
public function show($id)
{
    $authorization = $this->authorizeAccess();

    if ($authorization) {
        return $authorization;
    }

    $payment = $this->branchPaymentQuery()
        ->with([
            'student',
            'studentSession.classInfo',
            'studentSession.section',
            'studentSession.shift',
            'studentSession.academicSession',
            'studentSession.classGroup',
        ])
        ->where('id', $id)
        ->first();

    if (!$payment) {
        return response()->json([
            'status' => false,
            'message' => 'Payment not found.',
        ], 404);
    }

    return response()->json([
        'status' => true,
        'payment' => $payment,
    ]);
}
    /**
     * GET PAYMENTS FOR A STUDENT
     */
    public function studentPayments($id)
    {
        $student = $this->findAllowedStudent($id);

        $payments = $this->branchPaymentQuery()
            ->with([
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.academicSession',
            ])
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'payments' => $payments,
        ]);
    }

    /**
     * SESSION-WISE STUDENT PAYMENT REPORT
     *
     * Unpaid months:
     * Admission month through current month, inclusive.
     * Payments are checked separately for each student session.
     */
    public function studentPaymentReport()
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $sessions = $this->branchSessionQuery()
            ->with([
                'student',
                'payments' => function ($query) {
                    $query->latest();
                },
            ])
            ->get();

        $currentMonth = now()->startOfMonth();

        $report = $sessions
            ->filter(fn ($session) => $session->student !== null)
            ->map(function ($session) use ($currentMonth) {
                $student = $session->student;
                $payments = $session->payments;

                $totalPaid = (float) $payments->sum('paid_amount');
                $totalDue = (float) $payments->sum('due_amount');

                $paidMonths = $payments
                    ->pluck('month')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $unpaidMonths = 0;

                if ($student->admission_date) {
                    $checkMonth = Carbon::parse(
                        $student->admission_date
                    )->startOfMonth();

                    while ($checkMonth->lte($currentMonth)) {
                        $monthName = $checkMonth->format('F');

                        if (!in_array($monthName, $paidMonths, true)) {
                            $unpaidMonths++;
                        }

                        $checkMonth->addMonth();
                    }
                }

                $monthlyFee = (float) $session->monthly_fee;
                $unpaidAmount = $unpaidMonths * $monthlyFee;

                return [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'full_name' => $student->full_name,
                    'phone' => $student->phone,
                    'batch_name' => $student->batch_name ?? null,
                    'status' => $student->status ?? null,
                    'branch_id' => $session->branch_id,

                    'student_session_id' => $session->id,
                    'academic_session_id' => $session->academic_session_id,
                    'class_id' => $session->class_id,
                    'section_id' => $session->section_id,
                    'shift_id' => $session->shift_id,
                    'class_group_id' => $session->class_group_id,

                    'monthly_fee' => $monthlyFee,
                    'payments' => $payments,
                    'total_paid' => $totalPaid,
                    'total_due' => $totalDue,
                    'paid_months' => $paidMonths,
                    'unpaid_months' => $unpaidMonths,
                    'unpaid_amount' => $unpaidAmount,
                    'total_outstanding' => $totalDue + $unpaidAmount,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Session-wise student payment report fetched successfully.',
            'students' => $report,
        ]);
    }


/**
 * ALL REGISTER — ACADEMIC SESSION WISE PAYMENT REGISTER
 */
public function paymentRegister(Request $request)
{
    $authorization = $this->authorizeAccess();

    if ($authorization) {
        return $authorization;
    }

    $validated = $request->validate([
        'branch_id' => 'nullable|integer|exists:branches,id',
        'academic_session_id' => 'nullable|integer|exists:academic_sessions,id',
    ]);

    $staff = $this->staff();
    $currentBranchId = $this->currentBranchId();

    /*
     * Manager can select a branch only when the current
     * BranchContext is All Branches or matches that branch.
     */
    $selectedBranchId = $validated['branch_id'] ?? null;

    if ($staff->role === 'Manager') {
        if (
            $currentBranchId !== null &&
            $selectedBranchId !== null &&
            (int) $currentBranchId !== (int) $selectedBranchId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'The selected branch is outside your current branch context.',
            ], 403);
        }

        if ($currentBranchId !== null) {
            $selectedBranchId = $currentBranchId;
        }
    } else {
        if (
            $selectedBranchId !== null &&
            (int) $selectedBranchId !== (int) $staff->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot access another branch.',
            ], 403);
        }

        $selectedBranchId = $staff->branch_id;
    }

    /*
     * Academic sessions available to the current user
     * and selected branch.
     */
    $availableSessionsQuery = AcademicSession::query();

    if ($selectedBranchId !== null) {
        $availableSessionsQuery->where(
            'branch_id',
            $selectedBranchId
        );
    } elseif ($staff->role !== 'Manager') {
        $availableSessionsQuery->where(
            'branch_id',
            $staff->branch_id
        );
    }

    $availableSessions = (clone $availableSessionsQuery)
        ->orderByDesc('start_date')
        ->get([
            'id',
            'branch_id',
            'name',
            'start_date',
            'end_date',
            'is_active',
        ]);

    /*
     * If no session is selected, use active sessions.
     * In All Branches mode, each branch can have its own
     * active academic session.
     */
    if (!empty($validated['academic_session_id'])) {
        $selectedSessionId = (int) $validated['academic_session_id'];

        $sessionExists = $availableSessions->contains(
            fn ($session) => (int) $session->id === $selectedSessionId
        );

        if (!$sessionExists) {
            return response()->json([
                'status' => false,
                'message' => 'The selected academic session is not available for this branch.',
            ], 422);
        }

        $sessionIds = [$selectedSessionId];
    } else {
        $sessionIds = $availableSessions
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /*
     * Fetch session enrollment records, not the student's
     * current class fields. This preserves historical data.
     */
    $sessionsQuery = $this->branchSessionQuery()
        ->whereIn('academic_session_id', $sessionIds);

    if ($selectedBranchId !== null) {
        $sessionsQuery->where('branch_id', $selectedBranchId);
    }

    $studentSessions = $sessionsQuery
        ->with([
            'student',
            'academicSession',
            'classInfo',
            'section',
            'shift',
            'classGroup',
            'payments' => function ($query) {
                $query->orderBy('payment_date');
            },
        ])
        ->orderBy('class_id')
        ->orderBy('section_id')
        ->orderBy('student_id')
        ->get()
        ->filter(fn ($session) => $session->student !== null)
        ->values();

    $months = [
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

    /*
     * Each student gets one entry per month.
     * No payment record = Unpaid.
     * Existing payment record = actual paid amount.
     */
    $students = $studentSessions->map(function ($session) use ($months) {
        $student = $session->student;

        $paymentsByMonth = $session->payments
            ->keyBy('month');

        $monthlyPayments = collect($months)->map(
            function ($month) use ($paymentsByMonth) {
                $payment = $paymentsByMonth->get($month);

                if (!$payment) {
                    return [
                        'month' => $month,
                        'payment_id' => null,
                        'paid_amount' => null,
                        'due_amount' => null,
                        'status' => 'unpaid',
                    ];
                }

                return [
                    'month' => $month,
                    'payment_id' => $payment->id,
                    'paid_amount' => (float) $payment->paid_amount,
                    'due_amount' => (float) $payment->due_amount,
                    'status' => (float) $payment->paid_amount > 0
                        ? 'paid'
                        : 'unpaid',
                ];
            }
        )->values();

        return [
            'student_session_id' => $session->id,
            'academic_session_id' => $session->academic_session_id,
            'academic_session' => $session->academicSession,

            'student_id' => $student->id,
            'student_code' => $student->student_id,
            'full_name' => $student->full_name,
            'phone' => $student->phone,
            'image_url' => $student->image_url,

            'branch_id' => $session->branch_id,
            'class_id' => $session->class_id,
            'class_info' => $session->classInfo,
            'section_id' => $session->section_id,
            'section' => $session->section,
            'shift_id' => $session->shift_id,
            'shift' => $session->shift,
            'class_group_id' => $session->class_group_id,
            'class_group' => $session->classGroup,

            'monthly_fee' => (float) $session->monthly_fee,
            'monthly_payments' => $monthlyPayments,
        ];
    });

    /*
     * Group students by their session-specific class.
     */
    $classGroups = $students
        ->groupBy(fn ($student) => $student['class_id'])
        ->map(function ($classStudents, $classId) {
            return [
                'class_id' => (int) $classId,
                'class_info' => $classStudents->first()['class_info'],
                'students' => $classStudents->values(),
            ];
        })
        ->values();

    return response()->json([
        'status' => true,
        'message' => 'Payment register fetched successfully.',
        'selected_branch_id' => $selectedBranchId,
        'selected_session_id' => $validated['academic_session_id'] ?? null,
        'available_sessions' => $availableSessions->values(),
        'months' => $months,
        'total_students' => $students->count(),
        'class_groups' => $classGroups,
    ]);
  }


    /**
     * UPDATE PAYMENT
     *
     * The original session cannot be changed during payment editing.
     */
    public function update(Request $request, $id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $payment = $this->branchPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment record not found.',
            ], 404);
        }

        $validated = $request->validate([
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'payment_date' => 'nullable|date',
            'month' => 'required|string|max:30',
            'admission_fee' => 'nullable|numeric|min:0',
            'exam_fee' => 'nullable|numeric|min:0',
        ]);

        $studentSession = null;

        if ($payment->student_session_id) {
            $studentSession = $this->findAllowedStudentSession(
                (int) $payment->student_id,
                (int) $payment->student_session_id
            );

            if (!$studentSession) {
                return response()->json([
                    'status' => false,
                    'message' => 'The payment session is not accessible.',
                ], 403);
            }
        }

        $duplicate = $this->branchPaymentQuery()
            ->where('student_session_id', $payment->student_session_id)
            ->where('month', $validated['month'])
            ->where('id', '!=', $payment->id)
            ->exists();

        if ($payment->student_session_id && $duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'Another payment already exists for this month in this academic session.',
            ], 422);
        }

        $paidAmount = (float) $validated['paid_amount'];
        $amount = $studentSession
            ? (float) $studentSession->monthly_fee
            : (float) $payment->amount;

        $dueAmount = $amount - $paidAmount;

        $payment->update([
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date']
                ?? $payment->payment_date
                ?? now()->toDateString(),
            'month' => $validated['month'],
            'admission_fee' => $validated['admission_fee']
                ?? $payment->admission_fee,
            'exam_fee' => $validated['exam_fee']
                ?? $payment->exam_fee,
            'status' => $dueAmount <= 0 ? 'paid' : 'due',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Payment updated successfully.',
            'payment' => $payment->load(['student', 'studentSession']),
        ]);
    }

    /**
     * DELETE PAYMENT
     */
    public function destroy($id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $payment = $this->branchPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment record not found.',
            ], 404);
        }

        $payment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Payment deleted successfully.',
        ]);
    }
}
