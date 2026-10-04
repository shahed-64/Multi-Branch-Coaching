<?php

namespace App\Http\Controllers;

use App\Models\ClssM;
use App\Models\Subject;
use App\Services\BranchContext;
use Illuminate\Http\Request;

class ClssMController extends Controller
{
    /**
     * Get current branch from BranchContext.
     *
     * null = Manager selected "All Branches"
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * Class query according to current branch context.
     */
    private function classQuery()
    {
        $query = ClssM::query();

        $branchId = $this->currentBranchId();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /**
     * Display a listing of the classes.
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to access classes.'
            ], 403);
        }

        $classes = $this->classQuery()
            ->with('subjects', 'branch')
            ->latest()
            ->get();

        return response()->json([
            'status'  => true,
            'classes' => $classes
        ], 200);
    }

    /**
     * Show the form for creating a new class.
     */
    public function create()
    {
        // API-এর ক্ষেত্রে প্রয়োজন নেই
    }

    /**
     * Store a newly created class.
     */
    public function store(Request $request)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to create classes.'
            ], 403);
        }

        $request->validate([
            'class_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        /**
         * ----------------------------------------------------------
         * Branch Selection
         * ----------------------------------------------------------
         *
         * Manager:
         * - Selected branch -> BranchContext branch
         * - All Branches -> request branch_id required
         *
         * Other users:
         * - BranchContext automatically gives own branch
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {

            // Selected branch is authoritative.
            $branchId = $currentBranchId;

        } else {

            // All Branches is only possible for Manager.
            if (!$authUser || $authUser->role !== 'Manager') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is not assigned to any branch.'
                ], 422);
            }

            // Manager must provide a branch when All Branches is selected.
            if (!$request->branch_id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Branch is required for Manager.'
                ], 422);
            }

            $branchId = $request->branch_id;
        }

        /**
         * ----------------------------------------------------------
         * Subject Branch Validation
         * ----------------------------------------------------------
         *
         * Selected subjects must belong to the same branch
         * as the class.
         */
        if ($request->has('subject_ids') && !empty($request->subject_ids)) {

            $invalidSubjectExists = Subject::whereIn(
                'id',
                $request->subject_ids
            )
                ->where('branch_id', '!=', $branchId)
                ->exists();

            if ($invalidSubjectExists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Selected subject does not belong to the selected branch.'
                ], 422);
            }
        }

        // Class create
        $class = ClssM::create([
            'class_name' => $request->class_name,
            'branch_id'  => $branchId,
        ]);

        // Selected subjects attach
        if ($request->has('subject_ids')) {
            $class->subjects()->sync($request->subject_ids);
        }

        // Subjects + branch সহ fresh data
        $class->load('subjects', 'branch');

        return response()->json([
            'status'  => true,
            'message' => 'Class Created Successfully',
            'class'   => $class
        ], 201);
    }

    /**
     * Display the specified class.
     */
    public function show(Request $request, ClssM $class)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to access classes.'
            ], 403);
        }

        // BranchContext based access
        $currentBranchId = $this->currentBranchId();

        if (
            $currentBranchId !== null &&
            $class->branch_id !== $currentBranchId
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.'
            ], 403);
        }

        $class->load('subjects', 'branch');

        return response()->json([
            'status' => true,
            'class'  => $class
        ], 200);
    }

    /**
     * Show the form for editing the specified class.
     */
    public function edit(Request $request, ClssM $class)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to edit classes.'
            ], 403);
        }

        // BranchContext based access
        $currentBranchId = $this->currentBranchId();

        if (
            $currentBranchId !== null &&
            $class->branch_id !== $currentBranchId
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.'
            ], 403);
        }

        $class->load('subjects', 'branch');

        return response()->json([
            'status' => true,
            'class'  => $class
        ]);
    }

    /**
     * Update the specified class.
     */
    public function update(Request $request, ClssM $class)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to update classes.'
            ], 403);
        }

        // BranchContext based access
        $currentBranchId = $this->currentBranchId();

        if (
            $currentBranchId !== null &&
            $class->branch_id !== $currentBranchId
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.'
            ], 403);
        }

        $request->validate([
            'class_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
        ]);

        /**
         * ----------------------------------------------------------
         * Subject Branch Validation
         * ----------------------------------------------------------
         *
         * Selected subjects must belong to the same branch
         * as the class.
         */
        if ($request->has('subject_ids') && !empty($request->subject_ids)) {

            $invalidSubjectExists = Subject::whereIn(
                'id',
                $request->subject_ids
            )
                ->where('branch_id', '!=', $class->branch_id)
                ->exists();

            if ($invalidSubjectExists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Selected subject does not belong to this class branch.'
                ], 422);
            }
        }

        // Class update
        $class->update([
            'class_name' => $request->class_name
        ]);

        // Selected subjects থাকবে,
        // unselected subjects pivot table থেকে remove হবে।
        $class->subjects()->sync($request->subject_ids ?? []);

        // Subjects + branch সহ fresh data
        $class->load('subjects', 'branch');

        return response()->json([
            'status'  => true,
            'message' => 'Class Updated Successfully',
            'class'   => $class
        ], 200);
    }

    /**
     * Remove the specified class.
     */
    public function destroy(Request $request, ClssM $class)
    {
        $authUser = $request->user();

        // Accountant has no Academic access
        if (
            $authUser &&
            in_array($authUser->role, ['Branch Accountant', 'Accountant'])
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to delete classes.'
            ], 403);
        }

        // BranchContext based access
        $currentBranchId = $this->currentBranchId();

        if (
            $currentBranchId !== null &&
            $class->branch_id !== $currentBranchId
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.'
            ], 403);
        }

        $class->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Class Deleted Successfully'
        ], 200);
    }
}
