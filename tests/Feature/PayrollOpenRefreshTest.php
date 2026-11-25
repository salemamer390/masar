<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, PayrollRun, Role, Salary, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollOpenRefreshTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private EmployeeStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00', 'Africa/Cairo'));
        $this->status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'integration-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'OpenRefreshCo', 'slug' => 'open-refresh-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->actingAs(User::create(['name' => 'Manager', 'email' => 'openrefresh@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(string $code): Employee
    {
        $e = Employee::create(['employee_code' => $code, 'first_name' => $code, 'full_name' => $code, 'company_id' => $this->company->id, 'status_id' => $this->status->id, 'hire_date' => '2025-01-01']);
        Salary::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8000,
            'basic_salary' => 6000, 'allowances' => 2000, 'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 8000, 'net_salary' => 7000, 'no_insurance' => true, 'status' => 'Active']);
        return $e;
    }

    private function recordCount(string $code): int
    {
        return \App\Models\PayrollRecord::whereHas('employee', fn ($q) => $q->where('employee_code', $code))->count();
    }

    public function test_opening_the_page_refreshes_the_draft_every_time(): void
    {
        $this->employee('OR1');
        app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $this->employee('OR2');
        $this->assertSame(0, $this->recordCount('OR2'));

        Carbon::setTestNow(now()->addMinutes(10));
        $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']))->assertOk();
        $this->assertSame(1, $this->recordCount('OR2'), 'a stale draft is refreshed when the page opens');

        $this->employee('OR3');
        Carbon::setTestNow(now()->addMinute());
        $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']))->assertOk();
        $this->assertSame(1, $this->recordCount('OR3'), 'every open refreshes the draft, with no waiting period');
    }

    public function test_an_approved_run_is_never_refreshed_on_open(): void
    {
        $this->employee('OA1');
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $run->update(['status' => 'Approved']);
        $this->employee('OA2');
        Carbon::setTestNow(now()->addMinutes(30));
        $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']))->assertOk();
        $this->assertSame(0, $this->recordCount('OA2'));
        $this->assertSame('Approved', PayrollRun::find($run->id)->status);
    }

    public function test_a_closed_current_month_run_shows_a_notice_instead_of_staying_silent(): void
    {
        $this->employee('ON1');
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $run->update(['status' => 'Approved']);
        Carbon::setTestNow(Carbon::parse('2026-11-20'));
        $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']))->assertOk()
            ->assertSee('لا يتحدث تلقائيًا');
    }
}
