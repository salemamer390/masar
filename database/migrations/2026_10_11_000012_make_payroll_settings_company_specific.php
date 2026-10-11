<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_calculation_settings')) { DB::statement('ALTER TABLE payroll_calculation_settings DROP INDEX uq_payroll_calculation_setting'); Schema::table('payroll_calculation_settings', fn (Blueprint $table) => $table->unique(['company_id','setting_group','setting_key'],'payroll_settings_company_key_unique')); }
        if (Schema::hasTable('payroll_allowance_settings')) { DB::statement('ALTER TABLE payroll_allowance_settings DROP INDEX uq_allowance_key'); Schema::table('payroll_allowance_settings', fn (Blueprint $table) => $table->unique(['company_id','allowance_key'],'allowance_settings_company_key_unique')); }
    }
    public function down(): void
    {
        if (Schema::hasTable('payroll_calculation_settings')) { Schema::table('payroll_calculation_settings', fn (Blueprint $table) => $table->dropUnique('payroll_settings_company_key_unique')); DB::statement('ALTER TABLE payroll_calculation_settings ADD UNIQUE uq_payroll_calculation_setting (setting_group,setting_key)'); }
        if (Schema::hasTable('payroll_allowance_settings')) { Schema::table('payroll_allowance_settings', fn (Blueprint $table) => $table->dropUnique('allowance_settings_company_key_unique')); DB::statement('ALTER TABLE payroll_allowance_settings ADD UNIQUE uq_allowance_key (allowance_key)'); }
    }
};
