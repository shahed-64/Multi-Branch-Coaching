<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\StafftController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
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

// Middleware
use App\Http\Middleware\PaymentAccessMiddleware;


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Register
Route::post('/register', [StafftController::class, 'store']);

// Login
Route::post('/login', [StafftController::class, 'login']);


/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Staff
    |--------------------------------------------------------------------------
    */

    Route::get('/staff', [StafftController::class, 'index']);
    Route::get('/staff/dashboard', [StafftController::class, 'dashboard']);
    Route::get('/staff/{id}', [StafftController::class, 'show']);
    Route::post('/staff', [StafftController::class, 'store']);
    Route::put('/staff/{id}', [StafftController::class, 'update']);
    Route::delete('/staff/{id}', [StafftController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */

    Route::apiResource('students', StudentController::class);


    /*
    |--------------------------------------------------------------------------
    | Teachers
    |--------------------------------------------------------------------------
    */

    Route::apiResource('teachers', TeacherController::class);


    /*
    |--------------------------------------------------------------------------
    | Shifts
    |--------------------------------------------------------------------------
    */

    Route::apiResource('shifts', ShiftController::class);


    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    */

    Route::apiResource('sections', SectionController::class);


    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */

    Route::apiResource('subjects', SubjectController::class);


    /*
    |--------------------------------------------------------------------------
    | Classes
    |--------------------------------------------------------------------------
    */

    Route::apiResource('classes', ClssMController::class);


    /*
    |--------------------------------------------------------------------------
    | Class Groups
    |--------------------------------------------------------------------------
    */

    Route::apiResource('class-groups', ClassGroupController::class);


    /*
    |--------------------------------------------------------------------------
    | Examinations
    |--------------------------------------------------------------------------
    */

    Route::apiResource('examinations', ExaminationController::class);


    /*
    |--------------------------------------------------------------------------
    | Results
    |--------------------------------------------------------------------------
    */

    Route::apiResource('results', ResultController::class);


    /*
    |--------------------------------------------------------------------------
    | Final Result Configuration
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'final-results',
        FinalResultController::class
    );

    Route::get(
        '/final-results/student/{studentId}',
        [FinalResultController::class, 'studentFinalResult']
    );


    /*
    |--------------------------------------------------------------------------
    | Holidays
    |--------------------------------------------------------------------------
    */

    Route::apiResource('holidays', HolidayController::class);


    /*
    |--------------------------------------------------------------------------
    | Institute Info
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/institute-info',
        [InstituteInfoController::class, 'store']
    );

    Route::put(
        '/institute-info',
        [InstituteInfoController::class, 'update']
    );

    Route::get(
        '/institute-info',
        [InstituteInfoController::class, 'index']
    );


    /*
    |--------------------------------------------------------------------------
    | Branches
    |--------------------------------------------------------------------------
    */

    Route::apiResource('branches', BranchController::class);


    /*
    |--------------------------------------------------------------------------
    | Account Section
    |--------------------------------------------------------------------------
    |
    | Only:
    | Manager
    | Branch Manager
    | Branch Accountant
    |
    |--------------------------------------------------------------------------
    */

    Route::middleware(PaymentAccessMiddleware::class)->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Expense Staffs
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/expense-staffs',
            [ExpenseController::class, 'getStaffs']
        );


        /*
        |--------------------------------------------------------------------------
        | Payments
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | Other Payments
        |--------------------------------------------------------------------------
        */

        Route::apiResource(
            'other-payments',
            OtherPaymentController::class
        );


        /*
        |--------------------------------------------------------------------------
        | Expenses
        |--------------------------------------------------------------------------
        */

        Route::apiResource(
            'expenses',
            ExpenseController::class
        );


        /*
        |--------------------------------------------------------------------------
        | Expense Teachers
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/expense-teachers',
            [ExpenseController::class, 'getTeachers']
        );
    });


    /*
    |--------------------------------------------------------------------------
    | Grading Systems
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'grading-systems',
        GradingSystemController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Backup
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/backup',
        [BackupController::class, 'backup']
    );
});


/*
|--------------------------------------------------------------------------
| API User
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
