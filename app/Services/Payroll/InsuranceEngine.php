<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;
use App\Services\PayrollSettingsService;

class InsuranceEngine
{
    public function __construct(private ?PayrollSettingsService $settingsService = null)
    {
        $this->settingsService ??= new PayrollSettingsService();
    }

    public function settings(): array
    {
        if (DB::getSchemaBuilder()->hasTable('payroll_calculation_settings')) {
            return [
                'employee_rate' => $this->settingsService->employeeInsuranceRate(),
                'employer_rate' => $this->settingsService->employerInsuranceRate(),
                'martyrs_rate' => $this->settingsService->martyrsFundRate(),
                'minimum_wage' => $this->settingsService->insuranceMinimum(),
                'ceiling' => $this->settingsService->insuranceMaximum(),
                'allowance_exclusion_options' => config('payroll.insurance.allowance_exclusion_options', [30,25,20,0]),
            ];
        }
        $defaults = [
            'employee_rate' => (float) config('payroll.insurance.employee_rate', 0.11),
            'employer_rate' => (float) config('payroll.insurance.employer_rate', 0.1875),
            'martyrs_rate' => (float) config('payroll.insurance.martyrs_rate', 0.0005),
            'minimum_wage' => (float) config('payroll.insurance.minimum_wage', 2700),
            'ceiling' => (float) config('payroll.insurance.ceiling', 16700),
        ];
        if (DB::getSchemaBuilder()->hasTable('insurance_settings')) {
            $rows = DB::table('insurance_settings')->pluck('setting_value', 'setting_key');
            $defaults['employee_rate'] = isset($rows['employee_rate']) ? (float)$rows['employee_rate'] : $defaults['employee_rate'];
            $defaults['employer_rate'] = isset($rows['employer_rate']) ? (float)$rows['employer_rate'] : $defaults['employer_rate'];
            $defaults['martyrs_rate'] = isset($rows['martyrs_rate']) ? (float)$rows['martyrs_rate'] : $defaults['martyrs_rate'];
            $defaults['minimum_wage'] = isset($rows['minimum_insurance_wage']) ? (float)$rows['minimum_insurance_wage'] : $defaults['minimum_wage'];
            $defaults['ceiling'] = isset($rows['maximum_insurance_wage']) ? (float)$rows['maximum_insurance_wage'] : $defaults['ceiling'];
        }
        return [
            'employee_rate' => $defaults['employee_rate'],
            'employer_rate' => $defaults['employer_rate'],
            'martyrs_rate' => $defaults['martyrs_rate'],
            'minimum_wage' => $defaults['minimum_wage'],
            'ceiling' => $defaults['ceiling'],
            'allowance_exclusion_options' => config('payroll.insurance.allowance_exclusion_options', [30,25,20,0]),
        ];
    }

    public function calculate(float $gross, bool $insured = true, ?float $manualWage = null, float $allowanceExclusionPct = 25): array
    {
        $s = $this->settings();
        if (!$insured) {
            return [
                'insurance_wage' => 0.0,
                'employee_insurance' => 0.0,
                'employer_insurance' => 0.0,
                'insured' => false,
                'manual_override' => false,
            ];
        }

        $manual = $manualWage !== null && $manualWage >= 0;
        $raw = $manual ? $manualWage : $gross / (1 + max(0, $allowanceExclusionPct) / 100);
        $wage = min($s['ceiling'], max($s['minimum_wage'], $raw));

        return [
            'insurance_wage' => round($wage, 2),
            'employee_insurance' => round($wage * $s['employee_rate'], 2),
            'employer_insurance' => round($wage * $s['employer_rate'], 2),
            'insured' => true,
            'manual_override' => $manual,
        ];
    }
}
