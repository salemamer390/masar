<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Per-company activation of shared leave types (the settings tab toggle) and
 * the per-company sick-pay basis. Deactivation must never touch the shared
 * leave_types row; it lives in leave_type_company_limits.is_active and is
 * enforced when approving a leave and when listing requestable types.
 */
class LeaveTypeActivationPerCompanyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private Company $otherCompany;
    private User $manager;
    private EmployeeStatus $status;
    private LeaveType $sickType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'act-unlimited'], ['name' => 'T', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'act-a', 'slug' => 'act-a', 'status' => 'active']);
        $this->otherCompany = Company::create(['name' => 'act-b', 'slug' => 'act-b', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        CompanySubscription::create(['company_id' => $this->otherCompany->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->manager = User::create(['name' => 'M', 'email' => 'act-a@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->manager);
        $sickType = LeaveType::withoutGlobalScopes()->where('policy_code', 'sick')->whereNull('company_id')->first();
        if (!$sickType) {
            // withoutEvents: keep the type shared (company_id null) like the seeded production rows;
            // the model's creating hook would otherwise attach it to the manager's company.
            $sickType = LeaveType::withoutEvents(fn () => LeaveType::create(['name' => 'إجازة مرضية', 'policy_code' => 'sick', 'is_paid' => true, 'is_active' => true, 'company_id' => null]));
        }
        $this->sickType = $sickType;
    }

    private function employee(?int $companyId = null): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'employee_code' => 'ACT'.$n, 'first_name' => 'E'.$n, 'full_name' => 'Employee '.$n,
            'company_id' => $companyId ?? $this->company->id, 'status_id' => $this->status->id,
            'hire_date' => '2020-01-01',
        ]);
    }

    private function deactivateSick(): void
    {
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$this->sickType->id => ['is_active' => 0]],
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_deactivation_saves_per_company_without_touching_shared_type(): void
    {
        $this->deactivateSick();

        $row = DB::table('leave_type_company_limits')->where('company_id', $this->company->id)->where('leave_type_id', $this->sickType->id)->first();
        $this->assertNotNull($row, 'company limits row must store the deactivation');
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame(1, (int) LeaveType::withoutGlobalScopes()->find($this->sickType->id)->is_active, 'shared type row must stay active');
        $this->assertFalse(app(LeaveBalanceService::class)->limitsFor($this->sickType->fresh(), $this->company->id)['is_active']);
        $this->assertTrue(app(LeaveBalanceService::class)->limitsFor($this->sickType->fresh(), $this->otherCompany->id)['is_active']);
    }

    public function test_reactivation_restores_the_type_for_that_company(): void
    {
        $this->deactivateSick();
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$this->sickType->id => ['is_active' => 1]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue(app(LeaveBalanceService::class)->limitsFor($this->sickType->fresh(), $this->company->id)['is_active']);
    }

    public function test_deactivated_type_is_blocked_from_approval_for_that_company_only(): void
    {
        $this->deactivateSick();

        $leave = new Leave([
            'employee_id' => $this->employee()->id, 'leave_type_id' => $this->sickType->id,
            'start_date' => '2026-11-16', 'end_date' => '2026-11-17',
            'days' => 2, 'status' => 'Approved',
        ]);
        try {
            app(LeaveBalanceService::class)->validateForApproval($leave);
            $this->fail('deactivated type must fail approval validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('leave_type_id', $e->errors());
        }

        // Another company is unaffected by the deactivation. Act as that
        // company's manager so the company scopes load its employee.
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->otherCompany->id]);
        $managerB = User::create(['name' => 'MB', 'email' => 'act-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->otherCompany->id, 'is_active' => true]);
        $this->actingAs($managerB);
        $other = new Leave([
            'employee_id' => $this->employee($this->otherCompany->id)->id, 'leave_type_id' => $this->sickType->id,
            'start_date' => '2026-11-16', 'end_date' => '2026-11-17',
            'days' => 2, 'status' => 'Approved',
        ]);
        app(LeaveBalanceService::class)->validateForApproval($other);
        $this->assertTrue(true);
        $this->actingAs($this->manager);
    }

    public function test_leave_index_hides_deactivated_type(): void
    {
        $this->get(route('leaves.index'))->assertOk()->assertSee($this->sickType->name);
        $this->deactivateSick();
        $this->get(route('leaves.index'))->assertOk()->assertDontSee($this->sickType->name);
    }

    public function test_sick_pay_basis_saved_per_company_and_updatable(): void
    {
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'sick_pay_basis' => 'ins',
        ])->assertSessionDoesntHaveErrors();

        $basis = fn ($companyId) => DB::table('leave_policy_settings')->where('company_id', $companyId)->where('setting_key', 'sick_pay_basis')->value('setting_value');
        $this->assertSame('ins', $basis($this->company->id));
        $this->assertNull($basis($this->otherCompany->id));
        $this->assertSame(1, (int) LeaveType::withoutGlobalScopes()->find($this->sickType->id)->is_paid, 'shared sick type pay flag must not change');

        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'sick_pay_basis' => 'none',
        ])->assertSessionDoesntHaveErrors();
        $this->assertSame('none', $basis($this->company->id));
        $this->assertSame(1, DB::table('leave_policy_settings')->where('company_id', $this->company->id)->where('setting_key', 'sick_pay_basis')->count(), 'update must not duplicate the row');
    }

    public function test_sick_pay_basis_rejects_unknown_values(): void
    {
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'sick_pay_basis' => 'bogus',
        ])->assertSessionHasErrors(['sick_pay_basis']);
    }
}
