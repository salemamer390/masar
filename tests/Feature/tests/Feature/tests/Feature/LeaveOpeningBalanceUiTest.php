<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The initialization UI is the real surface the setup alerts link to:
 * baseline visible and editable, opening entries audited (who/when/reason),
 * confirmed-zero distinct from unknown, tenant-scoped and permission-gated.
 */
class LeaveOpeningBalanceUiTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private Company $otherCompany;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        $this->otherCompany = Company::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        CompanySubscription::create(['company_id' => $this->otherCompany->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->employee = Employee::create([
            'employee_code' => '7101', 'first_name' => 'E7101', 'full_name' => 'Employee 7101',
            'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-03-01',
        ]);
        $this->actingAs($this->user);
    }

    public function test_page_shows_baseline_and_prebaseline_employees_only(): void
    {
        $response = $this->get(route('leaves.opening.index'));
        $response->assertOk();
        $response->assertSee('Employee 7101');
        // Baseline date is visible, not hidden.
        $response->assertSee(today()->format('d/m/Y'));
    }

    public function test_store_saves_audited_entry_and_initializes_history(): void
    {
        $response = $this->post(route('leaves.opening.store'), [
            'employee_id' => $this->employee->id,
            'cycle_year' => 2024,
            'amount' => 7.5,
            'reason' => 'كشف موثق من الإدارة',
        ]);
        $response->assertRedirect();

        $entry = DB::table('leave_opening_entries')->where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(7.5, (float) $entry->amount);
        $this->assertSame(0, (int) $entry->is_confirmed_zero);
        $this->assertSame($this->user->id, (int) $entry->created_by);
        $this->assertSame('كشف موثق من الإدارة', $entry->reason);

        $service = app(LeaveBalanceService::class);
        $cycle = $service->cycleFor($this->employee->fresh(), today());
        $this->assertFalse($service->hasUninitializedHistory($this->employee->fresh(), $cycle, $service->openingEntriesFor($this->employee->fresh()), $this->company->id));
        $balance = $service->balance($this->employee->fresh(), today());
        $this->assertSame(7.5, (float) $balance['carried_balance']);
    }

    public function test_confirmed_zero_is_a_decision_not_missing_data(): void
    {
        $this->post(route('leaves.opening.store'), [
            'employee_id' => $this->employee->id,
            'cycle_year' => 2024,
            'is_confirmed_zero' => '1',
            'reason' => 'راجعنا الكشوف: لا يوجد رصيد',
        ])->assertRedirect();

        $service = app(LeaveBalanceService::class);
        $cycle = $service->cycleFor($this->employee->fresh(), today());
        $this->assertFalse($service->hasUninitializedHistory($this->employee->fresh(), $cycle, $service->openingEntriesFor($this->employee->fresh()), $this->company->id));
        $balance = $service->balance($this->employee->fresh(), today());
        $this->assertSame(0.0, (float) $balance['carried_balance']);
    }

    public function test_zero_amount_without_confirmed_zero_is_rejected(): void
    {
        $this->post(route('leaves.opening.store'), [
            'employee_id' => $this->employee->id,
            'cycle_year' => 2024,
            'amount' => 0,
            'reason' => 'محاولة',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, DB::table('leave_opening_entries')->count());
    }

    public function test_signed_negative_entry_reduces_tracked_cycle_capacity(): void
    {
        // Tracked era: baseline before the 2024 cycle; a −3 adjustment must
        // actually subtract, not clamp away unnoticed.
        DB::table('leave_policy_settings')->insert(['company_id' => $this->company->id, 'setting_key' => 'leave_carryover_baseline', 'setting_value' => '2024-01-01', 'label' => 'baseline', 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('leaves.opening.store'), [
            'employee_id' => $this->employee->id,
            'cycle_year' => 2025,
            'amount' => -3,
            'reason' => 'تصحيح زيادة سابقة',
        ])->assertRedirect();

        $service = app(LeaveBalanceService::class);
        $balance = $service->balance($this->employee->fresh(), Carbon::create(2026, 6, 1));
        // 2024 tracked bucket: 15 − 0 used = 15 remaining... wait, no: hire 2024-03-01.
        // Buckets: 2024 (ent 15), 2025 (ent 21 − 3 adj = 18). Reference 2026-06-01 → current cycle 2026-03-01.
        // carried = 15 + 18 = 33.
        $this->assertSame(33.0, (float) $balance['carried_balance']);
    }

    public function test_tenant_isolation_for_entries_and_pages(): void
    {
        $otherStatus = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        // Insert directly: the model's company scope rightly refuses to
        // fabricate another tenant's record while acting as company A.
        $otherId = DB::table('employees')->insertGetId([
            'employee_code' => '8101', 'first_name' => 'E8101', 'full_name' => 'Employee 8101 Other',
            'company_id' => $this->otherCompany->id, 'status_id' => $otherStatus->id, 'hire_date' => '2023-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Cannot record an entry for another company's employee.
        $this->post(route('leaves.opening.store'), [
            'employee_id' => $otherId,
            'cycle_year' => 2023,
            'amount' => 5,
            'reason' => 'اختراق',
        ])->assertNotFound();
        $this->assertSame(0, DB::table('leave_opening_entries')->count());

        // The page lists only own-company employees.
        $this->get(route('leaves.opening.index'))->assertDontSee('Employee 8101 Other');
    }

    public function test_baseline_update_persists_visible_company_scoped_date(): void
    {
        $this->put(route('leaves.opening.baseline'), ['baseline' => '2026-01-01'])->assertRedirect();
        $value = DB::table('leave_policy_settings')->where('company_id', $this->company->id)
            ->where('setting_key', 'leave_carryover_baseline')->value('setting_value');
        $this->assertSame('2026-01-01', $value);
        $this->get(route('leaves.opening.index'))->assertSee('01/01/2026');
        // Future baselines rejected.
        $this->put(route('leaves.opening.baseline'), ['baseline' => today()->addYear()->toDateString()])->assertSessionHasErrors('baseline');
    }
}
