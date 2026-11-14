<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardDetailController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\EmployeeContactController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\EditableS1Controller;
use App\Http\Controllers\EditableS2Controller;
use App\Http\Controllers\EditableS6Controller;
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
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\CompanyAccountController;
use App\Http\Controllers\SubscriptionRenewalController;
use App\Http\Controllers\TourController;
use App\Http\Controllers\MarketingSiteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Device-initiated fingerprint ingestion (no login session).
// ADMS / push protocol: ingestion exists ONLY on the per-device secret path
// (/iclock/{token}/...); the fixed ZK-standard path never imports data - the
// serial number is an identity, not authentication. The bridge API is
// bearer-token authenticated. Both reject plain HTTP in production.
Route::middleware(['throttle:60,1', \App\Http\Middleware\RequireSecureFingerprintIngestion::class])->group(function () {
    Route::any('/iclock/cdata', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'unauthenticated'])->name('fingerprint.adms.fixed-cdata');
    Route::any('/iclock/getrequest', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'unauthenticated'])->name('fingerprint.adms.fixed-getrequest');
    Route::any('/iclock/devicecmd', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'unauthenticated'])->name('fingerprint.adms.fixed-devicecmd');
    Route::get('/iclock/{token}/cdata', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'handshake'])->where('token', '[A-Za-z0-9]{32,64}')->name('fingerprint.adms.handshake');
    Route::post('/iclock/{token}/cdata', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'receiveData'])->where('token', '[A-Za-z0-9]{32,64}')->name('fingerprint.adms.cdata');
    Route::get('/iclock/{token}/getrequest', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'getRequest'])->where('token', '[A-Za-z0-9]{32,64}')->name('fingerprint.adms.getrequest');
    Route::post('/iclock/{token}/devicecmd', [\App\Http\Controllers\Fingerprint\AdmsController::class, 'deviceCmd'])->where('token', '[A-Za-z0-9]{32,64}')->name('fingerprint.adms.devicecmd');
});

Route::middleware(['throttle:120,1', \App\Http\Middleware\RequireSecureFingerprintIngestion::class, \App\Http\Middleware\AuthenticateFingerprintBridge::class])
    ->prefix('api/fingerprint-bridge')->name('fingerprint.bridge.')->group(function () {
        Route::match(['get', 'post'], '/handshake', [\App\Http\Controllers\Fingerprint\BridgeApiController::class, 'handshake'])->name('handshake');
        Route::post('/punches', [\App\Http\Controllers\Fingerprint\BridgeApiController::class, 'punches'])->name('punches');
    });

// Canonical marketing URLs. Keep legacy bookmarks working through redirects.
Route::get('/', [MarketingSiteController::class, 'home'])->name('site.home');
Route::get('/features', [MarketingSiteController::class, 'features'])->name('site.features');
Route::get('/privacy', [MarketingSiteController::class, 'privacy'])->name('site.privacy');
Route::get('/terms', [MarketingSiteController::class, 'terms'])->name('site.terms');
Route::get('/signup', [MarketingSiteController::class, 'signup'])->name('site.signup');
Route::get('/contact', [MarketingSiteController::class, 'contact'])->name('site.contact');

foreach ([
    'index' => 'site.home', 'features' => 'site.features', 'privacy' => 'site.privacy',
    'terms' => 'site.terms', 'login' => 'login', 'signup' => 'site.signup',
    'contact' => 'site.contact', 'forgot-password' => 'password.request',
] as $legacy => $canonical) {
    Route::get('/'.$legacy.'.html', fn (\Illuminate\Http\Request $request) =>
        redirect()->route($canonical, $request->query(), 301));
}

Route::post('/contact', [MarketingSiteController::class, 'submitContact'])->middleware('throttle:5,1')->name('site.contact.submit');
Route::get('/forgot-password', [MarketingSiteController::class, 'forgotPassword'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
Route::get('/reset-password/{token}', [MarketingSiteController::class, 'resetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

// Interface language switch (Arabic / English). Stored in the session and a
// long-lived cookie so the choice survives new sessions and works for guests.
Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['ar', 'en'], true), 404);

    session(['locale' => $locale]);

    return redirect()->back()->withCookie(cookie()->forever('masar_locale', $locale));
})->name('locale.switch');

// Guest Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [MarketingSiteController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    Route::post('/register/company', [AuthController::class, 'registerCompany'])
        ->middleware('throttle:5,1')
        ->name('register.company');
});

// Authenticated Routes
Route::middleware(['auth', 'subscription', 'no_auth_cache', \App\Http\Middleware\EnsureGuidedTourCompleted::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::middleware('global_superadmin')->prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/', [SubscriptionPlanController::class, 'index'])->name('index');
        Route::post('/plans', [SubscriptionPlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [SubscriptionPlanController::class, 'update'])->name('plans.update');
        Route::post('/companies', [SubscriptionPlanController::class, 'storeCompany'])->name('companies.store');
        Route::put('/companies/{company}/subscription', [SubscriptionPlanController::class, 'updateCompanySubscription'])->name('companies.subscription.update');
        Route::put('/companies/{company}/status', [SubscriptionPlanController::class, 'updateCompanyStatus'])->name('companies.status.update');
        Route::put('/renewals/{renewal}', [SubscriptionRenewalController::class, 'update'])->name('renewals.update');
        Route::post('/announcements', [SubscriptionPlanController::class, 'sendAnnouncement'])->name('announcements.send');
    });
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/tour/progress', [TourController::class, 'progress'])->name('tour.progress');
    Route::post('/tour/complete', [TourController::class, 'complete'])->name('tour.complete');
    Route::post('/tour/replay', [TourController::class, 'reset'])->name('tour.replay');
    Route::get('/access-denied', fn () => view('errors.access-denied'))->name('access.denied');
    Route::get('/company/account', [CompanyAccountController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('company.account');
    Route::post('/company/renewals', [SubscriptionRenewalController::class, 'store'])
        ->middleware('permission:users.view')
        ->name('company.renewals.store');

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
        Route::get('/import/template', [EmployeeImportController::class, 'template'])
            ->middleware('permission:employees.import')
            ->name('import.template');
        Route::post('/import/preview', [EmployeeImportController::class, 'preview'])
            ->middleware('permission:employees.import')
            ->name('import.preview');
        Route::post('/import/rejected', [EmployeeImportController::class, 'rejected'])
            ->middleware('permission:employees.import')
            ->name('import.rejected');
        Route::post('/import', [EmployeeImportController::class, 'store'])
            ->middleware('permission:employees.import')
            ->name('import.store');
        Route::get('/import-batches', [\App\Http\Controllers\EmployeeImportReviewController::class, 'index'])
            ->middleware('permission:employees.import')
            ->name('import-batches.index');
        Route::get('/import-rows/{row}', [\App\Http\Controllers\EmployeeImportReviewController::class, 'show'])
            ->middleware('permission:employees.import')
            ->name('import-rows.show');
        Route::put('/import-rows/{row}', [\App\Http\Controllers\EmployeeImportReviewController::class, 'update'])
            ->middleware('permission:employees.import')
            ->name('import-rows.update');
        Route::delete('/import-rows/{row}', [\App\Http\Controllers\EmployeeImportReviewController::class, 'destroy'])
            ->middleware('permission:employees.import')
            ->name('import-rows.destroy');
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
        Route::post('/{employee}/maternity-leave', [EmployeeController::class, 'maternityLeave'])
            ->middleware('permission:employees.edit')
            ->name('maternity-leave');
        Route::get('/{employee}/photo', [EmployeeController::class, 'showPhoto'])
            ->middleware('permission:employees.view')
            ->name('photo.show');
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
    Route::get('/jobs/rows', [JobDataController::class, 'rows'])
        ->middleware('permission:employees.view')
        ->name('jobs.rows');
    Route::get('/jobs/overtime-review', [JobDataController::class, 'overtimeReview'])
        ->middleware('permission:employees.view')
        ->name('jobs.overtime-review');
    Route::get('/jobs/export', [JobDataController::class, 'export'])
        ->middleware('permission:employees.view')
        ->name('jobs.export');
    // Compatibility endpoint for stale compiled views; no save button is rendered.
    Route::post('/jobs/summary-history', [JobDataController::class, 'saveSnapshot'])
        ->middleware('permission:employees.view')
        ->name('jobs.summary-history.store');
    Route::get('/jobs/summary-history/{snapshot}', [JobDataController::class, 'showSnapshot'])
        ->middleware('permission:employees.view')
        ->name('jobs.summary-history.show');
    Route::get('/jobs/summary-history/{snapshot}/export', [JobDataController::class, 'exportSnapshot'])
        ->middleware('permission:employees.view')
        ->name('jobs.summary-history.export');

    // Attendance
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view')
            ->name('index');
        Route::get('/export', [AttendanceController::class, 'export'])
            ->middleware('permission:attendance.view')
            ->name('export');
        Route::get('/archive', [AttendanceController::class, 'archiveIndex'])
            ->middleware('permission:attendance.view')
            ->name('archive.index');
        Route::get('/archive/{archive}', [AttendanceController::class, 'archiveShow'])
            ->middleware('permission:attendance.view')
            ->name('archive.show');
        Route::get('/archive/{archive}/export', [AttendanceController::class, 'archiveExport'])
            ->middleware('permission:attendance.view')
            ->name('archive.export');
        Route::post('/', [AttendanceController::class, 'store'])
            ->middleware('permission:attendance.create')
            ->name('store');
        Route::post('/import/preview', [\App\Http\Controllers\AttendanceImportController::class, 'preview'])
            ->middleware('permission:attendance.create')
            ->name('import.preview');
        Route::post('/import/rejected', [\App\Http\Controllers\AttendanceImportController::class, 'rejected'])
            ->middleware('permission:attendance.create')->name('import.rejected');
        Route::get('/import-result/{token}', [\App\Http\Controllers\AttendanceImportController::class, 'result'])
            ->middleware('permission:attendance.create')->name('import.result');
        Route::get('/import-result/{token}/rejected', [\App\Http\Controllers\AttendanceImportController::class, 'resultRejected'])
            ->middleware('permission:attendance.create')->name('import.result.rejected');
        Route::post('/import', [\App\Http\Controllers\AttendanceImportController::class, 'store'])
            ->middleware('permission:attendance.create')
            ->name('import');
        Route::get('/exceptions', [\App\Http\Controllers\AttendanceExceptionController::class,'index'])->middleware('permission:attendance.view')->name('exceptions.index');
        Route::get('/import-batches/{batch}', [\App\Http\Controllers\AttendanceExceptionController::class,'batch'])->middleware(['permission:attendance.view','permission:attendance.edit'])->name('exceptions.batch');
        Route::get('/exceptions/{day}', [\App\Http\Controllers\AttendanceExceptionController::class,'show'])->middleware('permission:attendance.view')->name('exceptions.show');
        Route::put('/exceptions/{day}', [\App\Http\Controllers\AttendanceExceptionController::class,'update'])->middleware(['permission:attendance.view','permission:attendance.edit'])->name('exceptions.update');
        Route::delete('/exceptions/{day}', [\App\Http\Controllers\AttendanceExceptionController::class,'destroy'])->middleware(['permission:attendance.view','permission:attendance.delete'])->name('exceptions.destroy');
        Route::put('/{attendance}', [AttendanceController::class, 'update'])
            ->middleware('permission:attendance.edit')
            ->name('update');
        Route::delete('/{attendance}', [AttendanceController::class, 'destroy'])
            ->middleware('permission:attendance.delete')
            ->name('destroy');
    });

    // Fingerprint devices and synchronization logs
    Route::prefix('fingerprint')->name('fingerprint.')->group(function () {
        Route::get('/devices', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'index'])->middleware('permission:fingerprint-devices.view')->name('devices.index');
        Route::get('/devices/create', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'create'])->middleware('permission:fingerprint-devices.create')->name('devices.create');
        Route::post('/devices', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'store'])->middleware('permission:fingerprint-devices.create')->name('devices.store');
        Route::post('/devices/sync-all', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'syncAll'])->middleware('permission:fingerprint-devices.sync')->name('devices.sync-all');
        Route::get('/devices/{fingerprintDevice}', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'show'])->middleware('permission:fingerprint-devices.view')->name('devices.show');
        Route::get('/devices/{fingerprintDevice}/edit', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'edit'])->middleware('permission:fingerprint-devices.update')->name('devices.edit');
        Route::put('/devices/{fingerprintDevice}', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'update'])->middleware('permission:fingerprint-devices.update')->name('devices.update');
        Route::delete('/devices/{fingerprintDevice}', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'destroy'])->middleware('permission:fingerprint-devices.delete')->name('devices.destroy');
        Route::post('/devices/{fingerprintDevice}/push-token', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'regeneratePushToken'])->middleware('permission:fingerprint-devices.update')->name('devices.push-token');
        Route::post('/devices/{fingerprintDevice}/test-connection', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'testConnection'])->middleware('permission:fingerprint-devices.test')->name('devices.test');
        Route::post('/devices/{fingerprintDevice}/retrieve-info', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'retrieveInfo'])->middleware('permission:fingerprint-devices.test')->name('devices.info');
        Route::post('/devices/{fingerprintDevice}/sync', [\App\Http\Controllers\Fingerprint\FingerprintDeviceController::class, 'sync'])->middleware('permission:fingerprint-devices.sync')->name('devices.sync');
        Route::get('/sync-logs', [\App\Http\Controllers\Fingerprint\FingerprintDeviceSyncLogController::class, 'index'])->middleware('permission:fingerprint-sync-logs.view')->name('sync-logs.index');
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
        Route::get('/{leave}/document', [LeaveController::class, 'document'])
            ->whereNumber('leave')
            ->middleware('permission:leaves.view')
            ->name('document');
        Route::get('/opening-balances', [\App\Http\Controllers\LeaveOpeningBalanceController::class, 'index'])
            ->middleware('permission:leaves.view')
            ->name('opening.index');
        Route::post('/opening-balances', [\App\Http\Controllers\LeaveOpeningBalanceController::class, 'store'])
            ->middleware('permission:leaves.edit')
            ->name('opening.store');
        Route::put('/opening-balances/baseline', [\App\Http\Controllers\LeaveOpeningBalanceController::class, 'updateBaseline'])
            ->middleware('permission:leaves.edit')
            ->name('opening.baseline');
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
        Route::get('/{hrRequest}/edit-data', [HrRequestController::class, 'editData'])
            ->middleware('permission:requests.create')
            ->name('edit-data');
        Route::put('/{hrRequest}', [HrRequestController::class, 'update'])
            ->middleware('permission:requests.create')
            ->name('update');
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
        Route::get('/{salary}/details', [SalaryController::class, 'details'])
            ->middleware('permission:salary.view')
            ->name('details');
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
        Route::put('/settings/rules', [PayrollSettingsController::class, 'updateRules'])
            ->middleware('permission:payroll.settings')->name('settings.rules');
        Route::put('/settings/tax', [PayrollSettingsController::class, 'updateTax'])
            ->middleware('permission:payroll.settings')->name('settings.tax');
        Route::put('/settings/operational', [PayrollSettingsController::class, 'updateOperational'])
            ->middleware('permission:payroll.settings')->name('settings.operational.update');
        Route::put('/settings/attendance-penalties', [PayrollSettingsController::class, 'updateAttendancePenalties'])
            ->middleware('permission:payroll.settings')->name('settings.attendance-penalties.update');
        Route::put('/settings/leaves', [PayrollSettingsController::class, 'updateLeavePolicy'])
            ->middleware('permission:payroll.settings')->name('settings.leaves.update');
        Route::post('/settings/leave-types', [PayrollSettingsController::class, 'storeLeaveType'])
            ->middleware('permission:payroll.settings')->name('settings.leave-types.store');
        Route::delete('/settings/leave-types/{leaveType}', [PayrollSettingsController::class, 'destroyLeaveType'])
            ->middleware('permission:payroll.settings')->name('settings.leave-types.destroy');
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
        Route::post('/runs/generate/start', [PayrollController::class, 'startGeneration'])
            ->middleware('permission:payroll.create')
            ->name('runs.generate.start');
        Route::post('/runs/generate/batch', [PayrollController::class, 'generateBatch'])
            ->middleware('permission:payroll.create')
            ->name('runs.generate.batch');
        Route::post('/runs/{payrollRun}/approve', [PayrollController::class, 'approveRun'])
            ->middleware('permission:payroll.approve')
            ->name('runs.approve');
        Route::post('/runs/{payrollRun}/lock', [PayrollController::class, 'lockRun'])
            ->middleware('permission:payroll.lock')
            ->name('runs.lock');
        Route::post('/close-period', [PayrollController::class, 'closePeriod'])
            ->middleware('permission:payroll.lock')
            ->name('close-period');
        Route::delete('/runs/{payrollRun}', [PayrollController::class, 'destroyRun'])
            ->middleware('permission:payroll.delete')
            ->name('runs.destroy');
        Route::post('/{payrollRecord}/approve', [PayrollController::class, 'approve'])
            ->middleware('permission:payroll.approve')
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

    // Final settlements (end of service)
    Route::prefix('settlements')->name('settlements.')->group(function () {
        Route::get('/', [SettlementController::class, 'index'])
            ->middleware('permission:settlements.view')->name('index');
        Route::get('/create', [SettlementController::class, 'create'])
            ->middleware('permission:settlements.create')->name('create');
        Route::post('/', [SettlementController::class, 'store'])
            ->middleware('permission:settlements.create')->name('store');
        Route::get('/{settlement}', [SettlementController::class, 'show'])
            ->middleware('permission:settlements.view')->name('show');
        Route::put('/{settlement}', [SettlementController::class, 'update'])
            ->middleware('permission:settlements.create')->name('update');
        Route::post('/{settlement}/approve', [SettlementController::class, 'approve'])
            ->middleware('permission:settlements.approve')->name('approve');
        Route::post('/{settlement}/pay', [SettlementController::class, 'pay'])
            ->middleware('permission:settlements.approve')->name('pay');
        Route::post('/{settlement}/cancel', [SettlementController::class, 'cancel'])
            ->middleware('permission:settlements.create')->name('cancel');
    });

    // Employee salaries (separate from the monthly payroll route)
    Route::get('/employee-salaries', [PayrollController::class, 'employeeSalaries'])
        ->middleware('permission:payroll.view')
        ->name('payroll.employee-salaries');
    // Backward-compatible URL used by older navigation links.
    Route::get('/payroll/employee-salaries', [PayrollController::class, 'employeeSalaries'])
        ->middleware('permission:payroll.view')
        ->name('payroll.employee-salaries.legacy');
    Route::get('/employee-salaries/{payrollRecord}/payslip-pdf', [PayrollController::class, 'payslipPdf'])
        ->middleware('permission:payroll.view')
        ->name('payroll.employee-salaries.payslip-pdf');

    // Insurance
    Route::get('/employees/{employee}/forms/s1-editable', [EditableS1Controller::class, 'show'])
        ->middleware('permission:insurance.view')
        ->name('employees.forms.s1.editable');
    Route::get('/insurance/forms/s1/print-all', [EditableS1Controller::class, 'printAll'])
        ->middleware('permission:insurance.view')
        ->name('insurance.forms.s1.print-all');
    Route::get('/insurance/forms/s2-editable', [EditableS2Controller::class, 'show'])
        ->middleware('permission:insurance.view')
        ->name('insurance.forms.s2.editable');
    Route::get('/employees/{employee}/forms/s6-editable', [EditableS6Controller::class, 'show'])
        ->middleware('permission:insurance.view')
        ->name('employees.forms.s6.editable');
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
        Route::put('/{type}/{item}', [MasterDataController::class, 'update'])
            ->middleware('permission:roles.edit')
            ->whereNumber('item')
            ->name('update');
        Route::delete('/{type}/{item}', [MasterDataController::class, 'destroy'])
            ->middleware('permission:roles.edit')
            ->whereNumber('item')
            ->name('destroy');
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
        Route::patch('/{user}/status', [UserController::class, 'updateStatus'])
            ->middleware('permission:users.edit')
            ->name('status.update');
        Route::delete('/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:users.delete')
            ->name('destroy');
    });

    Route::prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])
            ->middleware('permission:roles.view')
            ->name('index');
        Route::post('/', [RoleController::class, 'store'])
            ->middleware('permission:roles.create')
            ->name('store');
        Route::put('/{role}', [RoleController::class, 'update'])
            ->middleware('permission:roles.edit')
            ->name('update');
        Route::delete('/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:roles.delete')
            ->name('destroy');
    });
});


// Private owner dashboard: not listed in the sidebar. It authenticates
// through the dedicated `owner` guard (its own login below); the `owner`
// middleware answers 404 to anyone logged into the regular app who is not
// the owner, and sends only true guests to the owner login.
Route::get('/owner/login', [\App\Http\Controllers\OwnerAuthController::class, 'showLogin'])->name('owner.login');
Route::post('/owner/login', [\App\Http\Controllers\OwnerAuthController::class, 'login'])->middleware('throttle:5,1')->name('owner.login.attempt');
Route::post('/owner/logout', [\App\Http\Controllers\OwnerAuthController::class, 'logout'])->name('owner.logout');
Route::middleware(['no_auth_cache', 'owner'])->prefix('owner')->name('owner.')->group(function () {
    Route::get('/', [\App\Http\Controllers\OwnerDashboardController::class, 'index'])->name('index');
    Route::post('/plans', [\App\Http\Controllers\OwnerDashboardController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [\App\Http\Controllers\OwnerDashboardController::class, 'updatePlan'])->name('plans.update');
    Route::post('/companies', [\App\Http\Controllers\OwnerDashboardController::class, 'storeCompany'])->name('companies.store');
    Route::put('/companies/{company}/subscription', [\App\Http\Controllers\OwnerDashboardController::class, 'updateCompanySubscription'])->name('companies.subscription.update');
    Route::put('/companies/{company}/status', [\App\Http\Controllers\OwnerDashboardController::class, 'updateCompanyStatus'])->name('companies.status.update');
    Route::put('/renewals/{renewal}', [\App\Http\Controllers\OwnerDashboardController::class, 'updateRenewal'])->name('renewals.update');
    Route::post('/announcements', [\App\Http\Controllers\OwnerDashboardController::class, 'sendAnnouncement'])->name('announcements.send');

    // Guided-tour management: draft edits, preview and publish (see the
    // OwnerTourController docblock for the draft/publish rules).
    Route::get('/tour', [\App\Http\Controllers\OwnerTourController::class, 'index'])->name('tour.index');
    Route::post('/tour/draft', [\App\Http\Controllers\OwnerTourController::class, 'startDraft'])->name('tour.draft.start');
    Route::delete('/tour/draft', [\App\Http\Controllers\OwnerTourController::class, 'discardDraft'])->name('tour.draft.discard');
    Route::post('/tour/publish', [\App\Http\Controllers\OwnerTourController::class, 'publish'])->name('tour.publish');
    Route::post('/tour/steps', [\App\Http\Controllers\OwnerTourController::class, 'storeStep'])->name('tour.steps.store');
    Route::post('/tour/steps/picked', [\App\Http\Controllers\OwnerTourController::class, 'storePickedStep'])->name('tour.steps.picked');
    Route::put('/tour/steps/{tourStep}', [\App\Http\Controllers\OwnerTourController::class, 'updateStep'])->name('tour.steps.update');
    Route::post('/tour/steps/{tourStep}/move', [\App\Http\Controllers\OwnerTourController::class, 'moveStep'])->name('tour.steps.move');
    Route::post('/tour/steps/{tourStep}/toggle', [\App\Http\Controllers\OwnerTourController::class, 'toggleStep'])->name('tour.steps.toggle');
    Route::delete('/tour/steps/{tourStep}', [\App\Http\Controllers\OwnerTourController::class, 'destroyStep'])->name('tour.steps.destroy');
});

// Mobile JSON adapter, sharing the existing web session and business rules.
require __DIR__.'/mobile.php';
