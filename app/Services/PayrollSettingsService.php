<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PayrollSettingsService
{
    /** @var array<string, float> */
    private array $numberCache = [];

    /** @var array<string, string> */
    private array $ruleCache = [];

    private ?array $allowanceDistributionCache = null;

    /**
     * Read numeric payroll calculation setting.
     */
    public function number(
        string $group,
        string $key,
        float $default = 0.0
    ): float {
        $cacheKey = $group.'.'.$key;
        if (array_key_exists($cacheKey, $this->numberCache)) {
            return $this->numberCache[$cacheKey];
        }

        $value = DB::table('payroll_calculation_settings')
            ->where('company_id', auth()->user()?->company_id)
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->where('is_active', 1)
            ->value('setting_value');

        return $this->numberCache[$cacheKey] = ($value === null || $value === '')
            ? $default
            : (float) $value;
    }

    /**
     * Read text-based payroll calculation rule.
     */
    public function rule(
        string $group,
        string $key,
        string $default = ''
    ): string {
        $cacheKey = $group.'.'.$key;
        if (array_key_exists($cacheKey, $this->ruleCache)) {
            return $this->ruleCache[$cacheKey];
        }

        $value = DB::table('payroll_calculation_rules')
            ->where('rule_group', $group)
            ->where('rule_key', $key)
            ->where('is_active', 1)
            ->value('rule_value');

        return $this->ruleCache[$cacheKey] = ($value === null || $value === '')
            ? $default
            : (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */

    public function allowanceRate(): float
    {
        return $this->number(
            'general',
            'allowance_rate',
            25
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Insurance
    |--------------------------------------------------------------------------
    */

    public function employeeInsuranceRate(): float
    {
        return $this->number(
            'insurance',
            'employee_insurance_rate',
            11
        ) / 100;
    }

    public function employerInsuranceRate(): float
    {
        return $this->number(
            'insurance',
            'employer_insurance_rate',
            18.75
        ) / 100;
    }

    public function martyrsFundRate(): float
    {
        return $this->number(
            'insurance',
            'martyrs_fund_rate',
            0.05
        ) / 100;
    }

    public function insuranceMinimum(): float
    {
        return $this->number(
            'insurance',
            'insurance_minimum',
            2700
        );
    }

    public function insuranceMaximum(): float
    {
        return $this->number(
            'insurance',
            'insurance_maximum',
            16700
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Overtime
    |--------------------------------------------------------------------------
    */

    public function hoursDivisor(): float
    {
        return $this->number(
            'overtime',
            'hours_divisor',
            240
        );
    }

    public function daysDivisor(): float
    {
        return $this->number(
            'overtime',
            'days_divisor',
            30
        );
    }

    public function dayOvertimeMultiplier(): float
    {
        return $this->number(
            'overtime',
            'day_multiplier',
            1.35
        );
    }

    public function nightOvertimeMultiplier(): float
    {
        return $this->number(
            'overtime',
            'night_multiplier',
            1.70
        );
    }

    public function officialHolidayMultiplier(): float
    {
        return $this->number(
            'overtime',
            'official_holiday_multiplier',
            2
        );
    }

    public function weeklyRestMultiplier(): float
    {
        return $this->number(
            'overtime',
            'weekly_rest_multiplier',
            1
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    */

    public function personalExemption(): float
    {
        return $this->number(
            'tax',
            'personal_exemption',
            1667
        );
    }

    public function taxDeductibleContributions(): float
    {
        return $this->number(
            'tax',
            'tax_deductible_contributions',
            0
        );
    }

    public function profitShareTaxExempt(): float
    {
        return $this->number(
            'tax',
            'profit_share_tax_exempt',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Absence / Penalty
    |--------------------------------------------------------------------------
    */

    public function absenceBasis(): string
    {
        return $this->rule(
            'absence',
            'calculation_basis',
            'Gross'
        );
    }

    public function penaltyBasis(): string
    {
        return $this->rule(
            'penalty',
            'calculation_basis',
            'Basic'
        );
    }

    public function absenceDailyDivisor(): float
    {
        return $this->number(
            'absence',
            'daily_divisor',
            30
        );
    }

    public function penaltyDailyDivisor(): float
    {
        return $this->number(
            'penalty',
            'daily_divisor',
            30
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Overtime basis
    |--------------------------------------------------------------------------
    */

    public function overtimeBasis(): string
    {
        return $this->rule(
            'overtime',
            'calculation_basis',
            'Gross'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Insurance basis
    |--------------------------------------------------------------------------
    */

    public function insuranceAutomaticBasis(): string
    {
        return $this->rule(
            'insurance',
            'automatic_calculation_basis',
            'Gross'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Allowance Distribution
    |--------------------------------------------------------------------------
    */

    public function allowanceDistribution(): array
    {
        if ($this->allowanceDistributionCache !== null) {
            return $this->allowanceDistributionCache;
        }

        return $this->allowanceDistributionCache = DB::table('payroll_allowance_settings')
            ->where('company_id', auth()->user()?->company_id)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get([
                'allowance_key',
                'allowance_name',
                'percentage',
                'calculation_basis',
                'sort_order',
            ])
            ->mapWithKeys(function ($row) {
                return [
                    $row->allowance_key => [
                        'name' => $row->allowance_name,
                        'percentage' => (float) $row->percentage,
                        'basis' => $row->calculation_basis,
                        'sort_order' => (int) $row->sort_order,
                    ],
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Snapshot
    |--------------------------------------------------------------------------
    */

    public function snapshot(): array
    {
        return [
            'general' => [
                'allowance_rate' => $this->allowanceRate(),
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
                'tax_deductible_contributions' =>
                    $this->taxDeductibleContributions(),
                'profit_share_tax_exempt' =>
                    $this->profitShareTaxExempt(),
            ],

            'deductions' => [
                'absence_basis' => $this->absenceBasis(),
                'absence_daily_divisor' => $this->absenceDailyDivisor(),
                'penalty_basis' => $this->penaltyBasis(),
                'penalty_daily_divisor' => $this->penaltyDailyDivisor(),
            ],

            'allowances' => $this->allowanceDistribution(),
        ];
    }
}
