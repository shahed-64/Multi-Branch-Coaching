<?php

namespace App\Http\Controllers;

use App\Models\StaffAttendance;
use App\Models\Staff;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StaffAttendanceController extends Controller
{
    /**
     * ============================================================
     * INDEX
     * ============================================================
     * Date / Month / Shift অনুযায়ী staff attendance পাওয়া যাবে।
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        $query = StaffAttendance::query();

        // Manager → সব branch
        // Admin / Accountant → নিজের branch
        if ($authUser && $authUser->role !== 'Manager') {
            $query->where('branch_id', $authUser->branch_id);
        }

        // Specific date
        if ($request->filled('date')) {
            $query->where('date', $request->date);
        }

        // Specific month
        if ($request->filled('month')) {
            $month = Carbon::parse($request->month);

            $query->whereYear('date', $month->year)
                ->whereMonth('date', $month->month);
        }

        // Shift filter
        if ($request->filled('shift_name')) {
            $query->where('shift_name', $request->shift_name);
        }

        $attendances = $query
            ->with(['staff'])
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attendances
        ], 200);
    }


    /**
     * ============================================================
     * STORE
     * ============================================================
     * একসাথে একাধিক Staff attendance save/update করবে।
     *
     * IMPORTANT:
     * Holiday হলে database-এ কোনো attendance record থাকবে না।
     */
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',

            'attendances' => 'required|array',

            'attendances.*.staff_id' =>
                'required|exists:staff,id',

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

            /**
             * ----------------------------------------------------
             * CHECK HOLIDAY
             * ----------------------------------------------------
             */
            $isHoliday = Holiday::where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->exists();


            /**
             * ----------------------------------------------------
             * HOLIDAY হলে কোনো attendance থাকবে না
             * ----------------------------------------------------
             */
            if ($isHoliday) {

                $holidayQuery = StaffAttendance::where(
                    'date',
                    $date
                );

                // Non-manager নিজের branch-এর record delete করবে
                if ($authUser && $authUser->role !== 'Manager') {
                    $holidayQuery->where(
                        'branch_id',
                        $authUser->branch_id
                    );
                }

                $holidayQuery->delete();

                DB::commit();

                return response()->json([
                    'status' => true,
                    'is_holiday' => true,
                    'message' =>
                        'This date is a holiday. No attendance was saved.',
                    'data' => []
                ], 200);
            }


            /**
             * ----------------------------------------------------
             * NORMAL DAY
             * ----------------------------------------------------
             */
            $savedAttendances = [];

            foreach ($request->attendances as $attendanceData) {

                $staffId = $attendanceData['staff_id'];

                $shiftName =
                    $attendanceData['shift_name']
                    ?? 'General Shift';


                /**
                 * ------------------------------------------------
                 * FIND STAFF WITH BRANCH ACCESS
                 * ------------------------------------------------
                 */
                $staffQuery = Staff::query();

                // Manager → সব staff
                // Admin / Accountant → নিজের branch-এর staff
                if ($authUser && $authUser->role !== 'Manager') {

                    $staffQuery->where(
                        'branch_id',
                        $authUser->branch_id
                    );
                }

                $staff = $staffQuery->find($staffId);


                /**
                 * অন্য branch-এর staff হলে block
                 */
                if (!$staff) {

                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' =>
                            'You are not allowed to manage this staff attendance.'
                    ], 403);
                }


                /**
                 * Staff-এর branch অবশ্যই থাকতে হবে
                 */
                if (!$staff->branch_id) {

                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' =>
                            'This staff is not assigned to any branch.'
                    ], 422);
                }


                /**
                 * ------------------------------------------------
                 * FIND EXISTING ATTENDANCE
                 * ------------------------------------------------
                 */
                $attendanceQuery = StaffAttendance::where(
                    'staff_id',
                    $staffId
                )
                    ->where(
                        'date',
                        $date
                    )
                    ->where(
                        'shift_name',
                        $shiftName
                    );

                // Non-manager নিজের branch-এর attendance-ই update করবে
                if ($authUser && $authUser->role !== 'Manager') {

                    $attendanceQuery->where(
                        'branch_id',
                        $authUser->branch_id
                    );
                }

                $attendance = $attendanceQuery->first();


                /**
                 * ------------------------------------------------
                 * STATUS DETECTION
                 * ------------------------------------------------
                 */
                $isLeave =
                    isset($attendanceData['leave']) &&
                    filter_var(
                        $attendanceData['leave'],
                        FILTER_VALIDATE_BOOLEAN
                    );

                $status =
                    $attendanceData['status']
                    ?? ($isLeave ? 'Leave' : 'Absent');


                /**
                 * ------------------------------------------------
                 * LEAVE
                 * ------------------------------------------------
                 */
                if (
                    $isLeave ||
                    $status === 'Leave'
                ) {

                    $data = [
                        'staff_id' => $staffId,
                        'branch_id' => $staff->branch_id,
                        'date' => $date,
                        'shift_name' => $shiftName,
                        'status' => 'Leave',
                        'leave' => true,
                        'in_time' => null,
                        'out_time' => null,
                        'note' =>
                            $attendanceData['note']
                            ?? 'Staff is on leave',
                    ];
                }


                /**
                 * ------------------------------------------------
                 * PRESENT / LATE
                 * ------------------------------------------------
                 */
                elseif (
                    $status === 'Present' ||
                    $status === 'Late'
                ) {

                    $data = [
                        'staff_id' => $staffId,
                        'branch_id' => $staff->branch_id,
                        'date' => $date,
                        'shift_name' => $shiftName,
                        'status' => $status,
                        'leave' => false,
                        'in_time' =>
                            $attendanceData['in_time']
                            ?? null,
                        'out_time' =>
                            $attendanceData['out_time']
                            ?? null,
                        'note' =>
                            $attendanceData['note']
                            ?? null,
                    ];
                }


                /**
                 * ------------------------------------------------
                 * ABSENT / OFF DAY
                 * ------------------------------------------------
                 */
                else {

                    $data = [
                        'staff_id' => $staffId,
                        'branch_id' => $staff->branch_id,
                        'date' => $date,
                        'shift_name' => $shiftName,
                        'status' => $status,
                        'leave' => false,
                        'in_time' => null,
                        'out_time' => null,
                        'note' =>
                            $attendanceData['note']
                            ?? null,
                    ];
                }


                /**
                 * ------------------------------------------------
                 * CREATE অথবা UPDATE
                 * ------------------------------------------------
                 */
                if ($attendance) {

                    $attendance->update($data);

                } else {

                    $attendance =
                        StaffAttendance::create($data);
                }


                $savedAttendances[] =
                    $attendance->fresh();
            }


            DB::commit();

            return response()->json([
                'status' => true,
                'is_holiday' => false,
                'message' =>
                    'Attendance saved successfully.',
                'data' => $savedAttendances
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to save attendance.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * ============================================================
     * SHOW
     * ============================================================
     */
    public function show($id)
    {
        try {

            $authUser = request()->user();

            $query = StaffAttendance::with('staff');

            // Non-manager → নিজের branch
            if ($authUser && $authUser->role !== 'Manager') {

                $query->where(
                    'branch_id',
                    $authUser->branch_id
                );
            }

            $attendance = $query->find($id);

            if (!$attendance) {

                return response()->json([
                    'status' => false,
                    'message' =>
                        'Attendance record not found.'
                ], 404);
            }


            /**
             * Holiday safety check
             */
            $isHoliday = Holiday::where(
                'start_date',
                '<=',
                $attendance->date
            )
                ->where(
                    'end_date',
                    '>=',
                    $attendance->date
                )
                ->exists();


            if ($isHoliday) {

                $attendance->delete();

                return response()->json([
                    'status' => true,
                    'is_holiday' => true,
                    'message' =>
                        'This date is a holiday. Attendance record removed.',
                    'data' => null
                ], 200);
            }


            return response()->json([
                'status' => true,
                'data' => $attendance
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' =>
                    'Error retrieving record.',
                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * ============================================================
     * UPDATE
     * ============================================================
     */
    public function update(Request $request, $id)
    {
        $authUser = $request->user();

        $query = StaffAttendance::query();

        // Non-manager → নিজের branch
        if ($authUser && $authUser->role !== 'Manager') {

            $query->where(
                'branch_id',
                $authUser->branch_id
            );
        }

        $attendance = $query->find($id);

        if (!$attendance) {

            return response()->json([
                'status' => false,
                'message' =>
                    'Attendance record not found.'
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

            /**
             * ----------------------------------------------------
             * CHECK HOLIDAY
             * ----------------------------------------------------
             */
            $isHoliday = Holiday::where(
                'start_date',
                '<=',
                $attendance->date
            )
                ->where(
                    'end_date',
                    '>=',
                    $attendance->date
                )
                ->exists();


            /**
             * Holiday হলে record delete
             */
            if ($isHoliday) {

                $attendance->delete();

                return response()->json([
                    'status' => true,
                    'is_holiday' => true,
                    'message' =>
                        'This date is a holiday. Attendance record deleted.',
                    'data' => null
                ], 200);
            }


            /**
             * ----------------------------------------------------
             * LEAVE
             * ----------------------------------------------------
             */
            $isLeave =
                $request->has('leave')
                ? filter_var(
                    $request->leave,
                    FILTER_VALIDATE_BOOLEAN
                )
                : (bool) $attendance->leave;


            if (
                $isLeave ||
                $request->status === 'Leave'
            ) {

                $attendance->update([
                    'leave' => true,
                    'status' => 'Leave',
                    'in_time' => null,
                    'out_time' => null,
                    'note' =>
                        $request->has('note')
                        ? $request->note
                        : 'Staff is on leave',
                ]);
            }


            /**
             * ----------------------------------------------------
             * NORMAL ATTENDANCE
             * ----------------------------------------------------
             */
            else {

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
                    $attendance->fresh()
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to update attendance.',
                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * ============================================================
     * STAFF SUMMARY REPORT
     * ============================================================
     */
    public function staffSummaryReport(Request $request)
    {
        $year =
            $request->input(
                'year',
                date('Y')
            );

        $authUser = $request->user();

        try {

            $staffQuery = Staff::query();

            // Non-manager → নিজের branch
            if ($authUser && $authUser->role !== 'Manager') {

                $staffQuery->where(
                    'branch_id',
                    $authUser->branch_id
                );
            }

            $staffs = $staffQuery->get();


            $summary = $staffs->map(
                function ($staff) use ($year) {

                    $attendances =
                        StaffAttendance::where(
                            'staff_id',
                            $staff->id
                        )
                            ->where(
                                'branch_id',
                                $staff->branch_id
                            )
                            ->whereYear(
                                'date',
                                $year
                            )
                            ->get();


                    /**
                     * Holiday date বাদ দেওয়া
                     */
                    $attendances =
                        $attendances->filter(
                            function ($attendance) {

                                return !Holiday::where(
                                    'start_date',
                                    '<=',
                                    $attendance->date
                                )
                                    ->where(
                                        'end_date',
                                        '>=',
                                        $attendance->date
                                    )
                                    ->exists();
                            }
                        );


                    return [
                        'id' =>
                            $staff->id,

                        'name' =>
                            $staff->name
                            ?? 'N/A',

                        'code' =>
                            $staff->user_name
                            ?? $staff->staff_code
                            ?? 'STF-' . $staff->id,

                        'skill' =>
                            $staff->skill
                            ?? $staff->role
                            ?? 'N/A',

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
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * ============================================================
     * SINGLE STAFF YEARLY REPORT
     * ============================================================
     */
    public function singleStaffYearlyReport(
        $id,
        Request $request
    ) {
        $year =
            $request->input(
                'year',
                date('Y')
            );

        $authUser = $request->user();

        try {

            $staffQuery = Staff::query();

            // Non-manager → নিজের branch
            if ($authUser && $authUser->role !== 'Manager') {

                $staffQuery->where(
                    'branch_id',
                    $authUser->branch_id
                );
            }

            $staff = $staffQuery->findOrFail($id);


            $monthlyReports = [];


            for (
                $month = 1;
                $month <= 12;
                $month++
            ) {

                $monthName =
                    Carbon::create()
                        ->month($month)
                        ->format('F');


                $attendances =
                    StaffAttendance::where(
                        'staff_id',
                        $id
                    )
                        ->where(
                            'branch_id',
                            $staff->branch_id
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


                /**
                 * Holiday dates বাদ
                 */
                $attendances =
                    $attendances->filter(
                        function ($attendance) {

                            return !Holiday::where(
                                'start_date',
                                '<=',
                                $attendance->date
                            )
                                ->where(
                                    'end_date',
                                    '>=',
                                    $attendance->date
                                )
                                ->exists();
                        }
                    );


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
                'staff' =>
                    $staff,
                'year' =>
                    $year,
                'monthly_reports' =>
                    $monthlyReports
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to fetch yearly report.',
                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }


    /**
     * ============================================================
     * DESTROY
     * ============================================================
     */
    public function destroy($id)
    {
        try {

            $authUser = request()->user();

            $query = StaffAttendance::query();

            // Non-manager → নিজের branch
            if ($authUser && $authUser->role !== 'Manager') {

                $query->where(
                    'branch_id',
                    $authUser->branch_id
                );
            }

            $attendance = $query->find($id);

            if (!$attendance) {

                return response()->json([
                    'status' => false,
                    'message' =>
                        'Attendance record not found.'
                ], 404);
            }


            $attendance->delete();

            return response()->json([
                'status' => true,
                'message' =>
                    'Attendance record deleted successfully.'
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' =>
                    'Failed to delete record.',
                'error' =>
                    $e->getMessage()
            ], 500);
        }
    }
}
