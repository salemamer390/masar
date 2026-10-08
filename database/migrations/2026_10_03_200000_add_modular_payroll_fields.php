<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $salaryColumns = [
        'incentives'=>['decimal',[12,2]],'commission'=>['decimal',[12,2]],'profit_share'=>['decimal',[12,2]],'other_earnings'=>['decimal',[12,2]],
        'profit_share_tax_exempt'=>['boolean',[]], 'manual_insurance_wage'=>['decimal',[12,2]], 'manual_deduction'=>['decimal',[12,2]],
    ];
    private array $payrollColumns = [
        'incentives'=>['decimal',[12,2]],'commission'=>['decimal',[12,2]],'profit_share'=>['decimal',[12,2]],'other_earnings'=>['decimal',[12,2]],
        'profit_share_tax_exempt'=>['boolean',[]], 'tax_exempt_profit_share'=>['decimal',[12,2]], 'manual_insurance_wage'=>['decimal',[12,2]], 'manual_insurance_override'=>['boolean',[]],
        'day_overtime_hours'=>['decimal',[10,2]], 'night_overtime_hours'=>['decimal',[10,2]], 'official_holiday_days'=>['decimal',[10,2]], 'weekly_rest_days'=>['decimal',[10,2]],
        'day_overtime_amount'=>['decimal',[12,2]], 'night_overtime_amount'=>['decimal',[12,2]], 'official_holiday_amount'=>['decimal',[12,2]], 'weekly_rest_amount'=>['decimal',[12,2]],
        'penalty_deduction'=>['decimal',[12,2]], 'advance_deduction'=>['decimal',[12,2]], 'other_deduction'=>['decimal',[12,2]], 'manual_deduction'=>['decimal',[12,2]],
        'calculated_monthly_tax'=>['decimal',[14,6]], 'tax_paid_to_date'=>['decimal',[14,2]], 'tax_remaining'=>['decimal',[14,2]],
    ];
    public function up(): void
    {
        if (Schema::hasTable('salaries')) {
            foreach ($this->salaryColumns as $name=>$def) {
                if (!Schema::hasColumn('salaries',$name)) {
                    Schema::table('salaries', function(Blueprint $t) use ($name,$def) {
                        if ($def[0] === 'boolean') $t->boolean($name)->default(false);
                        else $t->decimal($name,$def[1][0],$def[1][1])->default(0);
                    });
                }
            }
        }
        if (Schema::hasTable('payroll_records')) {
            foreach ($this->payrollColumns as $name=>$def) {
                if (!Schema::hasColumn('payroll_records',$name)) {
                    Schema::table('payroll_records', function(Blueprint $t) use ($name,$def) {
                        if ($def[0] === 'boolean') $t->boolean($name)->default(false);
                        else $t->decimal($name,$def[1][0],$def[1][1])->default(0);
                    });
                }
            }
        }

        if (!Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function(Blueprint $t){
                $t->id(); $t->date('payroll_month'); $t->string('status',30)->default('draft'); $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('approved_by')->nullable(); $t->timestamp('approved_at')->nullable(); $t->json('settings_snapshot')->nullable(); $t->timestamps();
                $t->unique('payroll_month');
            });
        }
        if (!Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function(Blueprint $t){
                $t->id(); $t->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete(); $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete(); $t->unsignedBigInteger('payroll_record_id')->nullable(); $t->json('calculation_snapshot')->nullable(); $t->timestamps(); $t->unique(['payroll_run_id','employee_id']);
            });
        }
        if (!Schema::hasTable('payroll_deductions')) {
            Schema::create('payroll_deductions', function(Blueprint $t){
                $t->id(); $t->foreignId('payroll_record_id')->constrained('payroll_records')->cascadeOnDelete(); $t->string('type',50); $t->decimal('amount',12,2); $t->string('reason')->nullable(); $t->timestamps();
            });
        }
        if (!Schema::hasTable('tax_reconciliations')) {
            Schema::create('tax_reconciliations', function(Blueprint $t){
                $t->id(); $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete(); $t->unsignedSmallInteger('tax_year'); $t->decimal('actual_annual_tax',14,2)->default(0); $t->decimal('monthly_tax_paid',14,2)->default(0); $t->decimal('tax_difference',14,2)->default(0); $t->string('status',30)->default('pending'); $t->text('notes')->nullable(); $t->timestamps(); $t->unique(['employee_id','tax_year']);
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('tax_reconciliations'); Schema::dropIfExists('payroll_deductions'); Schema::dropIfExists('payroll_items'); Schema::dropIfExists('payroll_runs');
        if (Schema::hasTable('payroll_records')) foreach (array_keys($this->payrollColumns) as $c) if (Schema::hasColumn('payroll_records',$c)) Schema::table('payroll_records',fn(Blueprint $t)=>$t->dropColumn($c));
        if (Schema::hasTable('salaries')) foreach (array_keys($this->salaryColumns) as $c) if (Schema::hasColumn('salaries',$c)) Schema::table('salaries',fn(Blueprint $t)=>$t->dropColumn($c));
    }
};
