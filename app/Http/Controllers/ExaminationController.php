<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Services\BranchContext;
use Illuminate\Http\Request;

class ExaminationController extends Controller
{
    /**
     * Check Examination module access.
     *
     * Manager             → All branches / Selected branch
     * Branch Manager      → Own branch
     * Admin               → Own branch
     * Branch Admin        → Own branch
     * Accountant          → No access
     * Branch Accountant   → No access
     */
    private function authorizeAccess(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (in_array($user->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access examinations.',
            ], 403);
        }

        return null;
    }

    /**
     * Get current branch from central BranchContext.
     *
     * null = All Branches
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $query = Examination::orderBy('id', 'desc');

        $currentBranchId = $this->currentBranchId();

        // Selected branch → only that branch.
        // All Branches → no branch filter.
        if ($currentBranchId !== null) {
            $query->where('branch_id', $currentBranchId);
        }

        $examination = $query->get();

        return response()->json([
            'status' => true,
            'data' => $examination,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // API-এর ক্ষেত্রে প্রয়োজন নেই
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $request->validate([
            'examination_type' => 'required|string|max:255',
            'examination_year' => 'required|string|max:255',
            'exam_mark' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        /**
         * Determine Branch
         *
         * Selected Branch:
         *   BranchContext is authoritative.
         *
         * All Branches:
         *   Only Manager can choose branch_id.
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {

            // Selected branch is authoritative.
            $branchId = $currentBranchId;

        } else {

            // All Branches mode.
            if (!$user || $user->role !== 'Manager') {
                return response()->json([
                    'status' => false,
                    'message' => 'Your account is not assigned to any branch.',
                ], 422);
            }

            if (!$request->branch_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Branch is required for Manager.',
                ], 422);
            }

            $branchId = $request->branch_id;
        }

        /**
         * Duplicate check branch-wise
         */
        $existsQuery = Examination::where(
            'examination_type',
            $request->examination_type
        )
            ->where(
                'examination_year',
                $request->examination_year
            )
            ->where('branch_id', $branchId);

        if ($existsQuery->exists()) {
            return response()->json([
                'message' => "{$request->examination_year} সালের জন্য '{$request->examination_type}' পরীক্ষাটি ইতিমধ্যে এই branch-এ এন্ট্রি করা আছে!",
            ], 422);
        }

        $exam = Examination::create([
            'examination_type' => $request->examination_type,
            'examination_year' => $request->examination_year,
            'exam_mark' => $request->exam_mark,
            'branch_id' => $branchId,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Examination Created Successfully!',
            'exam' => $exam,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Examination $examination)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Selected Branch:
         * Examination must belong to selected branch.
         *
         * All Branches:
         * Manager can access all.
         */
        if (
            $currentBranchId !== null &&
            $examination->branch_id != $currentBranchId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this examination.',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'data' => $examination,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Examination $examination)
    {
        // API-এর ক্ষেত্রে প্রয়োজন নেই
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        Examination $examination
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Selected Branch:
         * Examination must belong to selected branch.
         *
         * All Branches:
         * Manager can update any examination.
         */
        if (
            $currentBranchId !== null &&
            $examination->branch_id != $currentBranchId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this examination.',
            ], 403);
        }

        $request->validate([
            'examination_type' => 'required|string|max:255',
            'examination_year' => 'required|string|max:255',
            'exam_mark' => 'nullable|numeric|min:0',
        ]);

        /**
         * Duplicate check branch-wise
         */
        $existsQuery = Examination::where(
            'examination_type',
            $request->examination_type
        )
            ->where(
                'examination_year',
                $request->examination_year
            )
            ->where('id', '!=', $examination->id)
            ->where(
                'branch_id',
                $examination->branch_id
            );

        if ($existsQuery->exists()) {
            return response()->json([
                'message' => "{$request->examination_year} সালের জন্য '{$request->examination_type}' পরীক্ষাটি ইতিমধ্যে এই branch-এ রয়েছে!",
            ], 422);
        }

        $examination->update([
            'examination_type' => $request->examination_type,
            'examination_year' => $request->examination_year,
            'exam_mark' => $request->exam_mark,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Examination Updated Successfully!',
            'exam' => $examination,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(
        Request $request,
        Examination $examination
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Selected Branch:
         * Examination must belong to selected branch.
         *
         * All Branches:
         * Manager can delete any examination.
         */
        if (
            $currentBranchId !== null &&
            $examination->branch_id != $currentBranchId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access to this examination.',
            ], 403);
        }

        $examination->delete();

        return response()->json([
            'status' => true,
            'message' => 'Examination Deleted Successfully!',
        ]);
    }
}
