<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('employees')) Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'termination_date')) $table->date('termination_date')->nullable();
            if (!Schema::hasColumn('employees', 'shift_start')) $table->time('shift_start')->nullable();
            if (!Schema::hasColumn('employees', 'shift_end')) $table->time('shift_end')->nullable();
            if (!Schema::hasColumn('employees', 'manual_annual_balance')) $table->decimal('manual_annual_balance', 6, 2)->nullable();
        });
        if (!Schema::hasTable('hr_operational_settings')) {
            Schema::create('hr_operational_settings', function (Blueprint $table) {
                $table->id(); $table->string('setting_key')->unique(); $table->string('setting_value'); $table->string('label'); $table->timestamps();
            });
        }
        foreach ([
            ['default_shift_start','09:00','بداية الدوام الافتراضية'], ['default_shift_end','17:00','نهاية الدوام الافتراضية'],
            ['grace_minutes','15','فترة السماح بالدقائق'], ['weekly_rest_pattern','0000100','الراحة الأسبوعية (الإثنين إلى الأحد)'],
            ['unpaid_leave_as_deduction','1','ترحيل الإجازة غير المدفوعة كغياب'], ['leave_exclude_rest_holidays','1','استبعاد الراحة والعطلات من الإجازات'],
            ['annual_leave_less_than_year','15','رصيد أقل من سنة'], ['annual_leave_under_ten_years','21','رصيد من سنة إلى أقل من عشر'], ['annual_leave_ten_years_plus','30','رصيد عشر سنوات فأكثر'],
        ] as [$key,$value,$label]) DB::table('hr_operational_settings')->updateOrInsert(['setting_key'=>$key], ['setting_value'=>$value,'label'=>$label,'updated_at'=>now(),'created_at'=>now()]);
    }
    public function down(): void
    {
        Schema::dropIfExists('hr_operational_settings');
        if (Schema::hasTable('employees')) Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['termination_date','shift_start','shift_end','manual_annual_balance']));
    }
};
