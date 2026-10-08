<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    protected $fillable = [
        'employee_id','effective_from','salary_type','salary_input','basic_salary','allowances',
        'allowance_limit_pct','allowance_limit','allowance_remaining',
        'housing_allowance','transportation_allowance','meal_allowance','clothing_allowance','other_allowances',
        'deductions','fixed_deductions','net_salary','gross_salary','insurance_wage','applied_insurance_wage',
        'employee_insurance','income_tax','taxable_annual','martyrs_fund','total_deductions',
        'employer_insurance','employee_cost','no_insurance','excluded_allowance_pct',
        'personal_exemption','tax_treatment','status','payment_method_id','notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'basic_salary' => 'decimal:2','salary_input' => 'decimal:2','allowances' => 'decimal:2',
        'allowance_limit_pct' => 'decimal:2','allowance_limit' => 'decimal:2','allowance_remaining' => 'decimal:2',
        'housing_allowance' => 'decimal:2','transportation_allowance' => 'decimal:2','meal_allowance' => 'decimal:2','clothing_allowance' => 'decimal:2','other_allowances' => 'decimal:2',
        'deductions' => 'decimal:2','fixed_deductions' => 'decimal:2','net_salary' => 'decimal:2','gross_salary' => 'decimal:2',
        'insurance_wage' => 'decimal:2','applied_insurance_wage' => 'decimal:2','employee_insurance' => 'decimal:2','income_tax' => 'decimal:2','taxable_annual' => 'decimal:2',
        'martyrs_fund' => 'decimal:2','total_deductions' => 'decimal:2','employer_insurance' => 'decimal:2','employee_cost' => 'decimal:2',
        'no_insurance' => 'boolean','excluded_allowance_pct' => 'decimal:2','personal_exemption' => 'decimal:2',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }

    // تحديث «الأجر التأميني» في سجل التأمين من آخر هيكل راتب ساري للموظف.
    public static function syncInsuredSalary(int $employeeId): void
    {
        $record = InsuranceRecord::where('employee_id', $employeeId)->first();
        if (!$record) return;
        $latest = static::where('employee_id', $employeeId)->where('status', 'Active')
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $record->update(['insured_salary' => ($latest && !$latest->no_insurance) ? $latest->applied_insurance_wage : null]);
    }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }
}
