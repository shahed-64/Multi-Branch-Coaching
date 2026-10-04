<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Services\BranchContext;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    /**
     * Role Authorization
     *
     * Manager          → All branches
     * Branch Manager   → Own branch
     * Admin            → Own branch
     * Branch Admin     → Own branch
     * Accountant       → No access
     * Branch Accountant → No access
     */
    private function authorizeAccess(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if (in_array($user->role, [
            'Accountant',
            'Branch Accountant'
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access holidays.'
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
     * Branch Access Check
     */
    private function canAccessHoliday(Holiday $holiday): bool
    {
        $currentBranchId = $this->currentBranchId();

        // Manager + All Branches
        if ($currentBranchId === null) {
            return true;
        }

        return (int) $holiday->branch_id === (int) $currentBranchId;
    }

    /**
     * Display a listing of holidays.
     */
    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $query = Holiday::query();

        /**
         * Branch Filter
         *
         * All Branches → সব branch
         * Selected Branch → শুধু selected branch
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        /**
         * Year Filter
         */
        if ($request->has('year')) {
            $query->whereYear(
                'start_date',
                $request->year
            );
        }

        $holidays = $query
            ->orderBy('start_date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $holidays
        ]);
    }

    /**
     * Store a newly created holiday.
     */
    public function store(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' =>
                'required|date|after_or_equal:start_date',
            'description' =>
                'nullable|string',
            'branch_id' =>
                'nullable|exists:branches,id',
        ]);

        /**
         * Branch Assignment
         *
         * Selected Branch → Context থেকে branch নেবে
         * All Branches → শুধুমাত্র Manager request থেকে branch_id দিতে পারবে
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {

            $branchId = $currentBranchId;

        } else {

            if (!$user || $user->role !== 'Manager') {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Your account is not assigned to any branch.'
                ], 422);
            }

            if (!$request->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select a branch.'
                ], 422);
            }

            $branchId = $request->branch_id;
        }

        /**
         * Create Holiday
         */
        $holiday = Holiday::create([
            'branch_id' =>
                $branchId,
            'title' =>
                $request->title,
            'start_date' =>
                $request->start_date,
            'end_date' =>
                $request->end_date,
            'description' =>
                $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Holiday added successfully!',
            'data' => $holiday
        ], 201);
    }

    /**
     * Remove the specified holiday.
     */
    public function destroy(
        Request $request,
        Holiday $holiday
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        /**
         * Branch Access Check
         *
         * All Branches → access allowed
         * Selected Branch → only selected branch
         */
        if (!$this->canAccessHoliday($holiday)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Unauthorized access to this holiday.'
            ], 403);
        }

        /**
         * Delete
         */
        $holiday->delete();

        return response()->json([
            'success' => true,
            'message' => 'Holiday deleted successfully!'
        ]);
    }
}
