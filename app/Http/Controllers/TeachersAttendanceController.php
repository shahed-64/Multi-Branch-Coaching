<?php

namespace App\Http\Controllers;

use App\Models\TeachersAttendance;
use App\Models\Teacher;
use App\Models\Holiday;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TeachersAttendanceController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * Get current branch from central BranchContext.
     * --------------------------------------------------------------------------
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * --------------------------------------------------------------------------
     * Check whether attendance belongs to current branch context.
     * --------------------------------------------------------------------------
     */
    private function attendanceQuery()
    {
        $query = TeachersAttendance::query();

        $currentBranchId = $this->currentBranchId();

        /**
         * All Branches => no branch filter.
         *
         * Selected branch / non-manager => current branch only.
         */
        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        return $query;
    }

    /**
     * --------------------------------------------------------------------------
     * Check whether teacher belongs to current branch context.
     * --------------------------------------------------------------------------
     */
    private function teacherQuery()
    {
        $query = Teacher::query();

        $currentBranchId = $this->currentBranchId();

        /**
         * All Branches => all teachers.
         *
         * Selected branch / non-manager => current branch only.
         */
        if ($currentBranchId !== null) {
            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        return $query;
    }

    /**
     * --------------------------------------------------------------------------
     * Display attendance list.
     * --------------------------------------------------------------------------
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        $query = $this->attendanceQuery()
            ->with('teacher');

        if ($request->has('date')) {
            $query->where(
                'date',
                $request->date
            );
        }

        /**
         * মাস অনুযায়ী filter
         */
        if ($request->has('month')) {
            $query->whereYear(
                'date',
                Carbon::parse($request->month)->year
            )->whereMonth(
                'date',
                Carbon::parse($request->month)->month
            );
        }

        if ($request->has('shift_name')) {
            $query->where(
                'shift_name',
                $request->shift_name
            );
        }

        $attendances = $query
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * Store / Update multiple teacher attendance.
     * --------------------------------------------------------------------------
     */
    public function store(Request $request)
    {
        $request->validate([
            'date' =>
                'required|date',

            'attendances' =>
                'required|array',

            'attendances.*.teacher_id' =>
                'required|exists:teachers,id',

            'attendances.*.shift_name' =>
                'nullable|string',

            'attendances.*.status' =>
                'nullable|in:Present,Absent,Late,Leave,Off Day',

            'attendances.*.leave' =>
                'nullable|boolean',

            'attendances.*.in_time' =>
                'nullable',

            'attendances.*.out_time' =>
                'nullable',

            'attendances.*.note' =>
                'nullable|string|max:255',
        ]);

        $authUser = $request->user();

        DB::beginTransaction();

        try {
            $date = $request->date;

            $savedAttendances = [];

            /**
             * ------------------------------------------------------------------
             * Holiday Check
             * ------------------------------------------------------------------
             */
            $isHoliday = Holiday::where(
                'start_date',
                '<=',
                $date
            )
                ->where(
                    'end_date',
                    '>=',
                    $date
                )
                ->exists();

            /**
             * ------------------------------------------------------------------
             * Save Attendance
             * ------------------------------------------------------------------
             */
            foreach ($request->attendances as $item) {

                /**
                 * --------------------------------------------------------------
                 * Teacher খুঁজে বের করা
                 * --------------------------------------------------------------
                 *
                 * Current BranchContext অনুযায়ী teacher খোঁজা হবে।
                 */
                $teacherQuery = $this->teacherQuery();

                $teacher = $teacherQuery->find(
                    $item['teacher_id']
                );

                /**
                 * --------------------------------------------------------------
                 * অন্য branch-এর teacher হলে block
                 * --------------------------------------------------------------
                 */
                if (!$teacher) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' =>
                            'You are not allowed to manage this teacher attendance.',
                    ], 403);
                }

                /**
                 * --------------------------------------------------------------
                 * Teacher-এর branch
                 * --------------------------------------------------------------
                 */
                $branchId = $teacher->branch_id;

                /**
                 * --------------------------------------------------------------
                 * Branch ছাড়া teacher হলে block
                 * --------------------------------------------------------------
                 */
                if (!$branchId) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' =>
                            'This teacher is not assigned to any branch.',
                    ], 422);
                }

                $shiftName = $item['shift_name']
                    ?? 'General Shift';

                $isLeave = $item['leave'] ?? false;

                /**
                 * --------------------------------------------------------------
                 * Attendance Status
                 * --------------------------------------------------------------
                 */
                if ($isHoliday) {
                    $status = 'Off Day';
                    $inTime = null;
                    $outTime = null;
                    $note = 'Holiday / Off Day';
                } elseif ($isLeave) {
                    $status = 'Leave';
                    $inTime = null;
                    $outTime = null;
                    $note = null;
                } elseif (empty($item['status'])) {
                    $status = 'Absent';
                    $inTime = null;
                    $outTime = null;
                    $note = null;
                } else {
                    $status = $item['status'];
                    $inTime = $item['in_time'] ?? null;
                    $outTime = $item['out_time'] ?? null;
                    $note = $item['note'] ?? null;
                }

                /**
                 * --------------------------------------------------------------
                 * Save / Update
                 * --------------------------------------------------------------
                 *
                 * একই teacher + shift + date = duplicate হবে না।
                 */
                $attendance = TeachersAttendance::updateOrCreate(
                    [
                        'teacher_id' =>
                            $teacher->id,

                        'shift_name' =>
                            $shiftName,

                        'date' =>
                            $date,
                    ],
                    [
                        'branch_id' =>
                            $branchId,

                        'status' =>
                            $status,

                        'in_time' =>
                            $inTime,

                        'out_time' =>
                            $outTime,

                        'note' =>
                            $note,

                        'leave' =>
                            $isLeave,
                    ]
                );

                $savedAttendances[] = $attendance;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' =>
                    'Attendance saved successfully.',
                'data' =>
                    $savedAttendances,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to save attendance.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Display single attendance.
     * --------------------------------------------------------------------------
     */
    public function show($id)
    {
        try {
            $attendance = $this->attendanceQuery()
                ->with('teacher')
                ->find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Attendance record not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $attendance,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Error retrieving record.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Update attendance.
     * --------------------------------------------------------------------------
     */
    public function update(
        Request $request,
        $id
    ) {
        $attendance = $this->attendanceQuery()
            ->find($id);

        if (!$attendance) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Attendance record not found.',
            ], 404);
        }

        $request->validate([
            'status' =>
                'nullable|in:Present,Absent,Late,Leave,Off Day',

            'leave' =>
                'nullable|boolean',

            'in_time' =>
                'nullable',

            'out_time' =>
                'nullable',

            'note' =>
                'nullable|string|max:255',
        ]);

        try {
            $isLeave = $request->has('leave')
                ? (bool) $request->leave
                : (bool) $attendance->leave;

            if ($isLeave) {
                $attendance->update([
                    'leave' => true,
                    'status' => 'Leave',
                    'in_time' => null,
                    'out_time' => null,
                    'note' => null,
                ]);
            } else {
                $attendance->update([
                    'leave' => false,

                    'status' =>
                        $request->has('status')
                            ? $request->status
                            : $attendance->status,

                    'in_time' =>
                        $request->has('in_time')
                            ? $request->in_time
                            : $attendance->in_time,

                    'out_time' =>
                        $request->has('out_time')
                            ? $request->out_time
                            : $attendance->out_time,

                    'note' =>
                        $request->has('note')
                            ? $request->note
                            : $attendance->note,
                ]);
            }

            return response()->json([
                'status' => true,
                'message' =>
                    'Attendance updated successfully.',
                'data' =>
                    $attendance->fresh(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to update attendance.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Teacher yearly summary report.
     * --------------------------------------------------------------------------
     */
    public function teacherSummaryReport(
        Request $request
    ) {
        $year = $request->input(
            'year',
            date('Y')
        );

        try {
            $teachers = $this->teacherQuery()
                ->with('shifts')
                ->get();

            $summary = $teachers->map(
                function ($teacher) use ($year) {

                    $attendanceQuery =
                        TeachersAttendance::where(
                            'teacher_id',
                            $teacher->id
                        )
                            ->whereYear(
                                'date',
                                $year
                            );

                    /**
                     * Selected branch হলে attendance-ও
                     * selected branch-এর মধ্যেই থাকবে।
                     */
                    $currentBranchId =
                        $this->currentBranchId();

                    if ($currentBranchId !== null) {
                        $attendanceQuery->where(
                            'branch_id',
                            $currentBranchId
                        );
                    }

                    $attendances =
                        $attendanceQuery->get();

                    $shiftNames =
                        $teacher->shifts
                            ->pluck('name')
                            ->implode(', ');

                    return [
                        'id' =>
                            $teacher->id,

                        'name' =>
                            $teacher->full_name
                            ?? $teacher->name
                            ?? 'N/A',

                        'code' =>
                            $teacher->teacher_id
                            ?? $teacher->code
                            ?? 'N/A',

                        'shift' =>
                            !empty($shiftNames)
                                ? $shiftNames
                                : 'General',

                        'total_present' =>
                            $attendances
                                ->where(
                                    'status',
                                    'Present'
                                )
                                ->count(),

                        'total_late' =>
                            $attendances
                                ->where(
                                    'status',
                                    'Late'
                                )
                                ->count(),

                        'total_absent' =>
                            $attendances
                                ->where(
                                    'status',
                                    'Absent'
                                )
                                ->count(),

                        'total_leave' =>
                            $attendances
                                ->where(
                                    'status',
                                    'Leave'
                                )
                                ->count(),

                        'total_off_day' =>
                            $attendances
                                ->where(
                                    'status',
                                    'Off Day'
                                )
                                ->count(),
                    ];
                }
            );

            return response()->json(
                $summary,
                200
            );

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to fetch summary report.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Single teacher January - December yearly report.
     * --------------------------------------------------------------------------
     */
    public function singleTeacherYearlyReport(
        $id,
        Request $request
    ) {
        $year = $request->input(
            'year',
            date('Y')
        );

        try {
            /**
             * Teacher must belong to current branch context.
             */
            $teacher =
                $this->teacherQuery()
                    ->findOrFail($id);

            $monthlyReports = [];

            for (
                $month = 1;
                $month <= 12;
                $month++
            ) {
                $monthName = Carbon::create()
                    ->month($month)
                    ->format('F');

                $attendances =
                    TeachersAttendance::where(
                        'teacher_id',
                        $id
                    )
                        ->where(
                            'branch_id',
                            $teacher->branch_id
                        )
                        ->whereYear(
                            'date',
                            $year
                        )
                        ->whereMonth(
                            'date',
                            $month
                        )
                        ->get();

                $monthlyReports[] = [
                    'month_number' =>
                        $month,

                    'month_name' =>
                        $monthName,

                    'present' =>
                        $attendances
                            ->where(
                                'status',
                                'Present'
                            )
                            ->count(),

                    'late' =>
                        $attendances
                            ->where(
                                'status',
                                'Late'
                            )
                            ->count(),

                    'absent' =>
                        $attendances
                            ->where(
                                'status',
                                'Absent'
                            )
                            ->count(),

                    'leave' =>
                        $attendances
                            ->where(
                                'status',
                                'Leave'
                            )
                            ->count(),

                    'off_day' =>
                        $attendances
                            ->where(
                                'status',
                                'Off Day'
                            )
                            ->count(),

                    'total_days' =>
                        $attendances->count(),
                ];
            }

            return response()->json([
                'status' => true,
                'teacher' =>
                    $teacher,
                'year' =>
                    $year,
                'monthly_reports' =>
                    $monthlyReports,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to fetch yearly report.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Delete attendance.
     * --------------------------------------------------------------------------
     */
    public function destroy($id)
    {
        try {
            $attendance =
                $this->attendanceQuery()
                    ->find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Attendance record not found.',
                ], 404);
            }

            $attendance->delete();

            return response()->json([
                'status' => true,
                'message' =>
                    'Attendance record deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to delete record.',
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }
}
