<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads payroll calculation settings, rules and allowance split for ONE company.
 *
 * The company is resolved through CompanyContext (signed-in user, or an explicit
 * context for console jobs). Every company owns its own rows, so a change made
 * by one company never affects another. When a company (or a key) has no row
 * the documented default below is used.
 */
class PayrollSettingsService
{
    private array $numberCache = [];

    private array $ruleCache = [];

    private array $allowanceDistributionCache = [];

    private static ?bool $rulesHaveCompany = null;

    protected function companyId(): ?int
    {
        return CompanyContext::id();
    }

    private function cacheKey(string $suffix): string
    {
        return ($this->companyId() ?? 'global') . '|' . $suffix;
    }

    public function number(string $group, string $key, float $default = 0.0): float
    {
        $cacheKey = $this->cacheKey($group . '.' . $key);
        if (array_key_exists($cacheKey, $this->numberCache)) {
            return $this->numberCache[$cacheKey];
        }

        $value = DB::table('payroll_calculation_settings')
            ->where('company_id', $this->companyId())
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->where('is_active', 1)
            ->value('setting_value');

        return $this->numberCache[$cacheKey] = ($value === null || $value === '')
            ? $default
            : (float) $value;
    }

    public function rule(string $group, string $key, string $default = ''): string
    {
        $cacheKey = $this->cacheKey($group . '.' . $key);
        if (array_key_exists($cacheKey, $this->ruleCache)) {
            return $this->ruleCache[$cacheKey];
        }

        $value = null;
        if ($this->rulesAreCompanyScoped()) {
            $value = DB::table('payroll_calculation_rules')
                ->where('company_id', $this->companyId())
                ->where('rule_group', $group)
                ->where('rule_key', $key)
                ->where('is_active', 1)
                ->value('rule_value');
        } else {
            $value = DB::table('payroll_calculation_rules')
                ->where('rule_group', $group)
                ->where('rule_key', $key)
                ->where('is_active', 1)
                ->value('rule_value');
        }

        return $this->ruleCache[$cacheKey] = ($value === null || $value === '')
            ? $default
            : (string) $value;
    }

    /** Basis rules accept only Gross or Basic; anything else falls back to the default. */
    private function basis(string $group, string $key, string $default): string
    {
        $value = ucfirst(strtolower(trim($this->rule($group, $key, $default))));

        return in_array($value, ['Gross', 'Basic'], true) ? $value : $default;
    }

    private function rulesAreCompanyScoped(): bool
    {
        if (self::$rulesHaveCompany === null) {
            try {
                self::$rulesHaveCompany = Schema::hasColumn('payroll_calculation_rules', 'company_id');
            } catch (\Throwable) {
                self::$rulesHaveCompany = false;
            }
        }

        return self::$rulesHaveCompany;
    }

    public function allowanceRate(): float
    {
        return $this->number('general', 'allowance_rate', 25);
    }

    /** Days a month is divided into when salary is prorated (hire / termination / to-date). */
    public function prorationDays(): int
    {
        return max(1, (int) round($this->number('general', 'proration_days', 30)));
    }

    public function employeeInsuranceRate(): float
    {
        return $this->number('insurance', 'employee_insurance_rate', 11) / 100;
    }

    public function employerInsuranceRate(): float
    {
        return $this->number('insurance', 'employer_insurance_rate', 18.75) / 100;
    }

    public function martyrsFundRate(): float
    {
        return $this->number('insurance', 'martyrs_fund_rate', 0.05) / 100;
    }

    public function insuranceMinimum(): float
    {
        return $this->number('insurance', 'insurance_minimum', 2700);
    }

    public function insuranceMaximum(): float
    {
        return $this->number('insurance', 'insurance_maximum', 16700);
    }

    public function hoursDivisor(): float
    {
        return $this->number('overtime', 'hours_divisor', 240);
    }

    public function daysDivisor(): float
    {
        return $this->number('overtime', 'days_divisor', 30);
    }

    public function dayOvertimeMultiplier(): float
    {
        return $this->number('overtime', 'day_multiplier', 1.35);
    }

    public function nightOvertimeMultiplier(): float
    {
        return $this->number('overtime', 'night_multiplier', 1.70);
    }

    public function officialHolidayMultiplier(): float
    {
        return $this->number('overtime', 'official_holiday_multiplier', 2);
    }

    public function weeklyRestMultiplier(): float
    {
        return $this->number('overtime', 'weekly_rest_multiplier', 1);
    }

    /** Monthly personal exemption; multiplied by 12 in the annual taxable base. */
    public function personalExemption(): float
    {
        return $this->number('tax', 'personal_exemption', 1667);
    }

    /** Annual amount deducted from the taxable base. */
    public function taxDeductibleContributions(): float
    {
        return $this->number('tax', 'tax_deductible_contributions', 0);
    }

    /** Profit share is the only monthly variable exempt from income tax. */
    public function profitShareExemptPercent(): float
    {
        return 100.0;
    }

    /** @deprecated kept for old snapshots; the live rule is profitShareExemptPercent(). */
    public function profitShareTaxExempt(): float
    {
        return $this->number('tax', 'profit_share_tax_exempt', 0);
    }

    public function absenceBasis(): string
    {
        return $this->basis('absence', 'calculation_basis', 'Gross');
    }

    /** Allowance keys whose daily value joins the absence-day deduction under the Basic basis. */
    public function absenceDeductedAllowances(): array
    {
        // The seeded row carries the meal,transportation default; an explicitly saved
        // empty selection must stay empty (rule() would otherwise resurrect the default).
        $keys = array_filter(array_map('trim', explode(',', strtolower($this->rule('absence', 'deducted_allowances', '')))));

        return array_values(array_intersect($keys, ['housing', 'transportation', 'meal', 'clothing', 'other']));
    }

    public function penaltyBasis(): string
    {
        return $this->basis('penalty', 'calculation_basis', 'Basic');
    }

    public function overtimeBasis(): string
    {
        return $this->basis('overtime', 'calculation_basis', 'Gross');
    }

    public function insuranceAutomaticBasis(): string
    {
        return $this->basis('insurance', 'automatic_calculation_basis', 'Basic');
    }

    public function absenceDailyDivisor(): float
    {
        return $this->number('absence', 'daily_divisor', 30);
    }

    public function penaltyDailyDivisor(): float
    {
        return $this->number('penalty', 'daily_divisor', 30);
    }

    /** Maximum penalty days deducted in one month (0 = no cap). */
    public function penaltyMaxDaysPerMonth(): float
    {
        return max(0.0, $this->number('penalty', 'max_days_per_month', 5));
    }

    /** Advance (and monthly installment) cap as a fraction of gross salary. */
    public function advanceMaxFraction(): float
    {
        return max(0.0, $this->number('advance', 'max_percent_of_gross', 10)) / 100;
    }

    public function allowanceDistribution(): array
    {
        $cacheKey = $this->cacheKey('allowances');
        if (array_key_exists($cacheKey, $this->allowanceDistributionCache)) {
            return $this->allowanceDistributionCache[$cacheKey];
        }

        $columns = ['allowance_key', 'allowance_name', 'percentage', 'calculation_basis', 'sort_order'];
        $hasProrationColumn = $this->allowancesHaveProrationFlag();
        if ($hasProrationColumn) $columns[] = 'is_prorated';

        return $this->allowanceDistributionCache[$cacheKey] = DB::table('payroll_allowance_settings')
            ->where('company_id', $this->companyId())
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get($columns)
            ->mapWithKeys(function ($row) use ($hasProrationColumn) {
                return [
                    $row->allowance_key => [
                        'name' => $row->allowance_name,
                        'percentage' => (float) $row->percentage,
                        'basis' => $row->calculation_basis,
                        'sort_order' => (int) $row->sort_order,
                        // Missing column/row means the historical behavior: prorated.
                        'prorated' => !$hasProrationColumn || (bool) ($row->is_prorated ?? true),
                    ],
                ];
            })
            ->toArray();
    }

    /** Payroll-record column => prorated flag, per the company's allowance settings. */
    public function allowanceProrationMap(): array
    {
        $columns = [
            'transport' => 'transportation_allowance', 'transportation' => 'transportation_allowance',
            'housing' => 'housing_allowance', 'meal' => 'meal_allowance', 'clothing' => 'clothing_allowance',
        ];
        $map = [];
        foreach ($this->allowanceDistribution() as $key => $row) {
            if (isset($columns[$key])) $map[$columns[$key]] = (bool) ($row['prorated'] ?? true);
        }

        return $map;
    }

    /** Whether one allowance column prorates with the worked part of the month. */
    public function allowanceIsProrated(string $column): bool
    {
        return $this->allowanceProrationMap()[$column] ?? true;
    }

    private function allowancesHaveProrationFlag(): bool
    {
        try {
            return Schema::hasColumn('payroll_allowance_settings', 'is_prorated');
        } catch (\Throwable) {
            return false;
        }
    }

    public function snapshot(): array
    {
        return [
            'general' => [
                'allowance_rate' => $this->allowanceRate(),
                'proration_days' => $this->prorationDays(),
            ],

            'insurance' => [
                'employee_rate' => $this->employeeInsuranceRate(),
                'employer_rate' => $this->employerInsuranceRate(),
                'martyrs_rate' => $this->martyrsFundRate(),
                'minimum' => $this->insuranceMinimum(),
                'maximum' => $this->insuranceMaximum(),
                'automatic_basis' => $this->insuranceAutomaticBasis(),
            ],

            'overtime' => [
                'hours_divisor' => $this->hoursDivisor(),
                'days_divisor' => $this->daysDivisor(),
                'day_multiplier' => $this->dayOvertimeMultiplier(),
                'night_multiplier' => $this->nightOvertimeMultiplier(),
                'official_holiday_multiplier' => $this->officialHolidayMultiplier(),
                'weekly_rest_multiplier' => $this->weeklyRestMultiplier(),
                'basis' => $this->overtimeBasis(),
            ],

            'tax' => [
                'personal_exemption' => $this->personalExemption(),
                'tax_deductible_contributions' => $this->taxDeductibleContributions(),
                'profit_share_exempt_percent' => $this->profitShareExemptPercent(),
            ],

            'deductions' => [
                'absence_basis' => $this->absenceBasis(),
                'absence_daily_divisor' => $this->absenceDailyDivisor(),
                'penalty_basis' => $this->penaltyBasis(),
                'penalty_daily_divisor' => $this->penaltyDailyDivisor(),
                'penalty_max_days_per_month' => $this->penaltyMaxDaysPerMonth(),
            ],

            'advance' => [
                'max_fraction_of_gross' => $this->advanceMaxFraction(),
            ],

            'allowances' => $this->allowanceDistribution(),
        ];
    }
}
