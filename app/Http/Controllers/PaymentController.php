<?php

namespace App\Http\Controllers;

use App\Models\OtherPayment;
use App\Models\Payment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * CURRENT LOGGED-IN STAFF
     * --------------------------------------------------------------------------
     */
    private function staff()
    {
        return request()->user();
    }

    /**
     * --------------------------------------------------------------------------
     * AUTHORIZATION
     * --------------------------------------------------------------------------
     *
     * Allowed roles:
     * Manager
     * Branch Manager
     * Branch Accountant
     *
     * Blocked:
     * Admin
     * Branch Admin
     * Accountant
     * Any other role
     * --------------------------------------------------------------------------
     */
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
            ])
            && empty($staff->branch_id)
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your branch is not assigned.',
            ], 403);
        }

        return null;
    }

    /**
     * --------------------------------------------------------------------------
     * MANAGER CHECK
     * --------------------------------------------------------------------------
     * Manager = All Branch Access
     * --------------------------------------------------------------------------
     */
    private function isManager(): bool
    {
        return $this->staff()?->role === 'Manager';
    }

    /**
     * --------------------------------------------------------------------------
     * BRANCH RESTRICTED ROLES
     * --------------------------------------------------------------------------
     */
    private function isBranchRestricted(): bool
    {
        return in_array(
            $this->staff()?->role,
            [
                'Branch Manager',
                'Branch Accountant',
            ]
        );
    }

    /**
     * --------------------------------------------------------------------------
     * PAYMENT QUERY WITH BRANCH ISOLATION
     * --------------------------------------------------------------------------
     *
     * Manager
     *      -> All Branches
     *
     * Branch Manager
     *      -> Own Branch
     *
     * Branch Accountant
     *      -> Own Branch
     *
     * Any other role
     *      -> No payment data
     *
     * --------------------------------------------------------------------------
     */
    private function branchPaymentQuery()
    {
        $query = Payment::query();
        $staff = $this->staff();

        /**
         * No authenticated staff
         */
        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        /**
         * Manager = All Branches
         */
        if ($staff->role === 'Manager') {
            return $query;
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array(
                $staff->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {
            /**
             * Staff must have a branch
             */
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        /**
         * Any other role
         * = No financial data
         */
        return $query->whereRaw('1 = 0');
    }

    /**
     * --------------------------------------------------------------------------
     * STUDENT QUERY WITH BRANCH ISOLATION
     * --------------------------------------------------------------------------
     */
    private function branchStudentQuery()
    {
        $query = Student::query();
        $staff = $this->staff();

        /**
         * No authenticated staff
         */
        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        /**
         * Manager = All Branches
         */
        if ($staff->role === 'Manager') {
            return $query;
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array(
                $staff->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(
                'branch_id',
                $staff->branch_id
            );
        }

        /**
         * Any other role
         * = No student data
         */
        return $query->whereRaw('1 = 0');
    }

    /**
     * --------------------------------------------------------------------------
     * OTHER PAYMENT QUERY WITH BRANCH ISOLATION
     * --------------------------------------------------------------------------
     *
     * OtherPayment does not have branch_id directly.
     *
     * OtherPayment
     *      -> student
     *          -> branch_id
     *
     * Manager
     *      -> All Branches
     *
     * Branch Manager
     *      -> Own Branch
     *
     * Branch Accountant
     *      -> Own Branch
     *
     * --------------------------------------------------------------------------
     */
    private function branchOtherPaymentQuery()
    {
        $query = OtherPayment::query();
        $staff = $this->staff();

        /**
         * No authenticated staff
         */
        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        /**
         * Manager = All Branches
         */
        if ($staff->role === 'Manager') {
            return $query;
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array(
                $staff->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        /**
         * Any other role
         * = No Other Payment data
         */
        return $query->whereRaw('1 = 0');
    }

    /**
     * --------------------------------------------------------------------------
     * CHECK STUDENT BELONGS TO STAFF'S ALLOWED BRANCH
     * --------------------------------------------------------------------------
     */
    private function studentAllowed(Student $student): bool
    {
        $staff = $this->staff();

        /**
         * No authenticated staff
         */
        if (!$staff) {
            return false;
        }

        /**
         * Manager = All Branches
         */
        if ($staff->role === 'Manager') {
            return true;
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array(
                $staff->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {
            return !empty($staff->branch_id)
                && (int) $student->branch_id === (int) $staff->branch_id;
        }

        /**
         * Any other role
         */
        return false;
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY PAYMENTS
     * --------------------------------------------------------------------------
     */
    public function index(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Branch-isolated payment query
         */
        $paymentQuery = $this->branchPaymentQuery();

        /**
         * Total Paid Amount
         */
        $totalPaidAmount = (clone $paymentQuery)
            ->sum('paid_amount');

        /**
         * Total Due Amount
         */
        $totalDueAmount = (clone $paymentQuery)
            ->sum('due_amount');

        /**
         * Total Students
         */
        $totalStudents = $this->branchStudentQuery()
            ->count();

        /**
         * Due Students
         */
        $dueStudents = (clone $paymentQuery)
            ->where('due_amount', '>', 0)
            ->distinct('student_id')
            ->count('student_id');

        /**
         * Today's Student Collection
         */
        $todayCollection = (clone $paymentQuery)
            ->whereDate('payment_date', today())
            ->sum('paid_amount');

        /**
         * Today's Other Payment
         * Branch isolated
         */
        $todayOtherCollection = (clone $this->branchOtherPaymentQuery())
            ->whereDate('payment_date', today())
            ->sum('total_amount');

        /**
         * Today's Exam Fee
         */
        $todayExamfee = (clone $paymentQuery)
            ->whereDate('payment_date', today())
            ->sum('exam_fee');

        /**
         * Today's Admission Fee
         */
        $todayadmissionFee = (clone $paymentQuery)
            ->whereDate('payment_date', today())
            ->sum('admission_fee');

        /**
         * Complete Today's Collection
         */
        $todayCollection +=
            $todayOtherCollection +
            $todayExamfee +
            $todayadmissionFee;

        /**
         * This Month Student Collection
         *
         * paid_amount
         * + admission_fee
         * + exam_fee
         */
        $thisMonthCollectionQuery = (clone $paymentQuery)
            ->whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear(
                'payment_date',
                now()->year
            )
            ->select(
                DB::raw(
                    'SUM(
                        COALESCE(paid_amount, 0) +
                        COALESCE(admission_fee, 0) +
                        COALESCE(exam_fee, 0)
                    ) as total'
                )
            )
            ->value('total');

        $thisMonthCollection = (float) $thisMonthCollectionQuery;

        /**
         * This Month Other Payment
         * Branch isolated
         */
        $otherPaymentCollection = (clone $this->branchOtherPaymentQuery())
            ->whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear(
                'payment_date',
                now()->year
            )
            ->sum('total_amount');

        /**
         * Complete This Month Collection
         */
        $thisMonthCollection += $otherPaymentCollection;

        /**
         * This Month Due
         */
        $thisMonthDue = (clone $paymentQuery)
            ->whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear(
                'payment_date',
                now()->year
            )
            ->sum('due_amount');

        /**
         * Monthly Payments
         */
        $monthlyPayments = (clone $paymentQuery)
            ->select(
                DB::raw('MONTH(payment_date) as month'),
                DB::raw('SUM(paid_amount) as total')
            )
            ->whereYear(
                'payment_date',
                date('Y')
            )
            ->groupBy(
                DB::raw('MONTH(payment_date)')
            )
            ->orderBy('month')
            ->get();

        /**
         * Recent Payments
         */
        $recentPayments = (clone $paymentQuery)
            ->with('student')
            ->whereDate(
                'payment_date',
                '>=',
                Carbon::today()->subDays(6)
            )
            ->orderBy(
                'payment_date',
                'desc'
            )
            ->get();

        /**
         * Current Month
         */
        $currentMonth = now()->format('F');

        /**
         * Branch-isolated students
         */
        $studentQuery = $this->branchStudentQuery();

        /**
         * Students who have NOT paid current month
         */
        $runningMonthUnpaidStudents = (clone $studentQuery)
            ->whereNotExists(function ($query) use ($currentMonth) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_id',
                        'students.id'
                    )
                    ->where(
                        'payments.month',
                        $currentMonth
                    );
            })
            ->count();

        /**
         * Students who have NEVER made any payment
         */
        $totalUnpaidStudents = (clone $studentQuery)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_id',
                        'students.id'
                    );
            })
            ->count();

        /**
         * Pagination
         */
        $perPage = 20;

        $payments = (clone $paymentQuery)
            ->with([
                'student' => function ($q) {
                    $q->select(
                        'id',
                        'full_name',
                        'student_id',
                        'class_id',
                        'branch_id'
                    );
                }
            ])
            ->latest()
            ->paginate($perPage);

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
            'running_month_unpaid_students' =>
                $runningMonthUnpaidStudents,
            'total_unpaid_students' =>
                $totalUnpaidStudents,
            'monthly_payments' =>
                $monthlyPayments,
            'recent_payments' =>
                $recentPayments,
            'payments' =>
                $payments,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STORE PAYMENT
     * --------------------------------------------------------------------------
     */
    public function store(Request $request)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'amount' => 'required|numeric',
            'paid_amount' => 'required|numeric',
            'payment_method' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'month' => 'required|string',
            'admission_fee' => 'nullable|numeric',
            'exam_fee' => 'nullable|numeric',
        ]);

        /**
         * Get Student
         */
        $student = Student::findOrFail(
            $request->student_id
        );

        /**
         * Branch Security
         */
        if (!$this->studentAllowed($student)) {
            return response()->json([
                'status' => false,
                'message' =>
                    'You cannot create payment for this branch.'
            ], 403);
        }

        /**
         * Set monthly fee if empty
         */
        if (empty($student->monthly_fee)) {
            $student->monthly_fee =
                $request->amount;

            $student->save();
        }

        /**
         * Check duplicate payment
         */
        $exists = $this->branchPaymentQuery()
            ->where(
                'student_id',
                $request->student_id
            )
            ->where(
                'month',
                $request->month
            )
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Payment for this month already exists.'
            ], 422);
        }

        /**
         * Payment Amount
         */
        $amount = $student->monthly_fee;

        $due =
            $amount -
            $request->paid_amount;

        /**
         * Create Payment
         */
        $payment = Payment::create([
            'student_id' =>
                $request->student_id,
            'amount' =>
                $amount,
            'paid_amount' =>
                $request->paid_amount,
            'due_amount' =>
                $due,
            'payment_method' =>
                $request->payment_method,
            'payment_date' =>
                now()->toDateString(),
            'month' =>
                $request->month,
            'admission_fee' =>
                $request->admission_fee,
            'exam_fee' =>
                $request->exam_fee,
            'status' =>
                $due <= 0
                    ? 'paid'
                    : 'due',
        ]);

        $payment->load('student');

        return response()->json([
            'status' => true,
            'message' =>
                'Payment created successfully',
            'payment' =>
                $payment,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * SHOW SINGLE PAYMENT
     * --------------------------------------------------------------------------
     */
    public function show($id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $payment = $this->branchPaymentQuery()
            ->with([
                'student.classInfo',
                'student.section'
            ])
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Payment not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'payment' => $payment,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STUDENT PAYMENTS
     * --------------------------------------------------------------------------
     */
    public function studentPayments($id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Check student belongs to allowed branch
         */
        $student = $this->branchStudentQuery()
            ->where('id', $id)
            ->first();

        if (!$student) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Student not found'
            ], 404);
        }

        /**
         * Get student's payments
         */
        $payments = $this->branchPaymentQuery()
            ->with('student')
            ->where(
                'student_id',
                $id
            )
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'payments' => $payments,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STUDENT PAYMENT REPORT
     * --------------------------------------------------------------------------
     */
    public function studentPaymentReport()
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $students = $this->branchStudentQuery()
            ->with([
                'payments' => function ($query) {
                    /**
                     * Payment relationship is already loaded
                     * through branch-isolated students.
                     *
                     * No additional global branch data is allowed.
                     */
                    $query->latest();
                }
            ])
            ->get();

        $report = $students->map(
            function ($student) {
                $totalPaid =
                    $student->payments
                        ->sum('paid_amount');

                $totalDue =
                    $student->payments
                        ->sum('due_amount');

                $startDate =
                    Carbon::parse(
                        $student->admission_date
                    )->startOfMonth();

                $endDate =
                    Carbon::now()
                        ->startOfMonth();

                $paidMonths =
                    $student->payments
                        ->pluck('month')
                        ->toArray();

                $unpaidMonths = 0;

                while ($startDate <= $endDate) {
                    $monthName =
                        $startDate->format('F');

                    if (
                        !in_array(
                            $monthName,
                            $paidMonths
                        )
                    ) {
                        $unpaidMonths++;
                    }

                    $startDate->addMonth();
                }

                $unpaidAmount =
                    $unpaidMonths *
                    $student->monthly_fee;

                $totalOutstanding =
                    $totalDue +
                    $unpaidAmount;

                return [
                    'id' =>
                        $student->id,

                    'student_id' =>
                        $student->student_id,

                    'full_name' =>
                        $student->full_name,

                    'phone' =>
                        $student->phone,

                    'batch_name' =>
                        $student->batch_name ?? null,

                    'monthly_fee' =>
                        $student->monthly_fee,

                    'status' =>
                        $student->status ?? null,

                    'branch_id' =>
                        $student->branch_id,

                    'payments' =>
                        $student->payments,

                    'total_paid' =>
                        $totalPaid,

                    'total_due' =>
                        $totalDue,

                    'unpaid_months' =>
                        $unpaidMonths,

                    'unpaid_amount' =>
                        $unpaidAmount,

                    'total_outstanding' =>
                        $totalOutstanding,
                ];
            }
        );

        return response()->json([
            'status' => true,
            'message' =>
                'Student payment report fetched successfully',
            'students' =>
                $report,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * UPDATE PAYMENT
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        $id
    ) {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Get payment only from allowed branch
         */
        $payment = $this->branchPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Payment record not found'
            ], 404);
        }

        $request->validate([
            'paid_amount' =>
                'required|numeric',

            'payment_method' =>
                'required|string',

            'month' =>
                'required|string',

            'admission_fee' =>
                'nullable|numeric',

            'exam_fee' =>
                'nullable|numeric',
        ]);

        /**
         * Payment calculation
         */
        $totalAmount =
            $payment->amount;

        $paidAmount =
            $request->paid_amount;

        $dueAmount =
            $totalAmount -
            $paidAmount;

        /**
         * Update
         */
        $payment->update([
            'paid_amount' =>
                $paidAmount,

            'due_amount' =>
                $dueAmount,

            'payment_method' =>
                $request->payment_method,

            'month' =>
                $request->month,

            'admission_fee' =>
                $request->admission_fee
                    ?? $payment->admission_fee,

            'exam_fee' =>
                $request->exam_fee
                    ?? $payment->exam_fee,

            'status' =>
                $dueAmount <= 0
                    ? 'paid'
                    : 'due',
        ]);

        return response()->json([
            'status' => true,
            'message' =>
                'Payment updated successfully',
            'payment' =>
                $payment->load('student'),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DELETE PAYMENT
     * --------------------------------------------------------------------------
     */
    public function destroy($id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Only allowed branch payment can be deleted
         */
        $payment = $this->branchPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Payment record not found'
            ], 404);
        }

        $payment->delete();

        return response()->json([
            'status' => true,
            'message' =>
                'Payment deleted successfully',
        ]);
    }
}
