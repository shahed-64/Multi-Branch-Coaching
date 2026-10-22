<?php

namespace App\Http\Controllers;

use App\Models\OtherPayment;
use App\Models\Student;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OtherPaymentController extends Controller
{
    private function staff()
    {
        return request()->user();
    }

    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    private function authorizeAccess(): void
    {
        $user = $this->staff();

        if (!$user || !in_array($user->role, [
            'Manager',
            'Branch Manager',
            'Branch Accountant',
        ], true)) {
            abort(403, 'You are not authorized to access other payments.');
        }

        if (
            in_array($user->role, ['Branch Manager', 'Branch Accountant'], true)
            && empty($user->branch_id)
        ) {
            abort(403, 'Branch is not assigned to this account.');
        }
    }

    private function studentAllowed(Student $student): void
    {
        $user = $this->staff();
        $branchId = $this->currentBranchId();

        if ($user->role === 'Manager') {
            if ($branchId !== null && (int) $student->branch_id !== $branchId) {
                abort(403, 'Student does not belong to the selected branch.');
            }

            return;
        }

        if ((int) $student->branch_id !== (int) $user->branch_id) {
            abort(403, 'You cannot access students from another branch.');
        }
    }

    private function branchOtherPaymentQuery()
    {
        $user = $this->staff();
        $branchId = $this->currentBranchId();

        $query = OtherPayment::query();

        if ($user->role === 'Manager') {
            if ($branchId !== null) {
                $query->whereHas('student', function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                });
            }

            return $query;
        }

        return $query->whereHas('student', function ($q) use ($user) {
            $q->where('branch_id', $user->branch_id);
        });
    }

    private function validateStudentSession(
        int $studentId,
        int $studentSessionId
    ): StudentSession {
        $session = StudentSession::with('student')->findOrFail($studentSessionId);

        if ((int) $session->student_id !== $studentId) {
            throw ValidationException::withMessages([
                'student_session_id' => [
                    'Selected academic session does not belong to this student.',
                ],
            ]);
        }

        if (!$session->student) {
            throw ValidationException::withMessages([
                'student_id' => ['Student record was not found.'],
            ]);
        }

        $this->studentAllowed($session->student);

        return $session;
    }
public function ledger(Request $request)
{
    $this->authorizeAccess();

    $validated = $request->validate([
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
    ]);

    $payments = $this->branchOtherPaymentQuery()
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
        ->where('total_amount', '>', 0)
        ->orderBy('payment_date')
        ->orderBy('id')
        ->get();

    return response()->json([
        'status' => true,
        'message' => 'Other payment ledger fetched successfully.',
        'payments' => $payments,
    ]);
}
    public function index()
    {
        $this->authorizeAccess();

        return $this->branchOtherPaymentQuery()
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
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'student_session_id' => [
                'required',
                'integer',
                'exists:student_sessions,id',
            ],
            'payment_type' => [
                'required',
                'string',
                Rule::in(['Other', 'Admission Fee', 'Exam Fee']),
            ],
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $this->validateStudentSession(
            (int) $validated['student_id'],
            (int) $validated['student_session_id']
        );

        $quantity = (int) $validated['quantity'];
        $price = (float) $validated['price'];

        $payment = OtherPayment::create([
            'student_id' => $validated['student_id'],
            'student_session_id' => $validated['student_session_id'],
            'payment_type' => $validated['payment_type'],
            'item_name' => $validated['item_name'],
            'quantity' => $quantity,
            'price' => $price,
            'total_amount' => round($quantity * $price, 2),
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Other payment created successfully.',
            'data' => $payment->load([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ]),
        ], 201);
    }

    public function show(OtherPayment $otherPayment)
    {
        $this->authorizeAccess();

        $payment = $this->branchOtherPaymentQuery()
            ->whereKey($otherPayment->id)
            ->with([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ])
            ->firstOrFail();

        return $payment;
    }

    public function update(Request $request, OtherPayment $otherPayment)
    {
        $this->authorizeAccess();

        $payment = $this->branchOtherPaymentQuery()
            ->whereKey($otherPayment->id)
            ->firstOrFail();

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                'exists:students,id',
            ],
            'student_session_id' => [
                'required',
                'integer',
                'exists:student_sessions,id',
            ],
            'payment_type' => [
                'required',
                'string',
                Rule::in(['Other', 'Admission Fee', 'Exam Fee']),
            ],
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $this->validateStudentSession(
            (int) $validated['student_id'],
            (int) $validated['student_session_id']
        );

        $quantity = (int) $validated['quantity'];
        $price = (float) $validated['price'];

        $payment->update([
            'student_id' => $validated['student_id'],
            'student_session_id' => $validated['student_session_id'],
            'payment_type' => $validated['payment_type'],
            'item_name' => $validated['item_name'],
            'quantity' => $quantity,
            'price' => $price,
            'total_amount' => round($quantity * $price, 2),
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Other payment updated successfully.',
            'data' => $payment->load([
                'student',
                'studentSession.academicSession',
                'studentSession.classInfo',
                'studentSession.section',
                'studentSession.shift',
                'studentSession.classGroup',
            ]),
        ]);
    }

    public function destroy(OtherPayment $otherPayment)
    {
        $this->authorizeAccess();

        $payment = $this->branchOtherPaymentQuery()
            ->whereKey($otherPayment->id)
            ->firstOrFail();

        $payment->delete();

        return response()->json([
            'message' => 'Other payment deleted successfully.',
        ]);
    }
}
