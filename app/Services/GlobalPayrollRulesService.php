<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\SalaryController;
use App\Models\ActivityLog;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Social insurance and income tax rules are shared by every company and are
 * changed only from the owner panel. They live in rows with company_id NULL:
 * payroll_calculation_settings (insurance and tax groups), tax_categories and
 * tax_brackets. Companies read them but can never edit them.
 */
class GlobalPayrollRulesService
{
    public const GROUPS = ['insurance', 'tax'];

    /** Setting keys the owner edits (group.key). */
    public const SETTING_KEYS = [
        'insurance.employee_insurance_rate', 'insurance.employer_insurance_rate', 'insurance.martyrs_fund_rate',
        'insurance.insurance_minimum', 'insurance.insurance_maximum',
        'tax.personal_exemption', 'tax.tax_deductible_contributions', 'tax.profit_share_exempt_percent',
    ];

    public static function isGlobalSetting(string $name): bool
    {
        return in_array($name, self::SETTING_KEYS, true);
    }

    /** Add any missing global setting row with the statutory default. Never overwrites. */
    public function ensureSettings(): void
    {
        $now = now();
        foreach (PayrollDefaultsService::SETTINGS as [$group, $key, $value, $type, $unit, $label, $description]) {
            if (!in_array($group, self::GROUPS, true)) continue;
            $exists = DB::table('payroll_calculation_settings')->whereNull('company_id')
                ->where('setting_group', $group)->where('setting_key', $key)->exists();
            if ($exists) continue;
            DB::table('payroll_calculation_settings')->insert([
                'company_id' => null, 'setting_group' => $group, 'setting_key' => $key,
                'setting_value' => $value, 'value_type' => $type, 'unit' => $unit,
                'label' => $label, 'description' => $description,
                'is_editable' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Add the built-in tax table when no global table exists yet. */
    public function ensureTax(): void
    {
        if (DB::table('tax_categories')->whereNull('company_id')->exists()) return;
        $this->writeTax(array_map(fn ($c) => [
            'label' => $c['label'], 'max_income' => $c['max_income'],
            'brackets' => array_map(fn ($b) => ['from' => $b['from'], 'to' => $b['to'], 'rate' => $b['rate'] * 100], $c['brackets']),
        ], app(PayrollDefaultsService::class)->builtInTaxTemplate()));
    }

    /**
     * Current global values (group.key => value). A key with no global row yet
     * shows its statutory default; reading never writes, so the seeding
     * migration can still copy the companies' real values first.
     *
     * @return array<string, string|float>
     */
    public function values(): array
    {
        $values = [];
        foreach (PayrollDefaultsService::SETTINGS as [$group, $key, $value]) {
            if (in_array($group, self::GROUPS, true)) $values["{$group}.{$key}"] = $value;
        }

        return DB::table('payroll_calculation_settings')->whereNull('company_id')
            ->whereIn('setting_group', self::GROUPS)->where('is_active', 1)->get()
            ->mapWithKeys(fn ($row) => ["{$row->setting_group}.{$row->setting_key}" => $row->setting_value])->all() + $values;
    }

    /** Categories with their brackets (rate as fraction), for display and editing. */
    public function taxCategories(): \Illuminate\Support\Collection
    {
        $rows = DB::table('tax_categories')->whereNull('company_id')->orderBy('sort_order')->get();
        if ($rows->isEmpty()) {
            return collect(app(PayrollDefaultsService::class)->builtInTaxTemplate())->map(fn ($c, $i) => (object) [
                'label' => $c['label'], 'max_income' => $c['max_income'],
                'brackets' => collect($c['brackets'])->map(fn ($b) => (object) ['from_amount' => $b['from'], 'to_amount' => $b['to'], 'rate' => $b['rate']]),
            ]);
        }

        return $rows->map(function ($category) {
            $category->brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get();

            return $category;
        });
    }

    /** @param array<string, mixed> $values group.key => number */
    public function saveSettings(array $values, string $effectiveFrom): void
    {
        $this->ensureSettings();
        DB::transaction(function () use ($values, $effectiveFrom): void {
            $before = $this->values();
            foreach ($values as $name => $value) {
                if ($value === null || $value === '' || !self::isGlobalSetting($name)) continue;
                [$group, $key] = explode('.', $name, 2);
                DB::table('payroll_calculation_settings')->whereNull('company_id')
                    ->where('setting_group', $group)->where('setting_key', $key)
                    ->update(['setting_value' => $value, 'is_active' => 1, 'effective_from' => $effectiveFrom, 'updated_at' => now()]);
            }
            $this->recordChange($effectiveFrom, ['settings' => $before], ['settings' => $this->values()]);
        });
    }

    /** Returns an Arabic error message, or null when the table is valid. */
    public function validateTax(array $categories): ?string
    {
        $categories = array_values($categories);
        $previousMax = null;
        foreach ($categories as $index => $category) {
            $isLastCategory = $index === count($categories) - 1;
            $max = ($category['max_income'] ?? null) === null || $category['max_income'] === '' ? null : (float) $category['max_income'];
            if ($isLastCategory !== ($max === null)) {
                return __('آخر فئة فقط تُترك بدون حد أعلى، وباقي الفئات لازم لها حد أعلى.');
            }
            if ($max !== null && $previousMax !== null && $max <= $previousMax) {
                return __('حدود الفئات لازم تكون تصاعدية.');
            }
            $previousMax = $max;

            $brackets = array_values($category['brackets']);
            $expectedFrom = 0.0;
            foreach ($brackets as $bracketIndex => $bracket) {
                $isLastBracket = $bracketIndex === count($brackets) - 1;
                $from = (float) $bracket['from'];
                $to = ($bracket['to'] ?? null) === null || $bracket['to'] === '' ? null : (float) $bracket['to'];
                if (abs($from - $expectedFrom) > 0.005) {
                    return "شرائح «{$category['label']}» لازم تكون متصلة: الشريحة رقم " . ($bracketIndex + 1) . ' يجب أن تبدأ من ' . number_format($expectedFrom, 2) . '.';
                }
                if ($isLastBracket !== ($to === null)) {
                    return "آخر شريحة فقط في «{$category['label']}» تُترك بدون حد أعلى.";
                }
                if ($to !== null && $to <= $from) {
                    return "حد أعلى الشريحة لازم يكون أكبر من بدايتها في «{$category['label']}».";
                }
                $expectedFrom = $to ?? 0.0;
            }
        }

        return null;
    }

    public function saveTax(array $categories, string $effectiveFrom): void
    {
        DB::transaction(function () use ($categories, $effectiveFrom): void {
            $snapshot = function (): array {
                $rows = DB::table('tax_categories')->whereNull('company_id')->orderBy('sort_order')->get();

                return ['categories' => $rows->map(fn ($row) => (array) $row)->all(),
                    'brackets' => DB::table('tax_brackets')->whereIn('tax_category_id', $rows->pluck('id')->all())->get()->map(fn ($row) => (array) $row)->all()];
            };
            $before = $snapshot();
            $this->writeTax($categories);
            $this->recordChange($effectiveFrom, $before, $snapshot());
        });
    }

    /** Replace the global tax table. Rates arrive as percentages (10 = 10%). */
    private function writeTax(array $categories): void
    {
        $oldIds = DB::table('tax_categories')->whereNull('company_id')->pluck('id')->all();
        if ($oldIds) {
            DB::table('tax_brackets')->whereIn('tax_category_id', $oldIds)->delete();
            DB::table('tax_categories')->whereIn('id', $oldIds)->delete();
        }
        $minIncome = 0.0;
        foreach (array_values($categories) as $index => $category) {
            $max = ($category['max_income'] ?? null) === null || $category['max_income'] === '' ? null : (float) $category['max_income'];
            $categoryId = DB::table('tax_categories')->insertGetId([
                'company_id' => null, 'category_key' => 'category_' . ($index + 1), 'label' => $category['label'],
                'min_income' => $minIncome, 'max_income' => $max, 'sort_order' => $index + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_values($category['brackets']) as $bracketIndex => $bracket) {
                DB::table('tax_brackets')->insert([
                    'tax_category_id' => $categoryId, 'from_amount' => (float) $bracket['from'],
                    'to_amount' => ($bracket['to'] ?? null) === null || $bracket['to'] === '' ? null : (float) $bracket['to'],
                    'rate' => round(((float) $bracket['rate']) / 100, 4), 'sort_order' => $bracketIndex + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $minIncome = $max === null ? 0.0 : round($max + 0.01, 2);
        }
    }

    private function recordChange(string $effectiveFrom, array $before, array $after): void
    {
        DB::table('payroll_setting_changes')->insert([
            'company_id' => null, 'effective_from' => $effectiveFrom,
            'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR), 'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
            'changed_by' => auth('owner')->id(), 'ip_address' => request()->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Recalculate every active salary structure of every company. Approved or paid runs are never touched. */
    public function recalculateAllCompanies(): int
    {
        $total = 0;
        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $total += (int) CompanyContext::run((int) $companyId, fn () => app(SalaryController::class)
                ->recalculateCompany(new \App\Services\PayrollCalculator(), (int) $companyId));
        }

        return $total;
    }
}
