<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Role, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollDrawerAccruedFiguresTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'integration-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'DrawerCo', 'slug' => 'drawer-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'drawer@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($user);
        $this->employee = Employee::create(['employee_code' => 'DRAWER1', 'first_name' => 'Lump', 'full_name' => 'Lump Employee', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_drawer_accrued_figures_use_the_contractual_prorated_amounts(): void
    {
        // Contractual row: basic 6000 + lump allowances 2600 (no itemized split),
        // gross 8600 - the engine's internal split (gross/1.25 = 6880) differs from
        // the contractual basic, which is exactly when the drawer contradicted itself.
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8600,
            'basic_salary' => 6000, 'allowances' => 2600,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true, 'status' => 'Active',
        ]);

        // Clock is 2026-11-02: two days into the period, factor 2/30.
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record = $run->records()->where('employee_id', $this->employee->id)->firstOrFail();

        // The record persists the contractual prorated amounts.
        $this->assertEqualsWithDelta(400.00, (float) $record->basic_salary, 0.01); // 6000 x 2/30
        $this->assertEqualsWithDelta(173.33, (float) $record->allowances, 0.01);   // 2600 x 2/30

        // «المستحق حتى اليوم» is the same contractual prorated basic, not the engine split.
        $this->assertEqualsWithDelta((float) $record->basic_salary, (float) data_get($record->calculation_snapshot, 'to_date_entitlement'), 0.01);

        // The drawer total includes the lump «البدلات» line: total = accrued + allowances.
        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        // The people JSON is HTML-hex-escaped (JSON_HEX_QUOT): match the value, not the quote.
        $this->assertMatchesRegularExpression('/entitlements.{0,8}:573\\.33/', $response->getContent()); // 400.00 + 173.33

        // A structure with a contractual itemized split keeps its own amounts,
        // prorated - the engine's split never replaces them on the record.
        $employee2 = Employee::create(['employee_code' => 'DRAWER2', 'first_name' => 'Split', 'full_name' => 'Split Employee', 'company_id' => $this->company->id, 'status_id' => $this->employee->status_id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $employee2->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8600,
            'basic_salary' => 6000, 'allowances' => 2600,
            'housing_allowance' => 600, 'transportation_allowance' => 500, 'meal_allowance' => 500, 'clothing_allowance' => 1000,
            'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record2 = PayrollRecord::withoutGlobalScopes()->where('employee_id', $employee2->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(40.00, (float) $record2->housing_allowance, 0.01);        // 600 x 2/30
        $this->assertEqualsWithDelta(33.33, (float) $record2->transportation_allowance, 0.01); // 500 x 2/30
        $this->assertEqualsWithDelta(33.33, (float) $record2->meal_allowance, 0.01);
        $this->assertEqualsWithDelta(66.67, (float) $record2->clothing_allowance, 0.01);
    }
}
