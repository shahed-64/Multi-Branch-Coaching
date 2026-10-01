<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StafftController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * Get currently authenticated staff.
     * --------------------------------------------------------------------------
     */
    private function authUser(Request $request)
    {
        return $request->user();
    }

    /**
     * --------------------------------------------------------------------------
     * STAFF VIEW ACCESS
     * --------------------------------------------------------------------------
     *
     * Manager
     *     -> All branches
     *
     * Branch Manager
     *     -> Own branch
     *
     * Admin
     *     -> Own branch
     *
     * Branch Admin
     *     -> Own branch
     *
     * Accountant / Branch Accountant
     *     -> No Staff access
     *
     * --------------------------------------------------------------------------
     */
    private function canViewStaff($authUser): bool
    {
        if (!$authUser) {
            return false;
        }

        if ($authUser->role === 'Manager') {
            return true;
        }

        return in_array($authUser->role, [
            'Admin',
            'Branch Manager',
            'Branch Admin',
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STAFF WRITE ACCESS
     * --------------------------------------------------------------------------
     *
     * Only Manager can create/update/delete staff.
     * --------------------------------------------------------------------------
     */
    private function canManageStaff($authUser): bool
    {
        return $authUser
            && $authUser->role === 'Manager';
    }

    /**
     * --------------------------------------------------------------------------
     * Check whether authenticated user can access
     * the requested staff record.
     *
     * Manager:
     * - Can access all branches.
     *
     * Other authorized view roles:
     * - Can access only their own branch.
     * --------------------------------------------------------------------------
     */
    private function canAccessStaff($authUser, Staff $staff): bool
    {
        if (!$authUser) {
            return false;
        }

        if ($authUser->role === 'Manager') {
            return true;
        }

        if (!in_array($authUser->role, [
            'Admin',
            'Branch Manager',
            'Branch Admin',
        ])) {
            return false;
        }

        return $staff->branch_id !== null
            && $authUser->branch_id !== null
            && (int) $staff->branch_id === (int) $authUser->branch_id;
    }

    /**
     * --------------------------------------------------------------------------
     * Return forbidden response.
     * --------------------------------------------------------------------------
     */
    private function forbidden(
        $message = 'You are not authorized to perform this action.'
    ) {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY A LISTING OF STAFF
     * --------------------------------------------------------------------------
     */
    public function index(Request $request)
    {
        $authUser = $this->authUser($request);

        /**
         * Authentication check
         */
        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Role authorization
         */
        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $query = Staff::with([
            'shift',
            'branch'
        ]);

        /**
         * Manager can see all staff.
         *
         * Non-Manager authorized roles can see
         * only their own branch.
         */
        if ($authUser->role !== 'Manager') {

            if (!$authUser->branch_id) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Your account is not assigned to any branch.'
                ], 403);
            }

            $query->where(
                'branch_id',
                $authUser->branch_id
            );
        }

        return response()->json([
            'status' => true,
            'staff' => $query
                ->orderBy('id', 'desc')
                ->get()
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STORE A NEW STAFF
     * --------------------------------------------------------------------------
     *
     * ONLY MANAGER CAN CREATE STAFF.
     * --------------------------------------------------------------------------
     */
    public function store(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Only Manager can create Staff.
         */
        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can create staff.'
            );
        }

        $request->validate([
            'name' => 'required',
            'user_name' => 'required',
            'skill' => 'required',

            'role' => [
                'required',
                Rule::in([
                    'Admin',
                    'Manager',
                    'Branch Manager',
                    'Branch Accountant',
                ]),
            ],

            'shift_id' =>
                'required|exists:shifts,id',

            'branch_id' =>
                'nullable|exists:branches,id',

            'email' =>
                'required|email|unique:staff,email',

            'password' =>
                'required|confirmed',

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'salary' =>
                'nullable|numeric',
        ]);

        /**
         * ----------------------------------------------------------------------
         * BRANCH SECURITY
         * ----------------------------------------------------------------------
         *
         * Manager:
         * - Can create staff in any branch.
         * - Manager can create another Manager.
         *
         * ----------------------------------------------------------------------
         */
        if ($authUser->role === 'Manager') {

            $branchId = $request->branch_id;

            /**
             * Manager role does not require a branch.
             *
             * Other roles require a branch.
             */
            if (
                $request->role !== 'Manager'
                && !$branchId
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required for non-Manager staff.'
                ], 422);
            }

        } else {

            /**
             * This branch is unreachable because
             * only Manager can create Staff.
             *
             * Kept as an extra backend safety layer.
             */
            return $this->forbidden(
                'Only Manager can create staff.'
            );
        }

        /**
         * ----------------------------------------------------------------------
         * SHIFT BRANCH VALIDATION
         * ----------------------------------------------------------------------
         *
         * If creating a non-Manager staff:
         * selected shift must belong to selected branch.
         *
         * Manager staff can have any shift.
         */
        $shift = Shift::findOrFail(
            $request->shift_id
        );

        if (
            $request->role !== 'Manager'
            && (int) $shift->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to the selected branch.'
            ], 422);
        }

        /**
         * Upload image.
         */
        $imagePath = null;

        if ($request->hasFile('image')) {

            $imagePath = $request
                ->file('image')
                ->store('staffs', 'public');
        }

        /**
         * Create staff.
         */
        $staff = Staff::create([
            'name' =>
                $request->name,

            'user_name' =>
                $request->user_name,

            'skill' =>
                $request->skill,

            'role' =>
                $request->role,

            'shift_id' =>
                $request->shift_id,

            'branch_id' =>
                $branchId,

            'email' =>
                $request->email,

            'password' =>
                Hash::make($request->password),

            'image' =>
                $imagePath,

            'salary' =>
                $request->salary ?? 0,
        ]);

        return response()->json([
            'status' =>
                true,

            'message' =>
                'Staff Created Successfully',

            'staff' =>
                $staff->load([
                    'shift',
                    'branch'
                ])
        ], 201);
    }

    /**
     * --------------------------------------------------------------------------
     * DISPLAY SINGLE STAFF
     * --------------------------------------------------------------------------
     */
    public function show($id, Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Role authorization.
         */
        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $staff = Staff::findOrFail($id);

        /**
         * Prevent cross-branch access.
         */
        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot access staff from another branch.'
            );
        }

        return response()->json([
            'status' => true,
            'staff' =>
                $staff->load([
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * SHOW FORM DATA FOR EDITING STAFF
     * --------------------------------------------------------------------------
     *
     * This is still VIEW access.
     * Therefore Manager / Branch Manager / Admin / Branch Admin
     * can access their allowed Staff data.
     *
     * Actual UPDATE is Manager-only.
     * --------------------------------------------------------------------------
     */
    public function edit($id, Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Role authorization.
         */
        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $staff = Staff::findOrFail($id);

        /**
         * Prevent cross-branch access.
         */
        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot access staff from another branch.'
            );
        }

        return response()->json([
            'status' => true,
            'staff' =>
                $staff->load([
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * UPDATE STAFF
     * --------------------------------------------------------------------------
     *
     * ONLY MANAGER CAN UPDATE STAFF.
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        $id
    ) {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Only Manager can update Staff.
         */
        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can update staff.'
            );
        }

        /**
         * Find requested staff by ID.
         */
        $staff = Staff::findOrFail($id);

        /**
         * Manager can access all branches.
         *
         * This check is kept for consistency.
         */
        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot update staff from another branch.'
            );
        }

        $request->validate([
            'name' =>
                'required',

            'user_name' =>
                'required',

            'skill' =>
                'required',

            'role' => [
                'required',
                Rule::in([
                    'Admin',
                    'Manager',
                    'Branch Manager',
                    'Branch Accountant',
                ]),
            ],

            'shift_id' =>
                'required|exists:shifts,id',

            'branch_id' =>
                'nullable|exists:branches,id',

            'email' => [
                'required',
                'email',
                Rule::unique(
                    'staff',
                    'email'
                )->ignore($staff->id),
            ],

            'password' =>
                'nullable|confirmed',

            'image' =>
                'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            'salary' =>
                'nullable|numeric',
        ]);

        /**
         * ----------------------------------------------------------------------
         * MANAGER CAN CHANGE ROLE / BRANCH
         * ----------------------------------------------------------------------
         */
        $branchId = $request->branch_id;

        /**
         * Manager role does not require a branch.
         *
         * Other roles require a branch.
         */
        if (
            $request->role !== 'Manager'
            && !$branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Branch is required for non-Manager staff.'
            ], 422);
        }

        /**
         * ----------------------------------------------------------------------
         * SHIFT BRANCH VALIDATION
         * ----------------------------------------------------------------------
         */
        $shift = Shift::findOrFail(
            $request->shift_id
        );

        if (
            $request->role !== 'Manager'
            && (int) $shift->branch_id !== (int) $branchId
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to the selected branch.'
            ], 422);
        }

        /**
         * Prepare update data.
         */
        $data = [
            'name' =>
                $request->name,

            'user_name' =>
                $request->user_name,

            'skill' =>
                $request->skill,

            'role' =>
                $request->role,

            'shift_id' =>
                $request->shift_id,

            'branch_id' =>
                $branchId,

            'email' =>
                $request->email,

            'salary' =>
                $request->salary ?? $staff->salary,
        ];

        /**
         * Replace old image with the new one.
         */
        if ($request->hasFile('image')) {

            if (
                $staff->image &&
                Storage::disk('public')->exists(
                    $staff->image
                )
            ) {
                Storage::disk('public')->delete(
                    $staff->image
                );
            }

            $data['image'] = $request
                ->file('image')
                ->store('staffs', 'public');
        }

        /**
         * Update password only if provided.
         */
        if ($request->filled('password')) {

            $data['password'] =
                Hash::make(
                    $request->password
                );
        }

        $staff->update($data);

        return response()->json([
            'status' =>
                true,

            'message' =>
                'Staff updated successfully',

            'staff' =>
                $staff->load([
                    'shift',
                    'branch'
                ])
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DELETE STAFF
     * --------------------------------------------------------------------------
     *
     * ONLY MANAGER CAN DELETE STAFF.
     * --------------------------------------------------------------------------
     */
    public function destroy(
        $id,
        Request $request
    ) {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Only Manager can delete Staff.
         */
        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can delete staff.'
            );
        }

        $staff = Staff::findOrFail($id);

        /**
         * Manager can access all branches.
         */
        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot delete staff from another branch.'
            );
        }

        /**
         * Prevent deleting own account.
         */
        if ($staff->id == $authUser->id) {
            return $this->forbidden(
                'You cannot delete your own account.'
            );
        }

        /**
         * Delete image.
         */
        if (
            $staff->image &&
            Storage::disk('public')->exists(
                $staff->image
            )
        ) {
            Storage::disk('public')->delete(
                $staff->image
            );
        }

        $staff->delete();

        return response()->json([
            'status' =>
                true,

            'message' =>
                'Staff deleted successfully'
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * LOGIN
     * --------------------------------------------------------------------------
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' =>
                'required|email',

            'password' =>
                'required'
        ]);

        $staff = Staff::where(
            'email',
            $request->email
        )->first();

        if (
            !$staff ||
            !Hash::check(
                $request->password,
                $staff->password
            )
        ) {
            return response()->json([
                'status' =>
                    false,

                'message' =>
                    'Invalid credentials'
            ], 401);
        }

        $token = $staff
            ->createToken('staff-token')
            ->plainTextToken;

        return response()->json([
            'status' =>
                true,

            'message' =>
                'Login successful',

            'staff' =>
                $staff->load([
                    'shift',
                    'branch'
                ]),

            'token' =>
                $token
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DASHBOARD
     * --------------------------------------------------------------------------
     *
     * Allowed:
     * - Manager
     * - Branch Manager
     * - Admin
     * - Branch Admin
     *
     * Not allowed:
     * - Accountant
     * - Branch Accountant
     * --------------------------------------------------------------------------
     */
    public function dashboard(Request $request)
    {
        $authUser = $this->authUser($request);

        /**
         * Authentication check.
         */
        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /**
         * Account section users should not access
         * Admin Dashboard through this endpoint.
         */
        if (!in_array($authUser->role, [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ])) {
            return $this->forbidden(
                'You are not authorized to access the dashboard.'
            );
        }

        /**
         * Non-Manager dashboard requires branch.
         */
        if (
            $authUser->role !== 'Manager'
            && !$authUser->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Your account is not assigned to any branch.'
            ], 403);
        }

        $staffQuery =
            Staff::query();

        $studentQuery =
            Student::query();

        $teacherQuery =
            Teacher::query();

        $branchQuery =
            Branch::query();

        /**
         * ----------------------------------------------------------------------
         * MANAGER
         * ----------------------------------------------------------------------
         *
         * Can see all branches.
         *
         * ----------------------------------------------------------------------
         */

        /**
         * ----------------------------------------------------------------------
         * NON-MANAGER
         * ----------------------------------------------------------------------
         *
         * Can see only own branch.
         *
         * ----------------------------------------------------------------------
         */
        if ($authUser->role !== 'Manager') {

            $staffQuery->where(
                'branch_id',
                $authUser->branch_id
            );

            $studentQuery->where(
                'branch_id',
                $authUser->branch_id
            );

            $teacherQuery->where(
                'branch_id',
                $authUser->branch_id
            );
        }

        /**
         * Recent Staff
         */
        $recentStaffQuery =
            Staff::with([
                'shift',
                'branch'
            ]);

        if ($authUser->role !== 'Manager') {

            $recentStaffQuery->where(
                'branch_id',
                $authUser->branch_id
            );
        }

        $recentStaff = $recentStaffQuery
            ->latest()
            ->take(5)
            ->get();

        /**
         * Total Branches
         */
        $totalBranches =
            $authUser->role !== 'Manager'
                ? 1
                : $branchQuery->count();

        /**
         * Dashboard Response
         */
        return response()->json([
            'status' =>
                true,

            'total_staff' =>
                $staffQuery->count(),

            'total_students' =>
                $studentQuery->count(),

            'total_teachers' =>
                $teacherQuery->count(),

            'total_branches' =>
                $totalBranches,

            'recent_staff' =>
                $recentStaff,

            /**
             * Logged-in User
             */
            'user' => [
                'id' =>
                    $authUser->id,

                'name' =>
                    $authUser->name,

                'role' =>
                    $authUser->role,

                'branch_id' =>
                    $authUser->branch_id,

                'designation' =>
                    $authUser->skill
                    ?? $authUser->role,

                'image' =>
                    $authUser->image
                        ? asset(
                            'storage/' .
                            $authUser->image
                        )
                        : null,
            ],
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * LOGOUT
     * --------------------------------------------------------------------------
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if (
            $user &&
            $user->currentAccessToken()
        ) {
            $user
                ->currentAccessToken()
                ->delete();
        }

        return response()->json([
            'status' =>
                true,

            'message' =>
                'Logout successful'
        ]);
    }
}
