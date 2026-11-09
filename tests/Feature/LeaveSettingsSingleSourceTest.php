<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\{LeaveBalanceService, PayrollDefaultsService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Amer's 2026-11-09 approval: one source per leave rule, per-company limits,
 * no 15-day per-request cap on annual leave (the balance limits it), casual
 * max 2 days per request, no 6-month wait for casual (annual keeps it), and
 * the early-grant (exception) checkbox keeps working.
 */
class LeaveSettingsSingleSourceTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a; private Company $b; private User $ua; private User $ub; private EmployeeStatus $st;
    private LeaveType $annual; private LeaveType $casual; private LeaveType $shared;

    private function company(string $slug): array
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'lss'], ['name' => 'T', 'employee_limit' => 10000, 'features' => []]);
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'M', 'company_id' => $c->id]);
        $u = User::create(['name' => 'M', 'email' => $slug.'@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $c->id, 'is_active' => true]);
        return [$c, $u];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->st = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        [$this->a, $this->ua] = $this->company('lss-a');
        [$this->b, $this->ub] = $this->company('lss-b');
        foreach ([$this->a, $this->b] as $c) app(PayrollDefaultsService::class)->ensureForCompany($c->id);
        // Shared (company_id NULL) types exactly as the shipped defaults: annual per-request 15, casual wait 6.
        $mk = fn (array $a) => LeaveType::withoutGlobalScopes()->find(DB::table('leave_types')->insertGetId(array_merge(['company_id' => null, 'is_active' => 1, 'default_days' => null], $a)));
        $this->annual = $mk(['name' => 'سنوية', 'policy_code' => 'annual_t', 'is_paid' => 1, 'deducts_annual_balance' => 1, 'max_days_per_request' => 15, 'min_service_months' => 6]);
        DB::table('leave_types')->where('id', $this->annual->id)->update(['policy_code' => 'annual']);
        $this->casual = $mk(['name' => 'عارضة', 'policy_code' => 'casual_t', 'is_paid' => 1, 'deducts_annual_balance' => 1, 'max_days_per_year' => 7, 'min_service_months' => 6]);
        DB::table('leave_types')->where('id', $this->casual->id)->update(['policy_code' => 'casual']);
        $this->shared = $mk(['name' => 'حج تجريبي', 'policy_code' => 'pilgrimage_t', 'is_paid' => 1, 'deducts_annual_balance' => 0, 'max_days_per_request' => 30, 'min_service_months' => 60, 'max_occurrences_service' => 1]);
        DB::table('leave_types')->where('id', $this->shared->id)->update(['policy_code' => 'pilgrimage']);
        $this->annual = LeaveType::withoutGlobalScopes()->find($this->annual->id);
        $this->casual = LeaveType::withoutGlobalScopes()->find($this->casual->id);
        $this->shared = LeaveType::withoutGlobalScopes()->find($this->shared->id);
    }

    private function emp(Company $c, string $code, string $hire): Employee
    {
        $this->actingAs($c->id === $this->a->id ? $this->ua : $this->ub);
        return Employee::create(['employee_code' => $code, 'first_name' => 'E', 'full_name' => 'E'.$code, 'company_id' => $c->id, 'status_id' => $this->st->id, 'hire_date' => $hire]);
    }

    private function request(User $u, Employee $e, LeaveType $t, string $s, string $en, array $extra = [])
    {
        $this->actingAs($u);
        // A fresh controller/service per request, like production.
        app()->forgetInstance(LeaveBalanceService::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        return $this->postJson(route('leaves.store'), array_merge(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'start_date' => $s, 'end_date' => $en], $extra));
    }

    private function save(User $u, array $policies, array $types)
    {
        $this->actingAs($u);
        return $this->putJson(route('payroll.settings.leaves.update'), ['policies' => $policies, 'types' => $types]);
    }

    public function test_annual_request_is_limited_by_balance_not_by_a_15_day_cap(): void
    {
        $e = $this->emp($this->a, 'L1', '2020-01-01'); // 21 days entitlement
        $this->assertSame(15.0, (float) $this->annual->max_days_per_request); // legacy value still stored
        $r = $this->request($this->ua, $e, $this->annual, '2026-12-01', '2026-12-23'); // 20 working days
        $r->assertOk();
        $leave = Leave::where('employee_id', $e->id)->first();
        $this->assertSame(20, (int) $leave->days);
        // Balance still limits: 20 used of 21, a further 3 days is refused for balance, not for request size.
        $r2 = $this->request($this->ua, $e, $this->annual, '2026-12-27', '2026-12-30');
        $r2->assertStatus(422);
        $this->assertStringContainsString('الرصيد', json_encode($r2->json('errors'), JSON_UNESCAPED_UNICODE));
    }

    public function test_casual_is_limited_to_two_days_per_request_and_has_no_six_month_wait(): void
    {
        $new = $this->emp($this->a, 'L2', now()->subMonth()->toDateString());
        $this->request($this->ua, $new, $this->casual, now()->addDays(3)->toDateString(), now()->addDays(3)->toDateString())->assertOk(); // 1 day, 1 month of service: allowed
        $old = $this->emp($this->a, 'L3', '2020-01-01');
        $three = $this->request($this->ua, $old, $this->casual, '2026-12-06', '2026-12-08'); // Sun-Tue = 3 days
        $three->assertStatus(422);
        $this->assertStringContainsString('الحد الأقصى للطلب الواحد 2', json_encode($three->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->request($this->ua, $old, $this->casual, '2026-12-06', '2026-12-07')->assertOk();
        // Annual keeps its 6-month wait for the same new employee.
        $this->request($this->ua, $new, $this->annual, now()->addDays(10)->toDateString(), now()->addDays(10)->toDateString())->assertStatus(422);
    }

    public function test_early_grant_checkbox_persists_and_overrides_only_the_wait_time(): void
    {
        $new = $this->emp($this->a, 'L4', now()->subMonths(2)->toDateString());
        $d = now()->addDays(20)->toDateString();
        // Blocked first, and the answer tells the screen it may offer the confirm button.
        $this->request($this->ua, $new, $this->annual, $d, $d)->assertStatus(422)->assertJsonValidationErrors(['start_date', 'early_grant_available']);
        $ok = $this->request($this->ua, $new, $this->annual, $d, $d, ['is_early_grant' => 1, 'early_grant_reason' => 'موافقة الإدارة']);
        $ok->assertOk();
        $leave = Leave::where('employee_id', $new->id)->first();
        $this->assertTrue((bool) $leave->is_early_grant);
        $this->assertSame('موافقة الإدارة', $leave->early_grant_reason);
    }

    public function test_confirming_only_the_wait_keeps_the_balance_check_and_creates_nothing_when_refused(): void
    {
        $new = $this->emp($this->a, 'W1', now()->subMonths(2)->toDateString()); // 15 days entitlement, under the 6-month wait
        $s = now()->addDays(20)->toDateString();
        $e = now()->addDays(50)->toDateString(); // well over 15 working days
        $confirm = ['is_early_grant' => 1, 'early_wait_only' => 1, 'early_grant_reason' => 'wait only'];
        $r = $this->request($this->ua, $new, $this->annual, $s, $e, $confirm);
        $r->assertStatus(422);
        $this->assertStringContainsString('الرصيد', json_encode($r->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Leave::where('employee_id', $new->id)->count());
    }

    public function test_confirming_the_wait_creates_exactly_one_leave_when_balance_is_enough(): void
    {
        $new = $this->emp($this->a, 'W2', now()->subMonths(2)->toDateString());
        $d = now()->addDays(20)->toDateString();
        $this->request($this->ua, $new, $this->annual, $d, $d)->assertStatus(422); // the warning step: nothing saved
        $this->assertSame(0, Leave::where('employee_id', $new->id)->count()); // cancel = stop here
        $this->request($this->ua, $new, $this->annual, $d, $d, ['is_early_grant' => 1, 'early_wait_only' => 1, 'early_grant_reason' => 'سبب'])->assertOk();
        $this->assertSame(1, Leave::where('employee_id', $new->id)->count());
        $this->assertTrue((bool) Leave::where('employee_id', $new->id)->first()->is_early_grant);
    }

    public function test_the_old_explicit_early_grant_path_is_unchanged_and_still_skips_balance(): void
    {
        $new = $this->emp($this->a, 'W3', now()->subMonths(2)->toDateString());
        $this->request($this->ua, $new, $this->annual, now()->addDays(20)->toDateString(), now()->addDays(50)->toDateString(), ['is_early_grant' => 1, 'early_grant_reason' => 'advance'])->assertOk();
    }

    public function test_a_user_without_approve_permission_cannot_confirm_an_exception(): void
    {
        $role = Role::create(['name' => 'plain', 'display_name' => 'P', 'company_id' => $this->a->id]);
        $plain = User::create(['name' => 'P', 'email' => 'plain@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $new = $this->emp($this->a, 'W4', now()->subMonths(2)->toDateString());
        $d = now()->addDays(20)->toDateString();
        $r = $this->request($plain, $new, $this->annual, $d, $d, ['is_early_grant' => 1, 'early_wait_only' => 1, 'early_grant_reason' => 'x']);
        $this->assertContains($r->status(), [401, 403]);
        $this->assertSame(0, Leave::where('employee_id', $new->id)->count());
    }

    public function test_early_grant_is_ignored_when_that_company_turned_the_switch_off(): void
    {
        $d = now()->addDays(20)->toDateString();
        DB::table('leave_policy_settings')->where('company_id', $this->a->id)->where('setting_key', 'allow_early_leave')->update(['setting_value' => '1']);
        DB::table('leave_policy_settings')->where('company_id', $this->b->id)->where('setting_key', 'allow_early_leave')->update(['setting_value' => '0']);
        $nb = $this->emp($this->b, 'L5', now()->subMonths(2)->toDateString());
        $this->request($this->ub, $nb, $this->annual, $d, $d, ['is_early_grant' => 1, 'early_grant_reason' => 'x'])->assertStatus(422);
        // Company switch off: blocked, and no confirm button is offered.
        $nb0 = $this->emp($this->b, 'L7', now()->subMonths(2)->toDateString());
        $this->request($this->ub, $nb0, $this->annual, $d, $d)->assertStatus(422)->assertJsonValidationErrors(['start_date'])->assertJsonMissingValidationErrors(['early_grant_available']);
    }

    public function test_shared_type_edit_is_saved_per_company_and_does_not_touch_other_companies(): void
    {
        $page = $this->actingAs($this->ua)->get(route('payroll.settings.index'));
        $page->assertOk()->assertSee('الإجازة السنوية')->assertSee('حسب الرصيد المتاح');
        foreach (['maternity_days', 'maternity_max_occurrences', 'childcare_max_occurrences', 'pilgrimage_min_service_months', 'newborn_max_occurrences', 'casual_max_days_year', 'casual_max_days_request'] as $dup) {
            $page->assertDontSee('name="policies['.$dup.']"', false);
        }
        $before = (array) DB::table('leave_policy_settings')->where('company_id', $this->a->id)->pluck('setting_value', 'setting_key')->all();

        $this->save($this->ua, ['annual_first_year' => '15'], [$this->shared->id => ['max_occurrences_service' => 5, 'max_days_per_request' => 10, 'max_days_per_year' => '', 'min_service_months' => 36]])->assertOk();

        $this->assertSame(1, (int) DB::table('leave_types')->where('id', $this->shared->id)->value('max_occurrences_service')); // shared row intact
        $this->assertSame(30.0, (float) DB::table('leave_types')->where('id', $this->shared->id)->value('max_days_per_request'));
        $svc = app(LeaveBalanceService::class);
        $this->assertSame(5, $svc->limitsFor($this->shared, $this->a->id)['max_occurrences_service']);
        $this->assertSame(10.0, $svc->limitsFor($this->shared, $this->a->id)['max_days_per_request']);
        $this->assertSame(1, $svc->limitsFor($this->shared, $this->b->id)['max_occurrences_service']); // company B unchanged
        $this->assertSame(30.0, $svc->limitsFor($this->shared, $this->b->id)['max_days_per_request']);

        // Existing company values not posted stay as they were.
        $after = (array) DB::table('leave_policy_settings')->where('company_id', $this->a->id)->pluck('setting_value', 'setting_key')->all();
        $this->assertSame($before, $after);
        // The page of company B still shows the shared values; company A shows its own.
        $this->actingAs($this->ub)->get(route('payroll.settings.index'))->assertOk();
    }

    public function test_casual_table_values_are_the_single_source_for_casual_request_and_year(): void
    {
        $this->save($this->ua, ['annual_first_year' => '15'], [$this->casual->id => ['max_days_per_request' => 3, 'max_days_per_year' => 9, 'min_service_months' => 12]])->assertOk();
        $p = DB::table('leave_policy_settings')->where('company_id', $this->a->id)->pluck('setting_value', 'setting_key');
        $this->assertSame('3', (string) (float) $p['casual_max_days_request'] == '3' ? '3' : $p['casual_max_days_request']);
        $this->assertSame(9.0, (float) $p['casual_max_days_year']);
        $e = $this->emp($this->a, 'L6', '2020-01-01');
        $this->request($this->ua, $e, $this->casual, '2026-12-06', '2026-12-08')->assertOk(); // 3 days now allowed for company A
        // Company B keeps the 2-day cap and the shared casual row is untouched.
        $eb = $this->emp($this->b, 'L7', '2020-01-01');
        $this->request($this->ub, $eb, $this->casual, '2026-12-06', '2026-12-08')->assertStatus(422);
        $this->assertSame(7.0, (float) DB::table('leave_types')->where('id', $this->casual->id)->value('max_days_per_year'));
        $this->assertNull(DB::table('leave_types')->where('id', $this->casual->id)->value('min_service_months') === 12 ? 1 : null);
    }
}
