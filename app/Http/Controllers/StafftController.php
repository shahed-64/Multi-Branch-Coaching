<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\StudentAttendance;
use App\Models\TeachersAttendance;
use App\Models\StaffAttendance;
use App\Models\Holiday;
use App\Models\Result;
use App\Models\Examination;
use App\Services\BranchContext;
use App\Models\AcademicSession;
use App\Models\StudentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StafftController extends Controller
{
    /**
     * --------------------------------------------------------------------------
     * AUTH USER
     * --------------------------------------------------------------------------
     */
    private function authUser(Request $request)
    {
        return $request->user();
    }

    /**
     * --------------------------------------------------------------------------
     * CURRENT BRANCH
     * --------------------------------------------------------------------------
     *
     * null = All Branches for Manager
     * branch id = selected/own branch
     *
     * --------------------------------------------------------------------------
     */
    private function currentBranchId(): ?int
    {
        return app(BranchContext::class)->id();
    }

    /**
     * --------------------------------------------------------------------------
     * STAFF VIEW ACCESS
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
     *
     * --------------------------------------------------------------------------
     */
    private function canManageStaff($authUser): bool
    {
        return $authUser
            && $authUser->role === 'Manager';
    }

    /**
     * --------------------------------------------------------------------------
     * STAFF ACCESS CHECK
     * --------------------------------------------------------------------------
     */
    private function canAccessStaff(
        $authUser,
        Staff $staff
    ): bool {
        if (!$authUser) {
            return false;
        }

        $currentBranchId = $this->currentBranchId();

        /**
         * Manager + All Branches
         */
        if (
            $authUser->role === 'Manager'
            && $currentBranchId === null
        ) {
            return true;
        }

        /**
         * Selected Branch / Own Branch
         */
        if ($currentBranchId === null) {
            return false;
        }

        return $staff->branch_id !== null
            && (int) $staff->branch_id === (int) $currentBranchId;
    }

    /**
     * --------------------------------------------------------------------------
     * FORBIDDEN RESPONSE
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
     * STAFF INDEX
     * --------------------------------------------------------------------------
     */
    public function index(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $query = Staff::with([
            'shift',
            'branch',
        ]);

        $currentBranchId = $this->currentBranchId();

        /**
         * Manager + All Branches
         */
        if (
            $currentBranchId === null
            && $authUser->role === 'Manager'
        ) {
            // All branches
        } else {
            /**
             * Non-Manager must have branch
             */
            if ($currentBranchId === null) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Your account is not assigned to any branch.',
                ], 403);
            }

            $query->where(
                'branch_id',
                $currentBranchId
            );
        }

        return response()->json([
            'status' => true,
            'staff' => $query
                ->orderBy('id', 'desc')
                ->get(),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STORE STAFF
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
         * Only Manager
         */
        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can create staff.'
            );
        }

        /**
         * VALIDATION
         */
        $request->validate([
            'name' => 'required|string',
            'user_name' => 'required|string',
            'skill' => 'required|string',
            'role' => [
            'required',
            'string',
            'max:100',
            'not_in:Others',
        ],
            'shift_id' => 'required|exists:shifts,id',
            'branch_id' => 'nullable|exists:branches,id',
            'email' => 'required|email|unique:staff,email',
            'password' => 'required|confirmed',
            'image' =>
                'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'salary' => 'nullable|numeric',
        ]);

        /**
         * BRANCH SECURITY
         */
        $currentBranchId = $this->currentBranchId();

        /**
         * Manager + Selected Branch
         */
        if ($currentBranchId !== null) {
            if ($request->role === 'Manager') {
                $branchId = $request->branch_id ?? null;
            } else {
                $branchId = $currentBranchId;
            }
        } else {
            /**
             * Manager + All Branches
             */
            $branchId = $request->branch_id;

            /**
             * Non-Manager requires branch
             */
            if (
                $request->role !== 'Manager'
                && !$branchId
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required for non-Manager staff.',
                ], 422);
            }
        }

        /**
         * SHIFT BRANCH VALIDATION
         */
        $shift = Shift::findOrFail(
            $request->shift_id
        );

        /**
         * Non-Manager staff must use same branch shift
         */
        if (
            $request->role !== 'Manager'
            && (
                !$branchId
                || (int) $shift->branch_id !== (int) $branchId
            )
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to the selected branch.',
            ], 422);
        }

        /**
         * IMAGE
         */
        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request
                ->file('image')
                ->store('staffs', 'public');
        }

        /**
         * CREATE STAFF
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
            'status' => true,
            'message' =>
                'Staff Created Successfully',
            'staff' =>
                $staff->load([
                    'shift',
                    'branch',
                ]),
        ], 201);
    }

    /**
     * --------------------------------------------------------------------------
     * SHOW STAFF
     * --------------------------------------------------------------------------
     */
    public function show(
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

        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $staff = Staff::findOrFail($id);

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
                    'branch',
                ]),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * EDIT STAFF
     * --------------------------------------------------------------------------
     */
    public function edit(
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

        if (!$this->canViewStaff($authUser)) {
            return $this->forbidden(
                'You are not authorized to access staff.'
            );
        }

        $staff = Staff::findOrFail($id);

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
                    'branch',
                ]),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * UPDATE STAFF
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

        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can update staff.'
            );
        }

        $staff = Staff::findOrFail($id);

        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot update staff from another branch.'
            );
        }

        /**
         * VALIDATION
         */
        $request->validate([
            'name' =>
                'required|string',
            'user_name' =>
                'required|string',
            'skill' =>
                'required|string',
            'role' => [
            'required',
            'string',
            'max:100',
            'not_in:Others',
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
                'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'salary' =>
                'nullable|numeric',
        ]);

        /**
         * BRANCH SECURITY
         */
        $currentBranchId = $this->currentBranchId();

        if ($currentBranchId !== null) {
            if ($request->role === 'Manager') {
                $branchId = $request->branch_id ?? null;
            } else {
                $branchId = $currentBranchId;
            }
        } else {
            $branchId = $request->branch_id;

            if (
                $request->role !== 'Manager'
                && !$branchId
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'Branch is required for non-Manager staff.',
                ], 422);
            }
        }

        /**
         * SHIFT BRANCH VALIDATION
         */
        $shift = Shift::findOrFail(
            $request->shift_id
        );

        if (
            $request->role !== 'Manager'
            && (
                !$branchId
                || (int) $shift->branch_id !== (int) $branchId
            )
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Selected shift does not belong to the selected branch.',
            ], 422);
        }

        /**
         * UPDATE DATA
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
         * IMAGE
         */
        if ($request->hasFile('image')) {
            if (
                $staff->image
                && Storage::disk('public')->exists(
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
         * PASSWORD
         */
        if ($request->filled('password')) {
            $data['password'] =
                Hash::make(
                    $request->password
                );
        }

        $staff->update($data);

        return response()->json([
            'status' => true,
            'message' =>
                'Staff updated successfully',
            'staff' =>
                $staff->load([
                    'shift',
                    'branch',
                ]),
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DELETE STAFF
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

        if (!$this->canManageStaff($authUser)) {
            return $this->forbidden(
                'Only Manager can delete staff.'
            );
        }

        $staff = Staff::findOrFail($id);

        if (!$this->canAccessStaff($authUser, $staff)) {
            return $this->forbidden(
                'You cannot delete staff from another branch.'
            );
        }

        /**
         * Prevent deleting own account
         */
        if ($staff->id == $authUser->id) {
            return $this->forbidden(
                'You cannot delete your own account.'
            );
        }

        /**
         * DELETE IMAGE
         */
        if (
            $staff->image
            && Storage::disk('public')->exists(
                $staff->image
            )
        ) {
            Storage::disk('public')->delete(
                $staff->image
            );
        }

        $staff->delete();

        return response()->json([
            'status' => true,
            'message' =>
                'Staff deleted successfully',
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * STAFF IMAGE
     * --------------------------------------------------------------------------
     */
    public function image($filename)
    {
        $path = storage_path(
            'app/public/staffs/' . $filename
        );

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Access-Control-Allow-Origin' =>
                'http://localhost:5173',
            'Access-Control-Allow-Methods' =>
                'GET, OPTIONS',
            'Access-Control-Allow-Headers' =>
                'Content-Type',
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
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $staff = Staff::where(
        'email',
        $request->email
    )->first();

    if (
        !$staff ||
        !Hash::check($request->password, $staff->password)
    ) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid credentials',
        ], 401);
    }

    // Roles allowed to login
    $allowedLoginRoles = [
        'Manager',
        'Branch Manager',
        'Admin',
        'Branch Admin',
        'Accountant',
        'Branch Accountant',
        'Result Entry',
    ];

    // Block custom roles such as Guard and Swiper
    if (!in_array($staff->role, $allowedLoginRoles, true)) {
        return response()->json([
            'status' => false,
            'message' => 'This role is not allowed to login.',
        ], 403);
        }

        $token = $staff
            ->createToken('staff-token')
            ->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'staff' => $staff->load([
                'shift',
                'branch',
            ]),
            'token' => $token,
        ]);
    }

    /**
     * --------------------------------------------------------------------------
     * DASHBOARD
     * --------------------------------------------------------------------------
     */

public function dashboard(Request $request)
{
    $authUser = $this->authUser($request);

    if (!$authUser) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

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

    if (
        $authUser->role !== 'Manager'
        && !$authUser->branch_id
    ) {
        return response()->json([
            'status' => false,
            'message' => 'Your account is not assigned to any branch.',
        ], 403);
    }

    $request->validate([
        'academic_session_id' => [
            'nullable',
            'integer',
            'exists:academic_sessions,id',
        ],
    ]);

    $selectedBranchId = null;

    if ($authUser->role === 'Manager') {
        $headerBranchId = $request->header('X-Branch-Id');

        if ($headerBranchId !== null && $headerBranchId !== '') {
            $selectedBranch = Branch::find($headerBranchId);

            if (!$selectedBranch) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected branch not found.',
                ], 404);
            }

            $selectedBranchId = (int) $selectedBranch->id;
        }
    } else {
        $selectedBranchId = (int) $authUser->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Academic Session
    |--------------------------------------------------------------------------
    */
    $sessionQuery = AcademicSession::query();

    if ($selectedBranchId !== null) {
        $sessionQuery->where('branch_id', $selectedBranchId);
    }

    if ($request->filled('academic_session_id')) {
        $academicSession = (clone $sessionQuery)
            ->where('id', $request->integer('academic_session_id'))
            ->first();

        if (!$academicSession) {
            return response()->json([
                'status' => false,
                'message' =>
                    'The selected academic session does not belong to your selected branch.',
            ], 403);
        }
    } else {
        $academicSession = (clone $sessionQuery)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    if (!$academicSession) {
        return response()->json([
            'status' => false,
            'message' =>
                'No active academic session was found. Please select an academic session.',
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Session Branch
    |--------------------------------------------------------------------------
    | A session belongs to a specific branch.
    | Use that branch to prevent mixing different branches' student data.
    */
    $selectedBranchId = (int) $academicSession->branch_id;

    /*
    |--------------------------------------------------------------------------
    | Dashboard Queries
    |--------------------------------------------------------------------------
    */
   /*
    |--------------------------------------------------------------------------
    | Dashboard Staff & Teacher Queries
    |--------------------------------------------------------------------------
    */
    $staffQuery = Staff::query();
    $teacherQuery = Teacher::query();

    if ($selectedBranchId !== null) {
        // Selected branch staff
        $staffQuery->where('branch_id', $selectedBranchId);

        // Selected branch teachers
        $teacherQuery->where('branch_id', $selectedBranchId);
    }

/*
|--------------------------------------------------------------------------
| Filter Staff by Record Creation Date
|--------------------------------------------------------------------------
| Staff table has no join_date column.
| Therefore, created_at is used as an approximate date.
|--------------------------------------------------------------------------
*/
$staffQuery->whereDate(
    'created_at',
    '<=',
    $academicSession->end_date
);

/*
|--------------------------------------------------------------------------
| Filter Teachers by Joining Date
|--------------------------------------------------------------------------
*/
$teacherQuery->whereDate(
    'join_date',
    '<=',
    $academicSession->end_date
);

    /*
    |--------------------------------------------------------------------------
    | Students Registered in Selected Session
    |--------------------------------------------------------------------------
    */
    $studentSessionQuery = StudentSession::query()
        ->where('academic_session_id', $academicSession->id)
        ->where('branch_id', $selectedBranchId);

    $totalStudents = $studentSessionQuery->count();

    /*
    |--------------------------------------------------------------------------
    | Recent Staff
    |--------------------------------------------------------------------------
    */
    $recentStaff = Staff::with([
        'shift',
        'branch',
    ])
        ->where('branch_id', $selectedBranchId)
        ->latest()
        ->take(5)
        ->get();

    /*
    |--------------------------------------------------------------------------
    | Total Branches
    |--------------------------------------------------------------------------
    */
    $totalBranches = $authUser->role === 'Manager'
        ? Branch::count()
        : 1;

    /*
    |--------------------------------------------------------------------------
    | Notifications: New Admissions
    |--------------------------------------------------------------------------
    */
    $sessionStudentIds = StudentSession::query()
        ->where('academic_session_id', $academicSession->id)
        ->where('branch_id', $selectedBranchId)
        ->select('student_id');

    $notifications = collect();

    $newAdmissions = Student::with([
        'classInfo',
        'branch',
    ])
        ->where('branch_id', $selectedBranchId)
        ->whereNotNull('admission_date')
        ->whereIn('id', $sessionStudentIds)
        ->orderByDesc('admission_date')
        ->orderByDesc('id')
        ->take(5)
        ->get();

    foreach ($newAdmissions as $student) {
        if (!$student->admission_date) {
            continue;
        }

        $admissionDate = \Carbon\Carbon::parse(
            $student->admission_date
        );

        $className = optional($student->classInfo)->name
            ?? optional($student->classInfo)->class_name
            ?? 'a class';

        $notificationTime = $admissionDate->isToday()
            ? 'Today'
            : ($admissionDate->isYesterday()
                ? 'Yesterday'
                : $admissionDate->diffForHumans());

        $notifications->push([
            'title' => 'New Admission',
            'text' => $student->full_name
                . ' has been admitted to '
                . $className . '.',
            'time' => $notificationTime,
            'type' => 'blue',
            'icon' => '🎓',
            'date' => $admissionDate->toDateString(),
            'student_id' => $student->id,
            'branch_id' => $student->branch_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications: Results in Selected Session
    |--------------------------------------------------------------------------
    */
    $recentResults = Result::with([
        'student.classInfo',
        'student.branch',
    ])
        ->where('branch_id', $selectedBranchId)
        ->where('student_session_id', function ($query) use ($academicSession) {
            $query->select('id')
                ->from('student_sessions')
                ->where('academic_session_id', $academicSession->id)
                ->where('branch_id', $academicSession->branch_id)
                ->limit(1);
        })
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->take(5)
        ->get();

    /*
     * A student session may have many results, so also load results
     * belonging to every registration in the selected session.
     */
    $recentResults = Result::with([
        'student.classInfo',
        'student.branch',
    ])
        ->where('branch_id', $selectedBranchId)
        ->whereIn(
            'student_session_id',
            StudentSession::query()
                ->where('academic_session_id', $academicSession->id)
                ->where('branch_id', $selectedBranchId)
                ->select('id')
        )
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->take(5)
        ->get();

    foreach ($recentResults as $result) {
        if (!$result->student || !$result->created_at) {
            continue;
        }

        $resultDate = \Carbon\Carbon::parse($result->created_at);

        $studentName = $result->student->full_name;

        $className = optional($result->student->classInfo)->name
            ?? optional($result->student->classInfo)->class_name
            ?? 'Class';

        $examType = $result->exam_type ?: 'Examination';

        $notificationTime = $resultDate->isToday()
            ? 'Today'
            : ($resultDate->isYesterday()
                ? 'Yesterday'
                : $resultDate->diffForHumans());

        $notifications->push([
            'title' => 'Recent Published Result',
            'text' => $studentName
                . "'s " . $className . ' '
                . $examType
                . ' result has been published.',
            'time' => $notificationTime,
            'type' => 'green',
            'icon' => '🏆',
            'date' => $resultDate->toDateString(),
            'student_id' => $result->student_id,
            'result_id' => $result->id,
            'branch_id' => $result->branch_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications: Examinations
    |--------------------------------------------------------------------------
    */
    $examinations = Examination::query()
        ->where('branch_id', $selectedBranchId)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->take(5)
        ->get();

    foreach ($examinations as $examination) {
        if (!$examination->created_at) {
            continue;
        }

        $examDate = \Carbon\Carbon::parse(
            $examination->created_at
        );

        $examType = $examination->examination_type
            ?: 'Examination';

        $examYear = $examination->examination_year ?: '';

        $notificationTime = $examDate->isToday()
            ? 'Today'
            : ($examDate->isYesterday()
                ? 'Yesterday'
                : $examDate->diffForHumans());

        $notifications->push([
            'title' => 'Upcoming Examination',
            'text' => $examType . ' examination '
                . ($examYear ? 'for ' . $examYear . ' ' : '')
                . 'has been scheduled.',
            'time' => $notificationTime,
            'type' => 'orange',
            'icon' => '📝',
            'date' => $examDate->toDateString(),
            'examination_id' => $examination->id,
            'branch_id' => $examination->branch_id,
        ]);
    }

    $notifications = $notifications
        ->sortByDesc('date')
        ->values()
        ->take(5);

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status' => true,

        'academic_session' => [
            'id' => $academicSession->id,
            'name' => $academicSession->name,
            'is_active' => (bool) $academicSession->is_active,
            'branch_id' => $academicSession->branch_id,
        ],

        'total_staff' => $staffQuery->count(),
        'total_students' => $totalStudents,
        'total_teachers' => $teacherQuery->count(),
        'total_branches' => $totalBranches,

        'recent_staff' => $recentStaff,
        'notifications' => $notifications,

        'user' => [
            'id' => $authUser->id,
            'name' => $authUser->name,
            'role' => $authUser->role,
            'branch_id' => $authUser->branch_id,
            'designation' => $authUser->skill ?? $authUser->role,
            'image' => $authUser->image
                ? asset('storage/' . $authUser->image)
                : null,
        ],
    ]);
}

    /**
     * --------------------------------------------------------------------------
     * DASHBOARD ATTENDANCE TREND
     * --------------------------------------------------------------------------
     */

public function attendanceTrend(Request $request)
{
    $authUser = $this->authUser($request);

    if (!$authUser) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    if (!in_array($authUser->role, [
        'Manager',
        'Branch Manager',
        'Admin',
        'Branch Admin',
    ])) {
        return $this->forbidden(
            'You are not authorized to access attendance trend.'
        );
    }

    if (
        $authUser->role !== 'Manager'
        && !$authUser->branch_id
    ) {
        return response()->json([
            'status' => false,
            'message' => 'Your account is not assigned to any branch.',
        ], 403);
    }

    $request->validate([
        'date' => 'nullable|date',
        'academic_session_id' => [
            'nullable',
            'integer',
            'exists:academic_sessions,id',
        ],
    ]);

    $selectedDate = $request->input(
        'date',
        now()->toDateString()
    );

    /*
    |--------------------------------------------------------------------------
    | Resolve Branch
    |--------------------------------------------------------------------------
    */
    $selectedBranchId = null;

    if ($authUser->role === 'Manager') {
        $headerBranchId = $request->header('X-Branch-Id');

        if ($headerBranchId !== null && $headerBranchId !== '') {
            $selectedBranch = Branch::find($headerBranchId);

            if (!$selectedBranch) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected branch not found.',
                ], 404);
            }

            $selectedBranchId = (int) $selectedBranch->id;
        }
    } else {
        $selectedBranchId = (int) $authUser->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Academic Session
    |--------------------------------------------------------------------------
    */
    $sessionQuery = AcademicSession::query();

    if ($selectedBranchId !== null) {
        $sessionQuery->where('branch_id', $selectedBranchId);
    }

    if ($request->filled('academic_session_id')) {
        $academicSession = (clone $sessionQuery)
            ->where('id', $request->integer('academic_session_id'))
            ->first();

        if (!$academicSession) {
            return response()->json([
                'status' => false,
                'message' =>
                    'The selected academic session does not belong to your selected branch.',
            ], 403);
        }
    } else {
        $academicSession = (clone $sessionQuery)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    if (!$academicSession) {
        return response()->json([
            'status' => false,
            'message' =>
                'No active academic session was found. Please select an academic session.',
        ], 422);
    }

    $selectedBranchId = (int) $academicSession->branch_id;

    /*
    |--------------------------------------------------------------------------
    | Date Range: Seven Days
    |--------------------------------------------------------------------------
    */
    $endDate = \Carbon\Carbon::parse($selectedDate)->startOfDay();
    $startDate = $endDate->copy()->subDays(6);

    /*
    |--------------------------------------------------------------------------
    | Students Registered in Selected Session
    |--------------------------------------------------------------------------
    */
    $studentSessionIds = StudentSession::query()
        ->where('academic_session_id', $academicSession->id)
        ->where('branch_id', $selectedBranchId)
        ->select('id');

    $totalStudents = StudentSession::query()
        ->where('academic_session_id', $academicSession->id)
        ->where('branch_id', $selectedBranchId)
        ->count();

    /*
    |--------------------------------------------------------------------------
    | Attendance Records for Selected Session Only
    |--------------------------------------------------------------------------
    */
    $attendanceQuery = StudentAttendance::query()
        ->whereBetween('date', [
            $startDate->toDateString(),
            $endDate->toDateString(),
        ])
        ->whereIn('student_session_id', $studentSessionIds);

    $attendanceRecords = $attendanceQuery->get([
        'date',
        'status',
        'student_session_id',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Build Seven-Day Trend
    |--------------------------------------------------------------------------
    */
    $trend = [];

    for ($i = 0; $i < 7; $i++) {
        $date = $startDate->copy()->addDays($i);
        $dateString = $date->toDateString();

        $dayRecords = $attendanceRecords->filter(
            function ($record) use ($dateString) {
                return \Carbon\Carbon::parse($record->date)
                    ->toDateString() === $dateString;
            }
        );

        $present = $dayRecords
            ->where('status', 'present')
            ->count();

        $absent = $dayRecords
            ->where('status', 'absent')
            ->count();

        $late = $dayRecords
            ->where('status', 'late')
            ->count();

        $leave = $dayRecords
            ->where('status', 'leave')
            ->count();

        $attendanceTotal = $present + $absent + $late + $leave;

        $presentPercentage = $totalStudents > 0
            ? round(($present / $totalStudents) * 100, 2)
            : 0;

        $absentPercentage = $totalStudents > 0
            ? round(($absent / $totalStudents) * 100, 2)
            : 0;

        $latePercentage = $totalStudents > 0
            ? round(($late / $totalStudents) * 100, 2)
            : 0;

        $leavePercentage = $totalStudents > 0
            ? round(($leave / $totalStudents) * 100, 2)
            : 0;

        $averageStatus = $totalStudents > 0
            ? round(($attendanceTotal / $totalStudents) * 100, 2)
            : 0;

        $trend[] = [
            'date' => $dateString,
            'day' => $date->format('D'),
            'day_number' => $date->format('d'),
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'leave' => $leave,
            'present_percentage' => $presentPercentage,
            'absent_percentage' => $absentPercentage,
            'late_percentage' => $latePercentage,
            'leave_percentage' => $leavePercentage,
            'average_status' => $averageStatus,
        ];
    }

    $selectedDay = collect($trend)
        ->firstWhere('date', $selectedDate);

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status' => true,

        'academic_session' => [
            'id' => $academicSession->id,
            'name' => $academicSession->name,
            'is_active' => (bool) $academicSession->is_active,
            'branch_id' => $academicSession->branch_id,
        ],

        'date' => $selectedDate,
        'total_students' => $totalStudents,

        'present' => $selectedDay['present'] ?? 0,
        'absent' => $selectedDay['absent'] ?? 0,
        'late' => $selectedDay['late'] ?? 0,
        'leave' => $selectedDay['leave'] ?? 0,

        'present_percentage' => $selectedDay['present_percentage'] ?? 0,
        'absent_percentage' => $selectedDay['absent_percentage'] ?? 0,
        'late_percentage' => $selectedDay['late_percentage'] ?? 0,
        'leave_percentage' => $selectedDay['leave_percentage'] ?? 0,
        'average_status' => $selectedDay['average_status'] ?? 0,

        'trend' => $trend,
    ]);
}

    /**
     * --------------------------------------------------------------------------
     * DASHBOARD CURRENT STAFF & TEACHERS ATTENDANCE
     * --------------------------------------------------------------------------
     */
    public function currentAttendance(Request $request)
    {
        $authUser = $this->authUser($request);

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($authUser->role, [
            'Manager',
            'Branch Manager',
            'Admin',
            'Branch Admin',
        ])) {
            return $this->forbidden(
                'You are not authorized to access current attendance.'
            );
        }

        if (
            $authUser->role !== 'Manager'
            && !$authUser->branch_id
        ) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Your account is not assigned to any branch.',
            ], 403);
        }

        $today =
            now()->toDateString();

        /**
         * ----------------------------------------------------------------------
         * Branch Selection
         * ----------------------------------------------------------------------
         */
        $selectedBranchId = null;

        if ($authUser->role === 'Manager') {
            $selectedBranchId =
                $request->header('X-Branch-Id');

            if (
                $selectedBranchId !== null
                && $selectedBranchId !== ''
            ) {
                $selectedBranch =
                    Branch::find($selectedBranchId);

                if (!$selectedBranch) {
                    return response()->json([
                        'status' => false,
                        'message' =>
                            'Selected branch not found.',
                    ], 404);
                }
            }
        } else {
            $selectedBranchId =
                $authUser->branch_id;
        }

        /**
         * ----------------------------------------------------------------------
         * Holiday Check
         * ----------------------------------------------------------------------
         */
        $holidayQuery =
            Holiday::query()
                ->where(
                    'start_date',
                    '<=',
                    $today
                )
                ->where(
                    'end_date',
                    '>=',
                    $today
                );

        if (
            $selectedBranchId !== null
            && $selectedBranchId !== ''
        ) {
            $holidayQuery->where(
                'branch_id',
                $selectedBranchId
            );
        }

        $isHoliday =
            $holidayQuery->exists();

        /**
         * ----------------------------------------------------------------------
         * TEACHERS
         * ----------------------------------------------------------------------
         */
        $teacherQuery =
            Teacher::query();

        if (
            $selectedBranchId !== null
            && $selectedBranchId !== ''
        ) {
            $teacherQuery->where(
                'branch_id',
                $selectedBranchId
            );
        }

        $totalTeachers =
            $teacherQuery->count();

        $teacherAttendanceQuery =
            TeachersAttendance::query()
                ->whereDate(
                    'date',
                    $today
                );

        if (
            $selectedBranchId !== null
            && $selectedBranchId !== ''
        ) {
            $teacherAttendanceQuery->where(
                'branch_id',
                $selectedBranchId
            );
        }

        $teacherAttendances =
            $teacherAttendanceQuery->get();

        $teacherPresent =
            $teacherAttendances
                ->whereIn(
                    'status',
                    [
                        'Present',
                        'Late',
                    ]
                )
                ->pluck('teacher_id')
                ->unique()
                ->count();

        $teacherAbsent =
            $teacherAttendances
                ->where(
                    'status',
                    'Absent'
                )
                ->pluck('teacher_id')
                ->unique()
                ->count();

        $teacherLeave =
            $teacherAttendances
                ->where(
                    'status',
                    'Leave'
                )
                ->pluck('teacher_id')
                ->unique()
                ->count();

        $teacherOffDay =
            $teacherAttendances
                ->where(
                    'status',
                    'Off Day'
                )
                ->pluck('teacher_id')
                ->unique()
                ->count();

        $teacherApplicable =
            max(
                $totalTeachers -
                $teacherOffDay,
                0
            );

        $teacherPercentage =
            $teacherApplicable > 0
                ? round(
                    (
                        $teacherPresent /
                        $teacherApplicable
                    ) * 100,
                    2
                )
                : 0;

        /**
         * ----------------------------------------------------------------------
         * STAFF
         * ----------------------------------------------------------------------
         */
        $staffQuery =
            Staff::query();

        if (
            $selectedBranchId !== null
            && $selectedBranchId !== ''
        ) {
            $staffQuery->where(
                'branch_id',
                $selectedBranchId
            );
        }

        $totalStaff =
            $staffQuery->count();

        $staffAttendanceQuery =
            StaffAttendance::query()
                ->whereDate(
                    'date',
                    $today
                );

        if (
            $selectedBranchId !== null
            && $selectedBranchId !== ''
        ) {
            $staffAttendanceQuery->where(
                'branch_id',
                $selectedBranchId
            );
        }

        $staffAttendances =
            $staffAttendanceQuery->get();

        $staffPresent =
            $staffAttendances
                ->whereIn(
                    'status',
                    [
                        'Present',
                        'Late',
                    ]
                )
                ->pluck('staff_id')
                ->unique()
                ->count();

        $staffAbsent =
            $staffAttendances
                ->where(
                    'status',
                    'Absent'
                )
                ->pluck('staff_id')
                ->unique()
                ->count();

        $staffLeave =
            $staffAttendances
                ->where(
                    'status',
                    'Leave'
                )
                ->pluck('staff_id')
                ->unique()
                ->count();

        $staffOffDay =
            $staffAttendances
                ->where(
                    'status',
                    'Off Day'
                )
                ->pluck('staff_id')
                ->unique()
                ->count();

        $staffApplicable =
            max(
                $totalStaff -
                $staffOffDay,
                0
            );

        $staffPercentage =
            $staffApplicable > 0
                ? round(
                    (
                        $staffPresent /
                        $staffApplicable
                    ) * 100,
                    2
                )
                : 0;

        /**
         * ----------------------------------------------------------------------
         * HOLIDAY
         * ----------------------------------------------------------------------
         */
        if ($isHoliday) {
            $teacherPresent = 0;
            $teacherAbsent = 0;
            $teacherLeave = 0;
            $teacherOffDay = $totalTeachers;
            $teacherApplicable = 0;
            $teacherPercentage = 0;

            $staffPresent = 0;
            $staffAbsent = 0;
            $staffLeave = 0;
            $staffOffDay = $totalStaff;
            $staffApplicable = 0;
            $staffPercentage = 0;
        }

        /**
         * ----------------------------------------------------------------------
         * RESPONSE
         * ----------------------------------------------------------------------
         */
        return response()->json([
            'status' =>
                true,
            'date' =>
                $today,
            'is_holiday' =>
                $isHoliday,
            'teachers' => [
                'total' =>
                    $totalTeachers,
                'present' =>
                    $teacherPresent,
                'absent' =>
                    $teacherAbsent,
                'leave' =>
                    $teacherLeave,
                'off_day' =>
                    $teacherOffDay,
                'applicable' =>
                    $teacherApplicable,
                'percentage' =>
                    $teacherPercentage,
            ],
            'staff' => [
                'total' =>
                    $totalStaff,
                'present' =>
                    $staffPresent,
                'absent' =>
                    $staffAbsent,
                'leave' =>
                    $staffLeave,
                'off_day' =>
                    $staffOffDay,
                'applicable' =>
                    $staffApplicable,
                'percentage' =>
                    $staffPercentage,
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
            $user
            && $user->currentAccessToken()
        ) {
            $user
                ->currentAccessToken()
                ->delete();
        }

        return response()->json([
            'status' => true,
            'message' =>
                'Logout successful',
        ]);
    }
}
