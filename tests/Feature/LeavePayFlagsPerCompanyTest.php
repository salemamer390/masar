<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The paid / deducts-from-annual switches on statutory cards are editable per
 * company: a company's choice is stored in its own leave_type_company_limits
 * row, never on the shared leave type, and payroll + balance consumers honor it.
 */
class LeavePayFlagsPerCompanyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a;
    private Company $b;
    private User $ua;
    private EmployeeStatus $st;

    protected function setUp(): void
    {
        parent::setUp();
        $this->st = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'pay-flags-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'pay-flags-a', 'slug' => 'pay-flags-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'pay-flags-b', 'slug' => 'pay-flags-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'pay-flags-a@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->a->id, 'is_active' => true]);
    }

    private function sharedType(string $code): LeaveType
    {
        return LeaveType::whereNull('company_id')->where('policy_code', $code)->firstOrFail();
    }

    public function test_paid_switch_saved_per_company_without_touching_shared_type(): void
    {
        $type = $this->sharedType('pilgrimage');
        $sharedPaid = (bool) $type->is_paid;
        $this->actingAs($this->ua);
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$type->id => ['is_paid' => '0']],
            'confirm_below_reference' => '1',
        ])->assertSessionDoesntHaveErrors();

        $row = DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->is_paid);
        $this->assertSame($sharedPaid, (bool) $type->fresh()->is_paid, 'shared type row must stay untouched');

        // Effective flags differ per company.
        $balances = app(LeaveBalanceService::class);
        $this->assertFalse($balances->payFlags($type->fresh(), $this->a->id)['is_paid']);
        $this->assertSame($sharedPaid, $balances->payFlags($type->fresh(), $this->b->id)['is_paid']);
    }

    public function test_deducts_switch_changes_balance_consumption_for_that_company_only(): void
    {
        $type = $this->sharedType('pilgrimage');
        $this->assertFalse((bool) $type->deducts_annual_balance);
        $this->actingAs($this->ua);
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$type->id => ['deducts_annual_balance' => '1']],
        ])->assertSessionDoesntHaveErrors();

        $balances = app(LeaveBalanceService::class);
        $this->assertTrue($balances->payFlags($type->fresh(), $this->a->id)['deducts_annual_balance']);
        $this->assertFalse($balances->payFlags($type->fresh(), $this->b->id)['deducts_annual_balance']);
        $this->assertFalse((bool) $type->fresh()->deducts_annual_balance, 'shared type row must stay untouched');
    }

    public function test_payroll_unpaid_decision_uses_company_override(): void
    {
        $type = $this->sharedType('pilgrimage');
        $employee = Employee::create(['employee_code' => 'pf1', 'first_name' => 'E', 'full_name' => 'Epf1', 'company_id' => $this->a->id, 'status_id' => $this->st->id, 'hire_date' => '2020-01-01']);
        DB::table('leave_type_company_limits')->insert([
            'company_id' => $this->a->id, 'leave_type_id' => $type->id, 'is_paid' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $leave = new Leave(['employee_id' => $employee->id]);
        $leave->setRelation('leaveType', $type);

        $service = app(\App\Services\PayrollRunService::class);
        $m = new \ReflectionMethod($service, 'isUnpaidLeave');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($service, $leave), 'company override is_paid=false must make the leave unpaid in payroll');

        DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->update(['is_paid' => true]);
        $leave2 = new Leave(['employee_id' => $employee->id]);
        $leave2->setRelation('leaveType', $type);
        $service2 = app(\App\Services\PayrollRunService::class);
        $this->assertFalse($m->invoke($service2, $leave2));
    }

    public function test_size_threshold_counts_requesting_company_only(): void
    {
        // Unsaved type instance: min_employees=2, no limits rows, no other policy branch.
        $type = new LeaveType(['name' => 'SizeTest', 'is_active' => true, 'is_paid' => true]);
        $type->id = 999999;
        $type->min_employees = 2;
        $type->policy_code = null;

        $mk = fn (Company $c, string $code) => Employee::create(['employee_code' => $code, 'first_name' => 'E', 'full_name' => 'E'.$code, 'company_id' => $c->id, 'status_id' => $this->st->id, 'hire_date' => '2020-01-01']);
        $empA = $mk($this->a, 'sz-a1');
        foreach (range(1, 3) as $i) $mk($this->b, 'sz-b'.$i); // global headcount already exceeds the threshold

        $leave = new Leave(['employee_id' => $empA->id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'days' => 1]);
        $leave->setRelation('leaveType', $type);
        $leave->setRelation('employee', $empA);

        try {
            app(LeaveBalanceService::class)->validateForApproval($leave);
            $this->fail('expected a size-threshold validation failure with 1 employee in company A');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('عدد العاملين', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $mk($this->a, 'sz-a2'); // company A now meets its own threshold
        app(LeaveBalanceService::class)->validateForApproval($leave);
        $this->assertTrue(true);
    }
}
