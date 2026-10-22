<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\OtherPayment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access payments.',
            ], 403);
        }

        if (
            in_array($staff->role, [
                'Branch Manager',
                'Branch Accountant',
            ], true) && empty($staff->branch_id)
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
        ], true)) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

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
        ], true)) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('branch_id', $staff->branch_id);
        }

        return $query->whereRaw('1 = 0');
    }

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
        ], true)) {
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
        ], true)) {
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
        ], true)) {
            return !empty($staff->branch_id)
                && (int) $student->branch_id === (int) $staff->branch_id;
        }

        return false;
    }

    private function findAllowedStudent($id): Student
    {
        return $this->branchStudentQuery()
            ->whereKey($id)
            ->firstOrFail();
    }

    private function findAllowedStudentSession(
        int $studentId,
        int $sessionId
    ): ?StudentSession {
        return $this->branchSessionQuery()
            ->whereKey($sessionId)
            ->where('student_id', $studentId)
            ->first();
    }

    /**
     * PAYMENT LEDGER
     */
    public function ledger(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $payments = $this->branchPaymentQuery()
            ->with([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ])
            ->whereDate('payment_date', '>=', $validated['start_date'])
            ->whereDate('payment_date', '<=', $validated['end_date'])
            ->where(function ($query) {
                $query->where('paid_amount', '>', 0)
                    ->orWhere('due_payment_amount', '>', 0);
            })
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Payment ledger fetched successfully.',
            'payments' => $payments,
        ]);
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

    /*
    |--------------------------------------------------------------------------
    | Active Academic Session
    |--------------------------------------------------------------------------
    | Manager: selected branch অথবা all branches-এর active sessions.
    | Branch Manager / Branch Accountant: নিজের branch-এর active session.
    */

    $activeStudentSessions = $this->branchSessionQuery()
        ->whereHas('academicSession', function ($query) {
            $query->where('is_active', true);
        });

    /*
    |--------------------------------------------------------------------------
    | Active Session Payment Queries
    |--------------------------------------------------------------------------
    */

    $paymentQuery = $this->branchPaymentQuery()
        ->whereHas('studentSession.academicSession', function ($query) {
            $query->where('is_active', true);
        });

    $otherPaymentQuery = $this->branchOtherPaymentQuery()
        ->whereHas('studentSession.academicSession', function ($query) {
            $query->where('is_active', true);
        });

    /*
    |--------------------------------------------------------------------------
    | Total Collection
    |--------------------------------------------------------------------------
    */

    $totalPaidAmount =
        (float) (clone $paymentQuery)->sum('paid_amount')
        + (float) (clone $paymentQuery)->sum('due_payment_amount');

    $totalDueAmount = max(
        0,
        (float) (clone $paymentQuery)->sum('due_amount')
        - (float) (clone $paymentQuery)->sum('due_payment_amount')
    );

    /*
    |--------------------------------------------------------------------------
    | Active Session Students
    |--------------------------------------------------------------------------
    */

    $totalStudents = (clone $activeStudentSessions)
        ->count();

    $dueStudents = (clone $paymentQuery)
        ->select(
            'student_id',
            DB::raw(
                'SUM(COALESCE(due_amount, 0))
                - SUM(COALESCE(due_payment_amount, 0)) AS remaining_due'
            )
        )
        ->groupBy('student_id')
        ->havingRaw(
            'SUM(COALESCE(due_amount, 0))
            - SUM(COALESCE(due_payment_amount, 0)) > 0'
        )
        ->get()
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Other Collection
    |--------------------------------------------------------------------------
    */

    $totalOtherPayment = (float) (clone $otherPaymentQuery)
        ->sum('total_amount');

    $admissionExamCollection = (float) (clone $otherPaymentQuery)
        ->whereIn('payment_type', ['Admission Fee', 'Exam Fee'])
        ->sum('total_amount');

    $thisMonthOtherCollection = (float) (clone $otherPaymentQuery)
        ->whereMonth('payment_date', now()->month)
        ->whereYear('payment_date', now()->year)
        ->sum('total_amount');

    /*
    |--------------------------------------------------------------------------
    | Today's Collection
    |--------------------------------------------------------------------------
    */

    $todayDate = Carbon::now('Asia/Dhaka')->toDateString();

    $todayPayments = (clone $paymentQuery)
        ->whereDate('payment_date', $todayDate);

    $todayCollection =
        (float) (clone $todayPayments)->sum('paid_amount')
        + (float) (clone $todayPayments)->sum('due_payment_amount');

    $todayCollection += (float) (clone $otherPaymentQuery)
        ->whereDate('payment_date', $todayDate)
        ->sum('total_amount');

    /*
    |--------------------------------------------------------------------------
    | Current Calendar Month
    |--------------------------------------------------------------------------
    */

    $thisMonthPayments = (clone $paymentQuery)
        ->whereMonth('payment_date', now()->month)
        ->whereYear('payment_date', now()->year);

    $currentMonthName = now()->format('F');

    $thisMonthPaidAmount = (float) (clone $paymentQuery)
        ->where('month', $currentMonthName)
        ->sum('paid_amount');

    $thisMonthPaidAmount += (float) (clone $paymentQuery)
        ->where('due_month', $currentMonthName)
        ->sum('due_payment_amount');

    $thisMonthDue = max(
        0,
        (float) (clone $thisMonthPayments)->sum('due_amount')
        - (float) (clone $thisMonthPayments)->sum('due_payment_amount')
    );

    $thisMonthCollection =
        (float) (clone $thisMonthPayments)->sum('paid_amount')
        + (float) (clone $thisMonthPayments)->sum('due_payment_amount')
        + $thisMonthOtherCollection;

    /*
    |--------------------------------------------------------------------------
    | Monthly Collection Chart
    |--------------------------------------------------------------------------
    */

    $studentMonthlyPayments = (clone $paymentQuery)
        ->select(
            DB::raw('MONTH(payment_date) as month'),
            DB::raw(
                'SUM(
                    COALESCE(paid_amount, 0)
                    + COALESCE(due_payment_amount, 0)
                ) as total'
            )
        )
        ->whereYear('payment_date', now()->year)
        ->groupBy(DB::raw('MONTH(payment_date)'))
        ->get();

    $otherMonthlyPayments = (clone $otherPaymentQuery)
        ->select(
            DB::raw('MONTH(payment_date) as month'),
            DB::raw('SUM(COALESCE(total_amount, 0)) as total')
        )
        ->whereYear('payment_date', now()->year)
        ->groupBy(DB::raw('MONTH(payment_date)'))
        ->get();

    $monthlyTotals = [];

    foreach ($studentMonthlyPayments as $item) {
        $monthlyTotals[(int) $item->month] = (float) $item->total;
    }

    foreach ($otherMonthlyPayments as $item) {
        $month = (int) $item->month;

        $monthlyTotals[$month] =
            ($monthlyTotals[$month] ?? 0) + (float) $item->total;
    }

    ksort($monthlyTotals);

    $monthlyPayments = collect($monthlyTotals)
        ->map(fn ($total, $month) => [
            'month' => (int) $month,
            'total' => (float) $total,
        ])
        ->values();

    /*
    |--------------------------------------------------------------------------
    | Recent Payments
    |--------------------------------------------------------------------------
    */

    $recentPayments = (clone $paymentQuery)
        ->with(['student', 'studentSession'])
        ->whereDate(
            'payment_date',
            '>=',
            Carbon::now('Asia/Dhaka')->subDays(6)->toDateString()
        )
        ->orderByDesc('payment_date')
        ->get();

    /*
    |--------------------------------------------------------------------------
    | Active Session Unpaid Students
    |--------------------------------------------------------------------------
    */

    $currentMonth = now()->format('F');

    $runningMonthUnpaidStudents = (clone $activeStudentSessions)
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

    $totalUnpaidStudents = (clone $activeStudentSessions)
        ->whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('payments')
                ->whereColumn(
                    'payments.student_session_id',
                    'student_sessions.id'
                );
        })
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Paginated Payments
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'status' => true,
        'message' => 'Payments fetched successfully',

        'total_paid_amount' => $totalPaidAmount,
        'total_due_amount' => $totalDueAmount,
        'total_students' => $totalStudents,
        'due_students' => $dueStudents,

        'today_collection' => $todayCollection,
        'this_month_paid_amount' => $thisMonthPaidAmount,
        'this_month_collection' => $thisMonthCollection,
        'this_month_due' => $thisMonthDue,

        'total_other_payment' => $totalOtherPayment,
        'admission_exam_collection' => $admissionExamCollection,
        'this_month_other_collection' => $thisMonthOtherCollection,

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
/**
 * CREATE SESSION-WISE PAYMENT
 * Supports running month payment + previous due payment in one row.
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
        'month' => 'required|string|in:January,February,March,April,May,June,July,August,September,October,November,December',
        'admission_fee' => 'nullable|numeric|min:0',
        'exam_fee' => 'nullable|numeric|min:0',
        'due_month' => 'nullable|string|in:January,February,March,April,May,June,July,August,September,October,November,December',
        'due_payment_amount' => 'nullable|numeric|min:0',
    ]);

    $student = $this->branchStudentQuery()
        ->whereKey($validated['student_id'])
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

    $dueMonth = $validated['due_month'] ?? null;
    $duePaymentAmount = (float) ($validated['due_payment_amount'] ?? 0);
    $paidAmount = (float) $validated['paid_amount'];
    $amount = (float) $studentSession->monthly_fee;

    // Due payment month and amount must be supplied together.
    if (($dueMonth && $duePaymentAmount <= 0)
        || (!$dueMonth && $duePaymentAmount > 0)) {
        return response()->json([
            'status' => false,
            'message' => 'Select a due month and enter its due payment amount.',
        ], 422);
    }

    // A month cannot be paid as its own previous due.
    if ($dueMonth && $dueMonth === $validated['month']) {
        return response()->json([
            'status' => false,
            'message' => 'The due month must be different from the running payment month.',
        ], 422);
    }

    return DB::transaction(function () use (
        $validated,
        $student,
        $studentSession,
        $dueMonth,
        $duePaymentAmount,
        $paidAmount,
        $amount
    ) {
        // Prevent duplicate regular payment for the running month.
        $exists = $this->branchPaymentQuery()
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

        // Check whether the selected previous month has an outstanding due.
        if ($dueMonth && $duePaymentAmount > 0) {
            $dueRecords = $this->branchPaymentQuery()
                ->where('student_session_id', $studentSession->id)
                ->where('month', $dueMonth)
                ->lockForUpdate()
                ->get();

            $previousDue = (float) $dueRecords->sum('due_amount')
                - (float) $dueRecords->sum('due_payment_amount');

            $previousDue = max(0, $previousDue);

            if ($previousDue <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => "There is no outstanding due for {$dueMonth}.",
                ], 422);
            }

            if ($duePaymentAmount > $previousDue) {
                return response()->json([
                    'status' => false,
                    'message' => "The due payment cannot exceed the outstanding {$dueMonth} due of {$previousDue}.",
                ], 422);
            }
        }

        // The regular payment applies only to the running month's fee.
        $dueAmount = max(0, $amount - $paidAmount);

        $payment = Payment::create([
            'student_id' => $student->id,
            'student_session_id' => $studentSession->id,

            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,

            'payment_method' => $validated['payment_method'] ?? 'Cash',
            'payment_date' => $validated['payment_date']
                ?? now()->toDateString(),

            'month' => $validated['month'],

            'admission_fee' => $validated['admission_fee'] ?? 0,
            'exam_fee' => $validated['exam_fee'] ?? 0,

            'due_month' => $dueMonth,
            'due_payment_amount' => $duePaymentAmount,

            'status' => $dueAmount <= 0 ? 'paid' : 'due',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Payment created successfully.',
            'payment' => $payment->load([
                'student',
                'studentSession',
            ]),
        ]);
    });
}
    /**
     * COLLECT AN EXISTING PAYMENT'S OUTSTANDING DUE
     */
    public function storeDuePayment(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'due_month' => 'required|string|in:January,February,March,April,May,June,July,August,September,October,November,December',
            'due_payment_amount' => 'required|numeric|gt:0',
        ]);

        $student = $this->branchStudentQuery()
            ->whereKey($validated['student_id'])
            ->first();

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' => 'Student not found or access denied.',
            ], 404);
        }

        $studentSession = $this->branchSessionQuery()
        ->where('student_id', $student->id)
        ->orderByDesc('id')
        ->first();
        if (!$studentSession) {
            return response()->json([
                'status' => false,
                'message' => 'No active session found for this student.',
            ], 422);
        }

        $payment = Payment::create([
            'student_id' => $student->id,
            'student_session_id' => $studentSession->id,

            'amount' => 0,
            'paid_amount' => 0,
            'admission_fee' => 0,
            'exam_fee' => 0,
            'due_amount' => 0,

            'payment_method' => 'Cash',
            'payment_date' => now()->toDateString(),
            'status' => 'paid',

            'month' => null,
            'due_month' => $validated['due_month'],
            'due_payment_amount' => $validated['due_payment_amount'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Due payment recorded successfully.',
            'payment' => $payment,
        ], 201);
    }
    /**
     * SHOW SINGLE PAYMENT
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
            ->whereKey($id)
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
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

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
                'payments' => fn ($query) => $query->latest(),
            ])
            ->get();

        $currentMonth = now()->startOfMonth();

        $report = $sessions
            ->filter(fn ($session) => $session->student !== null)
            ->map(function ($session) use ($currentMonth) {
                $student = $session->student;
                $payments = $session->payments;

                $totalPaid = (float) $payments->sum('paid_amount');

                $totalDue = max(
                    0,
                    (float) $payments->sum('due_amount')
                    - (float) $payments->sum('due_payment_amount')
                );

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
                        if (!in_array(
                            $checkMonth->format('F'),
                            $paidMonths,
                            true
                        )) {
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
     * ACADEMIC SESSION-WISE PAYMENT REGISTER
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
    $selectedBranchId = $validated['branch_id'] ?? null;

    if ($staff->role === 'Manager') {
        if (
            $currentBranchId !== null
            && $selectedBranchId !== null
            && (int) $currentBranchId !== (int) $selectedBranchId
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
            $selectedBranchId !== null
            && (int) $selectedBranchId !== (int) $staff->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot access another branch.',
            ], 403);
        }

        $selectedBranchId = $staff->branch_id;
    }

    $availableSessionsQuery = AcademicSession::query();

    if ($selectedBranchId !== null) {
        $availableSessionsQuery->where('branch_id', $selectedBranchId);
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

    if (!empty($validated['academic_session_id'])) {
        $selectedSessionId = (int) $validated['academic_session_id'];

        if (!$availableSessions->contains(
            fn ($session) => (int) $session->id === $selectedSessionId
        )) {
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
            'payments' => fn ($query) => $query
                ->orderBy('payment_date')
                ->orderBy('id'),
        ])
        ->orderBy('class_id')
        ->orderBy('section_id')
        ->orderBy('student_id')
        ->get()
        ->filter(fn ($session) => $session->student !== null)
        ->values();

    $months = [
        'January', 'February', 'March', 'April',
        'May', 'June', 'July', 'August',
        'September', 'October', 'November', 'December',
    ];

    $students = $studentSessions->map(function ($session) use ($months) {
        $student = $session->student;

        // Regular monthly payment records: month field is populated.
        $paymentsByMonth = $session->payments
            ->filter(fn ($payment) => !empty($payment->month))
            ->keyBy('month');

        // Separate due-payment records: due_month field is populated.
        $duePaymentsByMonth = $session->payments
            ->filter(fn ($payment) => !empty($payment->due_month))
            ->groupBy('due_month');

        $monthlyPayments = collect($months)->map(
    function ($month) use ($paymentsByMonth, $duePaymentsByMonth) {
        // এই মাসের নিয়মিত payment
        $payment = $paymentsByMonth->get($month);

        // এই মাসের বকেয়া পরিশোধের record
        $duePaymentRows = $duePaymentsByMonth->get(
            $month,
            collect()
        );

        $regularPaid = (float) ($payment?->paid_amount ?? 0);

        $regularDueCollected = (float) (
            $payment?->due_payment_amount ?? 0
        );

        $separateDueCollected = (float) $duePaymentRows
            ->sum('due_payment_amount');

        // শুধু এই মাসের payment এবং এই মাসের due collection যোগ হবে।
       // এই মাসের নিয়মিত payment amount
        $regularPaid = (float) ($payment?->paid_amount ?? 0);

        // এই মাসের due হিসেবে আদায় করা টাকা
        $regularDueCollected = (float) ($payment?->due_payment_amount ?? 0);

        $separateDueCollected = (float) $duePaymentRows
            ->sum('due_payment_amount');

        // এই মাসের ঘরে শুধু এই মাসের payment এবং এই মাসের due collection যোগ হবে।
        $totalPaid = $regularPaid
            + $regularDueCollected
            + $separateDueCollected;

        // অন্য মাসের due payment যেন running month's Paid-এ যোগ না হয়।
        if ($payment && $payment->due_month !== $month) {
            $totalPaid -= $regularDueCollected;
        }

        $regularDue = (float) ($payment?->due_amount ?? 0);

        // এই মাসের বকেয়া থেকে এই মাসের জন্য আদায় করা টাকা বাদ যাবে।
        $remainingDue = max(
            0,
            $regularDue
                - $regularDueCollected
                - $separateDueCollected
        );

        if (!$payment && $duePaymentRows->isEmpty()) {
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
            'payment_id' => $payment?->id,
            'paid_amount' => $totalPaid,
            'due_amount' => $remainingDue,
            'status' => $totalPaid > 0 ? 'paid' : 'unpaid',
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
     */
    public function update(Request $request, $id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $payment = $this->branchPaymentQuery()->whereKey($id)->first();

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
            'status' => (
                $paidAmount + (float) $payment->due_payment_amount
            ) >= $amount ? 'paid' : 'due',
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

        $payment = $this->branchPaymentQuery()->whereKey($id)->first();

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
