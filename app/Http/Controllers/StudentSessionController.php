<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\ClassGroup;
use App\Models\ClssM;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentSessionController extends Controller
{
    private function currentBranchId(): ?int
    {
        $branchId = app(BranchContext::class)->id();

        return $branchId !== null ? (int) $branchId : null;
    }

    private function isManager(Request $request): bool
    {
        return $request->user()?->role === 'Manager';
    }

    private function authorizeMigration(Request $request): ?JsonResponse
    {
        $allowedRoles = [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ];

        if (!in_array($request->user()?->role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'You are not authorized to access Class Migration.',
            ], 403);
        }

        return null;
    }

    /**
     * Return an academic session only when the current user
     * is allowed to access its branch.
     */
    private function accessibleSession(
        Request $request,
        int $sessionId
    ): ?AcademicSession {
        $session = AcademicSession::query()->find($sessionId);

        if (!$session) {
            return null;
        }

        $branchId = $this->currentBranchId();

        if ($this->isManager($request)) {
            if (
                $branchId !== null &&
                (int) $session->branch_id !== $branchId
            ) {
                return null;
            }

            return $session;
        }

        $userBranchId = $request->user()?->branch_id;

        if (
            $userBranchId === null ||
            (int) $session->branch_id !== (int) $userBranchId
        ) {
            return null;
        }

        // If a branch is selected in BranchContext,
        // it must also match the session's branch.
        if (
            $branchId !== null &&
            (int) $session->branch_id !== $branchId
        ) {
            return null;
        }

        return $session;
    }

    /**
     * Get options for the Class Migration page.
     */
    public function options(Request $request): JsonResponse
    {
        if ($response = $this->authorizeMigration($request)) {
            return $response;
        }

        $branchId = $this->currentBranchId();

        if ($branchId === null && !$this->isManager($request)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $sessions = AcademicSession::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->with('branch:id,name')
            ->orderByDesc('name')
            ->get();

        $classes = ClssM::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->orderBy('class_name')
            ->get();

        $sections = Section::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->orderBy('section_name')
            ->get();

        $shifts = Shift::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->orderBy('name')
            ->get();

        $groups = ClassGroup::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->orderBy('group_name')
            ->get();

        return response()->json([
            'academic_sessions' => $sessions,
            'classes' => $classes,
            'sections' => $sections,
            'shifts' => $shifts,
            'class_groups' => $groups,
        ]);
    }

    /**
     * List active students matching the source placement.
     * The frontend should select all returned records by default.
     */
    public function students(Request $request): JsonResponse
    {
        if ($response = $this->authorizeMigration($request)) {
            return $response;
        }

        $data = $request->validate([
            'from_session_id' => ['required', 'integer', 'min:1'],
            'from_class_id' => ['required', 'integer', 'min:1'],
            'from_shift_id' => ['present', 'nullable', 'integer', 'min:1'],
            'from_section_id' => ['required', 'integer', 'min:1'],
            'from_group_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $sourceSession = $this->accessibleSession(
            $request,
            (int) $data['from_session_id']
        );

        if (!$sourceSession) {
            return response()->json([
                'message' => 'Source session not found or access denied.',
            ], 403);
        }

        $branchId = (int) $sourceSession->branch_id;

        $this->validatePlacement($data, 'from', $branchId);

        $query = StudentSession::query()
            ->with([
                'student',
                'classInfo',
                'section',
                'shift',
                'classGroup',
            ])
            ->where('academic_session_id', $sourceSession->id)
            ->where('branch_id', $branchId)
            ->where('class_id', $data['from_class_id'])
            ->where('section_id', $data['from_section_id'])
            ->where('status', 'active');

        $this->applyNullableFilters($query, $data, 'from');

        $students = $query->orderBy('id')->get();

        return response()->json([
            'students' => $students,
            'total' => $students->count(),
        ]);
    }

    /**
     * Validate that class, section, shift and group belong
     * to the branch of the selected academic session.
     */
    private function validatePlacement(
        array $data,
        string $prefix,
        int $branchId
    ): void {
        $classId = $data["{$prefix}_class_id"];
        $sectionId = $data["{$prefix}_section_id"];
        $shiftId = $data["{$prefix}_shift_id"] ?? null;
        $groupId = $data["{$prefix}_group_id"] ?? null;

        if (
            !ClssM::query()
                ->where('id', $classId)
                ->where('branch_id', $branchId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                "{$prefix}_class_id" => [
                    'The selected class does not belong to this branch.',
                ],
            ]);
        }

        if (
            !Section::query()
                ->where('id', $sectionId)
                ->where('branch_id', $branchId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                "{$prefix}_section_id" => [
                    'The selected section does not belong to this branch.',
                ],
            ]);
        }

        if (
            $shiftId !== null &&
            !Shift::query()
                ->where('id', $shiftId)
                ->where('branch_id', $branchId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                "{$prefix}_shift_id" => [
                    'The selected shift does not belong to this branch.',
                ],
            ]);
        }

        if (
            $groupId !== null &&
            !ClassGroup::query()
                ->where('id', $groupId)
                ->where('branch_id', $branchId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                "{$prefix}_group_id" => [
                    'The selected group does not belong to this branch.',
                ],
            ]);
        }
    }

    /**
     * Apply exact shift and group filters.
     * A null selection matches records whose value is also null.
     */
    private function applyNullableFilters(
        $query,
        array $data,
        string $prefix
    ): void {
        $shiftId = $data["{$prefix}_shift_id"] ?? null;
        $groupId = $data["{$prefix}_group_id"] ?? null;

        if ($shiftId !== null) {
            $query->where('shift_id', $shiftId);
        } else {
            $query->whereNull('shift_id');
        }

        if ($groupId !== null) {
            $query->where('class_group_id', $groupId);
        } else {
            $query->whereNull('class_group_id');
        }
    }

    /**
     * Migrate selected students to a target academic session.
     */
    public function migrate(Request $request): JsonResponse
    {
        if ($response = $this->authorizeMigration($request)) {
            return $response;
        }

        $data = $request->validate([
            'from_session_id' => ['required', 'integer', 'min:1'],
            'from_class_id' => ['required', 'integer', 'min:1'],
            'from_shift_id' => ['present', 'nullable', 'integer', 'min:1'],
            'from_section_id' => ['required', 'integer', 'min:1'],
            'from_group_id' => ['present', 'nullable', 'integer', 'min:1'],

            'to_session_id' => ['required', 'integer', 'min:1'],
            'to_class_id' => ['required', 'integer', 'min:1'],
            'to_shift_id' => ['present', 'nullable', 'integer', 'min:1'],
            'to_section_id' => ['required', 'integer', 'min:1'],
            'to_group_id' => ['present', 'nullable', 'integer', 'min:1'],

            'student_session_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'student_session_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ]);

        if (
            (int) $data['from_session_id'] ===
            (int) $data['to_session_id']
        ) {
            throw ValidationException::withMessages([
                'to_session_id' => [
                    'Source and target sessions must be different.',
                ],
            ]);
        }

        $sourceSession = $this->accessibleSession(
            $request,
            (int) $data['from_session_id']
        );

        $targetSession = $this->accessibleSession(
            $request,
            (int) $data['to_session_id']
        );

        if (!$sourceSession || !$targetSession) {
            return response()->json([
                'message' => 'Source or target session not found, or access denied.',
            ], 403);
        }

        if (
            (int) $sourceSession->branch_id !==
            (int) $targetSession->branch_id
        ) {
            throw ValidationException::withMessages([
                'to_session_id' => [
                    'Students can only be migrated between sessions of the same branch.',
                ],
            ]);
        }

        $branchId = (int) $sourceSession->branch_id;

        $this->validatePlacement($data, 'from', $branchId);
        $this->validatePlacement($data, 'to', $branchId);

        $studentSessionIds = array_map(
            'intval',
            $data['student_session_ids']
        );

        $migratedCount = DB::transaction(function () use (
            $data,
            $sourceSession,
            $studentSessionIds
        ) {
            $query = StudentSession::query()
                ->whereIn('id', $studentSessionIds)
                ->where('academic_session_id', $sourceSession->id)
                ->where('branch_id', $sourceSession->branch_id)
                ->where('class_id', $data['from_class_id'])
                ->where('section_id', $data['from_section_id'])
                ->where('status', 'active');

            $this->applyNullableFilters($query, $data, 'from');

            $sourceRecords = $query
                ->with('student')
                ->lockForUpdate()
                ->get();

            if ($sourceRecords->count() !== count($studentSessionIds)) {
                throw ValidationException::withMessages([
                    'student_session_ids' => [
                        'Some selected students do not match the source placement or are no longer active.',
                    ],
                ]);
            }

            $studentIds = $sourceRecords
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $alreadyMigrated = StudentSession::query()
                ->where('academic_session_id', $data['to_session_id'])
                ->where('branch_id', $sourceSession->branch_id)
                ->whereIn('student_id', $studentIds)
                ->exists();

            if ($alreadyMigrated) {
                throw ValidationException::withMessages([
                    'student_session_ids' => [
                        'At least one selected student already has a record in the target session. No migration was completed.',
                    ],
                ]);
            }

            foreach ($sourceRecords as $sourceRecord) {
                StudentSession::create([
                    'student_id' => $sourceRecord->student_id,
                    'academic_session_id' => $data['to_session_id'],
                    'branch_id' => $sourceRecord->branch_id,
                    'class_id' => $data['to_class_id'],
                    'section_id' => $data['to_section_id'],
                    'shift_id' => $data['to_shift_id'],
                    'class_group_id' => $data['to_group_id'],
                    'monthly_fee' => $sourceRecord->monthly_fee,
                    'status' => 'active',
                ]);

                // Preserve the original session placement and mark it completed.
                $sourceRecord->update([
                    'status' => 'completed',
                ]);

                // Keep legacy Student fields synchronized with current placement.
                if ($sourceRecord->student) {
                    $sourceRecord->student->update([
                        'class_id' => $data['to_class_id'],
                        'section_id' => $data['to_section_id'],
                        'shift_id' => $data['to_shift_id'],
                        'class_group_id' => $data['to_group_id'],
                        'monthly_fee' => $sourceRecord->monthly_fee,
                    ]);
                }
            }

            return $sourceRecords->count();
        });

        return response()->json([
            'message' => 'Class migration completed successfully.',
            'migrated_count' => $migratedCount,
        ]);
    }
    public function migrateSingle(Request $request): JsonResponse
{
    $denied = $this->authorizeMigration($request);

    if ($denied) {
        return $denied;
    }

    $data = $request->validate([
        'student_session_id' => ['required', 'integer', 'exists:student_sessions,id'],
        'from_session_id'    => ['required', 'integer', 'exists:academic_sessions,id'],
        'to_session_id'      => ['required', 'integer', 'exists:academic_sessions,id'],

        'to_class_id'        => ['required', 'integer'],
        'to_section_id'      => ['required', 'integer'],
        'to_shift_id'        => ['present', 'nullable', 'integer'],
        'to_group_id'        => ['present', 'nullable', 'integer'],
        'to_monthly_fee'     => ['required', 'numeric', 'min:0'],
    ]);

    $sourceSession = $this->accessibleSession(
        $request,
        (int) $data['from_session_id']
    );

    if (!$sourceSession) {
        return response()->json([
            'message' => 'Source academic session not found or access denied.',
        ], 403);
    }

    $targetSession = $this->accessibleSession(
        $request,
        (int) $data['to_session_id']
    );

    if (!$targetSession) {
        return response()->json([
            'message' => 'Destination academic session not found or access denied.',
        ], 403);
    }

    // A student cannot be migrated across branches.
    if (
        $sourceSession->branch_id &&
        $targetSession->branch_id &&
        (int) $sourceSession->branch_id !== (int) $targetSession->branch_id
    ) {
        return response()->json([
            'message' => 'Source and destination sessions must belong to the same branch.',
        ], 422);
    }

    $branchId = $sourceSession->branch_id
        ? (int) $sourceSession->branch_id
        : $this->currentBranchId();

    if (!$branchId) {
        return response()->json([
            'message' => 'Unable to determine the branch for migration.',
        ], 422);
    }

    $placementError = $this->validatePlacement(
        $data,
        'to',
        $branchId
    );

    if ($placementError) {
        return $placementError;
    }

    return DB::transaction(function () use ($data, $branchId) {

        // Find the selected student's existing source-session record.
        $sourceEnrollment = StudentSession::where(
            'id',
            $data['student_session_id']
        )
            ->where('academic_session_id', $data['from_session_id'])
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (!$sourceEnrollment) {
            return response()->json([
                'message' => 'Student enrollment not found in the selected source session.',
            ], 404);
        }

        // Same session: update the existing row.
        if ((int) $data['from_session_id'] === (int) $data['to_session_id']) {
            $targetEnrollment = $sourceEnrollment;
        } else {
            // Different session: update its existing row, if present.
            $targetEnrollment = StudentSession::where(
                'student_id',
                $sourceEnrollment->student_id
            )
                ->where('academic_session_id', $data['to_session_id'])
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        $placement = [
            'class_id'       => $data['to_class_id'],
            'section_id'     => $data['to_section_id'],
            'shift_id'       => $data['to_shift_id'],
            'class_group_id' => $data['to_group_id'],
            'monthly_fee'    => $data['to_monthly_fee'],
        ];

        if ($targetEnrollment) {
            // Update only the destination student_sessions row.
            $targetEnrollment->update($placement);
        } else {
            // No enrollment exists in the destination session: create one.
            $targetEnrollment = StudentSession::create(array_merge(
                $placement,
                [
                    'student_id'         => $sourceEnrollment->student_id,
                    'academic_session_id' => $data['to_session_id'],
                    'branch_id'          => $branchId,
                    'status'              => 'active',
                ]
            ));
        }

        return response()->json([
            'message' => 'Student migration completed successfully.',
            'student_session' => $targetEnrollment->load([
                'student',
                'academicSession',
                'classInfo',
                'section',
                'shift',
                'classGroup',
            ]),
        ]);
    });
}
}
