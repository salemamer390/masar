<?php

namespace App\Services;

use App\Models\Salary;

/**
 * Authoritative fixed salary-structure calculator.
 * The formulas mirror مواصفة_منطق_المرتبات_وشئون_العاملين.md.
 */
class PayrollCalculator
{
    public const MIN_INSURANCE = 2700.0;
    public const MAX_INSURANCE = 16700.0;
    public const EMPLOYEE_RATE = 0.11;
    public const EMPLOYER_RATE = 0.1875;
    public const MARTYRS_RATE = 0.0005;
    public const PERSONAL_EXEMPTION = 1667.0;
    public const DEFAULT_EXCLUDED_PCT = 25.0;

    public function __construct(private ?TaxCalculator $tax = null, private ?PayrollSettingsService $settings = null)
    {
        $this->tax ??= new TaxCalculator();
        $this->settings ??= new PayrollSettingsService();
    }

    public function calculate(string $salaryType, float $salaryInput, ?float $manualInsuranceWage = null, bool $noInsurance = false, float $ignoredAllowancePct = self::DEFAULT_EXCLUDED_PCT, float $ignoredExemption = self::PERSONAL_EXEMPTION, string $taxTreatment = 'egyptian', float $extraDeductions = 0.0): array
    {
        if ($salaryType === 'net') return $this->grossFromNet($salaryInput, $manualInsuranceWage, $noInsurance, $extraDeductions);
        return $this->grossResult($salaryInput, $manualInsuranceWage, $noInsurance, $ignoredAllowancePct, $ignoredExemption, $taxTreatment, $extraDeductions);
    }

    public function grossResult(float $gross, ?float $manualInsuranceWage = null, bool $noInsurance = false, float $ignoredAllowancePct = self::DEFAULT_EXCLUDED_PCT, float $ignoredExemption = self::PERSONAL_EXEMPTION, string $taxTreatment = 'egyptian', float $extraDeductions = 0.0): array
    {
        $gross = max(0, $gross);
        $allowanceRate = $this->settings->allowanceRate() / 100;
        $basic = $this->round2($gross / (1 + $allowanceRate));
        $allowances = $this->round2($gross - $basic);
        $shares = $this->allowanceShares();
        $transport = $this->floor0($allowances * $shares['transportation']);
        $housing = $this->floor0($allowances * $shares['housing']);
        $meal = $this->floor0($allowances * $shares['meal']);
        $clothing = $this->round2($allowances - $transport - $housing - $meal);
        $insuranceWage = $noInsurance ? 0.0 : $this->clamp($manualInsuranceWage && $manualInsuranceWage > 0 ? $manualInsuranceWage : $basic, $this->settings->insuranceMinimum(), $this->settings->insuranceMaximum());
        $employeeInsurance = $this->round2($insuranceWage * $this->settings->employeeInsuranceRate());
        $annualBase = ($gross - $employeeInsurance - $this->settings->personalExemption()) * 12;
        $tax = $this->tax->calculate($annualBase);
        $martyrs = $this->round2($gross * $this->settings->martyrsFundRate());
        $statutory = $this->round2($employeeInsurance + $tax['monthly_tax'] + $martyrs);
        $extraDeductions = max(0, $extraDeductions);
        $total = $this->round2($statutory + $extraDeductions);
        $employerInsurance = $this->round2($insuranceWage * $this->settings->employerInsuranceRate());

        return [
            'gross'=>$this->round2($gross), 'basic_salary'=>$basic, 'allowances'=>$allowances,
            'transportation_allowance'=>$transport, 'housing_allowance'=>$housing, 'meal_allowance'=>$meal, 'clothing_allowance'=>$clothing,
            'insurance_wage'=>$this->round2($insuranceWage), 'employee_insurance'=>$employeeInsurance,
            'taxable_annual'=>$annualBase, 'tax_category'=>$tax['category'], 'tax_category_up_to'=>$tax['category_up_to'],
            'annual_tax'=>$tax['annual_tax'], 'income_tax'=>$tax['monthly_tax'], 'martyrs_fund'=>$martyrs,
            'extra_deductions'=>$extraDeductions, 'statutory_deductions'=>$statutory, 'total_deductions'=>$total,
            'net'=>$this->round2($gross - $total), 'employer_insurance'=>$employerInsurance, 'employee_cost'=>$this->round2($gross + $employerInsurance),
        ];
    }

    public function structure(array $in): array
    {
        $salaryType = ($in['salary_type'] ?? 'gross') === 'net' ? 'net' : 'gross';
        $fixed = max(0, (float)($in['fixed_deductions'] ?? 0));
        $calc = $this->calculate($salaryType, (float)($in['salary_input'] ?? 0), ($in['insurance_wage'] ?? '') === '' ? null : (float)$in['insurance_wage'], (bool)($in['no_insurance'] ?? false), 0, 0, 'egyptian', $fixed);
        return array_merge($calc, [
            'allowance_limit_pct'=>$this->settings->allowanceRate(), 'allowance_limit'=>$calc['allowances'], 'allowance_remaining'=>0.0,
            'fixed_deductions'=>$fixed, 'net_after_fixed'=>$calc['net'], 'personal_exemption'=>$this->settings->personalExemption(),
        ]);
    }

    public function structureForSalary(Salary $salary): array
    {
        return $this->structure(['salary_type'=>$salary->salary_type ?: 'gross','salary_input'=>$salary->salary_input,'insurance_wage'=>$salary->insurance_wage,'no_insurance'=>$salary->no_insurance,'fixed_deductions'=>$salary->fixed_deductions]);
    }

    /** Calculate the monthly payroll from a fixed gross structure and approved monthly inputs. */
    public function monthly(float $gross, array $monthly = [], ?float $manualInsuranceWage = null, bool $noInsurance = false): array
    {
        $structure = $this->grossResult($gross, $manualInsuranceWage, $noInsurance);
        $dayHours = max(0, (float)($monthly['ot_day_hours'] ?? 0));
        $nightHours = max(0, (float)($monthly['ot_night_hours'] ?? 0));
        $hourly = $gross / max(1, $this->settings->hoursDivisor());
        $otDay = $dayHours * $hourly * $this->settings->dayOvertimeMultiplier();
        $otNight = $nightHours * $hourly * $this->settings->nightOvertimeMultiplier();
        $overtime = $otDay + $otNight;
        $bonus = max(0, (float)($monthly['bonus'] ?? 0));
        $incentives = max(0, (float)($monthly['incentives'] ?? 0));
        $commission = max(0, (float)($monthly['commission'] ?? 0));
        $profitShare = max(0, (float)($monthly['profit_share'] ?? 0));
        $advanceEarnings = max(0, (float)($monthly['advance_earnings'] ?? 0));
        $otherEarnings = max(0, (float)($monthly['other_earnings'] ?? 0));
        $absenceDays = max(0, (float)($monthly['absence_days'] ?? 0));
        $penaltyDays = max(0, (float)($monthly['penalty_days'] ?? 0));
        $loans = max(0, (float)($monthly['loans'] ?? 0));
        $otherDeductions = max(0, (float)($monthly['other_deductions'] ?? 0));
        $tax = $overtime == 0.0 ? $structure['income_tax'] : $this->round2(
            $structure['income_tax'] + ($this->tax->annualTax($structure['taxable_annual'] + 12 * $overtime) - $structure['annual_tax']) / 12
        );
        $absenceDeduction = $absenceDays * $gross / max(1, $this->settings->absenceDailyDivisor());
        $penaltyDeduction = $penaltyDays * $structure['basic_salary'] / max(1, $this->settings->penaltyDailyDivisor());
        $entitlements = $structure['basic_salary'] + $structure['allowances'] + $otDay + $otNight + $bonus + $incentives + $commission + $profitShare + $otherEarnings + $advanceEarnings;
        $monthlyTotal = $structure['gross'] + $otDay + $otNight + $bonus + $incentives + $commission + $profitShare + $advanceEarnings;
        $deductions = $structure['employee_insurance'] + $tax + $structure['martyrs_fund'] + $absenceDeduction + $penaltyDeduction + $loans + $otherDeductions;

        return array_merge($structure, [
            'hourly_rate'=>$hourly, 'ot_day_hours'=>$dayHours, 'ot_night_hours'=>$nightHours, 'ot_day_amount'=>$otDay, 'ot_night_amount'=>$otNight,
            'overtime_amount'=>$otDay + $otNight, 'bonus'=>$bonus, 'incentives'=>$incentives, 'commission'=>$commission, 'profit_share'=>$profitShare, 'advance_earnings'=>$advanceEarnings, 'other_earnings'=>$otherEarnings,
            'absence_days'=>$absenceDays, 'penalty_days'=>$penaltyDays, 'loans'=>$loans, 'other_deductions'=>$otherDeductions,
            'income_tax'=>$tax, 'absence_deduction'=>$absenceDeduction, 'penalty_deduction'=>$penaltyDeduction,
            'entitlements'=>$entitlements, 'monthly_total'=>$this->round2($monthlyTotal), 'monthly_deductions'=>$deductions, 'net_monthly'=>$this->round2($entitlements - $deductions),
        ]);
    }

    public function persistFields(array $s): array
    {
        return [
            'basic_salary'=>$s['basic_salary'], 'allowances'=>$s['allowances'], 'allowance_limit_pct'=>$s['allowance_limit_pct'], 'allowance_limit'=>$s['allowance_limit'], 'allowance_remaining'=>0,
            'housing_allowance'=>$s['housing_allowance'], 'transportation_allowance'=>$s['transportation_allowance'], 'meal_allowance'=>$s['meal_allowance'], 'clothing_allowance'=>$s['clothing_allowance'],
            'gross_salary'=>$s['gross'], 'net_salary'=>$s['net_after_fixed'], 'applied_insurance_wage'=>$s['insurance_wage'], 'employee_insurance'=>$s['employee_insurance'],
            'income_tax'=>$s['income_tax'], 'taxable_annual'=>$s['taxable_annual'], 'martyrs_fund'=>$s['martyrs_fund'], 'total_deductions'=>$s['total_deductions'],
            'employer_insurance'=>$s['employer_insurance'], 'employee_cost'=>$s['employee_cost'], 'personal_exemption'=>$s['personal_exemption'],
        ];
    }

    private function grossFromNet(float $target, ?float $manual, bool $noInsurance, float $fixed): array
    {
        $lo = max(.01, $target); $hi = max($target * 2, $target + 50000);
        while ($this->grossResult($hi, $manual, $noInsurance, 0, 0, 'egyptian', $fixed)['net'] < $target) $hi *= 1.5;
        for ($i=0; $i<100; $i++) { $mid=($lo+$hi)/2; if ($this->grossResult($mid,$manual,$noInsurance,0,0,'egyptian',$fixed)['net'] < $target) $lo=$mid; else $hi=$mid; }
        $best = null; $difference = INF;
        for ($i=-3;$i<=3;$i++) { $candidate=$this->round2(($lo+$hi)/2)+$i/100; if ($candidate<=0) continue; $result=$this->grossResult($candidate,$manual,$noInsurance,0,0,'egyptian',$fixed); $d=abs($result['net']-$target); if ($d < $difference) { $best=$result; $difference=$d; } }
        return $best;
    }

    private function allowanceShares(): array
    {
        $rows = $this->settings->allowanceDistribution();
        $value = fn(string $key, float $default) => isset($rows[$key]) ? (float)$rows[$key]['percentage'] / 100 : $default;
        return ['transportation'=>isset($rows['transport']) ? (float)$rows['transport']['percentage'] / 100 : $value('transportation', .35), 'housing'=>$value('housing', .30), 'meal'=>$value('meal', .20)];
    }
    private function clamp(float $value, float $min, float $max): float { return min($max, max($min, $value)); }
    private function floor0(float $value): float { return floor($value + .000000001); }
    private function round2(float $value): float { return $this->tax->round2($value); }
}
