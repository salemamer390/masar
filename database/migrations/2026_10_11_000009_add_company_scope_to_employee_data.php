<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['attendances','documents','employee_bank_accounts','employee_emergency_contacts','employee_profile_details','hr_requests','insurance_records','leaves','movement_records','payroll_records','penalties','salaries','payroll_runs'];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) continue;
            Schema::table($table, function (Blueprint $blueprint): void { $blueprint->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete(); });
            if (Schema::hasColumn($table, 'employee_id')) DB::statement("UPDATE {$table} t INNER JOIN employees e ON e.id=t.employee_id SET t.company_id=e.company_id WHERE t.company_id IS NULL");
        }
    }
    public function down(): void
    {
        foreach (['attendances','documents','employee_bank_accounts','employee_emergency_contacts','employee_profile_details','hr_requests','insurance_records','leaves','movement_records','payroll_records','penalties','salaries','payroll_runs'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) Schema::table($table, function (Blueprint $blueprint): void { $blueprint->dropForeign(['company_id']); $blueprint->dropColumn('company_id'); });
    }
};
