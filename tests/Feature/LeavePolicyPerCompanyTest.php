<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, EmployeeStatus, Role, SubscriptionPlan, User};
use App\Services\PayrollDefaultsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every company gets its own editable copy of the leave entitlement policies.
 * Companies created after the original policy migrations (or a rebuilt DB)
 * had no rows, so the settings card rendered empty and could never be filled.
 */
class LeavePolicyPerCompanyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'leave-pol-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'leave-pol-a', 'slug' => 'leave-pol-a', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->manager = User::create(['name' => 'M', 'email' => 'leave-pol-a@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->manager);
        DB::table('leave_policy_settings')->where('company_id', $this->company->id)->delete();
    }

    private function companyPolicies()
    {
        return DB::table('leave_policy_settings')->where('company_id', $this->company->id)->pluck('setting_value', 'setting_key');
    }

    public function test_settings_page_seeds_and_renders_leave_entitlement_card(): void
    {
        $this->assertCount(0, $this->companyPolicies());

        $response = $this->get(route('payroll.settings.index'));
        $response->assertOk();

        $policies = $this->companyPolicies();
        $this->assertCount(count(PayrollDefaultsService::LEAVE_POLICIES), $policies);
        $this->assertSame('15', $policies['annual_first_year']);
        $this->assertSame('21', $policies['annual_second_year']);
        $this->assertSame('30', $policies['annual_long_service']);
        $this->assertSame('6', $policies['annual_wait_months']);
        $this->assertSame('0', $policies['annual_prorate_first_year']);

        // The card is no longer empty: Arabic labels and inputs render.
        $response->assertSee('رصيد السنة الأولى');
        $response->assertSee('مدة الانتظار (شهر)');
        $response->assertSee('name="policies[annual_first_year]"', false);
    }

    public function test_ensure_never_overwrites_existing_company_values(): void
    {
        app(PayrollDefaultsService::class)->ensureForCompany($this->company->id);
        DB::table('leave_policy_settings')
            ->where('company_id', $this->company->id)->where('setting_key', 'annual_first_year')
            ->update(['setting_value' => '20']);

        app(PayrollDefaultsService::class)->ensureForCompany($this->company->id);
        app(PayrollDefaultsService::class)->ensureLeavePolicies($this->company->id);

        $policies = $this->companyPolicies();
        $this->assertCount(count(PayrollDefaultsService::LEAVE_POLICIES), $policies);
        $this->assertSame('20', $policies['annual_first_year']);
    }

    public function test_new_company_inherits_legacy_shared_values(): void
    {
        // Simulate a pre-company-scope leftover: a shared row with no company.
        DB::table('leave_policy_settings')->where('setting_key', 'annual_first_year')
            ->update(['company_id' => null, 'setting_value' => '18']);

        app(PayrollDefaultsService::class)->ensureLeavePolicies($this->company->id);

        $this->assertSame('18', $this->companyPolicies()['annual_first_year']);
    }

    public function test_leave_policy_save_roundtrip_after_seeding(): void
    {
        $this->get(route('payroll.settings.index'));

        $types = DB::table('leave_types')->where('is_active', 1)->get();
        $this->put(route('payroll.settings.leaves.update'), [
            'policies' => ['annual_first_year' => 16, 'annual_wait_months' => 3],
            'types' => $types->mapWithKeys(fn ($t) => [$t->id => [
                'max_days_per_request' => $t->max_days_per_request,
                'max_days_per_year' => $t->max_days_per_year,
                'min_service_months' => $t->min_service_months,
                'max_occurrences_service' => $t->max_occurrences_service,
            ]])->all(),
        ])->assertSessionDoesntHaveErrors();

        $policies = $this->companyPolicies();
        $this->assertSame('16', $policies['annual_first_year']);
        $this->assertSame('3', $policies['annual_wait_months']);
        $this->assertSame('21', $policies['annual_second_year']);
    }
}
