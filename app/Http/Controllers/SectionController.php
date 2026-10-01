<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    /**
     * Check whether user is allowed to access Academic Section module.
     *
     * Manager        → All branches
     * Branch Manager → Own branch
     * Admin          → Own branch
     * Branch Admin   → Own branch
     * Accountant     → No access
     * Branch Accountant → No access
     */
    private function authorizeAccess(Request $request)
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (in_array($authUser->role, ['Accountant', 'Branch Accountant'])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to access sections.',
            ], 403);
        }

        return null;
    }

    // Section list
    public function index(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        $query = Section::with('branch')
            ->withCount('students');

        // Manager can see all branches
        if ($authUser->role !== 'Manager') {
            $query->where('branch_id', $authUser->branch_id);
        }

        $sections = $query
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status'   => true,
            'sections' => $sections,
        ]);
    }

    // New Section Create
    public function store(Request $request)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        $request->validate([
            'section_name' => 'required|string|max:255',
            'branch_id'    => 'nullable|exists:branches,id',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Branch fix
        |--------------------------------------------------------------------------
        */

        if ($authUser->role === 'Manager') {

            if (!$request->branch_id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Branch is required for Manager.',
                ], 422);
            }

            $branchId = $request->branch_id;

        } else {

            if (!$authUser->branch_id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is not assigned to any branch.',
                ], 422);
            }

            $branchId = $authUser->branch_id;
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Section protection in Same branch
        |--------------------------------------------------------------------------
        */

        $exists = Section::where('branch_id', $branchId)
            ->where('section_name', $request->section_name)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => false,
                'message' => 'This section name already exists in the selected branch.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Section
        |--------------------------------------------------------------------------
        */

        $section = Section::create([
            'section_name' => $request->section_name,
            'branch_id'    => $branchId,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Section Created Successfully',
            'section' => $section->load('branch'),
        ], 201);
    }

    // Specific Section Show
    public function show(Request $request, Section $section)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        if (
            $authUser->role !== 'Manager' &&
            $section->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.',
            ], 403);
        }

        return response()->json([
            'status'  => true,
            'section' => $section->load([
                'students',
                'branch',
            ]),
        ]);
    }

    // Section update
    public function update(Request $request, Section $section)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        if (
            $authUser->role !== 'Manager' &&
            $section->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.',
            ], 403);
        }

        $request->validate([
            'section_name' => 'required|string|max:255',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Same branch - duplicate Section protection
        |--------------------------------------------------------------------------
        */

        $exists = Section::where('branch_id', $section->branch_id)
            ->where('section_name', $request->section_name)
            ->where('id', '!=', $section->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => false,
                'message' => 'This section name already exists in this branch.',
            ], 422);
        }

        $section->update([
            'section_name' => $request->section_name,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Section Updated Successfully',
            'section' => $section->load('branch'),
        ]);
    }

    // Section delete
    public function destroy(Request $request, Section $section)
    {
        if ($response = $this->authorizeAccess($request)) {
            return $response;
        }

        $authUser = $request->user();

        if (
            $authUser->role !== 'Manager' &&
            $section->branch_id !== $authUser->branch_id
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Access denied.',
            ], 403);
        }

        $section->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Section Deleted Successfully',
        ]);
    }
}
