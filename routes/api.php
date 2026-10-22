
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\StafftController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TeachersAttendanceController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\ClssMController;
use App\Http\Controllers\ClassGroupController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\FinalResultController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\OtherPaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\InstituteInfoController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\GradingSystemController;
use App\Http\Middleware\PaymentAccessMiddleware;
use App\Http\Controllers\AcademicSessionController;
use App\Http\Controllers\StudentSessionController;
use App\Http\Controllers\SmsController;

Route::post('/login', [StafftController::class, 'login']);

Route::get(
    '/staff-image/{filename}',
    [StafftController::class, 'image']
);

Route::get(
    '/student-image/{filename}',
    [StudentController::class, 'image']
);

Route::get(
    '/teacher-image/{filename}',
    [TeacherController::class, 'image']
);

Route::middleware([
    'auth:sanctum',
    'branch.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | STAFF
    |--------------------------------------------------------------------------
    */

    Route::get('/staff', [StafftController::class, 'index']);

    Route::get(
        '/staff/dashboard',
        [StafftController::class, 'dashboard']
    );

    Route::get('/staff/{id}', [StafftController::class, 'show']);

    Route::post('/staff', [StafftController::class, 'store']);

    Route::put('/staff/{id}', [StafftController::class, 'update']);

    Route::delete('/staff/{id}', [StafftController::class, 'destroy']);

    Route::get(
        '/staff/dashboard/attendance-trend',
        [StafftController::class, 'attendanceTrend']
    );

    Route::get(
        '/staff/dashboard/current-attendance',
        [StafftController::class, 'currentAttendance']
    );


    /*
    |--------------------------------------------------------------------------
    | STUDENTS
    |--------------------------------------------------------------------------
    */

    Route::apiResource('students', StudentController::class);


    /*
    |--------------------------------------------------------------------------
    | STUDENT ATTENDANCE
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/student-attendance/monthly-summary',
        [StudentAttendanceController::class, 'monthlySummary']
    );

    Route::get(
        '/student-attendance/yearly-summary',
        [StudentAttendanceController::class, 'yearlySummary']
    );

    Route::post(
        '/student-attendance/scan',
        [StudentAttendanceController::class, 'scan']
    );

    Route::get(
        '/student-attendance',
        [StudentAttendanceController::class, 'index']
    );

    Route::post(
        '/student-attendance',
        [StudentAttendanceController::class, 'store']
    );

    Route::get(
        '/student-attendance/{studentAttendance}',
        [StudentAttendanceController::class, 'show']
    );

    Route::put(
        '/student-attendance/{studentAttendance}',
        [StudentAttendanceController::class, 'update']
    );

    Route::delete(
        '/student-attendance/{studentAttendance}',
        [StudentAttendanceController::class, 'destroy']
    );


    /*
    |--------------------------------------------------------------------------
    | SMS DATA
    |--------------------------------------------------------------------------
    | All routes use the existing auth:sanctum and branch.context middleware.
    | These routes only fetch data; they do not send SMS.
    */

    Route::prefix('sms')->controller(SmsController::class)->group(function () {

        // Academic sessions, classes, sections and shifts
        Route::get('/options', 'options');

        // Search an individual student
        Route::get('/students/search', 'searchStudents');

        // Students filtered by academic session and placement
        Route::get('/students', 'students');

        // Students filtered by attendance date and status
        Route::get('/attendance', 'attendanceStudents');
        // Student Due Reminder
        Route::get('/due-reminders', 'dueReminders');
        // SMS Gateway Balance
        Route::get('/balance', 'gatewayBalance');

        Route::post('/send', 'send');

    });


    /*
    |--------------------------------------------------------------------------
    | TEACHERS
    |--------------------------------------------------------------------------
    */

    Route::apiResource('teachers', TeacherController::class);


    /*
    |--------------------------------------------------------------------------
    | TEACHER ATTENDANCE
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/teacher-attendances',
        [TeachersAttendanceController::class, 'index']
    );

    Route::post(
        '/teacher-attendances',
        [TeachersAttendanceController::class, 'store']
    );

    Route::get(
        '/teacher-attendances/{teachersAttendance}',
        [TeachersAttendanceController::class, 'show']
    );

    Route::put(
        '/teacher-attendances/{teachersAttendance}',
        [TeachersAttendanceController::class, 'update']
    );

    Route::delete(
        '/teacher-attendances/{teachersAttendance}',
        [TeachersAttendanceController::class, 'destroy']
    );


    /*
    |--------------------------------------------------------------------------
    | STAFF ATTENDANCE
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/staff-attendances',
        [StaffAttendanceController::class, 'index']
    );

    Route::post(
        '/staff-attendances',
        [StaffAttendanceController::class, 'store']
    );

    Route::get(
        '/staff-attendances/{staffAttendance}',
        [StaffAttendanceController::class, 'show']
    );

    Route::put(
        '/staff-attendances/{staffAttendance}',
        [StaffAttendanceController::class, 'update']
    );

    Route::delete(
        '/staff-attendances/{staffAttendance}',
        [StaffAttendanceController::class, 'destroy']
    );


    /*
    |--------------------------------------------------------------------------
    | ACADEMIC
    |--------------------------------------------------------------------------
    */

    Route::apiResource('shifts', ShiftController::class);

    Route::apiResource('sections', SectionController::class);

    Route::apiResource('subjects', SubjectController::class);

    Route::apiResource('classes', ClssMController::class);

    Route::apiResource('class-groups', ClassGroupController::class);

    Route::apiResource(
        'academic-sessions',
        AcademicSessionController::class
    );


    /*
    |--------------------------------------------------------------------------
    | CLASS MIGRATION
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/class-migration/options',
        [StudentSessionController::class, 'options']
    );

    Route::get(
        '/class-migration/students',
        [StudentSessionController::class, 'students']
    );

    Route::post(
        '/class-migration/migrate',
        [StudentSessionController::class, 'migrate']
    );

    Route::post(
        '/student-migration/migrate',
        [StudentSessionController::class, 'migrateSingle']
    );


    /*
    |--------------------------------------------------------------------------
    | EXAMINATIONS
    |--------------------------------------------------------------------------
    */

    Route::apiResource('examinations', ExaminationController::class);


    /*
    |--------------------------------------------------------------------------
    | RESULTS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/results/institute',
        [ResultController::class, 'instituteResult']
    );

    Route::apiResource('results', ResultController::class);


    /*
    |--------------------------------------------------------------------------
    | FINAL RESULTS
    |--------------------------------------------------------------------------
    | Specific routes must come before apiResource.
    */

    Route::get(
        '/final-results/average-merit-list',
        [FinalResultController::class, 'averageMeritList']
    );

    Route::get(
        '/final-results/student/{studentId}',
        [FinalResultController::class, 'studentFinalResult']
    );

    Route::apiResource(
        'final-results',
        FinalResultController::class
    );


    /*
    |--------------------------------------------------------------------------
    | HOLIDAYS
    |--------------------------------------------------------------------------
    */

    Route::apiResource('holidays', HolidayController::class);


    /*
    |--------------------------------------------------------------------------
    | INSTITUTE INFO
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/institute-info',
        [InstituteInfoController::class, 'store']
    );

    Route::get(
        '/institute-info',
        [InstituteInfoController::class, 'index']
    );

    Route::put(
        '/institute-info/{instituteInfo}',
        [InstituteInfoController::class, 'update']
    );

    Route::delete(
        '/institute-info/{instituteInfo}',
        [InstituteInfoController::class, 'destroy']
    );


    /*
    |--------------------------------------------------------------------------
    | BRANCHES
    |--------------------------------------------------------------------------
    */

    Route::apiResource('branches', BranchController::class);


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT / PAYMENT
    |--------------------------------------------------------------------------
    */

    Route::middleware(PaymentAccessMiddleware::class)->group(function () {

        Route::get(
            '/expense-staffs',
            [ExpenseController::class, 'getStaffs']
        );


        // PAYMENTS

        Route::get(
            '/payments/ledger',
            [PaymentController::class, 'ledger']
        );

        Route::get(
            '/payments',
            [PaymentController::class, 'index']
        );

        Route::post(
            '/payments',
            [PaymentController::class, 'store']
        );

        Route::get(
            '/payments/{id}',
            [PaymentController::class, 'show']
        );

        Route::put(
            '/payments/{id}',
            [PaymentController::class, 'update']
        );

        Route::get(
            '/student-payment-register',
            [PaymentController::class, 'paymentRegister']
        );

        Route::delete(
            '/payments/{id}',
            [PaymentController::class, 'destroy']
        );

        Route::get(
            '/student-payments/{id}',
            [PaymentController::class, 'studentPayments']
        );

        Route::get(
            '/student-payment-report',
            [PaymentController::class, 'studentPaymentReport']
        );

        Route::get(
            '/payments/{id}/receipt',
            [PdfController::class, 'downloadReceipt']
        );


        // OTHER PAYMENTS
        // Keep the specific ledger route before apiResource.

        Route::get(
            '/other-payments/ledger',
            [OtherPaymentController::class, 'ledger']
        );

        Route::apiResource(
            'other-payments',
            OtherPaymentController::class
        );


        // DUE PAYMENTS

        Route::post(
            '/due-payments',
            [PaymentController::class, 'storeDuePayment']
        );


        // EXPENSES
        // Keep the specific ledger route before apiResource.

        Route::get(
            '/expenses/ledger',
            [ExpenseController::class, 'ledger']
        );

        Route::apiResource(
            'expenses',
            ExpenseController::class
        );

        Route::get(
            '/expense-teachers',
            [ExpenseController::class, 'getTeachers']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | GRADING
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'grading-systems',
        GradingSystemController::class
    );


    /*
    |--------------------------------------------------------------------------
    | BACKUP
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/backup',
        [BackupController::class, 'takeBackup']
    );

});


/*
|--------------------------------------------------------------------------
| AUTHENTICATED USER
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get(
    '/user',
    function (Request $request) {
        return $request->user();
    }
);
