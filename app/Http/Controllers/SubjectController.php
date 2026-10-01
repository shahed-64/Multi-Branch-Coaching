<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * Academic Subject module access check.
     *
     * Manager            → All branches
     * Branch Manager     → Own branch
     * Admin              → Own branch
     * Branch Admin       → Own branch
     * Accountant         → No access
     * Branch Accountant   → No access
     */
    private function authorizeAccess(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (in_array($authUser->role, ['Accountant', 'Branch Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access subjects.',
            ], 403);
        }

        return null;
    }

    /**
     * Display a listing of subjects.
     */
    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        $query = Subject::with([
            'branch',
            'classes',
            'classGroups',
        ]);

        // Manager can see all branches.
        // Other academic users can see only their own branch.
        if ($authUser->role !== 'Manager') {
            $query->where('branch_id', $authUser->branch_id);
        }

        $subjects = $query
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    /**
     * Show the form for creating a new subject.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created subject.
     */
    public function store(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'required',
                'string',
                'max:50',
            ],
            'full_mark' => [
                'required',
                'numeric',
                'min:1',
            ],
            'branch_id' => [
                'nullable',
                'exists:branches,id',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Determine Branch
        |--------------------------------------------------------------------------
        */

        if ($authUser->role === 'Manager') {

            if (!$request->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch is required for Manager.',
                ], 422);
            }

            $branchId = $request->branch_id;

        } else {

            if (!$authUser->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not assigned to any branch.',
                ], 422);
            }

            $branchId = $authUser->branch_id;
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Code Check
        |--------------------------------------------------------------------------
        */

        $code = strtoupper($request->code);

        $exists = Subject::where('branch_id', $branchId)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This subject code already exists in the selected branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Subject
        |--------------------------------------------------------------------------
        */

        $subject = Subject::create([
            'name' => $request->name,
            'code' => $code,
            'full_mark' => $request->full_mark,
            'branch_id' => $branchId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject created successfully.',
            'data' => $subject->load('branch'),
        ], 201);
    }

    /**
     * Display the specified subject.
     */
    public function show(Request $request, Subject $subject)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Branch Access Check
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $subject->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $subject->load([
                'branch',
                'classes',
                'classGroups',
            ]),
        ]);
    }

    /**
     * Show the form for editing the specified subject.
     */
    public function edit(Subject $subject)
    {
        //
    }

    /**
     * Update the specified subject.
     */
    public function update(
        Request $request,
        Subject $subject
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Branch Access Check
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $subject->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'required',
                'string',
                'max:50',
            ],
            'full_mark' => [
                'required',
                'numeric',
                'min:1',
            ],
        ]);

        $code = strtoupper($request->code);

        /*
        |--------------------------------------------------------------------------
        | Duplicate Code Check
        |--------------------------------------------------------------------------
        */

        $exists = Subject::where('branch_id', $subject->branch_id)
            ->where('code', $code)
            ->where('id', '!=', $subject->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This subject code already exists in this branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Subject
        |--------------------------------------------------------------------------
        */

        $subject->update([
            'name' => $request->name,
            'code' => $code,
            'full_mark' => $request->full_mark,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject updated successfully.',
            'data' => $subject->fresh()->load('branch'),
        ]);
    }

    /**
     * Remove the specified subject.
     */
    public function destroy(
        Request $request,
        Subject $subject
    ) {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Branch Access Check
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $subject->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied.',
            ], 403);
        }

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully.',
        ]);
    }
}
