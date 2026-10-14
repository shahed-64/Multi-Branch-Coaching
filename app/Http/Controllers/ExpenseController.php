<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\AcademicSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    private function staff()
    {
        return Auth::user()
            ?? request()->user('sanctum')
            ?? auth('sanctum')->user();
    }

    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

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
            ]) && empty($staff->branch_id)
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your branch is not assigned.',
            ], 403);
        }

        return null;
    }

    /**
     * Branch-isolated expense query.
     */
    private function branchExpenseQuery()
    {
        $query = Expense::query();
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
            return $query->where(
                'branch_id',
                $staff->branch_id
            );
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Validate that the academic session belongs
     * to the branch where the expense is recorded.
     */
    private function sessionBelongsToBranch(
        int $sessionId,
        int $branchId
    ): bool {
        return AcademicSession::query()
            ->where('id', $sessionId)
            ->where('branch_id', $branchId)
            ->exists();
    }

    /**
     * GET /expenses
     */
    public function index()
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $expenses = $this->branchExpenseQuery()
            ->with([
                'branch:id,name',
                'academicSession:id,name,branch_id,is_active',
            ])
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'expenses' => $expenses,
        ]);
    }

    /**
     * POST /expenses
     */
    public function store(Request $request)
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $request->validate([
            'expense_type' => 'required|string|max:255',
            'employee_name' => 'nullable|string|max:255',
            'salary_amount' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:Cash,Bkash,Nagad,Bank',
            'payment_month' => [
                'required',
                'date_format:Y-m',
            ],
            'payment_date' => 'nullable|date',
            'academic_session_id' => 'required|integer|exists:academic_sessions,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $user = $this->staff();

        /*
         * Resolve branch using the existing branch rules.
         */
        if ($user->role === 'Manager') {
            $contextBranchId = $this->currentBranchId();

            if ($contextBranchId !== null) {
                $branchId = $contextBranchId;
            } else {
                $branchId = $request->branch_id;
            }
        } elseif (in_array($user->role, [
            'Branch Manager',
            'Branch Accountant',
        ])) {
            $branchId = $user->branch_id;
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Access Denied.',
            ], 403);
        }

        if (!$branchId) {
            return response()->json([
                'status' => false,
                'message' => 'Branch is required for this expense.',
            ], 422);
        }

        /*
         * Session must belong to the resolved branch.
         */
        if (!$this->sessionBelongsToBranch(
            (int) $request->academic_session_id,
            (int) $branchId
        )) {
            return response()->json([
                'status' => false,
                'message' => 'The selected academic session does not belong to this branch.',
            ], 422);
        }

        $salary = (float) $request->salary_amount;
        $paid = (float) $request->paid_amount;

        $paymentMonth = $request->payment_month . '-01';

        $expense = Expense::create([
            'expense_type' => $request->expense_type,
            'employee_name' => $request->employee_name,
            'salary_amount' => $salary,
            'paid_amount' => $paid,
            'due_amount' => max(0, $salary - $paid),
            'payment_month' => $paymentMonth,
            'payment_method' => $request->payment_method,
            'payment_date' => $request->payment_date
                ?? now()->toDateString(),
            'created_by' => $user->name ?? 'System Admin',
            'branch_id' => $branchId,
            'academic_session_id' => $request->academic_session_id,
        ]);

        $expense->load([
            'branch:id,name',
            'academicSession:id,name,branch_id,is_active',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Expense added successfully.',
            'expense' => $expense,
        ], 201);
    }

    /**
     * GET /expenses/{id}
     */
    public function show($id)
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $expense = $this->branchExpenseQuery()
            ->with([
                'branch:id,name',
                'academicSession:id,name,branch_id,is_active',
            ])
            ->where('id', $id)
            ->first();

        if (!$expense) {
            return response()->json([
                'status' => false,
                'message' => 'Expense not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'expense' => $expense,
        ]);
    }

    /**
     * PUT /expenses/{id}
     */
    public function update(Request $request, $id)
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
                'message' => 'Expense not found.',
            ], 404);
        }

        $request->validate([
            'expense_type' => 'required|string|max:255',
            'employee_name' => 'nullable|string|max:255',
            'salary_amount' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:Cash,Bkash,Nagad,Bank',
            'payment_month' => 'required|date_format:Y-m',
            'payment_date' => 'nullable|date',
            'academic_session_id' => 'required|integer|exists:academic_sessions,id',
        ]);

        /*
         * Always validate against the expense's existing branch.
         * Updating an expense must not move it to another branch.
         */
        if (!$this->sessionBelongsToBranch(
            (int) $request->academic_session_id,
            (int) $expense->branch_id
        )) {
            return response()->json([
                'status' => false,
                'message' => 'The selected academic session does not belong to this expense branch.',
            ], 422);
        }

        $salary = (float) $request->salary_amount;
        $paid = (float) $request->paid_amount;

        $expense->update([
            'expense_type' => $request->expense_type,
            'employee_name' => $request->employee_name,
            'salary_amount' => $salary,
            'paid_amount' => $paid,
            'due_amount' => max(0, $salary - $paid),
            'payment_month' => $request->payment_month . '-01',
            'payment_method' => $request->payment_method,
            'payment_date' => $request->payment_date
                ?? $expense->payment_date
                ?? now()->toDateString(),
            'academic_session_id' => $request->academic_session_id,
        ]);

        $expense->load([
            'branch:id,name',
            'academicSession:id,name,branch_id,is_active',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Expense updated successfully.',
            'expense' => $expense,
        ]);
    }

    /**
     * GET /expense-teachers
     */
    public function getteachers()
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $user = $this->staff();

        $query = \App\Models\Teacher::query();

        if ($user->role === 'Manager') {
            $branchId = $this->currentBranchId();

            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
        } else {
            $query->where('branch_id', $user->branch_id);
        }

        return response()->json([
            'status' => true,
            'teachers' => $query->get(),
        ]);
    }

    /**
     * GET /expense-staffs
     */
    public function getStaffs()
    {
        $authorization = $this->authorizeExpenseAccess();

        if ($authorization) {
            return $authorization;
        }

        $user = $this->staff();

        $query = \App\Models\Staff::query()
            ->where('role', '!=', 'Manager');

        if ($user->role === 'Manager') {
            $branchId = $this->currentBranchId();

            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
        } else {
            $query->where('branch_id', $user->branch_id);
        }

        return response()->json([
            'status' => true,
            'staffs' => $query
                ->select('id', 'user_name', 'salary', 'branch_id')
                ->get(),
        ]);
    }

    /**
     * DELETE /expenses/{id}
     */
    public function destroy($id)
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
                'message' => 'Expense not found.',
            ], 404);
        }

        $expense->delete();

        return response()->json([
            'status' => true,
            'message' => 'Expense deleted successfully.',
        ]);
    }
}
