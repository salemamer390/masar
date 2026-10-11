<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $companyId = DB::table('companies')->value('id');
        if (!$companyId) return;
        foreach (['payroll_runs','hr_operational_settings','hr_holidays','insurance_organization_settings'] as $table) if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) DB::table($table)->whereNull('company_id')->update(['company_id'=>$companyId]);
        if (Schema::hasTable('payroll_runs') && Schema::hasTable('payroll_records')) DB::statement("UPDATE payroll_runs r INNER JOIN (SELECT payroll_run_id, MIN(company_id) company_id FROM payroll_records WHERE company_id IS NOT NULL GROUP BY payroll_run_id) p ON p.payroll_run_id=r.id SET r.company_id=p.company_id WHERE r.company_id IS NULL");
    }
    public function down(): void {}
};
