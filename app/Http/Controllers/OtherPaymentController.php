<?php

namespace App\Http\Controllers;


use App\Models\OtherPayment;
use App\Models\Student;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtherPaymentController extends Controller
{
    /**
     * Current logged-in staff.
     */
    private function staff()
    {
        return request()->user();
    }

    /**
     * Current branch context.
     *
     * Manager + All Branches = null
     * Manager + Selected Branch = branch ID
     * Other branch-restricted users = selected own branch
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * Authorize Other Payment access.
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
     * Check whether the student belongs to an allowed branch.
     */
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
     * Other Payment query with branch isolation.
     */
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

    /**
     * Validate that the selected session belongs to the student
     * and is within the user's allowed branch.
     */
    private function validateStudentSession(
        int $studentId,
        int $studentSessionId
    ): StudentSession {
        $student = Student::findOrFail($studentId);

        if (!$this->studentAllowed($student)) {
            abort(response()->json([
                'status' => false,
                'message' => 'You cannot access this student.',
            ], 403));
        }

        $session = StudentSession::query()
            ->where('id', $studentSessionId)
            ->where('student_id', $studentId)
            ->first();

        if (!$session) {
            throw ValidationException::withMessages([
                'student_session_id' =>
                    'The selected session does not belong to this student.',
            ]);
        }

        if ((int) $session->branch_id !== (int) $student->branch_id) {
            throw ValidationException::withMessages([
                'student_session_id' =>
                    'The selected session does not belong to the student branch.',
            ]);
        }

        if (
            $this->staff()->role !== 'Manager'
            && (int) $session->branch_id !== (int) $this->staff()->branch_id
        ) {
            abort(response()->json([
                'status' => false,
                'message' => 'You cannot access a session from another branch.',
            ], 403));
        }

        if ($this->staff()->role === 'Manager') {
            $branchId = $this->currentBranchId();

            if (
                $branchId !== null
                && (int) $session->branch_id !== (int) $branchId
            ) {
                abort(response()->json([
                    'status' => false,
                    'message' => 'The selected session is outside the current branch.',
                ], 403));
            }
        }

        return $session;
    }

    /**
     * DISPLAY ALL OTHER PAYMENTS.
     */
    public function index()
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $otherPayments = $this->branchOtherPaymentQuery()
            ->with([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ])
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $otherPayments,
        ]);
    }

    /**
     * STORE OTHER PAYMENT.
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
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $student = Student::findOrFail($validated['student_id']);

        if (!$this->studentAllowed($student)) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot create other payment for this branch.',
            ], 403);
        }

        $this->validateStudentSession(
            (int) $validated['student_id'],
            (int) $validated['student_session_id']
        );

        $otherPayment = OtherPayment::create([
            'student_id' => $validated['student_id'],
            'student_session_id' => $validated['student_session_id'],
            'item_name' => $validated['item_name'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'total_amount' =>
                $validated['quantity'] * $validated['price'],
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Added Successfully.',
            'data' => $otherPayment->load([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ]),
        ], 201);
    }

    /**
     * DISPLAY SINGLE OTHER PAYMENT.
     */
    public function show(string $id)
    {
        $authorization = $this->authorizeAccess();

        if ($authorization) {
            return $authorization;
        }

        $otherPayment = $this->branchOtherPaymentQuery()
            ->with([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ])
            ->where('id', $id)
            ->first();

        if (!$otherPayment) {
            return response()->json([
                'status' => false,
                'message' => 'Other Payment not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $otherPayment,
        ]);
    }

    /**
     * UPDATE OTHER PAYMENT.
     */
    public function update(Request $request, string $id)
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
                'message' => 'Other Payment not found.',
            ], 404);
        }

        $validated = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'student_session_id' => 'required|integer|exists:student_sessions,id',
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $student = Student::findOrFail($validated['student_id']);

        if (!$this->studentAllowed($student)) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot move this payment to another branch.',
            ], 403);
        }

        $this->validateStudentSession(
            (int) $validated['student_id'],
            (int) $validated['student_session_id']
        );

        $otherPayment->update([
            'student_id' => $validated['student_id'],
            'student_session_id' => $validated['student_session_id'],
            'item_name' => $validated['item_name'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'total_amount' =>
                $validated['quantity'] * $validated['price'],
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Updated Successfully.',
            'data' => $otherPayment->load([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ]),
        ]);
    }

    /**
     * DELETE OTHER PAYMENT.
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
                'message' => 'Other Payment not found.',
            ], 404);
        }

        $otherPayment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Other Payment Deleted Successfully.',
        ]);
    }
}
