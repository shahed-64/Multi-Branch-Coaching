<?php
namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\ClssM;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentSession;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class SmsController extends Controller
{
    /**
     * SMS access:
     * Manager, Branch Manager, Admin, Branch Admin
     */
    private function authorizeSmsAccess(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($user->role, [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access SMS data.',
            ], 403);
        }

        return null;
    }

    private function currentBranchId(): ?int
    {
        $id = app(BranchContext::class)->id();

        return $id === null ? null : (int) $id;
    }

    /**
     * Resolve an academic session without allowing
     * non-managers to access another branch's data.
     */
    private function accessibleSession(
        Request $request,
        int $sessionId
    ): ?AcademicSession {
        $user = $request->user();
        $branchId = $this->currentBranchId();

        $query = AcademicSession::query()
            ->whereKey($sessionId);

        if ($user->role === 'Manager') {
            // Manager with a selected branch can access that branch only.
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
        } else {
            // Non-manager must belong to the session's branch.
            if (!$user->branch_id) {
                return null;
            }

            $query->where('branch_id', (int) $user->branch_id);

            if (
                $branchId !== null &&
                $branchId !== (int) $user->branch_id
            ) {
                return null;
            }
        }

        return $query->first();
    }

    /**
     * Only active enrollments in the selected academic session.
     */
    private function studentSessionQuery(
        AcademicSession $session
    ): Builder {
        return StudentSession::query()
            ->where('academic_session_id', $session->id)
            ->where('branch_id', $session->branch_id)
            ->where('status', 'active')
            ->whereHas('student')
            ->with([
                'student:id,full_name,student_id,phone,image,admission_date',
                'classInfo:id,class_name',
                'section:id,section_name',
                'shift:id,name',
            ]);
    }

    /**
     * Apply historical session placement filters.
     */
    private function applyPlacementFilters(
        Builder $query,
        Request $request
    ): Builder {
        foreach ([
            'class_id',
            'section_id',
            'shift_id',
            'class_group_id',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where(
                    $field,
                    $request->integer($field)
                );
            }
        }

        return $query;
    }

    /**
     * Consistent student data for the SMS frontend.
     */
    private function formatStudent(
        StudentSession $enrollment,
        ?StudentAttendance $attendance = null
    ): array {
        $student = $enrollment->student;

        return [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'student_session_id' => $enrollment->id,
            'academic_session_id' => $enrollment->academic_session_id,

            'full_name' => $student->full_name,
            'student_name' => $student->full_name,

            // Guardian mobile number
            'phone' => $student->phone,
            'guardian_mobile' => $student->phone,

            'image_url' => $student->image_url,

            'class_id' => $enrollment->class_id,
            'class_name' => $enrollment->classInfo?->class_name,

            'section_id' => $enrollment->section_id,
            'section_name' => $enrollment->section?->section_name,

            'shift_id' => $enrollment->shift_id,
            'shift_name' => $enrollment->shift?->name,

            'class_group_id' => $enrollment->class_group_id,
            'class_group_name' => $enrollment->classGroup?->name,

            'attendance_id' => $attendance?->id,
            'attendance_date' => $attendance?->date?->format('Y-m-d'),
            'attendance_status' => $attendance?->status,
        ];
    }

    /**
     * GET /api/sms/options
     *
     * Without academic_session_id:
     * returns accessible active academic sessions.
     *
     * With academic_session_id:
     * returns classes, sections and shifts actually used
     * by active enrollments in that session.
     */
    public function options(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $user = $request->user();
        $branchId = $this->currentBranchId();

        $sessionsQuery = AcademicSession::query()
            ->where('is_active', true);

        if ($user->role === 'Manager') {
            if ($branchId !== null) {
                $sessionsQuery->where('branch_id', $branchId);
            }
        } else {
            if (!$user->branch_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Your account is not assigned to a branch.',
                ], 403);
            }

            if (
                $branchId !== null &&
                $branchId !== (int) $user->branch_id
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Access denied.',
                ], 403);
            }

            $sessionsQuery->where('branch_id', (int) $user->branch_id);
        }

        $sessions = $sessionsQuery
            ->orderByDesc('start_date')
            ->get([
                'id',
                'branch_id',
                'name',
                'start_date',
                'end_date',
                'is_active',
            ]);

        if (!$request->filled('academic_session_id')) {
            return response()->json([
                'status' => true,
                'academic_sessions' => $sessions,
                'classes' => [],
                'sections' => [],
                'shifts' => [],
            ]);
        }

        $validated = $request->validate([
            'academic_session_id' => [
                'required',
                'integer',
            ],
        ]);

        $session = $this->accessibleSession(
            $request,
            (int) $validated['academic_session_id']
        );

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session is not accessible.',
            ], 403);
        }

        $enrollments = StudentSession::query()
            ->where('academic_session_id', $session->id)
            ->where('branch_id', $session->branch_id)
            ->where('status', 'active');

        $classIds = (clone $enrollments)
            ->whereNotNull('class_id')
            ->distinct()
            ->pluck('class_id');

        $sectionIds = (clone $enrollments)
            ->whereNotNull('section_id')
            ->distinct()
            ->pluck('section_id');

        $shiftIds = (clone $enrollments)
            ->whereNotNull('shift_id')
            ->distinct()
            ->pluck('shift_id');

        return response()->json([
            'status' => true,
            'academic_sessions' => $sessions,
            'selected_academic_session' => $session,
            'classes' => ClssM::query()
                ->where('branch_id', $session->branch_id)
                ->whereIn('id', $classIds)
                ->orderBy('class_name')
                ->get(['id', 'class_name']),
            'sections' => Section::query()
                ->where('branch_id', $session->branch_id)
                ->whereIn('id', $sectionIds)
                ->orderBy('section_name')
                ->get(['id', 'section_name']),
            'shifts' => Shift::query()
                ->where('branch_id', $session->branch_id)
                ->whereIn('id', $shiftIds)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * GET /api/sms/students/search
     *
     * Query parameters:
     * academic_session_id, q
     */
    public function searchStudents(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer'],
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $session = $this->accessibleSession(
            $request,
            (int) $validated['academic_session_id']
        );

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session is not accessible.',
            ], 403);
        }

        $term = trim($validated['q']);

        $query = $this->studentSessionQuery($session)
            ->whereHas('student', function (Builder $studentQuery) use ($term) {
                $studentQuery->where(function (Builder $search) use ($term) {
                    $search->where('full_name', 'like', "%{$term}%")
                        ->orWhere('student_id', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('student_id');

        $students = $query->limit(25)->get()
            ->map(fn (StudentSession $enrollment) => $this->formatStudent($enrollment))
            ->values();

        return response()->json([
            'status' => true,
            'students' => $students,
            'total' => $students->count(),
        ]);
    }

    /**
     * GET /api/sms/students
     *
     * Query parameters:
     * academic_session_id, class_id, section_id, shift_id,
     * class_group_id (optional)
     */
    public function students(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer'],
            'class_id' => ['sometimes', 'nullable', 'integer'],
            'section_id' => ['sometimes', 'nullable', 'integer'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'class_group_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $session = $this->accessibleSession(
            $request,
            (int) $validated['academic_session_id']
        );

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session is not accessible.',
            ], 403);
        }

        $query = $this->applyPlacementFilters(
            $this->studentSessionQuery($session),
            $request
        );

        $students = $query
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('shift_id')
            ->orderBy('student_id')
            ->get()
            ->map(fn (StudentSession $enrollment) => $this->formatStudent($enrollment))
            ->values();

        return response()->json([
            'status' => true,
            'academic_session_id' => $session->id,
            'total_students' => $students->count(),
            'students' => $students,
        ]);
    }


    /**
     * GET /api/sms/due-reminders
     *
     * Includes:
     * 1. Unpaid months from admission month through current month.
     * 2. Outstanding balance from recorded payment dues.
     *
     * PaymentController is not modified.
     */

    public function dueReminders(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'class_group_id' => ['nullable', 'integer'],
            'reminder_type' => [
                'nullable',
                Rule::in(['monthly', 'previous', 'all']),
            ],
        ]);

        $reminderType = $validated['reminder_type'] ?? 'all';

        $session = $this->accessibleSession(
            $request,
            (int) $validated['academic_session_id']
        );

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session is not accessible.',
            ], 403);
        }

        $enrollments = $this->applyPlacementFilters(
            $this->studentSessionQuery($session),
            $request
        )
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('shift_id')
            ->orderBy('student_id')
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'SMS due reminder students fetched successfully.',
                'academic_session_id' => $session->id,
                'reminder_type' => $reminderType,
                'total_students' => 0,
                'total_unpaid_students' => 0,
                'total_due_students' => 0,
                'total_outstanding_due' => 0,
                'students' => [],
            ]);
        }

        $paymentsBySession = Payment::query()
            ->whereIn('student_session_id', $enrollments->pluck('id'))
            ->get([
                'id',
                'student_session_id',
                'paid_amount',
                'due_amount',
                'month',
                'due_month',
                'due_payment_amount',
            ])
            ->groupBy('student_session_id');

        $monthNames = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        $currentMonth = now('Asia/Dhaka')->startOfMonth();
        $currentMonthKey = strtolower($currentMonth->format('F'));

        $students = $enrollments->map(function ($enrollment) use (
            $paymentsBySession,
            $monthNames,
            $currentMonth,
            $currentMonthKey,
            $reminderType
        ) {
            $student = $enrollment->student;

            if (!$student) {
                return null;
            }

            $payments = $paymentsBySession->get(
                $enrollment->id,
                collect()
            );

            $monthlyPayments = $payments
                ->filter(fn ($payment) => filled($payment->month))
                ->groupBy(fn ($payment) => strtolower(trim($payment->month)));

            $dueCollections = $payments
                ->filter(fn ($payment) =>
                    filled($payment->due_month)
                    && (float) $payment->due_payment_amount > 0
                )
                ->groupBy(fn ($payment) => strtolower(trim($payment->due_month)));

            $monthlyFee = (float) ($enrollment->monthly_fee ?? 0);

            /*
            * Calculate the outstanding recorded dues by month.
            */
            $outstandingDueByMonth = [];

            $monthKeys = $monthlyPayments->keys()
                ->merge($dueCollections->keys())
                ->unique()
                ->values();

            foreach ($monthKeys as $monthKey) {
                $monthRows = $monthlyPayments->get($monthKey, collect());
                $dueRows = $dueCollections->get($monthKey, collect());

                $monthDue = (float) $monthRows->sum('due_amount');
                $monthDuePaid = (float) $dueRows->sum('due_payment_amount');

                $outstandingDueByMonth[$monthKey] = max(
                    0,
                    $monthDue - $monthDuePaid
                );
            }

            /*
            * Calculate unpaid monthly fees from admission month
            * through the current month.
            */
            $unpaidMonths = [];
            $currentUnpaidAmount = 0;
            $previousUnpaidAmount = 0;

            if ($student->admission_date) {
                $admissionMonth = \Carbon\Carbon::parse(
                    $student->admission_date
                )->startOfMonth();

                $startIndex = ((int) $admissionMonth->format('Y') * 12)
                    + (int) $admissionMonth->format('n');

                $endIndex = ((int) $currentMonth->format('Y') * 12)
                    + (int) $currentMonth->format('n');

                for (
                    $monthIndex = $startIndex;
                    $monthIndex <= $endIndex;
                    $monthIndex++
                ) {
                    $monthNumber = (($monthIndex - 1) % 12) + 1;
                    $monthName = $monthNames[$monthNumber];
                    $monthKey = strtolower($monthName);

                    $monthRows = $monthlyPayments->get($monthKey, collect());
                    $dueRows = $dueCollections->get($monthKey, collect());

                    $paidThisMonth = (float) $monthRows->sum('paid_amount');
                    $duePaidThisMonth = (float) $dueRows->sum('due_payment_amount');

                    $monthDueOutstanding = (float) (
                        $outstandingDueByMonth[$monthKey] ?? 0
                    );

                    $monthUnpaid = max(
                        0,
                        $monthlyFee - $paidThisMonth - $duePaidThisMonth
                    );

                    // Do not count the same balance as both unpaid and due.
                    $monthUnpaid = max(
                        0,
                        $monthUnpaid - $monthDueOutstanding
                    );

                    if ($monthUnpaid > 0) {
                        $unpaidMonths[] = $monthName;

                        if ($monthKey === $currentMonthKey) {
                            $currentUnpaidAmount += $monthUnpaid;
                        } else {
                            $previousUnpaidAmount += $monthUnpaid;
                        }
                    }
                }
            }

            /*
            * Split recorded outstanding dues into current-month
            * and previous-month amounts.
            */
            $currentDueAmount = 0;
            $previousDueAmount = 0;

            foreach ($outstandingDueByMonth as $monthKey => $amount) {
                if ($monthKey === $currentMonthKey) {
                    $currentDueAmount += $amount;
                } else {
                    $previousDueAmount += $amount;
                }
            }

            /*
            * Select the requested reminder category.
            */
            if ($reminderType === 'monthly') {
                $selectedUnpaid = $currentUnpaidAmount;
                $selectedDue = $currentDueAmount;

                $selectedUnpaidMonths = $selectedUnpaid > 0
                    ? [$currentMonth->format('F')]
                    : [];
            } elseif ($reminderType === 'previous') {
                $selectedUnpaid = $previousUnpaidAmount;
                $selectedDue = $previousDueAmount;

                $selectedUnpaidMonths = array_values(array_filter(
                    $unpaidMonths,
                    fn ($month) =>
                        strtolower($month) !== $currentMonthKey
                ));
            } else {
                $selectedUnpaid = $currentUnpaidAmount + $previousUnpaidAmount;
                $selectedDue = $currentDueAmount + $previousDueAmount;

                $selectedUnpaidMonths = $unpaidMonths;
            }

            $selectedTotal = $selectedUnpaid + $selectedDue;

            // Exclude students who have no balance in the selected category.
            if ($selectedTotal <= 0) {
                return null;
            }

            $studentData = $this->formatStudent($enrollment);

            if ($selectedUnpaid > 0 && $selectedDue > 0) {
                $studentReminderType = 'unpaid_and_due';
            } elseif ($selectedUnpaid > 0) {
                $studentReminderType = 'unpaid';
            } else {
                $studentReminderType = 'due';
            }

            return array_merge($studentData, [
                'unpaid_months' => array_values(array_unique($selectedUnpaidMonths)),
                'unpaid_month_count' => count(array_unique($selectedUnpaidMonths)),

                'unpaid_amount' => round($selectedUnpaid, 2),
                'total_due' => round($selectedDue, 2),
                'total_due_paid' => 0,
                'outstanding_due' => round($selectedDue, 2),
                'total_outstanding' => round($selectedTotal, 2),

                'reminder_type' => $studentReminderType,
            ]);
        })
            ->filter()
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'SMS due reminder students fetched successfully.',
            'academic_session_id' => $session->id,
            'reminder_type' => $reminderType,

            'total_students' => $students->count(),

            'total_unpaid_students' => $students
                ->where('unpaid_amount', '>', 0)
                ->count(),

            'total_due_students' => $students
                ->where('outstanding_due', '>', 0)
                ->count(),

            'total_outstanding_due' => round(
                (float) $students->sum('total_outstanding'),
                2
            ),

            'students' => $students,
        ]);
    }
    /**
     * GET /api/sms/attendance
     *
     * Query parameters:
     * academic_session_id, date, status,
     * class_id, section_id, shift_id (optional)
     *
     * Supported status values: present, absent, late.
     */
    public function attendanceStudents(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],
            'status' => [
                'required',
                Rule::in(['present', 'absent', 'late']),
            ],
            'class_id' => ['sometimes', 'nullable', 'integer'],
            'section_id' => ['sometimes', 'nullable', 'integer'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'class_group_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $session = $this->accessibleSession(
            $request,
            (int) $validated['academic_session_id']
        );

        if (!$session) {
            return response()->json([
                'status' => false,
                'message' => 'Selected academic session is not accessible.',
            ], 403);
        }

        $query = $this->applyPlacementFilters(
            $this->studentSessionQuery($session),
            $request
        );

                $enrollments = $query
            ->whereHas('attendances', function (Builder $attendanceQuery) use ($validated) {
                $attendanceQuery
                    ->whereBetween('date', [
                        $validated['date_from'],
                        $validated['date_to'],
                    ])
                    ->where('status', $validated['status']);
            })
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('student_id')
            ->get();

        $attendanceMap = StudentAttendance::query()
            ->whereIn('student_session_id', $enrollments->pluck('id'))
            ->where('branch_id', $session->branch_id)
            ->whereBetween('date', [
                $validated['date_from'],
                $validated['date_to'],
            ])
            ->where('branch_id', $session->branch_id)
            ->where('status', $validated['status'])
            ->get()
            ->keyBy('student_session_id');

        $students = $enrollments
            ->map(function (StudentSession $enrollment) use ($attendanceMap) {
                return $this->formatStudent(
                    $enrollment,
                    $attendanceMap->get($enrollment->id)
                );
            })
            ->values();

        return response()->json([
            'status' => true,
            'academic_session_id' => $session->id,
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'attendance_status' => $validated['status'],
            'total_students' => $students->count(),
            'students' => $students,
        ]);
    }
    /**
 * GET /api/sms/balance
 * Check SMS Gateway balance without sending SMS.
 */
public function gatewayBalance(Request $request)
{
    if ($response = $this->authorizeSmsAccess($request)) {
        return $response;
    }

    $apiKey = config('services.sms.api_key');
    $baseUrl = rtrim(
        config('services.sms.base_url', 'https://api.sms.net.bd'),
        '/'
    );

    if (!$apiKey) {
        return response()->json([
            'status' => false,
            'message' => 'SMS Gateway API key is not configured.',
        ], 500);
    }

    try {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get($baseUrl . '/user/balance/', [
                'api_key' => $apiKey,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'status' => false,
                'message' => 'SMS Gateway balance request failed.',
                'gateway_http_status' => $response->status(),
            ], 502);
        }

        $data = $response->json();

        if (!is_array($data) || (int) ($data['error'] ?? -1) !== 0) {
            return response()->json([
                'status' => false,
                'message' => 'SMS Gateway rejected the balance request.',
                'gateway_error' => $data['error'] ?? null,
                'gateway_message' => $data['msg'] ?? null,
            ], 502);
        }

        return response()->json([
            'status' => true,
            'message' => 'SMS Gateway connected successfully.',
            'balance' => $data['data']['balance'] ?? null,
        ]);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        return response()->json([
            'status' => false,
            'message' => 'Unable to connect to SMS Gateway.',
        ], 502);
    }
 }

 /**
 * POST /api/sms/send
 */
/**
 * POST /api/sms/send
 */
    public function send(Request $request)
    {
        if ($response = $this->authorizeSmsAccess($request)) {
            return $response;
        }

        $validated = $request->validate([
            'numbers' => ['required', 'array'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $apiKey = config('services.sms.api_key');
        $baseUrl = rtrim(config('services.sms.base_url', 'https://api.sms.net.bd'), '/');

        if (!$apiKey) {
            return response()->json([
                'status' => false,
                'message' => 'SMS Gateway API key is not configured.',
            ], 500);
        }

     try {
        $response = Http::timeout(15)
            ->acceptJson()
            ->post($baseUrl . '/sendsms', [
                'api_key' => $apiKey,
                'msg'     => $validated['message'],
                'to'      => implode(',', $validated['numbers']),
            ]);

        // এটি দিয়ে লারাভেলের storage/logs/laravel.log ফাইলে গেটওয়ের আসল রেসপন্স দেখতে পাবেন
        \Log::info('SMS Gateway Response:', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        $data = $response->json();
        // ... বাকী কোড
        } catch (\Exception $e) {
            \Log::error('SMS Gateway Error: ' . $e->getMessage());
            // ...
        }
    }

    public function history(Request $request)
    {
        // আপনার SMS Log / History মডেল অনুযায়ী কোয়েরি করুন
        $logs = SmsLog::latest()
            ->paginate(10); // অথবা ->take(10)->get();

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }
}




