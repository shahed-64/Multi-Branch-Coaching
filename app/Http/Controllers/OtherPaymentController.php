<?php

namespace App\Http\Controllers;

use App\Models\OtherPayment;
use App\Models\Student;
use App\Services\BranchContext;
use Illuminate\Http\Request;

class OtherPaymentController extends Controller
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
     * CURRENT BRANCH CONTEXT
     * --------------------------------------------------------------------------
     *
     * Manager + All Branches       = null
     * Manager + Selected Branch    = branch id
     * Other branch-restricted user = own branch through staff->branch_id
     * --------------------------------------------------------------------------
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * --------------------------------------------------------------------------
     * AUTHORIZE OTHER PAYMENT ACCESS
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
                'message' => 'You are not authorized to access other payments.',
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
     * OTHER PAYMENT QUERY WITH BRANCH ISOLATION
     * --------------------------------------------------------------------------
     *
     * Manager + All Branches
     *     -> All branches
     *
     * Manager + Selected Branch
     *     -> Selected branch only
     *
     * Branch Manager
     *     -> Own branch only
     *
     * Branch Accountant
     *     -> Own branch only
     * --------------------------------------------------------------------------
     */
    private function branchOtherPaymentQuery()
    {
        $query = OtherPayment::query();

        $staff = $this->staff();

        if (!$staff) {
            return $query->whereRaw('1 = 0');
        }

        /**
         * Manager
         */
        if ($staff->role === 'Manager') {

            $currentBranchId = $this->currentBranchId();

            /**
             * All Branches
             */
            if ($currentBranchId === null) {
                return $query;
            }

            /**
             * Selected Branch
             */
            return $query->whereHas('student', function ($q) use ($currentBranchId) {
                $q->where('branch_id', $currentBranchId);
            });
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array($staff->role, [
                'Branch Manager',
                'Branch Accountant',
            ])
        ) {
            if (empty($staff->branch_id)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('student', function ($q) use ($staff) {
                $q->where('branch_id', $staff->branch_id);
            });
        }

        /**
         * Any unauthorized role gets no data.
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

        if (!$staff) {
            return false;
        }

        /**
         * Manager
         */
        if ($staff->role === 'Manager') {

            $currentBranchId = $this->currentBranchId();

            /**
             * All Branches
             */
            if ($currentBranchId === null) {
                return true;
            }

            /**
             * Selected Branch
             */
            return (int) $student->branch_id === (int) $currentBranchId;
        }

        /**
         * Branch Manager / Branch Accountant
         * = Own Branch Only
         */
        if (
            in_array($staff->role, [
                'Branch Manager',
                'Branch Accountant',
            ])
        ) {
            return !empty($staff->branch_id)
                && (int) $student->branch_id === (int) $staff->branch_id;
        }

        /**
         * All other roles are blocked.
         */
        return false;
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY ALL OTHER PAYMENTS
     * --------------------------------------------------------------------------
     */
    public function index()
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $otherPayments = $this->branchOtherPaymentQuery()
            ->with('student')
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $otherPayments,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STORE OTHER PAYMENT
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
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
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
                'message' => 'You cannot create other payment for this branch.',
            ], 403);
        }

        /**
         * Create Other Payment
         */
        $otherPayment = OtherPayment::create([
            'student_id' => $request->student_id,
            'item_name' => $request->item_name,
            'quantity' => $request->quantity,
            'price' => $request->price,
            'total_amount' =>
                $request->quantity * $request->price,
            'payment_method' => $request->payment_method,
            'payment_date' => $request->payment_date,
            'remarks' => $request->remarks,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Added Successfully.',
            'data' => $otherPayment->load('student'),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY SINGLE OTHER PAYMENT
     * --------------------------------------------------------------------------
     */
    public function show(string $id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $otherPayment = $this->branchOtherPaymentQuery()
            ->with('student')
            ->where('id', $id)
            ->first();

        if (!$otherPayment) {
            return response()->json([
                'status' => false,
                'message' => 'Other Payment not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $otherPayment,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * UPDATE OTHER PAYMENT
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        string $id
    ) {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Find Payment Within Allowed Branch
         */
        $otherPayment = $this->branchOtherPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$otherPayment) {
            return response()->json([
                'status' => false,
                'message' => 'Other Payment not found',
            ], 404);
        }

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        /**
         * Check New Student Branch
         */
        $student = Student::findOrFail(
            $request->student_id
        );

        if (!$this->studentAllowed($student)) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot move this payment to another branch.',
            ], 403);
        }

        /**
         * Update
         */
        $otherPayment->update([
            'student_id' => $request->student_id,
            'item_name' => $request->item_name,
            'quantity' => $request->quantity,
            'price' => $request->price,
            'total_amount' =>
                $request->quantity * $request->price,
            'payment_method' => $request->payment_method,
            'payment_date' => $request->payment_date,
            'remarks' => $request->remarks,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Updated Successfully.',
            'data' => $otherPayment->load('student'),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DELETE OTHER PAYMENT
     * --------------------------------------------------------------------------
     */
    public function destroy(string $id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $otherPayment = $this->branchOtherPaymentQuery()
            ->where('id', $id)
            ->first();

        if (!$otherPayment) {
            return response()->json([
                'status' => false,
                'message' => 'Other Payment not found',
            ], 404);
        }

        $otherPayment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Deleted Successfully.',
        ]);
    }
}
