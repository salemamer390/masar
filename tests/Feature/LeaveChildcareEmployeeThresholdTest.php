<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, LeaveType, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The childcare establishment-size rule (statutory reference: 50) is a real
 * per-company limit stored in leave_type_company_limits: the approval check
 * reads the company's own value, lowering it grants the benefit to a smaller
 * company, and one company's choice never edits the shared type row or
 * another company's value.
 */
class LeaveChildcareEmployeeThresholdTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a;
    private Company $b;
    private User $ua;
    private User $ub;

    protected function setUp(): void
    {
        parent::setUp();
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'childcare-thr-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'cc-thr-a', 'slug' => 'cc-thr-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'cc-thr-b', 'slug' => 'cc-thr-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'cc-thr-a@example.test', 'password' => bcrypt('x'), 'role_id' => $roleA->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->b->id]);
        $this->ub = User::create(['name' => 'M', 'email' => 'cc-thr-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->b->id, 'is_active' => true]);
    }

    private function childcare(): LeaveType
    {
        return LeaveType::whereNull('company_id')->where('policy_code', 'childcare')->firstOrFail();
    }

    private function saveMinEmployees(Company $company, int $value): void
    {
        $type = $this->childcare();
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15, 'annual_wait_months' => 6],
            'types' => [$type->id => ['min_employees' => $value]],
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_company_can_lower_the_threshold_and_the_approval_check_uses_it(): void
    {
        $this->actingAs($this->ua);
        $type = $this->childcare();
        $this->assertSame(50, (int) $type->getRawOriginal('min_employees'), 'shared type keeps the statutory reference');

        $this->saveMinEmployees($this->a, 2);

        // Stored as this company's override; the shared row is untouched.
        $this->assertSame(2, (int) DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->value('min_employees'));
        $this->assertSame(50, (int) $type->fresh()->getRawOriginal('min_employees'));

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $emp = Employee::create(['employee_code' => 'CC1', 'first_name' => 'E', 'full_name' => 'E CC1', 'gender' => 'female', 'company_id' => $this->a->id, 'status_id' => $status->id, 'hire_date' => '2010-01-01']);

        // One employee on record: below the saved threshold of 2, so approval is blocked.
        $this->post(route('leaves.store'), [
            'employee_id' => $emp->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-12-20', 'end_date' => '2027-01-18',
        ])->assertSessionHasErrors('leave_type_id');
        $errors = session('errors') ? session('errors')->get('leave_type_id') : [];
        $this->assertStringContainsString('عدد العاملين', json_encode($errors, JSON_UNESCAPED_UNICODE));

        // Lower the threshold to 1: the same request now passes the size check.
        // (The type still demands a supporting document, so we assert the size
        // error specifically is gone rather than a fully clean save.)
        $this->saveMinEmployees($this->a, 1);
        $this->post(route('leaves.store'), [
            'employee_id' => $emp->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-12-20', 'end_date' => '2027-01-18',
        ]);
        $errors = session('errors') ? session('errors')->get('leave_type_id') : [];
        $this->assertStringNotContainsString('عدد العاملين', json_encode($errors, JSON_UNESCAPED_UNICODE), 'with the threshold lowered to 1 the size check no longer blocks');
    }

    public function test_raising_the_threshold_above_the_statutory_reference_requires_confirmation(): void
    {
        $this->actingAs($this->ua);
        $type = $this->childcare();

        // 60 > the statutory 50: restricts a right, so it is flagged below-reference and rejected without confirmation.
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15, 'annual_wait_months' => 6],
            'types' => [$type->id => ['min_employees' => 60]],
        ])->assertSessionHasErrors('below_reference');
        $errors = session('errors') ? session('errors')->get('below_reference') : [];
        $this->assertStringContainsString('57', json_encode($errors, JSON_UNESCAPED_UNICODE), 'the warning names article 57');
        $this->assertSame(0, DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->count(), 'nothing is saved without confirmation');

        // 30 < 50 widens eligibility (a company benefit): no warning, saves cleanly.
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15, 'annual_wait_months' => 6],
            'types' => [$type->id => ['min_employees' => 30]],
        ])->assertSessionDoesntHaveErrors();
        $this->assertSame(30, (int) DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->value('min_employees'));
    }

    public function test_other_companys_threshold_is_independent(): void
    {
        $this->actingAs($this->ua);
        $this->saveMinEmployees($this->a, 2);

        $this->actingAs($this->ub);
        $type = $this->childcare();
        $this->assertSame(0, DB::table('leave_type_company_limits')->where('company_id', $this->b->id)->where('leave_type_id', $type->id)->count(), 'company B has no override written by company A');

        // Company B falls back to the shared statutory value (50) for its own check.
        $limits = app(\App\Services\LeaveBalanceService::class)->limitsFor($type, $this->b->id);
        $this->assertSame(50, $limits['min_employees']);
        $limitsA = app(\App\Services\LeaveBalanceService::class)->limitsFor($type, $this->a->id);
        $this->assertSame(2, $limitsA['min_employees']);
    }
}
