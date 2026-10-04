<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * CURRENT LOGGED-IN STAFF
     * --------------------------------------------------------------------------
     */
    private function staff()
    {
        return Auth::user()
            ?? request()->user('sanctum')
            ?? auth('sanctum')->user();
    }

    /**
     * --------------------------------------------------------------------------
     * CURRENT BRANCH CONTEXT
     * --------------------------------------------------------------------------
     *
     * Manager + All Branches
     *     = null
     *
     * Manager + Selected Branch
     *     = selected branch id
     *
     * Branch Manager / Branch Accountant
     *     = their own branch through staff->branch_id
     * --------------------------------------------------------------------------
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
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
     * EXPENSE AUTHORIZATION
     * --------------------------------------------------------------------------
     */
    private function authorizeExpenseAccess()
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
                'message' => 'You are not authorized to access expenses.',
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
     * EXPENSE QUERY WITH BRANCH ISOLATION
     * --------------------------------------------------------------------------
     *
     * Manager + All Branches
     *     -> All branches
     *
     * Manager + Selected Branch
     *     -> Selected branch only
     *
     * Branch Manager
     *     -> Own Branch Only
     *
     * Branch Accountant
     *     -> Own Branch Only
     * --------------------------------------------------------------------------
     */
    private function branchExpenseQuery()
    {
        $query = Expense::query();

        $staff = $this->staff();

        /**
         * No authenticated staff
         */
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
            return $query->where(
                'branch_id',
                $currentBranchId
            );
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
         * = No expense data
         */
        return $query->whereRaw('1 = 0');
    }

    /**
     * --------------------------------------------------------------------------
     * CHECK WHETHER STAFF CAN ACCESS THIS EXPENSE
     * --------------------------------------------------------------------------
     */
    private function expenseAllowed(Expense $expense): bool
    {
        $staff = $this->staff();

        /**
         * No authenticated staff
         */
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
            return (int) $expense->branch_id ===
                (int) $currentBranchId;
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
                && (int) $expense->branch_id ===
                (int) $staff->branch_id;
        }

        /**
         * Other roles
         */
        return false;
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY ALL EXPENSES
     * --------------------------------------------------------------------------
     */
    public function index()
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        return response()->json([
            'status' => true,
            'expenses' => $this->branchExpenseQuery()
                ->latest()
                ->get()
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STORE EXPENSE
     * --------------------------------------------------------------------------
     */
    public function store(Request $request)
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $request->validate([
            'expense_type' => 'required|string',
            'employee_name' => 'nullable|string|max:255',
            'salary_amount' => 'required|numeric',
            'paid_amount' => 'required|numeric',
            'payment_method' => 'required|string',
            'payment_month' => 'required',
            'payment_date' => 'nullable|date',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        /**
         * Payment month formatting
         */
        $paymentMonth = $request->payment_month
            ? (
                strlen($request->payment_month) == 7
                    ? $request->payment_month . '-01'
                    : $request->payment_month
            )
            : null;

        /**
         * Calculate due
         */
        $dueAmount =
            $request->salary_amount -
            $request->paid_amount;

        /**
         * Get authenticated user
         */
        $user = $this->staff();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        /**
         * Created by name
         */
        $createdByName =
            $user->name ?? 'System Admin';

        /**
         * ----------------------------------------------------------------------
         * BRANCH
         * ----------------------------------------------------------------------
         *
         * Manager:
         *     All Branches selected
         *         -> request branch_id
         *
         *     Specific branch selected
         *         -> selected context branch
         *
         * Branch Manager / Branch Accountant:
         *     Always own branch
         * ----------------------------------------------------------------------
         */
        if ($user->role === 'Manager') {

            $currentBranchId = $this->currentBranchId();

            /**
             * Manager + selected branch
             * = Context is authoritative
             */
            if ($currentBranchId !== null) {
                $branchId = $currentBranchId;
            } else {
                /**
                 * Manager + All Branches
                 * = request branch is allowed
                 */
                $branchId = $request->branch_id;
            }

        } elseif (
            in_array(
                $user->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {

            /**
             * Always own branch.
             * Request branch_id intentionally ignored.
             */
            $branchId = $user->branch_id;

        } else {

            /**
             * Normally blocked by authorizeExpenseAccess()
             */
            return response()->json([
                'status' => false,
                'message' => 'Access Denied'
            ], 403);
        }

        /**
         * Branch is required
         */
        if (!$branchId) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Branch is required for this expense.'
            ], 422);
        }

        /**
         * Create Expense
         */
        $expense = Expense::create([
            'expense_type' =>
                $request->expense_type,

            'employee_name' =>
                $request->employee_name,

            'salary_amount' =>
                $request->salary_amount,

            'paid_amount' =>
                $request->paid_amount,

            'due_amount' =>
                $dueAmount < 0
                    ? 0
                    : $dueAmount,

            'payment_month' =>
                $paymentMonth,

            'payment_method' =>
                $request->payment_method,

            'payment_date' =>
                $request->payment_date
                ?? now()->toDateString(),

            'created_by' =>
                $createdByName,

            'branch_id' =>
                $branchId,
        ]);

        return response()->json([
            'status' => true,
            'message' =>
                'Expense added successfully.',
            'expense' =>
                $expense
        ], 201);
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY SINGLE EXPENSE
     * --------------------------------------------------------------------------
     */
    public function show($id)
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $expense = $this->branchExpenseQuery()
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Expense not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'expense' => $expense
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * UPDATE EXPENSE
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        $id
    ) {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Only allowed branch expense
         * can be updated.
         */
        $expense = $this->branchExpenseQuery()
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Expense not found.'
            ], 404);
        }

        $request->validate([
            'expense_type' =>
                'required|string',

            'employee_name' =>
                'nullable|string|max:255',

            'salary_amount' =>
                'required|numeric',

            'paid_amount' =>
                'required|numeric',

            'payment_method' =>
                'required|string',

            'payment_month' =>
                'nullable',

            'payment_date' =>
                'nullable|date',
        ]);

        /**
         * Calculate due
         */
        $dueAmount =
            $request->salary_amount -
            $request->paid_amount;

        /**
         * Payment month formatting
         */
        $paymentMonth = $request->payment_month
            ? (
                strlen($request->payment_month) == 7
                    ? $request->payment_month . '-01'
                    : $request->payment_month
            )
            : $expense->payment_month;

        /**
         * Update expense
         *
         * IMPORTANT:
         * branch_id is NOT updated here.
         */
        $expense->update([
            'expense_type' =>
                $request->expense_type,

            'employee_name' =>
                $request->employee_name,

            'salary_amount' =>
                $request->salary_amount,

            'paid_amount' =>
                $request->paid_amount,

            'due_amount' =>
                $dueAmount < 0
                    ? 0
                    : $dueAmount,

            'payment_month' =>
                $paymentMonth,

            'payment_method' =>
                $request->payment_method,

            'payment_date' =>
                $request->payment_date
                ?? $expense->payment_date
                ?? now()->toDateString(),
        ]);

        return response()->json([
            'status' => true,
            'message' =>
                'Expense updated successfully.',
            'expense' =>
                $expense
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * GET TEACHERS FOR EXPENSE SALARY AUTO-FILL
     * --------------------------------------------------------------------------
     */
    public function getteachers()
    {
        try {

            $authorization = $this->authorizeExpenseAccess();

            if ($authorization) {
                return $authorization;
            }

            $user = $this->staff();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Unauthenticated'
                ], 401);
            }

            $query =
                \App\Models\Teacher::query();

            /**
             * Manager
             */
            if ($user->role === 'Manager') {

                $currentBranchId = $this->currentBranchId();

                /**
                 * All Branches
                 */
                if ($currentBranchId === null) {
                    // All teachers
                } else {
                    /**
                     * Selected Branch
                     */
                    $query->where(
                        'branch_id',
                        $currentBranchId
                    );
                }

            /**
             * Branch Manager / Branch Accountant
             */
            } elseif (
                in_array(
                    $user->role,
                    [
                        'Branch Manager',
                        'Branch Accountant',
                    ]
                )
            ) {

                /**
                 * Own branch only
                 */
                if (empty($user->branch_id)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(
                        'branch_id',
                        $user->branch_id
                    );
                }

            } else {

                /**
                 * Other roles
                 */
                $query->whereRaw('1 = 0');
            }

            $teachers = $query->get();

            return response()->json([
                'status' => true,
                'teachers' => $teachers
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' =>
                    $e->getMessage()
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * GET STAFF FOR EXPENSE SALARY AUTO-FILL
     * --------------------------------------------------------------------------
     */
    public function getStaffs()
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $user = $this->staff();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Unauthenticated'
            ], 401);
        }

        /**
         * Manager excluded from employee list
         */
        $query = \App\Models\Staff::where(
            'role',
            '!=',
            'Manager'
        );

        /**
         * Manager
         */
        if ($user->role === 'Manager') {

            $currentBranchId = $this->currentBranchId();

            /**
             * All Branches
             */
            if ($currentBranchId === null) {
                // All branch staff
            } else {
                /**
                 * Selected Branch
                 */
                $query->where(
                    'branch_id',
                    $currentBranchId
                );
            }

        /**
         * Branch Manager / Branch Accountant
         */
        } elseif (
            in_array(
                $user->role,
                [
                    'Branch Manager',
                    'Branch Accountant',
                ]
            )
        ) {

            /**
             * Own branch only
             */
            if (empty($user->branch_id)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(
                    'branch_id',
                    $user->branch_id
                );
            }

        } else {

            /**
             * Other roles
             */
            $query->whereRaw('1 = 0');
        }

        $staffs = $query
            ->select(
                'id',
                'user_name',
                'salary',
                'branch_id'
            )
            ->get();

        return response()->json([
            'status' => true,
            'staffs' => $staffs
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DELETE EXPENSE
     * --------------------------------------------------------------------------
     */
    public function destroy($id)
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        /**
         * Only allowed branch expense
         * can be deleted.
         */
        $expense = $this->branchExpenseQuery()
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Expense not found.'
            ], 404);
        }

        $expense->delete();

        return response()->json([
            'status' => true,
            'message' =>
                'Expense deleted successfully.'
        ]);
    }
}
