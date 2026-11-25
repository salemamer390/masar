<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Role, SubscriptionPlan, User};
use Illuminate\Support\Facades\Hash;
use App\Services\GlobalPayrollRulesService;
use App\Services\Payroll\TaxEngine;
use App\Services\PayrollSettingsService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalInsuranceAndTaxRulesTest extends TestCase
{
    use DatabaseTransactions;

    private function setting(?int $companyId, string $group, string $key, $value): void
    {
        DB::table('payroll_calculation_settings')->updateOrInsert(
            ['company_id' => $companyId, 'setting_group' => $group, 'setting_key' => $key],
            ['setting_value' => $value, 'value_type' => 'number', 'label' => $key, 'is_editable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_insurance_and_tax_numbers_come_from_the_global_row_for_every_company(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'gl-a', 'status' => 'active']);
        $b = Company::create(['name' => 'B', 'slug' => 'gl-b', 'status' => 'active']);
        $this->setting(null, 'insurance', 'employee_insurance_rate', 12);
        $this->setting($a->id, 'insurance', 'employee_insurance_rate', 5); // stale per-company copy, ignored
        $this->setting(null, 'tax', 'personal_exemption', 2000);

        foreach ([$a, $b] as $company) {
            $rate = CompanyContext::run($company->id, fn () => (new PayrollSettingsService())->employeeInsuranceRate());
            $exemption = CompanyContext::run($company->id, fn () => (new PayrollSettingsService())->personalExemption());
            $this->assertEqualsWithDelta(0.12, $rate, 0.0000001);
            $this->assertEqualsWithDelta(2000, $exemption, 0.0001);
        }
    }

    public function test_non_global_settings_stay_per_company(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'gl-c', 'status' => 'active']);
        $b = Company::create(['name' => 'B', 'slug' => 'gl-d', 'status' => 'active']);
        $this->setting($a->id, 'overtime', 'day_multiplier', 1.5);
        $this->setting($b->id, 'overtime', 'day_multiplier', 1.9);
        $this->assertEqualsWithDelta(1.5, CompanyContext::run($a->id, fn () => (new PayrollSettingsService())->dayOvertimeMultiplier()), 0.0001);
        $this->assertEqualsWithDelta(1.9, CompanyContext::run($b->id, fn () => (new PayrollSettingsService())->dayOvertimeMultiplier()), 0.0001);
    }

    public function test_tax_brackets_are_global_and_saved_by_the_service(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'gl-e', 'status' => 'active']);
        $service = app(GlobalPayrollRulesService::class);
        $categories = [
            ['label' => 'فئة', 'max_income' => null, 'brackets' => [['from' => 0, 'to' => 100000, 'rate' => 10], ['from' => 100000, 'to' => null, 'rate' => 20]]],
        ];
        $this->assertNull($service->validateTax($categories));
        $service->saveTax($categories, '2026-11-01');

        $tax = CompanyContext::run($a->id, fn () => (new TaxEngine())->annualTax(150000));
        $this->assertEqualsWithDelta(100000 * 0.10 + 50000 * 0.20, $tax, 0.01);
        $this->assertSame(0, DB::table('tax_categories')->whereNull('company_id')->where('label', '!=', 'فئة')->count());
    }

    public function test_invalid_tax_table_is_rejected(): void
    {
        $service = app(GlobalPayrollRulesService::class);
        $this->assertNotNull($service->validateTax([['label' => 'x', 'max_income' => 100, 'brackets' => [['from' => 0, 'to' => null, 'rate' => 5]]]]));
    }

    public function test_company_cannot_save_insurance_or_tax_values(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('payroll.settings.tax'));
        $this->assertTrue(GlobalPayrollRulesService::isGlobalSetting('insurance.insurance_maximum'));
        $this->assertFalse(GlobalPayrollRulesService::isGlobalSetting('overtime.day_multiplier'));
    }

    public function test_migration_seeds_globals_from_the_most_common_company_values(): void
    {
        DB::table('payroll_calculation_settings')->whereNull('company_id')->whereIn('setting_group', ['insurance', 'tax'])->delete();
        DB::table('tax_brackets')->whereIn('tax_category_id', DB::table('tax_categories')->whereNull('company_id')->pluck('id'))->delete();
        DB::table('tax_categories')->whereNull('company_id')->delete();
        $a = Company::create(['name' => 'A', 'slug' => 'gl-f', 'status' => 'active']);
        $b = Company::create(['name' => 'B', 'slug' => 'gl-g', 'status' => 'active']);
        $c = Company::create(['name' => 'C', 'slug' => 'gl-h', 'status' => 'active']);
        foreach ([[$a, 3000], [$b, 3500], [$c, 3500]] as [$company, $min]) {
            $this->setting($company->id, 'insurance', 'insurance_minimum', $min);
        }

        (require database_path('migrations/2026_11_23_000002_make_insurance_and_tax_rules_global.php'))->up();

        $this->assertEquals(3500, (float) DB::table('payroll_calculation_settings')->whereNull('company_id')->where('setting_key', 'insurance_minimum')->value('setting_value'));
        $this->assertEquals(11, (float) DB::table('payroll_calculation_settings')->whereNull('company_id')->where('setting_key', 'employee_insurance_rate')->value('setting_value'), 'missing company rows fall back to the statutory default');
        // The companies' own rows are untouched.
        $this->assertEquals(3000, (float) DB::table('payroll_calculation_settings')->where('company_id', $a->id)->where('setting_key', 'insurance_minimum')->value('setting_value'));
    }

    private function owner(): User
    {
        config()->set('owner.emails', ['glowner@example.com']);
        $role = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'company_id' => null]);

        return User::create(['company_id' => null, 'role_id' => $role->id, 'name' => 'مالك', 'email' => 'glowner@example.com', 'phone' => '01000000009', 'password' => Hash::make('password'), 'is_active' => true]);
    }

    public function test_owner_saves_global_values_and_company_page_shows_them_read_only(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->get(route('owner.index'))->assertOk()->assertSee('owner-tax-category', false)->assertSee('name="settings[insurance.insurance_maximum]"', false)->assertSee('ownerTaxPane', false);
        $this->actingAs($owner, 'owner')->put(route('owner.payroll-rules.settings'), [
            'effective_from' => '2026-11-01',
            'settings' => ['insurance.insurance_maximum' => 20000, 'tax.personal_exemption' => 1800],
        ])->assertRedirect();
        $this->assertEquals(20000, (float) DB::table('payroll_calculation_settings')->whereNull('company_id')->where('setting_key', 'insurance_maximum')->value('setting_value'));
        $this->assertNotNull(DB::table('payroll_setting_changes')->whereNull('company_id')->first());

        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'gl-unlimited'], ['name' => 'T', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'gl-page', 'slug' => 'gl-page', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $manager = User::create(['name' => 'M', 'email' => 'gl-page@example.test', 'password' => bcrypt('x'), 'role_id' => Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id])->id, 'company_id' => $company->id, 'is_active' => true]);
        $page = $this->actingAs($manager)->get(route('payroll.settings.index'));
        $page->assertOk()->assertDontSee('value="20000"', false)->assertDontSee('taxBrackets', false)->assertDontSee('tax.personal_exemption', false)->assertDontSee('insurance.employee_insurance_rate', false)->assertDontSee('name="settings[insurance.insurance_maximum]"', false)->assertDontSee('name="settings[tax.personal_exemption]"', false);
        $this->actingAs($manager)->put(route('payroll.settings.update'), ['effective_from' => '2026-11-01', 'settings' => ['insurance.insurance_maximum' => 1], 'allowances' => ['transport' => 100]])->assertStatus(422);
    }

    public function test_owner_cannot_save_an_invalid_tax_table(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->put(route('owner.payroll-rules.tax'), [
            'effective_from' => '2026-11-01',
            'categories' => [['label' => 'x', 'max_income' => 100, 'brackets' => [['from' => 0, 'to' => null, 'rate' => 5]]]],
        ])->assertSessionHasErrors('payroll_tax');
    }
}
