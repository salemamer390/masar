<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_operational_settings')) {
            return;
        }

        if (!Schema::hasColumn('hr_operational_settings', 'company_id')) {
            Schema::table('hr_operational_settings', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }

        $companies = DB::table('companies')->pluck('id');
        foreach ($companies as $companyId) {
            DB::table('hr_operational_settings')->updateOrInsert(
                ['company_id' => $companyId, 'setting_key' => 'payroll_period_start_day'],
                ['setting_value' => '1', 'label' => 'بداية فترة المسير (يوم الشهر)', 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('hr_operational_settings')->updateOrInsert(
                ['company_id' => $companyId, 'setting_key' => 'payroll_period_end_day'],
                ['setting_value' => '30', 'label' => 'نهاية فترة المسير (يوم الشهر)', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_operational_settings')) {
            DB::table('hr_operational_settings')->whereIn('setting_key', [
                'payroll_period_start_day',
                'payroll_period_end_day',
            ])->delete();
        }
    }
};
