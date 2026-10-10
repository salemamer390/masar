<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardDetailController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeContactController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\EditableS1Controller;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\JobDataController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollSettingsController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Guest Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('/', fn() => redirect()->route('dashboard'));

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');
    Route::get('/dashboard/details/{type}', [DashboardDetailController::class, 'show'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard.details');
    Route::get('/dashboard/employee-search', [DashboardDetailController::class, 'employees'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard.employee-search');

    // Employees
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view')
            ->name('index');
        Route::post('/', [EmployeeController::class, 'store'])
            ->middleware('permission:employees.create')
            ->name('store');
        Route::post('/salary-preview', [EmployeeController::class, 'salaryPreview'])
            ->middleware('permission:employees.view')
            ->name('salary-preview');
        Route::get('/salary-preview', [EmployeeController::class, 'salaryPreview'])
            ->middleware('permission:employees.view')
            ->name('salary-preview.get');
        Route::get('/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission:employees.view')
            ->name('show');
        Route::put('/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.edit')
            ->name('update');
        Route::patch('/{employee}/photo', [EmployeeController::class, 'updatePhoto'])
            ->middleware('permission:employees.edit')
            ->name('photo');
        Route::delete('/{employee}/photo', [EmployeeController::class, 'deletePhoto'])
            ->middleware('permission:employees.edit')
            ->name('photo.delete');
        Route::get('/{employee}/print', [EmployeeController::class, 'print'])
            ->middleware('permission:employees.view')
            ->name('print');
        Route::post('/{employee}/documents', [DocumentController::class, 'store'])
            ->middleware('permission:documents.upload')
            ->name('documents.store');
        Route::post('/{employee}/emergency-contacts', [EmployeeContactController::class, 'storeEmergencyContact'])
            ->middleware('permission:employees.edit')
            ->name('emergency-contacts.store');
        Route::post('/{employee}/bank-accounts', [EmployeeContactController::class, 'storeBankAccount'])
            ->middleware('permission:employees.edit')
            ->name('bank-accounts.store');
    });

    // Job Data
    Route::get('/jobs', [JobDataController::class, 'index'])
        ->middleware('permission:employees.view')
        ->name('jobs.index');

    // Attendance
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')
            ->name('index');
        Route::post('/', [AttendanceController::class, 'store'])
            ->middleware('permission:attendance.create')
            ->name('store');
        Route::put('/{attendance}', [AttendanceController::class, 'update'])
            ->middleware('permission:attendance.edit')
            ->name('update');
        Route::delete('/{attendance}', [AttendanceController::class, 'destroy'])
            ->middleware('permission:attendance.edit')
            ->name('destroy');
        Route::patch('/{attendance}/approve', [AttendanceController::class, 'approve'])
            ->middleware('permission:attendance.edit')
            ->name('approve');
    });

    // Leaves
    Route::prefix('leaves')->name('leaves.')->group(function () {
        Route::get('/', [LeaveController::class, 'index'])
            ->middleware('permission:leaves.view')
            ->name('index');
        Route::post('/', [LeaveController::class, 'store'])
            ->middleware('permission:leaves.create')
            ->name('store');
          Route::put('/{leave}', [LeaveController::class, 'update'])
             ->middleware('permission:leaves.edit')
             ->name('update');
        Route::patch('/{leave}/approve', [LeaveController::class, 'approve'])
            ->middleware('permission:leaves.approve')
            ->name('approve');
        Route::patch('/{leave}/reject', [LeaveController::class, 'reject'])
            ->middleware('permission:leaves.approve')
            ->name('reject');
        Route::delete('/{leave}', [LeaveController::class, 'destroy'])
            ->middleware('permission:leaves.delete')
            ->name('destroy');
    });

    // HR Requests (generic requests such as advances, permissions, missions, documents, other)
    Route::prefix('requests')->name('requests.')->group(function () {
        Route::get('/', [HrRequestController::class, 'index'])
            ->middleware('permission:requests.view')
            ->name('index');
        Route::post('/', [HrRequestController::class, 'store'])
            ->middleware('permission:requests.create')
            ->name('store');
        Route::patch('/{hrRequest}/approve', [HrRequestController::class, 'approve'])
            ->middleware('permission:requests.approve')
            ->name('approve');
        Route::patch('/{hrRequest}/reject', [HrRequestController::class, 'reject'])
            ->middleware('permission:requests.approve')
            ->name('reject');
        Route::delete('/{hrRequest}', [HrRequestController::class, 'destroy'])
            ->middleware('permission:requests.delete')
            ->name('destroy');
    });

    // Documents
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])
            ->middleware('permission:documents.view')
            ->name('index');
        Route::post('/{document}/replace', [DocumentController::class, 'replace'])
            ->middleware('permission:documents.replace')
            ->name('replace');
        Route::get('/{document}/preview', [DocumentController::class, 'preview'])
            ->middleware('permission:documents.view')
            ->name('preview');
        Route::delete('/{document}', [DocumentController::class, 'destroy'])
            ->middleware('permission:documents.delete')
            ->name('destroy');
    });

    // Salaries
    Route::prefix('salaries')->name('salaries.')->group(function () {
        Route::get('/', [SalaryController::class, 'index'])
            ->middleware('permission:salary.view')
            ->name('index');
        Route::post('/', [SalaryController::class, 'store'])
            ->middleware('permission:salary.create')
            ->name('store');
        Route::post('/preview', [SalaryController::class, 'preview'])
            ->middleware('permission:salary.view')
            ->name('preview');
        Route::post('/recalculate', [SalaryController::class, 'recalculate'])
            ->middleware('permission:salary.edit')
            ->name('recalculate');
        Route::put('/{salary}', [SalaryController::class, 'update'])
            ->middleware('permission:salary.edit')
            ->name('update');
        Route::delete('/{salary}', [SalaryController::class, 'destroy'])
            ->middleware('permission:salary.delete')
            ->name('destroy');
    });

    // Monthly Payroll
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])
            ->middleware('permission:payroll.view')
            ->name('index');
        Route::get('/settings', [PayrollSettingsController::class, 'index'])
            ->middleware('permission:payroll.settings')
            ->name('settings.index');
        Route::put('/settings', [PayrollSettingsController::class, 'update'])
            ->middleware('permission:payroll.settings')
            ->name('settings.update');
        Route::put('/settings/operational', [PayrollSettingsController::class, 'updateOperational'])
            ->middleware('permission:payroll.settings')->name('settings.operational.update');
        Route::post('/settings/holidays', [PayrollSettingsController::class, 'storeHoliday'])
            ->middleware('permission:payroll.settings')->name('settings.holidays.store');
        Route::put('/settings/holidays/{holiday}', [PayrollSettingsController::class, 'updateHoliday'])
            ->middleware('permission:payroll.settings')->name('settings.holidays.update');
        Route::delete('/settings/holidays/{holiday}', [PayrollSettingsController::class, 'destroyHoliday'])
            ->middleware('permission:payroll.settings')->name('settings.holidays.destroy');
        Route::post('/generate', [PayrollController::class, 'generate'])
            ->middleware('permission:payroll.create')
            ->name('generate');
        Route::post('/runs/generate', [PayrollController::class, 'generateRun'])
            ->middleware('permission:payroll.create')
            ->name('runs.generate');
        Route::post('/runs/{payrollRun}/approve', [PayrollController::class, 'approveRun'])
            ->middleware('permission:payroll.approve')
            ->name('runs.approve');
        Route::post('/runs/{payrollRun}/lock', [PayrollController::class, 'lockRun'])
            ->middleware('permission:payroll.lock')
            ->name('runs.lock');
        Route::delete('/runs/{payrollRun}', [PayrollController::class, 'destroyRun'])
            ->middleware('permission:payroll.create')
            ->name('runs.destroy');
        Route::post('/{payrollRecord}/approve', [PayrollController::class, 'approve'])
            ->middleware('permission:payroll.edit')
            ->name('approve');
        Route::put('/{payrollRecord}/inputs', [PayrollController::class, 'updateInputs'])
            ->middleware('permission:payroll.edit')
            ->name('inputs.update');
        Route::post('/{payrollRecord}/pay', [PayrollController::class, 'pay'])
            ->middleware('permission:payroll.edit')
            ->name('pay');
        Route::get('/export', [PayrollController::class, 'export'])
            ->middleware('permission:payroll.export')
            ->name('export');
    });

    // Insurance
    Route::get('/employees/{employee}/forms/s1-editable', [EditableS1Controller::class, 'show'])
        ->middleware('permission:insurance.view')
        ->name('employees.forms.s1.editable');
    Route::prefix('insurance')->name('insurance.')->group(function () {
        Route::get('/', [InsuranceController::class, 'index'])
            ->middleware('permission:insurance.view')
            ->name('index');
        Route::post('/', [InsuranceController::class, 'store'])
            ->middleware('permission:insurance.create')
            ->name('store');
        Route::put('/{insuranceRecord}', [InsuranceController::class, 'update'])
            ->middleware('permission:insurance.edit')
            ->name('update');
        Route::get('/{insuranceRecord}/form', [InsuranceController::class, 'form'])
            ->middleware('permission:insurance.view')
            ->name('form');
        Route::put('/organization/settings', [InsuranceController::class, 'updateOrganization'])
            ->middleware('permission:insurance.edit')
            ->name('organization.update');
    });

    // Discipline & Penalties
    Route::prefix('discipline')->name('discipline.')->group(function () {
        Route::get('/', [PenaltyController::class, 'index'])
            ->middleware('permission:penalties.view')
            ->name('index');
        Route::post('/penalties', [PenaltyController::class, 'store'])
            ->middleware('permission:penalties.create')
            ->name('store');
        Route::patch('/penalties/{penalty}/close', [PenaltyController::class, 'close'])
            ->middleware('permission:penalties.edit')
            ->name('close');
        Route::put('/penalties/{penalty}', [PenaltyController::class, 'update'])
            ->middleware('permission:penalties.edit')
            ->name('update');
        Route::delete('/penalties/{penalty}', [PenaltyController::class, 'destroy'])
            ->middleware('permission:penalties.delete')
            ->name('destroy');
    });

    // Career Movements
    Route::prefix('movements')->name('movements.')->group(function () {
        Route::get('/', [MovementController::class, 'index'])
            ->middleware('permission:movements.view')
            ->name('index');
        Route::post('/', [MovementController::class, 'store'])
            ->middleware('permission:movements.create')
            ->name('store');
    });

    // Master Data
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/', [MasterDataController::class, 'index'])
            ->middleware('permission:roles.view')
            ->name('index');
        Route::get('/{type}', [MasterDataController::class, 'showType'])
            ->middleware('permission:roles.view')
            ->name('show');
        Route::post('/{type}', [MasterDataController::class, 'store'])
            ->middleware('permission:roles.edit')
            ->name('store');
        Route::put('/documents/{documentType}', [MasterDataController::class, 'updateDocument'])
            ->middleware('permission:roles.edit')
            ->name('documents.update');
        Route::delete('/documents/{documentType}', [MasterDataController::class, 'destroyDocument'])
            ->middleware('permission:roles.edit')
            ->name('documents.destroy');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])
            ->middleware('permission:reports.view')
            ->name('index');
        Route::get('/{type}', [ReportController::class, 'show'])
            ->middleware('permission:reports.view')
            ->name('show');
        Route::get('/{type}/export', [ReportController::class, 'export'])
            ->middleware('permission:reports.export_excel')
            ->name('export');
    });

    // Users & Roles
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('permission:users.view')
            ->name('index');
        Route::post('/', [UserController::class, 'store'])
            ->middleware('permission:users.create')
            ->name('store');
        Route::put('/{user}', [UserController::class, 'update'])
            ->middleware('permission:users.edit')
            ->name('update');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])
            ->middleware('permission:roles.view')
            ->name('index');
        Route::put('/{role}', [RoleController::class, 'update'])
            ->middleware('permission:roles.edit')
            ->name('update');
    });
});
