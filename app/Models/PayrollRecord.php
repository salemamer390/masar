<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class PayrollRecord extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'payroll_run_id','employee_id','salary_id','payroll_month','basic_salary','allowances','housing_allowance','transportation_allowance','meal_allowance','clothing_allowance','overtime_amount','deductions','absence_deduction',
        'gross_salary','insurance_wage','employee_insurance','income_tax','martyrs_fund','employer_insurance',
        'employee_cost','fixed_deductions','attendance_deduction','penalties','penalty_deduction','day_overtime_hours','day_overtime_amount','total_overtime','net_salary','status','paid_at','notes','calculation_snapshot','approved_at','approved_by','is_locked',
        'absence_days','penalty_days','ot_day_hours','ot_night_hours','ot_day_amount','ot_night_amount','bonus','loans','other_earnings','other_deductions',
    ];

    protected $casts = [
        'payroll_month' => 'date','basic_salary' => 'decimal:2','allowances' => 'decimal:2','housing_allowance' => 'decimal:2','transportation_allowance' => 'decimal:2','meal_allowance' => 'decimal:2','clothing_allowance' => 'decimal:2','overtime_amount' => 'decimal:2',
        'deductions' => 'decimal:2','absence_deduction' => 'decimal:2','agreed_net_salary' => 'decimal:2','gross_salary' => 'decimal:2','insurance_wage' => 'decimal:2',
        'employee_insurance' => 'decimal:2','income_tax' => 'decimal:2','martyrs_fund' => 'decimal:2','employer_insurance' => 'decimal:2',
        'employee_cost' => 'decimal:2','fixed_deductions' => 'decimal:2','attendance_deduction' => 'decimal:2','penalties' => 'decimal:2','penalty_deduction' => 'decimal:2','day_overtime_hours' => 'decimal:2','day_overtime_amount' => 'decimal:2','total_overtime' => 'decimal:2',
        'net_salary' => 'decimal:2','paid_at' => 'datetime','calculation_snapshot' => 'array','approved_at' => 'datetime','is_locked' => 'boolean',
        'absence_days'=>'decimal:2','penalty_days'=>'decimal:2','ot_day_hours'=>'decimal:2','ot_night_hours'=>'decimal:2','ot_day_amount'=>'decimal:2','ot_night_amount'=>'decimal:2','bonus'=>'decimal:2','loans'=>'decimal:2','other_earnings'=>'decimal:2','other_deductions'=>'decimal:2',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function payrollRun(): BelongsTo { return $this->belongsTo(PayrollRun::class); }
    public function salary(): BelongsTo { return $this->belongsTo(Salary::class); }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'تم الصرف', 'Paid' => 'success',
            'قيد الإعداد', 'Draft' => 'warning',
            'معتمدة', 'Approved' => 'info',
            default => 'secondary',
        };
    }
}
