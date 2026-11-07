<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\FingerprintDevice;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\ContractExpiryNotification;
use App\Notifications\FingerprintDeviceStaleNotification;
use App\Notifications\PayrollPeriodClosedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Notification audit coverage:
 *  - contract expiry alerts fire once per threshold, not daily, and stay
 *    inside the employee's company;
 *  - a stale fingerprint device alerts the company's device users once a day;
 *  - the automatic payroll period close tells the company's payroll users once.
 */
class NotificationsAuditTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private Company $otherCompany;
    private EmployeeStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'Notify A', 'slug' => 'notify-a', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $this->otherCompany = Company::create(['name' => 'Notify B', 'slug' => 'notify-b', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->otherCompany->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
    }

    private function userWithPermissions(Company $company, string $email, array $permissions): User
    {
        $role = Role::create(['name' => 'role_'.uniqid(), 'display_name' => 'Role', 'company_id' => $company->id]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('name', $permissions)->pluck('id'));
        return User::create(['name' => 'U', 'email' => $email, 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function employee(Company $company, string $code, ?string $contractEnd): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'E'.$code, 'full_name' => 'Employee '.$code,
            'company_id' => $company->id, 'status_id' => $this->status->id, 'hire_date' => '2024-01-01',
            'contract_end_date' => $contractEnd,
        ]);
    }

    public function test_contract_expiry_alerts_fire_once_per_threshold_and_stay_in_company(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $user = $this->userWithPermissions($this->company, 'a1@example.test', ['employees.view']);
        $outsider = $this->userWithPermissions($this->otherCompany, 'b1@example.test', ['employees.view']);
        $noPermission = $this->userWithPermissions($this->company, 'a2@example.test', []);
        $employee = $this->employee($this->company, 'C1', '2026-11-08'); // 7 days away: a threshold

        Artisan::call('tazamun:send-contract-alerts');
        $this->assertSame(1, $user->notifications()->where('type', ContractExpiryNotification::class)->count());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(0, $noPermission->notifications()->count());

        // Same threshold, later run: no duplicate.
        Artisan::call('tazamun:send-contract-alerts');
        $this->assertSame(1, $user->notifications()->where('type', ContractExpiryNotification::class)->count());

        // A non-threshold day (6 days left) stays quiet.
        Carbon::setTestNow('2026-11-02 09:00:00');
        Artisan::call('tazamun:send-contract-alerts');
        $this->assertSame(1, $user->notifications()->where('type', ContractExpiryNotification::class)->count());

        // The next threshold (3 days left) alerts exactly once more.
        Carbon::setTestNow('2026-11-05 09:00:00');
        Artisan::call('tazamun:send-contract-alerts');
        $this->assertSame(2, $user->notifications()->where('type', ContractExpiryNotification::class)->count());
        $this->assertSame(3, $user->notifications()->where('type', ContractExpiryNotification::class)->latest()->first()->data['days_remaining']);
        Carbon::setTestNow();
    }

    public function test_stale_fingerprint_device_alerts_company_device_users_once_per_day(): void
    {
        $user = $this->userWithPermissions($this->company, 'd1@example.test', ['fingerprint-devices.view']);
        $outsider = $this->userWithPermissions($this->otherCompany, 'd2@example.test', ['fingerprint-devices.view']);
        FingerprintDevice::withoutGlobalScopes()->forceCreate([
            'company_id' => $this->company->id, 'name' => 'Main Door', 'ip_address' => '192.168.1.10',
            'sync_enabled' => true, 'is_active' => true, 'sync_interval_minutes' => 15,
            'last_sync_at' => now()->subHours(6),
        ]);
        FingerprintDevice::withoutGlobalScopes()->forceCreate([
            'company_id' => $this->company->id, 'name' => 'Healthy', 'ip_address' => '192.168.1.11',
            'sync_enabled' => true, 'is_active' => true, 'sync_interval_minutes' => 15,
            'last_sync_at' => now()->subMinutes(2),
        ]);

        Artisan::call('operations:detect-stale-sync');
        $this->assertSame(1, $user->notifications()->where('type', FingerprintDeviceStaleNotification::class)->count());
        $this->assertSame('Main Door', $user->notifications()->first()->data['device_name']);
        $this->assertSame(0, $outsider->notifications()->count());

        // Same day: no repeat while the device stays stale.
        Artisan::call('operations:detect-stale-sync');
        $this->assertSame(1, $user->notifications()->where('type', FingerprintDeviceStaleNotification::class)->count());
    }

    public function test_automatic_payroll_period_close_notifies_payroll_users_once(): void
    {
        $user = $this->userWithPermissions($this->company, 'p1@example.test', ['payroll.view']);
        $outsider = $this->userWithPermissions($this->otherCompany, 'p2@example.test', ['payroll.view']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_month' => '2026-10-01',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'Approved',
            'closes_at' => today()->subDay(),
        ]);

        Artisan::call('payroll:close-periods', ['--company' => $this->company->id]);
        $this->assertSame(1, $user->notifications()->where('type', PayrollPeriodClosedNotification::class)->count());
        $this->assertSame($run->id, $user->notifications()->first()->data['payroll_run_id']);
        $this->assertSame(0, $outsider->notifications()->count());

        Artisan::call('payroll:close-periods', ['--company' => $this->company->id]);
        $this->assertSame(1, $user->notifications()->where('type', PayrollPeriodClosedNotification::class)->count());
    }
}
