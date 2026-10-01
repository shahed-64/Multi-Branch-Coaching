<?php

namespace App\Http\Controllers;

use App\Models\InstituteInfo;
use Illuminate\Http\Request;

class InstituteInfoController extends Controller
{
    /**
     * Display institute information.
     *
     * Manager        -> All branches
     * Admin          -> Own branch
     * Branch Admin   -> Own branch
     * Branch Manager -> Own branch
     * Accountant     -> No access
     */
    public function index(Request $request)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Role Authorization
        |--------------------------------------------------------------------------
        */
        if (
            in_array($user->role, [
                'Branch Accountant',
                'Accountant',
            ])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Manager
        |--------------------------------------------------------------------------
        | Manager can access institute information from all branches.
        |
        | Existing frontend expects one institute object,
        | so we keep the existing response structure.
        |--------------------------------------------------------------------------
        */
        if ($user->role === 'Manager') {

            $institute = InstituteInfo::orderBy('id', 'desc')->first();

        } else {

            /*
            |--------------------------------------------------------------------------
            | Admin / Branch Admin / Branch Manager
            |--------------------------------------------------------------------------
            | Only own branch
            |--------------------------------------------------------------------------
            */
            $institute = InstituteInfo::where(
                'branch_id',
                $user->branch_id
            )
                ->orderBy('id', 'desc')
                ->first();
        }

        return response()->json([
            'success' => true,
            'data' => $institute
                ? $this->formatInstitute($institute)
                : null,
        ]);
    }


    /**
     * Store institute information.
     *
     * Manager        -> Can create for any branch
     * Admin          -> Own branch
     * Branch Admin   -> Own branch
     * Branch Manager -> Own branch
     * Accountant     -> No access
     */
    public function store(Request $request)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Role Authorization
        |--------------------------------------------------------------------------
        */
        if (
            in_array($user->role, [
                'Branch Accountant',
                'Accountant',
            ])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to create institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([
            'institute_name' => 'required|string|max:255',
            'established_year' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Determine Branch
        |--------------------------------------------------------------------------
        */
        if ($user->role === 'Manager') {

            /*
            | Manager can select any branch.
            |
            | If branch_id is not sent, fallback to Manager's own branch.
            */
            $branchId = $request->branch_id ?? $user->branch_id;

            if (!$branchId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch is required.',
                ], 422);
            }

        } else {

            /*
            | Admin / Branch Admin / Branch Manager
            | ALWAYS use logged-in user's branch.
            |
            | Even if someone sends another branch_id,
            | it will be ignored.
            */
            if (!$user->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not assigned to any branch.',
                ], 422);
            }

            $branchId = $user->branch_id;
        }

        /*
        |--------------------------------------------------------------------------
        | One Institute Information Per Branch
        |--------------------------------------------------------------------------
        */
        if (
            InstituteInfo::where(
                'branch_id',
                $branchId
            )->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Institute information already exists for this branch.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Logo
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadImage(
                $request->file('logo'),
                'institute'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Force Branch ID
        |--------------------------------------------------------------------------
        */
        $validated['branch_id'] = $branchId;

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */
        $institute = InstituteInfo::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Institute information created successfully.',
            'data' => $this->formatInstitute($institute),
        ], 201);
    }


    /**
     * Display specified institute information.
     *
     * IDOR protection included.
     */
    public function show(
        Request $request,
        InstituteInfo $instituteInfo
    ) {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Role Authorization
        |--------------------------------------------------------------------------
        */
        if (
            in_array($user->role, [
                'Branch Accountant',
                'Accountant',
            ])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation
        |--------------------------------------------------------------------------
        |
        | Manager -> Can access any branch
        |
        | Others -> Must belong to same branch
        |--------------------------------------------------------------------------
        */
        if (
            $user->role !== 'Manager' &&
            (int) $instituteInfo->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this institute information.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatInstitute($instituteInfo),
        ]);
    }


    /**
     * Update institute information.
     *
     * Manager        -> Any branch
     * Admin          -> Own branch
     * Branch Admin   -> Own branch
     * Branch Manager -> Own branch
     * Accountant     -> No access
     */
    public function update(
        Request $request,
        InstituteInfo $instituteInfo
    ) {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Role Authorization
        |--------------------------------------------------------------------------
        */
        if (
            in_array($user->role, [
                'Branch Accountant',
                'Accountant',
            ])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation / IDOR Protection
        |--------------------------------------------------------------------------
        */
        if (
            $user->role !== 'Manager' &&
            (int) $instituteInfo->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([
            'institute_name' => 'required|string|max:255',
            'established_year' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Upload New Logo
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('logo')) {
            $validated['logo'] = $this->uploadImage(
                $request->file('logo'),
                'institute',
                $instituteInfo->logo
            );
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        | branch_id is NOT updated here.
        |
        | Therefore nobody can move an existing institute
        | from one branch to another through update.
        |--------------------------------------------------------------------------
        */

        $instituteInfo->update($validated);

        $instituteInfo->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Institute information updated successfully.',
            'data' => $this->formatInstitute($instituteInfo),
        ]);
    }


    /**
     * Delete institute information.
     *
     * Manager        -> Any branch
     * Admin          -> Own branch
     * Branch Admin   -> Own branch
     * Branch Manager -> Own branch
     * Accountant     -> No access
     */
    public function destroy(
        Request $request,
        InstituteInfo $instituteInfo
    ) {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Role Authorization
        |--------------------------------------------------------------------------
        */
        if (
            in_array($user->role, [
                'Branch Accountant',
                'Accountant',
            ])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation / IDOR Protection
        |--------------------------------------------------------------------------
        */
        if (
            $user->role !== 'Manager' &&
            (int) $instituteInfo->branch_id !==
            (int) $user->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this institute information.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Logo
        |--------------------------------------------------------------------------
        */
        if ($instituteInfo->logo) {
            $this->deleteImage($instituteInfo->logo);
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Institute
        |--------------------------------------------------------------------------
        */
        $instituteInfo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Institute information deleted successfully.',
        ]);
    }


    /**
     * Format institute data for API response.
     */
    private function formatInstitute($institute)
    {
        if (!$institute) {
            return null;
        }

        $data = $institute->toArray();

        if (!empty($institute->logo)) {
            $data['logo'] = asset(
                'storage/' . $institute->logo
            );
        }

        return $data;
    }
}
