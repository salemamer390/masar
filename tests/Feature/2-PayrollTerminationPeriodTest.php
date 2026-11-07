<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Role, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollTerminationPeriodTest extends TestCase
{
    use DatabaseTransactions;

    private EmployeeStatus $terminated;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 11:16:00', 'Africa/Cairo'));
        $this->terminated = EmployeeStatus::firstOrCreate(['name' => 'منتهي الخدمة']);
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeCompany(string $slug, ?int $startDay, ?int $endDay): Company
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'term-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        if ($startDay !== null) {
            DB::table('hr_operational_settings')->insert([
                ['company_id' => $company->id, 'setting_key' => 'payroll_period_start_day', 'setting_value' => (string) $startDay, 'label' => 't', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => $company->id, 'setting_key' => 'payroll_period_end_day', 'setting_value' => (string) $endDay, 'label' => 't', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Manager', 'email' => $slug . '@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($user);
        return $company;
    }

    private function terminatedEmployee(Company $company, string $code, string $terminationDate): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'Term', 'full_name' => 'Term Employee', 'company_id' => $company->id,
            'status_id' => $this->terminated->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00',
            'termination_type' => 'استقالة', 'termination_date' => $terminationDate,
        ]);
    }

    public function test_termination_inside_a_cross_month_period_is_payable_for_its_days(): void
    {
        // 26/25 company: the period 26 Nov - 25 Dec is anchored to December.
        $company = $this->makeCompany('term-co-a', 26, 25);
        $employee = $this->terminatedEmployee($company, 'TA1', '2026-11-28');

        $window = app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-12-01'));
        $this->assertNotNull($window, 'terminated 28 Nov must be payable in the 26 Nov - 25 Dec period');
        $this->assertSame('2026-11-26', $window['start']->toDateString());
        $this->assertSame('2026-11-28', $window['end']->toDateString());
        $this->assertSame(3, $window['days']);
        $this->assertEqualsWithDelta(3 / 30, $window['factor'], 0.0001);

        // End to end: the December run carries a record prorated to 3/30 of the fixed amounts.
        \App\Models\Salary::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 6000,
            'basic_salary' => 6000, 'allowances' => 0,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 6000, 'net_salary' => 6000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $company->id);
        $record = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(600.00, (float) $record->basic_salary, 0.01); // 6000 x 3/30
    }

    public function test_termination_later_inside_the_same_cross_month_period_counts_to_that_date(): void
    {
        $company = $this->makeCompany('term-co-b', 26, 25);
        $employee = $this->terminatedEmployee($company, 'TB1', '2026-12-20');

        $window = app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-12-01'));
        $this->assertSame('2026-11-26', $window['start']->toDateString());
        $this->assertSame('2026-12-20', $window['end']->toDateString());
        $this->assertSame(25, $window['days']); // 5 (26-30 Nov) + 20 (1-20 Dec)
    }

    public function test_termination_outside_the_period_is_still_excluded(): void
    {
        $company = $this->makeCompany('term-co-c', 26, 25);
        $employee = $this->terminatedEmployee($company, 'TC1', '2026-10-10');

        $this->assertNull(app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-11-01')));
        $this->assertNull(app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-12-01')));
    }

    public function test_default_same_month_period_behavior_is_unchanged(): void
    {
        $company = $this->makeCompany('term-co-d', null, null); // defaults 1 -> 30
        $employee = $this->terminatedEmployee($company, 'TD1', '2026-11-07');

        $window = app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-11-01'));
        $this->assertSame('2026-11-01', $window['start']->toDateString());
        $this->assertSame('2026-11-07', $window['end']->toDateString());
        $this->assertSame(7, $window['days']);

        $this->assertNull(app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-12-01')));
    }

    public function test_terminal_status_without_any_end_date_is_still_excluded(): void
    {
        $company = $this->makeCompany('term-co-e', 26, 25);
        $employee = Employee::create([
            'employee_code' => 'TE1', 'first_name' => 'NoDate', 'full_name' => 'NoDate Employee', 'company_id' => $company->id,
            'status_id' => $this->terminated->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00',
        ]);

        $this->assertNull(app(PayrollRunService::class)->serviceWindow($employee, Carbon::parse('2026-12-01')));
    }
}
