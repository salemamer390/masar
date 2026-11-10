<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds every company with its own independent copy of the payroll settings,
 * allowance split, calculation rules and tax brackets. Every method is
 * idempotent: existing rows are never overwritten, only missing ones are added.
 */
class PayrollDefaultsService
{
    /** [group, key, value, type, unit, label, description] */
    public const SETTINGS = [
        ['general', 'allowance_rate', 25, 'percentage', '%', 'نسبة إجمالي البدلات', 'النسبة المستخدمة لتحديد حد إجمالي البدلات'],
        ['general', 'proration_days', 30, 'number', 'day', 'أيام الشهر لتسوية الراتب', 'عدد أيام الشهر المعتمد في تسوية الراتب الجزئي (تعيين أو ترك خدمة وسط الشهر)'],
        ['insurance', 'employee_insurance_rate', 11, 'percentage', '%', 'حصة الموظف في التأمينات', 'النسبة التي يتحملها الموظف'],
        ['insurance', 'employer_insurance_rate', 18.75, 'percentage', '%', 'حصة صاحب العمل في التأمينات', 'النسبة التي يتحملها صاحب العمل'],
        ['insurance', 'martyrs_fund_rate', 0.05, 'percentage', '%', 'صندوق أسر الشهداء', 'نسبة صندوق أسر الشهداء'],
        ['insurance', 'insurance_minimum', 2700, 'number', 'EGP', 'الحد الأدنى للأجر التأميني', 'الحد الأدنى للأجر التأميني'],
        ['insurance', 'insurance_maximum', 16700, 'number', 'EGP', 'الحد الأقصى للأجر التأميني', 'الحد الأقصى للأجر التأميني'],
        ['overtime', 'hours_divisor', 240, 'number', 'hour', 'مقام حساب أجر الساعة', 'الأساس ÷ هذا الرقم = أجر ساعة الإضافي'],
        ['overtime', 'days_divisor', 30, 'number', 'day', 'مقام حساب أجر اليوم', 'الأساس ÷ هذا الرقم = أجر اليوم (عمل الراحة والعطلات الرسمية)'],
        ['overtime', 'day_multiplier', 1.35, 'number', 'x', 'معامل الإضافي النهاري', 'معامل الإضافي النهاري'],
        ['overtime', 'night_multiplier', 1.7, 'number', 'x', 'معامل الإضافي الليلي', 'معامل الإضافي الليلي'],
        ['overtime', 'official_holiday_multiplier', 2, 'number', 'x', 'معامل العطلة الرسمية', 'إجمالي أمثال أجر اليوم عند العمل في عطلة رسمية (2 = يومين إجمالًا، يُضاف الفرق فوق المرتب)'],
        ['overtime', 'weekly_rest_multiplier', 1, 'number', 'x', 'معامل الراحة الأسبوعية', 'مضاعف أجر اليوم عند العمل في يوم الراحة الأسبوعية (1 = بنفس سعر اليوم)'],
        ['tax', 'personal_exemption', 1667, 'number', 'EGP/month', 'الإعفاء الشخصي الشهري', 'قيمة الإعفاء الشخصي الشهري (تُضرب × 12 في الوعاء السنوي)'],
        ['tax', 'tax_deductible_contributions', 0, 'number', 'EGP/year', 'المساهمات القابلة للخصم ضريبيًا', 'مبلغ سنوي يُخصم من الوعاء الضريبي'],
        ['tax', 'profit_share_exempt_percent', 100, 'percentage', '%', 'نسبة نصيب الأرباح المعفاة ضريبيًا', 'باقي المكافآت والحوافز والعمولات والاستحقاقات الأخرى تدخل في الوعاء الضريبي'],
        ['absence', 'daily_divisor', 30, 'number', 'day', 'مقام حساب الغياب', 'عدد الأيام المستخدمة في حساب خصم الغياب'],
        ['penalty', 'daily_divisor', 30, 'number', 'day', 'مقام حساب الجزاء', 'عدد الأيام المستخدمة في حساب الجزاء'],
        ['penalty', 'max_days_per_month', 5, 'number', 'day', 'سقف أيام الجزاءات في الشهر', 'أقصى عدد أيام خصم بسبب الجزاءات في الشهر الواحد (0 = بدون سقف)'],
        ['advance', 'max_percent_of_gross', 10, 'percentage', '%', 'سقف السلفة/القسط من الإجمالي', 'الحد الأقصى للسلفة وللقسط الشهري كنسبة من إجمالي الراتب'],
    ];

    /** [key, name, percentage, sort] */
    public const ALLOWANCES = [
        ['transport', 'بدل انتقال', 35, 1],
        ['housing', 'بدل سكن', 30, 2],
        ['meal', 'بدل وجبة', 20, 3],
        ['clothing', 'بدل ملبس', 15, 4],
    ];

    /** [group, key, value, label, description] */
    public const RULES = [
        ['absence', 'calculation_basis', 'Gross', 'أساس حساب الغياب', 'أساس حساب أجر يوم الغياب: Gross إجمالي الراتب أو Basic الأساسي'],
        ['absence', 'deducted_allowances', 'meal,transportation', 'البدلات المخصومة مع الغياب', 'البدلات التي تُضاف قيمتها اليومية إلى خصم يوم الغياب عند أساس «الأساسي»: housing المسكن، transportation الانتقال، meal الوجبة، clothing الملبس، other أخرى'],
        ['penalty', 'calculation_basis', 'Basic', 'أساس حساب الجزاءات', 'أساس حساب أجر يوم الجزاء: Gross إجمالي الراتب أو Basic الأساسي'],
        ['overtime', 'calculation_basis', 'Gross', 'أساس حساب الإضافي', 'أساس حساب أجر ساعة الإضافي وأجر يوم الراحة/العطلة: Gross أو Basic'],
        ['insurance', 'automatic_calculation_basis', 'Basic', 'أساس حساب الأجر التأميني', 'الحساب التلقائي للأجر التأميني: Basic الأساسي أو Gross الإجمالي'],
    ];

    /**
     * Leave entitlement policy defaults (key => value). Same values the original
     * leave-policy migrations seeded; prorate-off matches the full-15-days rule.
     */
    public const LEAVE_POLICIES = [
        'annual_first_year' => '15',
        'annual_second_year' => '21',
        'annual_long_service' => '30',
        'annual_long_service_years' => '10',
        'annual_age_threshold' => '50',
        'annual_disability_days' => '45',
        'annual_hazardous_extra_days' => '7',
        'annual_wait_months' => '6',
        'annual_prorate_first_year' => '0',
        'allow_early_leave' => '1',
        'casual_max_days_year' => '7',
        'casual_max_days_request' => '2',
        'maternity_days' => '120',
        'maternity_min_postpartum_days' => '45',
        'maternity_max_occurrences' => '3',
        'pregnancy_reduced_hours_from_month' => '6',
        'pregnancy_reduced_hours_per_day' => '1',
        'pregnancy_overtime_protection_months' => '6',
        'nursing_window_months' => '24',
        'nursing_sessions_per_day' => '2',
        'nursing_minutes_per_session' => '30',
        'childcare_max_years_per_request' => '2',
        'childcare_max_occurrences' => '3',
        'pilgrimage_min_service_months' => '60',
        'newborn_max_occurrences' => '3',
        'maternity_months' => '4',
        'childcare_min_gap_years' => '2',
        'exam_notice_days' => '10',
        'annual_min_consecutive_days' => '6',
        'leave_settlement_planning_years' => '3',
        'weekly_rest_accumulate_weeks' => '8',
        'weekly_rest_paid' => '1',
        'jobsearch_weekly_hours' => '8',
        'jobsearch_paid' => '1',
        'exam_annual_leave_notice_days' => '15',
        'nursing_paid_full' => '1',
        'holidays_paid' => '1',
        'holidays_alt_day_allowed' => '0',
        'contagious_max_months' => '3',
        'sick_phase1_months' => '3',
        'sick_phase1_pct' => '100',
        'sick_phase2_months' => '6',
        'sick_phase2_pct' => '85',
        'sick_phase3_months' => '3',
        'sick_phase3_pct' => '75',
        'sick_cycle_years' => '3',
        'sick_termination_notice_days' => '15',
    ];

    public function ensureForCompany(int $companyId): void
    {
        if ($this->isComplete($companyId)) return;

        $this->ensureSettings($companyId);
        $this->ensureAllowances($companyId);
        $this->ensureRules($companyId);
        $this->ensureTax($companyId);
        $this->ensureLeavePolicies($companyId);
    }

    /**
     * Give the company its own editable copy of the leave entitlement policies.
     * Companies created after the original policy migrations (or a rebuilt DB)
     * had no rows, so the settings card rendered empty and saving could not
     * create them. Existing company rows are never overwritten; when a legacy
     * shared (NULL company) row exists its current value is inherited.
     */
    public function ensureLeavePolicies(int $companyId): void
    {
        if (!Schema::hasTable('leave_policy_settings') || !Schema::hasColumn('leave_policy_settings', 'company_id')) return;
        $legacy = DB::table('leave_policy_settings')->whereNull('company_id')->pluck('setting_value', 'setting_key');
        $now = now();
        foreach (self::LEAVE_POLICIES as $key => $default) {
            DB::table('leave_policy_settings')->insertOrIgnore([
                'company_id' => $companyId,
                'setting_key' => $key,
                'setting_value' => (string) ($legacy[$key] ?? $default),
                'label' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Cheap check so opening the settings page does not re-run every insert. */
    private function isComplete(int $companyId): bool
    {
        try {
            if (DB::table('payroll_calculation_settings')->where('company_id', $companyId)->count() < count(self::SETTINGS)) return false;
            if (DB::table('payroll_allowance_settings')->where('company_id', $companyId)->doesntExist()) return false;
            if (Schema::hasColumn('payroll_calculation_rules', 'company_id')
                && DB::table('payroll_calculation_rules')->where('company_id', $companyId)->count() < count(self::RULES)) return false;
            if (Schema::hasColumn('tax_categories', 'company_id')
                && DB::table('tax_categories')->where('company_id', $companyId)->doesntExist()) return false;
            if (Schema::hasColumn('leave_policy_settings', 'company_id')
                && DB::table('leave_policy_settings')->where('company_id', $companyId)->count() < count(self::LEAVE_POLICIES)) return false;

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureSettings(int $companyId): void
    {
        if (!Schema::hasTable('payroll_calculation_settings')) return;
        $now = now();
        foreach (self::SETTINGS as [$group, $key, $value, $type, $unit, $label, $description]) {
            DB::table('payroll_calculation_settings')->insertOrIgnore([
                'company_id' => $companyId, 'setting_group' => $group, 'setting_key' => $key,
                'setting_value' => $value, 'value_type' => $type, 'unit' => $unit,
                'label' => $label, 'description' => $description,
                'is_editable' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function ensureAllowances(int $companyId): void
    {
        if (!Schema::hasTable('payroll_allowance_settings')) return;
        if (DB::table('payroll_allowance_settings')->where('company_id', $companyId)->exists()) return;
        $now = now();
        foreach (self::ALLOWANCES as [$key, $name, $percentage, $sort]) {
            DB::table('payroll_allowance_settings')->insertOrIgnore([
                'company_id' => $companyId, 'allowance_key' => $key, 'allowance_name' => $name,
                'percentage' => $percentage, 'calculation_basis' => 'allowance_pool',
                'is_active' => 1, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function ensureRules(int $companyId): void
    {
        if (!Schema::hasTable('payroll_calculation_rules') || !Schema::hasColumn('payroll_calculation_rules', 'company_id')) return;
        $now = now();
        foreach (self::RULES as [$group, $key, $value, $label, $description]) {
            DB::table('payroll_calculation_rules')->insertOrIgnore([
                'company_id' => $companyId, 'rule_group' => $group, 'rule_key' => $key, 'rule_value' => $value,
                'label' => $label, 'description' => $description,
                'is_editable' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function ensureTax(int $companyId): void
    {
        if (!Schema::hasTable('tax_categories') || !Schema::hasTable('tax_brackets')) return;
        if (!Schema::hasColumn('tax_categories', 'company_id')) return;
        if (DB::table('tax_categories')->where('company_id', $companyId)->exists()) return;

        $template = $this->taxTemplate();
        $now = now();
        DB::transaction(function () use ($template, $companyId, $now): void {
            foreach ($template as $index => $category) {
                $categoryId = DB::table('tax_categories')->insertGetId([
                    'company_id' => $companyId,
                    'category_key' => 'category_' . ($index + 1),
                    'label' => $category['label'],
                    'min_income' => $category['min_income'],
                    'max_income' => $category['max_income'],
                    'sort_order' => $index + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($category['brackets'] as $bracketIndex => $bracket) {
                    DB::table('tax_brackets')->insert([
                        'tax_category_id' => $categoryId,
                        'from_amount' => $bracket['from'], 'to_amount' => $bracket['to'],
                        'rate' => $bracket['rate'], 'sort_order' => $bracketIndex + 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Global (company_id NULL) tax rows when present, otherwise the built-in law table. */
    public function taxTemplate(): array
    {
        if (Schema::hasColumn('tax_categories', 'company_id')) {
            $rows = DB::table('tax_categories')->whereNull('company_id')->orderBy('sort_order')->get();
            if ($rows->isNotEmpty()) {
                return $rows->map(function ($category) {
                    $brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get()
                        ->map(fn ($b) => ['from' => (float) $b->from_amount, 'to' => $b->to_amount === null ? null : (float) $b->to_amount, 'rate' => (float) $b->rate])
                        ->all();
                    return [
                        'label' => $category->label,
                        'min_income' => (float) $category->min_income,
                        'max_income' => $category->max_income === null ? null : (float) $category->max_income,
                        'brackets' => $brackets,
                    ];
                })->all();
            }
        }

        return $this->builtInTaxTemplate();
    }

    public function builtInTaxTemplate(): array
    {
        $b = fn (float $from, ?float $to, float $rate) => ['from' => $from, 'to' => $to, 'rate' => $rate];
        return [
            ['label' => 'الفئة الأولى — حتى 600,000', 'min_income' => 0, 'max_income' => 600000, 'brackets' => [$b(0, 40000, 0), $b(40000, 55000, .10), $b(55000, 70000, .15), $b(70000, 200000, .20), $b(200000, 400000, .225), $b(400000, null, .25)]],
            ['label' => 'الفئة الثانية — أكثر من 600,000 حتى 700,000', 'min_income' => 600000.01, 'max_income' => 700000, 'brackets' => [$b(0, 55000, .10), $b(55000, 70000, .15), $b(70000, 200000, .20), $b(200000, 400000, .225), $b(400000, null, .25)]],
            ['label' => 'الفئة الثالثة — أكثر من 700,000 حتى 800,000', 'min_income' => 700000.01, 'max_income' => 800000, 'brackets' => [$b(0, 70000, .15), $b(70000, 200000, .20), $b(200000, 400000, .225), $b(400000, null, .25)]],
            ['label' => 'الفئة الرابعة — أكثر من 800,000 حتى 900,000', 'min_income' => 800000.01, 'max_income' => 900000, 'brackets' => [$b(0, 200000, .20), $b(200000, 400000, .225), $b(400000, null, .25)]],
            ['label' => 'الفئة الخامسة — أكثر من 900,000 حتى 1,200,000', 'min_income' => 900000.01, 'max_income' => 1200000, 'brackets' => [$b(0, 400000, .225), $b(400000, null, .25)]],
            ['label' => 'الفئة السادسة — أكثر من 1,200,000', 'min_income' => 1200000.01, 'max_income' => null, 'brackets' => [$b(0, 1200000, .25), $b(1200000, null, .275)]],
        ];
    }
}
