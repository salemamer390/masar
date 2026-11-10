<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two planning-alert settings that used to be dead static chips on the
 * annual card: consecutive-days threshold (Art. 125: 6 at least) and the
 * settlement review cadence (Art. 125: every 3 years at most). Both drive
 * smart planning alerts only - never approval blocking and never balance
 * expiry - and both are adjustable per company with below-reference warnings.
 */
class LeavePlanningAlertPoliciesTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a;
    private Company $b;
    private User $ua;
    private User $ub;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'plan-alert-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'plan-alert-a', 'slug' => 'plan-alert-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'plan-alert-b', 'slug' => 'plan-alert-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'plan-alert-a@example.test', 'password' => bcrypt('x'), 'role_id' => $roleA->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->b->id]);
        $this->ub = User::create(['name' => 'M', 'email' => 'plan-alert-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->b->id, 'is_active' => true]);
    }

    private function savePolicies(User $user, array $policies)
    {
        return $this->actingAs($user)->put(route('payroll.settings.leaves.update'), [
            'policies' => array_merge(['annual_first_year' => 15, 'annual_wait_months' => 6], $policies),
        ]);
    }

    private function companyValue(int $companyId, string $key): ?string
    {
        return DB::table('leave_policy_settings')->where('company_id', $companyId)->where('setting_key', $key)->value('setting_value');
    }

    public function test_settings_page_renders_both_planning_inputs_editable(): void
    {
        $this->actingAs($this->ua);
        $this->get(route('payroll.settings.index'))
            ->assertOk()
            ->assertSee('name="policies[annual_min_consecutive_days]"', false)
            ->assertSee('name="policies[leave_settlement_planning_years]"', false);
    }

    public function test_every_formerly_static_field_is_now_an_editable_input(): void
    {
        $this->actingAs($this->ua);
        $response = $this->get(route('payroll.settings.index'))->assertOk();
        foreach ([
            'policies[weekly_rest_accumulate_weeks]', 'policies[weekly_rest_paid]',
            'policies[jobsearch_weekly_hours]', 'policies[jobsearch_paid]',
            'policies[exam_annual_leave_notice_days]', 'policies[nursing_paid_full]',
            'policies[pregnancy_reduced_hours_from_month]', 'policies[holidays_paid]',
            'policies[holidays_alt_day_allowed]',
        ] as $name) {
            $response->assertSee('name="'.$name.'"', false);
        }
    }

    public function test_all_card_activation_settings_render_save_and_stay_company_scoped(): void
    {
        $keys = ['weekly_enabled', 'holidays_enabled', 'jobsearch_enabled', 'nursing_enabled', 'pregnant_enabled'];
        $this->actingAs($this->ua);
        $page = $this->get(route('payroll.settings.index'))->assertOk();
        foreach ($keys as $key) $page->assertSee('name="policies['.$key.']"', false);
        $this->savePolicies($this->ua, array_fill_keys($keys, 0))->assertSessionDoesntHaveErrors();
        foreach ($keys as $key) {
            $this->assertSame('0', $this->companyValue($this->a->id, $key));
            $this->assertNull($this->companyValue($this->b->id, $key));
        }
        $this->savePolicies($this->ua, ['nursing_enabled' => 2])->assertSessionHasErrors('policies.nursing_enabled');
    }

    public function test_all_new_keys_round_trip_per_company(): void
    {
        $this->savePolicies($this->ua, [
            'weekly_rest_accumulate_weeks' => 6, 'weekly_rest_paid' => 1,
            'jobsearch_weekly_hours' => 10, 'jobsearch_paid' => 1,
            'exam_annual_leave_notice_days' => 10, 'nursing_paid_full' => 1,
            'pregnancy_reduced_hours_from_month' => 5, 'holidays_paid' => 1,
            'holidays_alt_day_allowed' => 1,
        ])->assertSessionDoesntHaveErrors();
        foreach ([
            'weekly_rest_accumulate_weeks' => '6', 'weekly_rest_paid' => '1',
            'jobsearch_weekly_hours' => '10', 'jobsearch_paid' => '1',
            'exam_annual_leave_notice_days' => '10', 'nursing_paid_full' => '1',
            'pregnancy_reduced_hours_from_month' => '5', 'holidays_paid' => '1',
            'holidays_alt_day_allowed' => '1',
        ] as $key => $expected) {
            $this->assertSame($expected, $this->companyValue($this->a->id, $key), $key.' saved for this company');
        }
    }

    public function test_restrictive_values_on_new_numeric_keys_need_confirmation(): void
    {
        // 4h < the Art. 162 floor of 8h: below-reference warning, nothing saved.
        $this->savePolicies($this->ua, ['jobsearch_weekly_hours' => 4])->assertSessionHasErrors('below_reference');
        $errors = session('errors') ? session('errors')->get('below_reference') : [];
        $this->assertStringContainsString('162', json_encode($errors, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->companyValue($this->a->id, 'jobsearch_weekly_hours'));

        // 10 weeks > the Art. 120 cap of 8: below-reference warning, nothing saved.
        $this->savePolicies($this->ua, ['weekly_rest_accumulate_weeks' => 10])->assertSessionHasErrors('below_reference');
        $errors = session('errors') ? session('errors')->get('below_reference') : [];
        $this->assertStringContainsString('120', json_encode($errors, JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->companyValue($this->a->id, 'weekly_rest_accumulate_weeks'));
    }

    public function test_consecutive_days_below_the_reference_needs_confirmation_and_saves_nothing(): void
    {
        // 5 < the Art. 125 floor of 6: weakens the compliance alert, so it is flagged.
        $this->savePolicies($this->ua, ['annual_min_consecutive_days' => 5])->assertSessionHasErrors('below_reference');
        $errors = session('errors') ? session('errors')->get('below_reference') : [];
        $this->assertStringContainsString('125', json_encode($errors, JSON_UNESCAPED_UNICODE), 'the warning names article 125');
        $this->assertNull($this->companyValue($this->a->id, 'annual_min_consecutive_days'), 'nothing is saved without confirmation');
    }

    public function test_stricter_consecutive_days_save_cleanly(): void
    {
        // 8 >= 6: stricter planning is the company's choice, no warning.
        $this->savePolicies($this->ua, ['annual_min_consecutive_days' => 8])->assertSessionDoesntHaveErrors();
        $this->assertSame('8', $this->companyValue($this->a->id, 'annual_min_consecutive_days'));
    }

    public function test_longer_settlement_interval_needs_confirmation_and_saves_nothing(): void
    {
        // 4 > the Art. 125 cadence of 3 years at most: review would drift past the reference.
        $this->savePolicies($this->ua, ['leave_settlement_planning_years' => 4])->assertSessionHasErrors('below_reference');
        $errors = session('errors') ? session('errors')->get('below_reference') : [];
        $this->assertStringContainsString('125', json_encode($errors, JSON_UNESCAPED_UNICODE), 'the warning names article 125');
        $this->assertNull($this->companyValue($this->a->id, 'leave_settlement_planning_years'), 'nothing is saved without confirmation');
    }

    public function test_shorter_settlement_interval_saves_cleanly(): void
    {
        // 2 <= 3: reviewing earlier never breaches the reference.
        $this->savePolicies($this->ua, ['leave_settlement_planning_years' => 2])->assertSessionDoesntHaveErrors();
        $this->assertSame('2', $this->companyValue($this->a->id, 'leave_settlement_planning_years'));
    }

    public function test_non_integer_values_are_rejected(): void
    {
        $this->savePolicies($this->ua, ['annual_min_consecutive_days' => 6.5])->assertSessionHasErrors('policies.annual_min_consecutive_days');
        $this->assertNull($this->companyValue($this->a->id, 'annual_min_consecutive_days'));
    }

    public function test_one_companys_values_never_touch_another_company_or_the_shared_rows(): void
    {
        $this->savePolicies($this->ua, ['annual_min_consecutive_days' => 8, 'leave_settlement_planning_years' => 2])->assertSessionDoesntHaveErrors();

        $this->assertNull($this->companyValue($this->b->id, 'annual_min_consecutive_days'), 'company B keeps the default');
        $this->assertNull($this->companyValue($this->b->id, 'leave_settlement_planning_years'), 'company B keeps the default');
        $this->assertSame(0, DB::table('leave_policy_settings')->whereNull('company_id')->whereIn('setting_key', ['annual_min_consecutive_days', 'leave_settlement_planning_years'])->count(), 'no shared rows were written by a company save');
    }
}
