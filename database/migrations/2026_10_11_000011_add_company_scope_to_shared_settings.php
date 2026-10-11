<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $companyId = DB::table('companies')->value('id');
        $tables = ['leave_policy_settings','hr_operational_settings','hr_holidays','insurance_organization_settings','payroll_calculation_settings','payroll_allowance_settings'];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) continue;
            Schema::table($table, function (Blueprint $blueprint): void { $blueprint->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete(); });
            if ($companyId) DB::table($table)->whereNull('company_id')->update(['company_id'=>$companyId]);
        }
        foreach (['leave_policy_settings','hr_operational_settings'] as $table) {
            if (!Schema::hasTable($table)) continue;
            $indexes = DB::select("SHOW INDEX FROM {$table}");
            foreach ($indexes as $index) if ((int) $index->Non_unique === 0 && $index->Key_name !== 'PRIMARY') DB::statement("ALTER TABLE {$table} DROP INDEX `{$index->Key_name}`");
            Schema::table($table, function (Blueprint $blueprint) use ($table): void { $column = $table === 'leave_policy_settings' ? 'setting_key' : 'setting_key'; $blueprint->unique(['company_id', $column], $table.'_company_key_unique'); });
        }
    }
    public function down(): void
    {
        foreach (['leave_policy_settings','hr_operational_settings','hr_holidays','insurance_organization_settings','payroll_calculation_settings','payroll_allowance_settings'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) Schema::table($table, function (Blueprint $blueprint): void { $blueprint->dropForeign(['company_id']); $blueprint->dropColumn('company_id'); });
    }
};
