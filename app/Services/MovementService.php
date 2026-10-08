<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\MovementRecord;
use App\Models\Salary;
use Illuminate\Support\Facades\DB;

class MovementService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PayrollSettingsService $settings,
    )
    {
    }

    /** Apply the movement, employee state, salary history, and audit trail atomically. */
    public function apply(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::with('currentSalary')->lockForUpdate()->findOrFail($data['employee_id']);
            $oldValues = [
                'department_id' => $employee->department_id,
                'job_id' => $employee->job_id,
                'salary_input' => $employee->currentSalary?->salary_input ?? $employee->currentSalary?->net_salary,
            ];
            $newValues = [
                'department_id' => $data['new_department_id'] ?? $employee->department_id,
                'job_id' => $data['new_job_id'] ?? $employee->job_id,
                'salary_input' => $data['new_salary'] ?? $oldValues['salary_input'],
            ];

            $movement = MovementRecord::create([
                'employee_id' => $employee->id,
                'movement_type_id' => $data['movement_type_id'],
                'movement_date' => $data['movement_date'],
                'old_department_id' => $oldValues['department_id'],
                'new_department_id' => $newValues['department_id'],
                'old_job_id' => $oldValues['job_id'],
                'new_job_id' => $newValues['job_id'],
                'old_salary' => $oldValues['salary_input'],
                'new_salary' => $newValues['salary_input'],
                'reason' => $data['reason'] ?? null,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $employee->update([
                'department_id' => $newValues['department_id'],
                'job_id' => $newValues['job_id'],
            ]);

            $allowancesReset = false;
            if (!empty($data['new_salary'])) {
                $previous = $employee->currentSalary;
                $newNet = (float) $data['new_salary'];
                $limit = round($newNet * $this->settings->allowanceRate() / 100, 2);
                $housing = (float) ($previous?->housing_allowance ?? 0);
                $transport = (float) ($previous?->transportation_allowance ?? 0);
                $meal = (float) ($previous?->meal_allowance ?? 0);
                $clothing = (float) ($previous?->clothing_allowance ?? 0);
                if (round($housing + $transport + $meal + $clothing, 2) > $limit) {
                    $housing = $transport = $meal = $clothing = 0.0;
                    $allowancesReset = true;
                }

                $input = [
                    'salary_type' => 'net', 'salary_input' => $newNet,
                    'insurance_wage' => $previous?->insurance_wage,
                    'no_insurance' => (bool) ($previous?->no_insurance ?? false),
                    'excluded_allowance_pct' => $previous?->excluded_allowance_pct ?? PayrollCalculator::DEFAULT_EXCLUDED_PCT,
                    'personal_exemption' => $this->settings->personalExemption(),
                    'tax_treatment' => $previous?->tax_treatment ?: 'egyptian',
                    'housing_allowance' => $housing, 'transportation_allowance' => $transport,
                    'meal_allowance' => $meal, 'clothing_allowance' => $clothing,
                    'fixed_deductions' => (float) ($previous?->fixed_deductions ?? 0),
                ];
                $structure = $this->calculator->structure($input);
                Salary::where('employee_id', $employee->id)->where('effective_from', '<', $data['movement_date'])->where('status', 'Active')->update(['status' => 'Historical']);
                Salary::updateOrCreate(
                    ['employee_id' => $employee->id, 'effective_from' => $data['movement_date']],
                    array_merge($input, $this->calculator->persistFields($structure), [
                        'deductions' => $input['fixed_deductions'], 'other_allowances' => 0,
                        'payment_method_id' => $previous?->payment_method_id, 'status' => 'Active',
                    ])
                );
                Salary::syncInsuredSalary((int) $employee->id);
            }

            ActivityLog::record('movement_create', 'Employment movement created.', $movement, $oldValues, $newValues, 'movements');
            return compact('movement', 'allowancesReset');
        });
    }
}
