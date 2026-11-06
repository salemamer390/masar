<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Mobile\{SessionController, ReadController};
use App\Http\Controllers\{EmployeeController, AttendanceController, LeaveController, PenaltyController, HrRequestController, DocumentController, NotificationController, PayrollController, ReportController};

// Loaded from web.php: session encryption and Laravel CSRF remain enabled.
Route::prefix('mobile/v1')->name('mobile.')->middleware(\App\Http\Middleware\MobileJson::class)->group(function () {
    Route::get('/session', [SessionController::class,'show'])->name('session');
    Route::post('/login', [SessionController::class,'login'])->middleware('throttle:5,1')->name('login');
    Route::post('/logout', [SessionController::class,'logout'])->name('logout');
    Route::middleware(['auth', \App\Http\Middleware\MobileActiveUser::class, 'subscription'])->group(function () {
        Route::get('/summary',[ReadController::class,'summary'])->middleware('permission:employees.view');
        Route::get('/lookups', [ReadController::class,'lookups']);
        Route::get('/people', [ReadController::class,'people']);
        foreach (['dashboard','employees','attendance','leaves','documents','penalties','requests','payroll','reports'] as $module) {
            Route::get('/'.$module, [ReadController::class,$module])->middleware('permission:'.$module.'.view');
        }
        Route::post('/payroll/generate/start', [PayrollController::class,'startGeneration'])->middleware('permission:payroll.create');
        Route::post('/payroll/generate/batch', [PayrollController::class,'generateBatch'])->middleware('permission:payroll.create');
        Route::post('/payroll/runs/{payrollRun}/approve', [PayrollController::class,'approveRun'])->middleware('permission:payroll.approve');
        Route::post('/payroll/runs/{payrollRun}/lock', [PayrollController::class,'lockRun'])->middleware('permission:payroll.lock');
        Route::delete('/payroll/runs/{payrollRun}', [PayrollController::class,'destroyRun'])->middleware('permission:payroll.delete');
        Route::post('/payroll/close-period', [PayrollController::class,'closePeriod'])->middleware('permission:payroll.lock');
        Route::put('/payroll/records/{payrollRecord}/inputs', [PayrollController::class,'updateInputs'])->middleware('permission:payroll.edit');
        Route::post('/payroll/records/{payrollRecord}/approve', [PayrollController::class,'approve'])->middleware('permission:payroll.approve');
        Route::post('/payroll/records/{payrollRecord}/pay', [PayrollController::class,'pay'])->middleware('permission:payroll.edit');
        Route::get('/payroll/export', [PayrollController::class,'export'])->middleware('permission:payroll.export');
        Route::get('/payroll/records/{payrollRecord}/payslip', [PayrollController::class,'payslipPdf'])->middleware('permission:payroll.view');
        Route::get('/reports/export/{type}', [ReportController::class,'export'])->middleware('permission:reports.export_excel');
        Route::get('/documents/{document}/file', [DocumentController::class,'preview'])->middleware('permission:documents.view');
        Route::get('/employees/{employee}', [ReadController::class,'employeeShow'])->middleware('permission:employees.view');
        Route::get('/notifications', [ReadController::class,'notifications']);
        Route::post('/notifications/read-all', [NotificationController::class,'markAllRead']);
        Route::post('/employees', [EmployeeController::class,'store'])->middleware('permission:employees.create');
        Route::put('/employees/{employee}', [EmployeeController::class,'update'])->middleware('permission:employees.edit');
        Route::post('/attendance', [AttendanceController::class,'store'])->middleware('permission:attendance.create');
        Route::put('/attendance/{attendance}', [AttendanceController::class,'update'])->middleware('permission:attendance.edit');
        // Administrative manual clock actions already exist on the web. This is not employee GPS attendance.
        Route::post('/attendance/clock-in', [AttendanceController::class,'clockIn'])->middleware('permission:attendance.create');
        Route::post('/attendance/clock-out', [AttendanceController::class,'clockOut'])->middleware('permission:attendance.create');
        Route::post('/leaves', [LeaveController::class,'store'])->middleware('permission:leaves.create');
        Route::put('/leaves/{leave}', [LeaveController::class,'update'])->middleware('permission:leaves.edit');
        Route::patch('/leaves/{leave}/approve', [LeaveController::class,'approve'])->middleware('permission:leaves.approve');
        Route::patch('/leaves/{leave}/reject', [LeaveController::class,'reject'])->middleware('permission:leaves.approve');
        Route::delete('/leaves/{leave}', [LeaveController::class,'destroy'])->middleware('permission:leaves.delete');
        Route::post('/penalties', [PenaltyController::class,'store'])->middleware('permission:penalties.create');
        Route::put('/penalties/{penalty}', [PenaltyController::class,'update'])->middleware('permission:penalties.edit');
        Route::delete('/penalties/{penalty}', [PenaltyController::class,'destroy'])->middleware('permission:penalties.delete');
        Route::post('/requests', [HrRequestController::class,'store'])->middleware('permission:requests.create');
        Route::put('/requests/{hrRequest}', [HrRequestController::class,'update'])->middleware('permission:requests.create');
        Route::patch('/requests/{hrRequest}/reject', [HrRequestController::class,'reject'])->middleware('permission:requests.approve');
        Route::post('/employees/{employee}/documents', [DocumentController::class,'store'])->middleware('permission:documents.upload');
        Route::delete('/documents/{document}', [DocumentController::class,'destroy'])->middleware('permission:documents.delete');
    });
});
