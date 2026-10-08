<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('leave_types')) Schema::table('leave_types', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_types', 'deducts_annual_balance')) $table->boolean('deducts_annual_balance')->default(true);
            if (!Schema::hasColumn('leave_types', 'is_paid')) $table->boolean('is_paid')->default(true);
        });
        if (Schema::hasTable('penalty_types')) Schema::table('penalty_types', function (Blueprint $table) {
            if (!Schema::hasColumn('penalty_types', 'default_deduction_days')) $table->decimal('default_deduction_days', 5, 2)->default(0);
        });
        if (Schema::hasTable('penalties')) Schema::table('penalties', function (Blueprint $table) {
            if (!Schema::hasColumn('penalties', 'deduction_days')) $table->decimal('deduction_days', 5, 2)->nullable();
        });
        if (Schema::hasTable('attendance')) Schema::table('attendance', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance', 'early_leave_minutes')) $table->unsignedInteger('early_leave_minutes')->default(0);
            if (!Schema::hasColumn('attendance', 'worked_hours')) $table->decimal('worked_hours', 5, 2)->nullable();
        });
        if (Schema::hasTable('payroll_records')) Schema::table('payroll_records', function (Blueprint $table) {
            foreach (['absence_days','penalty_days','ot_day_hours','ot_night_hours'] as $column) if (!Schema::hasColumn('payroll_records', $column)) $table->decimal($column, 8, 2)->default(0);
            foreach (['ot_day_amount','ot_night_amount','bonus','loans','other_earnings','other_deductions'] as $column) if (!Schema::hasColumn('payroll_records', $column)) $table->decimal($column, 14, 2)->default(0);
        });

        // Settings are stored in display units: 11 means 11%, 0.05 means 0.05%.
        if (Schema::hasTable('payroll_calculation_settings')) {
            $defaults = [
                ['general','allowance_rate',25], ['insurance','insurance_minimum',2700], ['insurance','insurance_maximum',16700],
                ['insurance','employee_insurance_rate',11], ['insurance','employer_insurance_rate',18.75], ['insurance','martyrs_fund_rate',.05],
                ['tax','personal_exemption',1667], ['overtime','hours_divisor',240], ['overtime','days_divisor',30],
                ['overtime','day_multiplier',1.35], ['overtime','night_multiplier',1.70], ['absence','daily_divisor',30], ['penalty','daily_divisor',30],
            ];
            foreach ($defaults as [$group,$key,$value]) {
                DB::table('payroll_calculation_settings')->updateOrInsert(
                    ['setting_group'=>$group, 'setting_key'=>$key],
                    ['setting_value'=>$value, 'effective_from'=>'2026-10-07', 'is_active'=>true, 'updated_at'=>now(), 'created_at'=>now()]
                );
            }
        }
        if (Schema::hasTable('payroll_allowance_settings')) {
            foreach ([['transport',35],['transportation',35],['housing',30],['meal',20],['clothing',15]] as [$key,$value]) {
                DB::table('payroll_allowance_settings')->where('allowance_key',$key)->update(['percentage'=>$value, 'updated_at'=>now()]);
            }
        }
        if (Schema::hasTable('leave_types')) {
            DB::table('leave_types')->whereIn('name',['مرضية','بدون أجر'])->update(['deducts_annual_balance'=>false]);
            DB::table('leave_types')->where('name','بدون أجر')->update(['is_paid'=>false]);
        }
        if (Schema::hasTable('penalty_types')) {
            foreach ([['إنذار',0],['نصف يوم',.5],['يوم',1],['يومان',2],['3 أيام',3]] as [$name,$days]) DB::table('penalty_types')->where('name',$name)->update(['default_deduction_days'=>$days]);
        }
    }

    public function down(): void
    {
        // Reference data is intentionally retained; only additive columns are reversed.
        if (Schema::hasTable('payroll_records')) Schema::table('payroll_records', fn (Blueprint $table) => $table->dropColumn(['absence_days','penalty_days','ot_day_hours','ot_night_hours','ot_day_amount','ot_night_amount','bonus','loans','other_earnings','other_deductions']));
        if (Schema::hasTable('attendance')) Schema::table('attendance', fn (Blueprint $table) => $table->dropColumn(['early_leave_minutes','worked_hours']));
        if (Schema::hasTable('penalties')) Schema::table('penalties', fn (Blueprint $table) => $table->dropColumn('deduction_days'));
        if (Schema::hasTable('penalty_types')) Schema::table('penalty_types', fn (Blueprint $table) => $table->dropColumn('default_deduction_days'));
        if (Schema::hasTable('leave_types')) Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn(['deducts_annual_balance','is_paid']));
    }
};
