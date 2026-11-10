<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\LeaveBalanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Statutory shared types and the company's own types stay listed on the leave
 * settings page even while inactive, so a company can switch them back on.
 * Switching on is company-effective: it only writes the company's limits row
 * (which wins over the shared row), never mutates the shared row, and never
 * changes what other companies see in request choices or can approve.
 */
class LeaveStatutoryReactivationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a;
    private Company $b;
    private User $ua;
    private User $ub;
    private EmployeeStatus $st;

    protected function setUp(): void
    {
        parent::setUp();
        $this->st = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'react-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'react-a', 'slug' => 'react-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'react-b', 'slug' => 'react-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'react-a@example.test', 'password' => bcrypt('x'), 'role_id' => $roleA->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->b->id]);
        $this->ub = User::create(['name' => 'M', 'email' => 'react-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->b->id, 'is_active' => true]);
    }

    private function sharedType(string $code): LeaveType
    {
        $type = LeaveType::whereNull('company_id')->where('policy_code', $code)->first();
        if ($type) return $type;
        // The broken TazamunSeeder is the only source of the annual/casual/sick rows, so a
        // freshly migrated test DB may not have them; mirror the migration defaults.
        $id = DB::table('leave_types')->insertGetId([
            'name' => 'الإجازة السنوية', 'policy_code' => 'annual', 'company_id' => null,
            'is_active' => true, 'is_paid' => true, 'deducts_annual_balance' => true,
            'default_days' => 15, 'max_days_per_request' => 15, 'min_service_months' => 6,
        ]);
        return LeaveType::withoutGlobalScopes()->findOrFail($id);
    }

    private function employee(Company $company, string $code): Employee
    {
        return Employee::create(['employee_code' => $code, 'first_name' => 'E', 'full_name' => 'E '.$code, 'company_id' => $company->id, 'status_id' => $this->st->id, 'hire_date' => '2020-01-01']);
    }

    public function test_wholesale_deactivated_statutory_type_reactivates_for_one_company_only(): void
    {
        $type = $this->sharedType('annual');
        DB::table('leave_types')->where('id', $type->id)->update(['is_active' => false]);
        $this->actingAs($this->ua);

        // Still listed on the settings page with a real (named) toggle.
        $this->get(route('payroll.settings.index'))
            ->assertOk()
            ->assertSee('types['.$type->id.'][is_active]', false);

        // Not offered in request choices while wholesale inactive.
        $this->get(route('leaves.index'))->assertOk()->assertDontSee($type->name);

        // Company A switches it on.
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$type->id => ['is_active' => '1']],
        ])->assertSessionDoesntHaveErrors();

        // The shared row is NOT reactivated: no other company's state changes.
        $this->assertSame(0, (int) LeaveType::withoutGlobalScopes()->find($type->id)->is_active, 'shared row must stay inactive');
        $row = DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_active);

        // Company A now sees it in request choices and can approve it.
        $this->get(route('leaves.index'))->assertOk()->assertSee($type->name);
        $leaveA = new Leave([
            'employee_id' => $this->employee($this->a, 'RA1')->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-12-20', 'end_date' => '2026-12-21', 'days' => 2, 'status' => 'Approved',
        ]);
        app(LeaveBalanceService::class)->validateForApproval($leaveA);
        $this->assertTrue(true);

        // Company B still does not see it and cannot approve it.
        $this->actingAs($this->ub);
        $this->get(route('leaves.index'))->assertOk()->assertDontSee($type->name);
        $leaveB = new Leave([
            'employee_id' => $this->employee($this->b, 'RB1')->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-12-20', 'end_date' => '2026-12-21', 'days' => 2, 'status' => 'Approved',
        ]);
        try {
            app(LeaveBalanceService::class)->validateForApproval($leaveB);
            $this->fail('wholesale-inactive type must stay blocked for company B');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('leave_type_id', $e->errors());
        }
    }

    public function test_switching_off_statutory_type_keeps_shared_row_active(): void
    {
        $type = $this->sharedType('pilgrimage');
        $this->actingAs($this->ua);

        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15],
            'types' => [$type->id => ['is_active' => '0']],
            'confirm_below_reference' => '1',
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue((bool) $type->fresh()->is_active, 'shared row stays active for other companies');
        $row = DB::table('leave_type_company_limits')->where('company_id', $this->a->id)->where('leave_type_id', $type->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->is_active);
    }

    public function test_inactive_company_owned_type_stays_listed_for_reactivation(): void
    {
        $type = LeaveType::create(['name' => 'إجازة خاصة معطلة', 'company_id' => $this->a->id, 'is_active' => false, 'is_paid' => true]);
        $this->actingAs($this->ua);

        $this->get(route('payroll.settings.index'))
            ->assertOk()
            ->assertSee('types['.$type->id.'][is_active]', false);
    }
}
