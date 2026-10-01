<?php

namespace App\Http\Controllers;

use App\Models\Expense;
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
     *
     * Manager
     *      -> Full Expense access
     *
     * Branch Manager
     *      -> Own branch only
     *
     * Branch Accountant
     *      -> Own branch only
     *
     * Other roles
     *      -> No Expense access
     *
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
     * Manager
     *      -> All Branches
     *
     * Branch Manager
     *      -> Own Branch Only
     *
     * Branch Accountant
     *      -> Own Branch Only
     *
     * Other roles
     *      -> No expense data
     *
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
             * No branch assigned
             */
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
         * = No financial data
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
                && (int) $expense->branch_id === (int) $staff->branch_id;
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
         * Manager
         * -> Can create expense for any branch.
         *
         * Branch Manager / Branch Accountant
         * -> Always own branch.
         *
         * ----------------------------------------------------------------------
         */
        if ($user->role === 'Manager') {

            $branchId =
                $request->branch_id
                ?? $user->branch_id;

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
             * Branch Manager / Branch Accountant
             * -> Always own branch.
             *
             * Request branch_id is intentionally ignored.
             */
            $branchId =
                $user->branch_id;

        } else {

            /**
             * This should normally never execute because
             * authorizeExpenseAccess() already blocks them.
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
         *
         * This prevents a Branch Accountant/Manager
         * from moving an expense into another branch.
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
             * -> All branches
             */
            if ($user->role === 'Manager') {

                // All teachers

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
                 * Branch roles
                 * -> Own branch only
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
         * -> All branches
         */
        if ($user->role === 'Manager') {

            // All branch staff

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
             * Branch roles
             * -> Own branch only
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
