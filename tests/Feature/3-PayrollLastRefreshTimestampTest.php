<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, Company, CompanySubscription, Employee, EmployeeStatus, PayrollRun, Role, SubscriptionPlan, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollLastRefreshTimestampTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 13:45:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'refresh-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'RefreshCo', 'slug' => 'refresh-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'refresh@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($user);
        $employee = Employee::create(['employee_code' => 'REF1', 'first_name' => 'Ref', 'full_name' => 'Ref Employee', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 6000,
            'basic_salary' => 6000, 'allowances' => 0,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 6000, 'net_salary' => 6000, 'no_insurance' => true, 'status' => 'Active',
        ]);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function currentRun(): PayrollRun
    {
        return PayrollRun::where('company_id', $this->company->id)->whereDate('payroll_month', '2026-11-01')->firstOrFail();
    }

    public function test_successful_refresh_shows_todays_time_next_to_the_refresh_button(): void
    {
        $this->post(route('payroll.runs.generate', ['month' => '2026-11-01']))->assertRedirect();
        $this->assertTrue(ActivityLog::where('model_id', $this->currentRun()->id)->where('action', 'payroll_generate')->exists());

        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        $response->assertSee('آخر تحديث');
        $response->assertSee('13:45');
    }

    public function test_a_refresh_from_a_previous_day_shows_the_date_instead(): void
    {
        $this->post(route('payroll.runs.generate', ['month' => '2026-11-01']))->assertRedirect();

        // Backdate the refresh log: same truth, different day.
        ActivityLog::where('model_id', $this->currentRun()->id)->where('action', 'payroll_generate')
            ->update(['created_at' => Carbon::parse('2026-11-05 09:30:00', 'Africa/Cairo')]);

        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        $response->assertSee('آخر تحديث');
        $response->assertSee('05/11/2026');
    }

    public function test_batched_generation_completion_also_records_the_refresh(): void
    {
        $this->postJson(route('payroll.runs.generate.start'), ['month' => '2026-11-01'])->assertOk();
        $run = $this->currentRun();
        $this->assertFalse(ActivityLog::where('model_id', $run->id)->where('action', 'payroll_generate')->exists());

        $this->postJson(route('payroll.runs.generate.batch'), ['month' => '2026-11-01', 'offset' => 0])
            ->assertOk()->assertJsonPath('complete', true);

        $this->assertTrue(ActivityLog::where('model_id', $run->id)->where('action', 'payroll_generate')->exists());

        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        $response->assertSee('آخر تحديث');
        $response->assertSee('13:45');
    }

    public function test_a_month_without_any_successful_refresh_shows_no_timestamp(): void
    {
        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-12-01']));
        $response->assertOk();
        $response->assertDontSee('آخر تحديث');
    }
}
