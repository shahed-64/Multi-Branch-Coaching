<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Display a listing of branches.
     */
    public function index()
    {
        $branches = Branch::latest()->get();

        return response()->json([
            'status' => true,
            'branches' => $branches,
        ]);
    }

    /**
     * Store a newly created branch.
     */
    public function store(Request $request)
    {
        // Only Manager can create a branch
        if ($request->user()?->role !== 'Manager') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Only Manager can create a branch.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:branches,code',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|boolean',
        ]);

        $branch = Branch::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Branch created successfully.',
            'branch' => $branch,
        ], 201);
    }

    /**
     * Display the specified branch.
     */
    public function show(Branch $branch)
    {
        return response()->json([
            'status' => true,
            'branch' => $branch,
        ]);
    }

    /**
     * Update the specified branch.
     */
    public function update(Request $request, Branch $branch)
    {
        // Only Manager can update a branch
        if ($request->user()?->role !== 'Manager') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Only Manager can update a branch.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:branches,code,' . $branch->id,
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|boolean',
        ]);

        $branch->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Branch updated successfully.',
            'branch' => $branch,
        ]);
    }

    /**
     * Remove the specified branch.
     */
    public function destroy(Request $request, Branch $branch)
    {
        // Only Manager can delete a branch
        if ($request->user()?->role !== 'Manager') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Only Manager can delete a branch.',
            ], 403);
        }

        $branch->delete();

        return response()->json([
            'status' => true,
            'message' => 'Branch deleted successfully.',
        ]);
    }
}
