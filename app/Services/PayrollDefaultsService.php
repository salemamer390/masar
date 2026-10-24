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
        ['overtime', 'official_holiday_multiplier', 2, 'number', 'x', 'معامل العطلة الرسمية', 'مضاعف أجر اليوم عند العمل في عطلة رسمية (2 = يومين)'],
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
        ['penalty', 'calculation_basis', 'Basic', 'أساس حساب الجزاءات', 'أساس حساب أجر يوم الجزاء: Gross إجمالي الراتب أو Basic الأساسي'],
        ['overtime', 'calculation_basis', 'Gross', 'أساس حساب الإضافي', 'أساس حساب أجر ساعة الإضافي وأجر يوم الراحة/العطلة: Gross أو Basic'],
        ['insurance', 'automatic_calculation_basis', 'Basic', 'أساس حساب الأجر التأميني', 'الحساب التلقائي للأجر التأميني: Basic الأساسي أو Gross الإجمالي'],
    ];

    public function ensureForCompany(int $companyId): void
    {
        if ($this->isComplete($companyId)) return;

        $this->ensureSettings($companyId);
        $this->ensureAllowances($companyId);
        $this->ensureRules($companyId);
        $this->ensureTax($companyId);
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
