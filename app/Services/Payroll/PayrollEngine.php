<?php

namespace App\Services\Payroll;

use App\Services\PayrollSettingsService;

class PayrollEngine
{
    public function __construct(
        private readonly TaxEngine $tax,
        private readonly InsuranceEngine $insurance,
        private readonly OvertimeEngine $overtime,
        private readonly GrossUpEngine $grossUp,
        private readonly PayrollSettingsService $settings,
    ) {}

    public function calculate(array $input): array
    {
        $salaryType = strtolower((string)($input['salary_type'] ?? 'net'));
        $targetSalary = max(0, (float)($input['salary_input'] ?? 0));
        $allowances = $this->sum($input, ['transportation_allowance','meal_allowance','housing_allowance','clothing_allowance']);
        $incentives = max(0, (float)($input['incentives'] ?? 0));
        $commission = max(0, (float)($input['commission'] ?? 0));
        $profitShare = max(0, (float)($input['profit_share'] ?? 0));
        $otherEarnings = max(0, (float)($input['other_earnings'] ?? 0));
        $insured = !((bool)($input['no_insurance'] ?? false));
        $manualInsurance = isset($input['manual_insurance_wage']) && $input['manual_insurance_wage'] !== '' ? (float)$input['manual_insurance_wage'] : null;
        $exclusion = (float)($input['allowance_exclusion_pct'] ?? 25);
        $taxDeductible = max(0, (float)($input['tax_deductible_contributions'] ?? 0));
        $exemptProfit = (bool)($input['profit_share_tax_exempt'] ?? false) ? $profitShare : 0;
        $personalExemption = isset($input['personal_exemption']) ? (float)$input['personal_exemption'] : $this->tax->personalExemption();

        $fixedGross = $salaryType === 'net'
            ? $this->grossUp->solve($targetSalary, function(float $gross) use ($insured, $manualInsurance, $exclusion, $taxDeductible, $personalExemption) {
                return $this->netFromGross($gross, $insured, $manualInsurance, $exclusion, $taxDeductible, 0, $personalExemption);
            })
            : $targetSalary;

        $overtime = $this->overtime->calculate(
            $fixedGross,
            (float)($input['day_overtime_hours'] ?? 0),
            (float)($input['night_overtime_hours'] ?? 0),
            (float)($input['official_holiday_days'] ?? 0),
            (float)($input['weekly_rest_days'] ?? 0),
        );

        $gross = $fixedGross + $incentives + $commission + $profitShare + $otherEarnings + $overtime['total_overtime'];
        $basic = max(0, $gross - $allowances);
        $insurance = $this->insurance->calculate($gross, $insured, $manualInsurance, $exclusion);
        $annualGross = $gross * 12;
        $annualInsurance = $insurance['employee_insurance'] * 12;
        $taxable = max(0, $annualGross - $annualInsurance - $taxDeductible - $exemptProfit - $personalExemption);
        $tax = $this->tax->breakdown($taxable);
        $monthlyTax = $this->tax->monthlyTax($taxable);
        $calculatedMonthlyTax = $this->tax->calculatedMonthlyTax($taxable);
        $martyrs = round($gross * $this->settings->martyrsFundRate(), 2);
        $absence = max(0, (float)($input['absence_deduction'] ?? 0));
        $penalties = max(0, (float)($input['penalties'] ?? 0));
        $advances = max(0, (float)($input['advances'] ?? 0));
        $otherDeductions = max(0, (float)($input['other_deductions'] ?? 0));
        $manualDeduction = max(0, (float)($input['manual_deduction'] ?? 0));
        $totalDeductions = $insurance['employee_insurance'] + $monthlyTax + $martyrs + $absence + $penalties + $advances + $otherDeductions + $manualDeduction;
        $net = $gross - $totalDeductions;
        $employerCost = $gross + $insurance['employer_insurance'];

        return [
            'salary_type'=>$salaryType,'agreed_net_salary'=>$salaryType==='net'?$targetSalary:null,'basic_salary'=>round($basic,2),'allowances'=>round($allowances,2),
            'transportation_allowance'=>(float)($input['transportation_allowance'] ?? 0),'meal_allowance'=>(float)($input['meal_allowance'] ?? 0),'housing_allowance'=>(float)($input['housing_allowance'] ?? 0),'clothing_allowance'=>(float)($input['clothing_allowance'] ?? 0),
            'incentives'=>$incentives,'commission'=>$commission,'profit_share'=>$profitShare,'profit_share_tax_exempt'=>$exemptProfit,'other_earnings'=>$otherEarnings,
            'overtime'=>$overtime,'overtime_amount'=>$overtime['total_overtime'],'gross_salary'=>round($gross,2),
            'annual_gross'=>round($annualGross,2),'insurance_wage'=>$insurance['insurance_wage'],'employee_insurance'=>$insurance['employee_insurance'],'annual_employee_insurance'=>round($annualInsurance,2),
            'manual_insurance_override'=>$insurance['manual_override'],'tax_deductible_contributions'=>round($taxDeductible,2),'personal_exemption'=>round($personalExemption,2),'tax_exempt_profit_share'=>round($exemptProfit,2),
            'taxable_annual'=>$tax['taxable_income'],'tax_category'=>$tax['category'],'tax_category_label'=>$tax['category_label'],'tax_brackets'=>$tax['rows'],'annual_tax'=>$tax['annual_tax'],'income_tax'=>round($monthlyTax,2),'calculated_monthly_tax'=>round($calculatedMonthlyTax,6),
            'martyrs_fund'=>$martyrs,'absence_deduction'=>$absence,'penalties'=>$penalties,'advances'=>$advances,'other_deductions'=>$otherDeductions,'manual_deduction'=>$manualDeduction,
            'total_deductions'=>round($totalDeductions,2),'net_salary'=>round($net,2),'employer_insurance'=>$insurance['employer_insurance'],'employee_cost'=>round($employerCost,2),
        ];
    }

    private function netFromGross(float $gross, bool $insured, ?float $manualInsurance, float $exclusion, float $taxDeductible, float $exemptProfit, float $personalExemption): float
    {
        $ins = $this->insurance->calculate($gross, $insured, $manualInsurance, $exclusion);
        $taxable = max(0, ($gross * 12) - ($ins['employee_insurance'] * 12) - $taxDeductible - $exemptProfit - $personalExemption);
        $tax = $this->tax->monthlyTax($taxable);
        $martyrs = round($gross * $this->settings->martyrsFundRate(), 2);
        return $gross - $ins['employee_insurance'] - $tax - $martyrs;
    }

    private function sum(array $input, array $keys): float { return array_sum(array_map(fn($k)=>(float)($input[$k] ?? 0), $keys)); }
}
