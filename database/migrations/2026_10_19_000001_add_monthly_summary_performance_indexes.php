<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $add = function (string $tableName, array $columns, string $indexName): void {
            if (!Schema::hasTable($tableName)) return;
            $exists = DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]);
            if (!$exists) Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $indexName));
        };
        $add('employees', ['company_id', 'department_id', 'id'], 'employees_company_department_id_index');
        $add('employees', ['company_id', 'contract_end_date'], 'employees_company_contract_end_index');
        $add('attendance', ['employee_id', 'attendance_date', 'approval_status'], 'attendance_monthly_summary_index');
        $add('leaves', ['employee_id', 'status', 'start_date', 'end_date'], 'leaves_monthly_summary_index');
        $add('penalties', ['employee_id', 'penalty_date', 'status'], 'penalties_monthly_summary_index');
        $add('monthly_summary_snapshots', ['company_id', 'summary_month'], 'snapshots_company_month_index');
    }

    public function down(): void
    {
        foreach ([
            'employees' => ['employees_company_department_id_index', 'employees_company_contract_end_index'],
            'attendance' => ['attendance_monthly_summary_index'],
            'leaves' => ['leaves_monthly_summary_index'],
            'penalties' => ['penalties_monthly_summary_index'],
            'monthly_summary_snapshots' => ['snapshots_company_month_index'],
        ] as $tableName => $indexes) {
            if (!Schema::hasTable($tableName)) continue;
            foreach ($indexes as $indexName) {
                $exists = DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]);
                if ($exists) Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($indexName));
            }
        }
    }
};
