<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Branch;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicSessionController extends Controller
{
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    private function isManager(Request $request): bool
    {
        return $request->user()?->role === 'Manager';
    }

    private function canAccess(
        Request $request,
        AcademicSession $academicSession
    ): bool {
        if ($this->isManager($request)) {
            $branchId = $this->currentBranchId();

            return $branchId === null
                || $branchId === (int) $academicSession->branch_id;
        }

        return (int) $request->user()->branch_id
            === (int) $academicSession->branch_id;
    }

    public function index(Request $request)
    {
        $query = AcademicSession::with('branch:id,name');

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } elseif (!$this->isManager($request)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json(
            $query->orderByDesc('name')->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $branchContextId = $this->currentBranchId();

        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->isManager($request)) {
            $rules['branch_id'] = [
                Rule::requiredIf($branchContextId === null),
                'nullable',
                'integer',
                'exists:branches,id',
            ];
        }

        $data = $request->validate($rules);

        if ($this->isManager($request)) {
            $branchId = $branchContextId
                ?? (int) $data['branch_id'];

            if (!Branch::whereKey($branchId)->exists()) {
                return response()->json([
                    'message' => 'Branch not found.',
                ], 422);
            }
        } else {
            $branchId = (int) $user->branch_id;
        }

        $duplicate = AcademicSession::where('branch_id', $branchId)
            ->where('name', $data['name'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This academic session already exists for this branch.',
            ], 422);
        }

        $isActive = (bool) ($data['is_active'] ?? false);

        if ($isActive) {
            AcademicSession::where('branch_id', $branchId)
                ->update(['is_active' => false]);
        }

        $session = AcademicSession::create([
            'branch_id' => $branchId,
            'name' => $data['name'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'is_active' => $isActive,
        ]);

        return response()->json([
            'message' => 'Academic session created successfully.',
            'data' => $session->load('branch:id,name'),
        ], 201);
    }

    public function show(
        Request $request,
        AcademicSession $academicSession
    ) {
        if (!$this->canAccess($request, $academicSession)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json(
            $academicSession->load('branch:id,name')
        );
    }

    public function update(
        Request $request,
        AcademicSession $academicSession
    ) {
        if (!$this->canAccess($request, $academicSession)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $name = $data['name'] ?? $academicSession->name;

        $duplicate = AcademicSession::where(
            'branch_id',
            $academicSession->branch_id
        )
            ->where('name', $name)
            ->where('id', '!=', $academicSession->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'This academic session already exists for this branch.',
            ], 422);
        }

        if (($data['is_active'] ?? false) === true) {
            AcademicSession::where(
                'branch_id',
                $academicSession->branch_id
            )
                ->where('id', '!=', $academicSession->id)
                ->update(['is_active' => false]);
        }

        $academicSession->update($data);

        return response()->json([
            'message' => 'Academic session updated successfully.',
            'data' => $academicSession->fresh()->load('branch:id,name'),
        ]);
    }

    public function destroy(
        Request $request,
        AcademicSession $academicSession
    ) {
        if (!$this->canAccess($request, $academicSession)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        if ($academicSession->studentSessions()->exists()) {
            return response()->json([
                'message' => 'This session has student records and cannot be deleted.',
            ], 422);
        }

        $academicSession->delete();

        return response()->json([
            'message' => 'Academic session deleted successfully.',
        ]);
    }
}
