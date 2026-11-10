<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a company-owned custom leave type from payroll settings.
 * A type with no requests is removed outright; a type that already has
 * requests is archived (is_active=false) so its records, balances and payroll
 * history stay intact, and it simply stops appearing in new-request choices.
 * Shared statutory types and other companies' types are never touched.
 */
class LeaveCustomTypeDeleteTest extends TestCase
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
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'custom-del-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'custom-del-a', 'slug' => 'custom-del-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'custom-del-b', 'slug' => 'custom-del-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'custom-del-a@example.test', 'password' => bcrypt('x'), 'role_id' => $roleA->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->b->id]);
        $this->ub = User::create(['name' => 'M', 'email' => 'custom-del-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->b->id, 'is_active' => true]);
    }

    private function makeType(Company $company, string $name): LeaveType
    {
        return LeaveType::create(['name' => $name, 'company_id' => $company->id, 'is_active' => true, 'is_paid' => true, 'deducts_annual_balance' => false]);
    }

    public function test_unreferenced_custom_type_is_deleted_outright_with_its_limits(): void
    {
        $type = $this->makeType($this->a, 'إجازة بدون طلبات');
        DB::table('leave_type_company_limits')->insert(['company_id' => $this->a->id, 'leave_type_id' => $type->id, 'max_days_per_year' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->ua);
        $this->deleteJson(route('payroll.settings.leave-types.destroy', $type->id))
            ->assertOk()->assertJson(['success' => true, 'archived' => false]);

        $this->assertNull(LeaveType::find($type->id), 'a type with no requests is removed outright');
        $this->assertSame(0, DB::table('leave_type_company_limits')->where('leave_type_id', $type->id)->count());
    }

    public function test_referenced_custom_type_is_archived_not_deleted_and_leaves_new_requests(): void
    {
        $type = $this->makeType($this->a, 'إجازة لها طلبات');
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $emp = Employee::create(['employee_code' => 'DEL1', 'first_name' => 'E', 'full_name' => 'E DEL1', 'company_id' => $this->a->id, 'status_id' => $status->id, 'hire_date' => '2010-01-01']);
        Leave::create(['employee_id' => $emp->id, 'leave_type_id' => $type->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-06', 'days' => 2, 'status' => 'Approved']);

        $this->actingAs($this->ua);
        $this->deleteJson(route('payroll.settings.leave-types.destroy', $type->id))
            ->assertOk()->assertJson(['success' => true, 'archived' => true]);

        $type->refresh();
        $this->assertFalse((bool) $type->is_active, 'referenced type is archived, not removed');
        $this->assertSame(1, Leave::where('leave_type_id', $type->id)->count(), 'history is preserved');

        // Archived: gone from the new-request choices (its name stays visible in history rows, which is the point of archiving).
        $this->get(route('leaves.index'))->assertOk()->assertDontSee('<option value="'.$type->id.'"', false);
        $this->assertNotNull($type->archived_at, 'archive is stamped, distinct from an ordinary off switch');

        // Gone from the settings cards too, even though settings deliberately keeps
        // ordinary inactive company types listed for reactivation.
        $this->get(route('payroll.settings.index'))->assertOk()->assertDontSee('إجازة لها طلبات');

        // A crafted settings save cannot resurrect it.
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15, 'annual_wait_months' => 6],
            'types' => [$type->id => ['is_active' => '1']],
        ])->assertSessionDoesntHaveErrors();
        $type->refresh();
        $this->assertFalse((bool) $type->is_active, 'archived type stays off');
        $this->assertNotNull($type->archived_at, 'archive stamp survives a settings save');
    }

    public function test_shared_statutory_type_cannot_be_deleted(): void
    {
        $shared = LeaveType::whereNull('company_id')->where('policy_code', 'pilgrimage')->firstOrFail();
        $this->actingAs($this->ua);
        $this->deleteJson(route('payroll.settings.leave-types.destroy', $shared->id))->assertNotFound();
        $this->assertNotNull(LeaveType::find($shared->id));
    }

    public function test_other_companys_type_cannot_be_deleted(): void
    {
        $theirs = $this->makeType($this->b, 'إجازة شركة أخرى');
        $this->actingAs($this->ua);
        $this->deleteJson(route('payroll.settings.leave-types.destroy', $theirs->id))->assertNotFound();
        $this->assertNotNull(LeaveType::withoutGlobalScopes()->find($theirs->id));
    }

    public function test_ordinary_inactive_custom_type_stays_listed_for_reactivation(): void
    {
        $type = $this->makeType($this->a, 'إجازة موقوفة مؤقتًا');
        $type->is_active = false;
        $type->save();

        $this->actingAs($this->ua);
        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('إجازة موقوفة مؤقتًا');

        // And a normal settings save switches it back on (contrast with archived types).
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 15, 'annual_wait_months' => 6],
            'types' => [$type->id => ['is_active' => '1']],
        ])->assertSessionDoesntHaveErrors();
        $this->assertTrue((bool) $type->fresh()->is_active);
    }

    public function test_user_without_payroll_settings_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'viewer', 'display_name' => 'Viewer', 'company_id' => $this->a->id]);
        $viewer = User::create(['name' => 'V', 'email' => 'custom-del-viewer@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $type = $this->makeType($this->a, 'إجازة محمية بالصلاحيات');

        $this->actingAs($viewer);
        $this->deleteJson(route('payroll.settings.leave-types.destroy', $type->id))->assertForbidden();
        $this->assertNotNull(LeaveType::find($type->id));
    }
}
