<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\BranchContext;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Role Authorization
     */
    private function authorizeAccess(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Accountant এবং Branch Accountant
        // Shift module access করতে পারবে না
        if (in_array($authUser->role, ['Accountant', 'Branch Accountant'])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access shifts.',
            ], 403);
        }

        return null;
    }

    /**
     * Current Branch Context
     *
     * Manager + All Branches = null
     * Manager + Selected Branch = branch id
     * Non-Manager = own branch id
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * Branch security check
     */
    private function canAccessShift(Shift $shift): bool
    {
        $currentBranchId = $this->currentBranchId();

        // Manager + All Branches
        if ($currentBranchId === null) {
            return true;
        }

        return (int) $shift->branch_id === (int) $currentBranchId;
    }

    /**
     * সব শিফটের তালিকা
     */
    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        try {
            $query = Shift::with([
                'teachers',
                'branch',
            ]);

            $currentBranchId = $this->currentBranchId();

            // Selected Branch হলে শুধু সেই branch
            // All Branches হলে কোনো filter নয়
            if ($currentBranchId !== null) {
                $query->where(
                    'branch_id',
                    $currentBranchId
                );
            }

            $shifts = $query
                ->latest()
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Shifts fetched successfully.',
                'data' => $shifts,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch shifts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * নতুন শিফট তৈরি
     */
    public function store(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'start_time' => 'required',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        try {
            /**
             * Branch determine
             *
             * Selected Branch থাকলে সেটাই authoritative.
             *
             * All Branches হলে শুধুমাত্র Manager
             * request থেকে branch_id দিতে পারবে।
             */
            $currentBranchId = $this->currentBranchId();

            if ($currentBranchId !== null) {

                $branchId = $currentBranchId;

            } else {

                if (!$authUser || $authUser->role !== 'Manager') {
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
             * Duplicate Shift check within branch
             */
            $exists = Shift::where(
                    'branch_id',
                    $branchId
                )
                ->where(
                    'name',
                    $request->name
                )
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'This shift name already exists in the selected branch.',
                ], 422);
            }

            /**
             * Create
             */
            $shift = Shift::create([
                'name' => $request->name,
                'start_time' => $request->start_time,
                'branch_id' => $branchId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Shift created successfully.',
                'data' => $shift->load('branch'),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create shift.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * নির্দিষ্ট শিফট দেখানো
     */
    public function show(Request $request, Shift $shift)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        /**
         * Branch security
         */
        if (!$this->canAccessShift($shift)) {
            return response()->json([
                'status' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        try {
            $shift->load([
                'teachers',
                'branch',
            ]);

            return response()->json([
                'status' => true,
                'data' => $shift,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch shift.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * শিফট আপডেট
     */
    public function update(
        Request $request,
        Shift $shift
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        /**
         * Branch security
         */
        if (!$this->canAccessShift($shift)) {
            return response()->json([
                'status' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'start_time' => 'required',
        ]);

        try {
            /**
             * Duplicate check within same branch
             */
            $exists = Shift::where(
                    'branch_id',
                    $shift->branch_id
                )
                ->where(
                    'name',
                    $request->name
                )
                ->where(
                    'id',
                    '!=',
                    $shift->id
                )
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'This shift name already exists in this branch.',
                ], 422);
            }

            /**
             * Update
             *
             * branch_id intentionally update করা হচ্ছে না।
             * কারণ branch context-ই branch নির্ধারণ করবে।
             */
            $shift->update([
                'name' => $request->name,
                'start_time' => $request->start_time,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Shift updated successfully.',
                'data' => $shift->load('branch'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update shift.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * শিফট ডিলিট
     */
    public function destroy(
        Request $request,
        Shift $shift
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        /**
         * Branch security
         */
        if (!$this->canAccessShift($shift)) {
            return response()->json([
                'status' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        try {
            $shift->delete();

            return response()->json([
                'status' => true,
                'message' => 'Shift deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete shift.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
