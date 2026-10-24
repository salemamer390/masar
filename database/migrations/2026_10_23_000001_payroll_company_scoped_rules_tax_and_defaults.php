<?php

use App\Services\PayrollDefaultsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every company its own independent payroll rules, tax brackets and settings rows.
 *  - payroll_calculation_rules and tax_categories become company scoped (rows with a NULL
 *    company stay as the shared template).
 *  - every existing company receives any missing settings / allowance / rule / tax rows.
 *  - payroll_runs.skipped_report stores who was left out when a run was approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_calculation_rules') && !Schema::hasColumn('payroll_calculation_rules', 'company_id')) {
            Schema::table('payroll_calculation_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->dropUnique('uq_payroll_calculation_rule');
                $table->unique(['company_id', 'rule_group', 'rule_key'], 'uq_payroll_rule_company');
                $table->index('company_id', 'idx_payroll_rules_company');
            });
        }

        if (Schema::hasTable('tax_categories') && !Schema::hasColumn('tax_categories', 'company_id')) {
            Schema::table('tax_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
                $table->dropUnique('tax_categories_category_key_unique');
                $table->unique(['company_id', 'category_key'], 'tax_categories_company_key_unique');
                $table->index('company_id', 'idx_tax_categories_company');
            });
        }

        if (Schema::hasTable('payroll_runs') && !Schema::hasColumn('payroll_runs', 'skipped_report')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->json('skipped_report')->nullable();
            });
        }

        // The insurance wage has always been calculated on the basic salary; the stored rule
        // said "Gross" but was never read. Align the rule with the approved calculation.
        DB::table('payroll_calculation_rules')
            ->where('rule_group', 'insurance')->where('rule_key', 'automatic_calculation_basis')
            ->update(['rule_value' => 'Basic']);

        // The personal exemption value is monthly (it is multiplied by 12), the old label said yearly.
        DB::table('payroll_calculation_settings')
            ->where('setting_group', 'tax')->where('setting_key', 'personal_exemption')
            ->update(['label' => 'الإعفاء الشخصي الشهري', 'unit' => 'EGP/month']);

        $defaults = app(PayrollDefaultsService::class);
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $defaults->ensureForCompany((int) $companyId);
        }
    }

    public function down(): void
    {
        // Intentionally empty: dropping the company-scoped rows would lose every company's own settings.
    }
};
