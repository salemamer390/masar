<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, EmployeeStatus, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Allowance settings: the last allowance takes the remainder and each allowance has its own proration flag. */
class PayrollAllowanceSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'allow-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'allow-a', 'slug' => 'allow-a', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->manager = User::create(['name' => 'M', 'email' => 'allow-a@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->manager);
        app(\App\Services\PayrollDefaultsService::class)->ensureForCompany($this->company->id);
    }

    private function allowanceRows()
    {
        return DB::table('payroll_allowance_settings')->where('company_id', $this->company->id)->where('is_active', 1)->orderBy('sort_order')->get();
    }

    public function test_last_allowance_takes_the_remainder_and_proration_flags_are_saved(): void
    {
        $this->put(route('payroll.settings.update'), [
            'effective_from' => '2026-11-01',
            'settings' => ['general.allowance_rate' => 25],
            'allowances' => ['transport' => 40, 'housing' => 30, 'meal' => 20, 'clothing' => 5],
            'allowance_prorated' => ['transport' => 1, 'housing' => 0, 'meal' => 1, 'clothing' => 1],
        ])->assertRedirect(route('payroll.settings.index'));

        $rows = $this->allowanceRows()->keyBy('allowance_key');
        // The last allowance (clothing) takes the remainder: 100 - (40+30+20) = 10, not the submitted 5.
        $this->assertEqualsWithDelta(10.0, (float) $rows['clothing']->percentage, 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $rows['transport']->percentage, 0.0001);
        $this->assertEquals(0, (int) $rows['housing']->is_prorated);
        $this->assertEquals(1, (int) $rows['transport']->is_prorated);
        $this->assertEquals(1, (int) $rows['meal']->is_prorated);
    }

    public function test_shares_above_100_before_the_last_allowance_are_rejected(): void
    {
        $this->from(route('payroll.settings.index'))->put(route('payroll.settings.update'), [
            'effective_from' => '2026-11-01',
            'settings' => ['general.allowance_rate' => 25],
            'allowances' => ['transport' => 60, 'housing' => 30, 'meal' => 20, 'clothing' => 0],
        ])->assertSessionHasErrors('allowances');
    }

    public function test_exact_split_still_saves(): void
    {
        $this->put(route('payroll.settings.update'), [
            'effective_from' => '2026-11-01',
            'settings' => ['general.allowance_rate' => 25],
            'allowances' => ['transport' => 35, 'housing' => 30, 'meal' => 20, 'clothing' => 15],
        ])->assertRedirect(route('payroll.settings.index'));

        $rows = $this->allowanceRows()->keyBy('allowance_key');
        $this->assertEqualsWithDelta(15.0, (float) $rows['clothing']->percentage, 0.0001);
        $this->assertEquals(1, (int) $rows['housing']->is_prorated);
    }

    public function test_unknown_allowance_keys_are_rejected(): void
    {
        $this->put(route('payroll.settings.update'), [
            'effective_from' => '2026-11-01',
            'settings' => ['general.allowance_rate' => 25],
            'allowances' => ['transport' => 35, 'housing' => 30, 'meal' => 20, 'clothing' => 15, 'bonus' => 5],
        ])->assertStatus(422);
    }
}
