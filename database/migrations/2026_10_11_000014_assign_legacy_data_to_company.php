<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) return;

        $company = DB::table('companies')->oldest('id')->first();
        if (!$company) {
            $slug = 'legacy-company-'.Str::lower(Str::random(8));
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'الشركة الحالية',
                'slug' => $slug,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $company = DB::table('companies')->where('id', $companyId)->first();
        }

        $companyId = (int) $company->id;
        $planId = DB::table('subscription_plans')->orderByDesc('employee_limit')->value('id');
        if ($planId && Schema::hasTable('company_subscriptions') && !DB::table('company_subscriptions')->where('company_id', $companyId)->exists()) {
            DB::table('company_subscriptions')->insert([
                'company_id' => $companyId,
                'subscription_plan_id' => $planId,
                'status' => 'active',
                'starts_at' => today()->toDateString(),
                'ends_at' => null,
                'trial_days' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tables = [
            'users', 'employees', 'attendances', 'documents', 'employee_bank_accounts',
            'employee_emergency_contacts', 'employee_profile_details', 'hr_requests',
            'insurance_records', 'leaves', 'movement_records', 'payroll_records',
            'penalties', 'salaries', 'payroll_runs', 'leave_policy_settings',
            'hr_operational_settings', 'hr_holidays', 'insurance_organization_settings',
            'payroll_calculation_settings', 'payroll_allowance_settings',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        }
    }

    public function down(): void
    {
        // لا نحذف بيانات الشركة عند التراجع حتى لا نفقد بيانات المستخدمين القديمة.
    }
};
