<?php

namespace App\Http\Controllers;

use App\Models\ClassGroup;
use App\Models\Subject;
use App\Models\GroupSubjectMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassGroupController extends Controller
{
    /**
     * Class Group list
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access class groups.'
            ], 403);
        }

        $query = ClassGroup::with([
            'subjects',
            'groupSubjectMappings.subject',
            'branch'
        ]);

        // Manager can see all branches
        if ($authUser->role === 'Manager') {
            // Optional branch filter for Manager
            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
        } else {
            // Branch Manager / Admin → own branch only
            $query->where('branch_id', $authUser->branch_id);
        }

        $classGroups = $query
            ->orderBy('group_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $classGroups
        ]);
    }

    /**
     * Store Class Group
     */
    public function store(Request $request)
    {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to create class groups.'
            ], 403);
        }

        $validated = $request->validate([
            'group_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'group_subject_ids' => 'nullable|array',
            'group_subject_ids.*' => 'integer|exists:subjects,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Branch Selection
        |--------------------------------------------------------------------------
        */

        if ($authUser->role === 'Manager') {
            // Manager can select branch
            if (empty($validated['branch_id'])) {
                $branchId = $authUser->branch_id;
            } else {
                $branchId = $validated['branch_id'];
            }
        } else {
            // Branch Manager / Admin → always own branch
            $branchId = $authUser->branch_id;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Subject Branch
        |--------------------------------------------------------------------------
        */

        $subjectIds = $validated['subject_ids'] ?? [];
        $groupSubjectIds = $validated['group_subject_ids'] ?? [];

        $allSubjectIds = array_unique(
            array_merge($subjectIds, $groupSubjectIds)
        );

        if (!empty($allSubjectIds)) {
            $invalidSubject = Subject::whereIn('id', $allSubjectIds)
                ->where('branch_id', '!=', $branchId)
                ->exists();

            if ($invalidSubject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected subject does not belong to the selected branch.'
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Group Name Within Branch
        |--------------------------------------------------------------------------
        */

        $exists = ClassGroup::where('branch_id', $branchId)
            ->where('group_name', $validated['group_name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This class group already exists in the selected branch.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Create Class Group
            |--------------------------------------------------------------------------
            */

            $classGroup = ClassGroup::create([
                'group_name' => $validated['group_name'],
                'branch_id' => $branchId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Sync Normal Subjects
            |--------------------------------------------------------------------------
            */

            if (!empty($subjectIds)) {
                $classGroup->subjects()->sync($subjectIds);
            }

            /*
            |--------------------------------------------------------------------------
            | Create Group Subject Mappings
            |--------------------------------------------------------------------------
            */

            if (!empty($groupSubjectIds)) {
                foreach ($groupSubjectIds as $subjectId) {
                    GroupSubjectMapping::create([
                        'class_group_id' => $classGroup->id,
                        'subject_id' => $subjectId,
                    ]);
                }
            }

            DB::commit();

            $classGroup->load([
                'subjects',
                'groupSubjectMappings.subject',
                'branch'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Class group created successfully.',
                'data' => $classGroup
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create class group.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show single Class Group
     */
    public function show(Request $request, ClassGroup $classGroup)
    {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access class groups.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation / IDOR Protection
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $classGroup->branch_id != $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access this class group.'
            ], 403);
        }

        $classGroup->load([
            'subjects',
            'groupSubjectMappings.subject',
            'branch'
        ]);

        return response()->json([
            'success' => true,
            'data' => $classGroup
        ]);
    }

    /**
     * Update Class Group
     */
    public function update(
        Request $request,
        ClassGroup $classGroup
    ) {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update class groups.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation / IDOR Protection
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $classGroup->branch_id != $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this class group.'
            ], 403);
        }

        $validated = $request->validate([
            'group_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'group_subject_ids' => 'nullable|array',
            'group_subject_ids.*' => 'integer|exists:subjects,id',
        ]);

        $branchId = $classGroup->branch_id;

        $subjectIds = $validated['subject_ids'] ?? [];
        $groupSubjectIds = $validated['group_subject_ids'] ?? [];

        /*
        |--------------------------------------------------------------------------
        | Validate Subject Branch
        |--------------------------------------------------------------------------
        */

        $allSubjectIds = array_unique(
            array_merge($subjectIds, $groupSubjectIds)
        );

        if (!empty($allSubjectIds)) {
            $invalidSubject = Subject::whereIn('id', $allSubjectIds)
                ->where('branch_id', '!=', $branchId)
                ->exists();

            if ($invalidSubject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected subject does not belong to this branch.'
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Group Name Within Branch
        |--------------------------------------------------------------------------
        */

        $exists = ClassGroup::where('branch_id', $branchId)
            ->where('group_name', $validated['group_name'])
            ->where('id', '!=', $classGroup->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This class group already exists in this branch.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Update Group Name
            |--------------------------------------------------------------------------
            */

            $classGroup->update([
                'group_name' => $validated['group_name'],
            ]);

            /*
            |--------------------------------------------------------------------------
            | Sync Normal Subjects
            |--------------------------------------------------------------------------
            */

            $classGroup->subjects()->sync($subjectIds);

            /*
            |--------------------------------------------------------------------------
            | Remove Existing Group Subject Mappings
            |--------------------------------------------------------------------------
            */

            GroupSubjectMapping::where(
                'class_group_id',
                $classGroup->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Create New Group Subject Mappings
            |--------------------------------------------------------------------------
            */

            if (!empty($groupSubjectIds)) {
                foreach ($groupSubjectIds as $subjectId) {
                    GroupSubjectMapping::create([
                        'class_group_id' => $classGroup->id,
                        'subject_id' => $subjectId,
                    ]);
                }
            }

            DB::commit();

            $classGroup->load([
                'subjects',
                'groupSubjectMappings.subject',
                'branch'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Class group updated successfully.',
                'data' => $classGroup
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update class group.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete Class Group
     */
    public function destroy(
        Request $request,
        ClassGroup $classGroup
    ) {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete class groups.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Isolation / IDOR Protection
        |--------------------------------------------------------------------------
        */

        if (
            $authUser->role !== 'Manager' &&
            $classGroup->branch_id != $authUser->branch_id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this class group.'
            ], 403);
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Delete Related Group Subject Mappings
            |--------------------------------------------------------------------------
            */

            GroupSubjectMapping::where(
                'class_group_id',
                $classGroup->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Detach Normal Subjects
            |--------------------------------------------------------------------------
            */

            $classGroup->subjects()->detach();

            /*
            |--------------------------------------------------------------------------
            | Delete Class Group
            |--------------------------------------------------------------------------
            */

            $classGroup->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Class group deleted successfully.'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete class group.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subjects for Class Group
     */
    public function subjects(Request $request)
    {
        $authUser = $request->user();

        // Accountant / Branch Accountant has no Academic access
        if (in_array($authUser->role, ['Branch Accountant', 'Accountant'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access subjects.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Branch Selection
        |--------------------------------------------------------------------------
        */

        if ($authUser->role === 'Manager') {

            if (!$request->filled('branch_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'branch_id is required for Manager.'
                ], 422);
            }

            $branchId = $request->branch_id;

        } else {

            // Branch Manager / Admin → own branch only
            $branchId = $authUser->branch_id;
        }

        $subjects = Subject::where('branch_id', $branchId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects
        ]);
    }
}
