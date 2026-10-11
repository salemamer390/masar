<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')->whereIn('name', ['سنوية', 'مرضية', 'عارضة', 'بدون أجر'])->update(['is_active' => false]);
        DB::table('leave_types')->where('policy_code', 'annual')->update(['default_days' => 15, 'max_days_per_request' => 15, 'min_service_months' => 6, 'deducts_annual_balance' => true, 'is_paid' => true]);
        DB::table('leave_types')->where('policy_code', 'casual')->update(['default_days' => 7, 'max_days_per_request' => 2, 'max_days_per_year' => 7, 'min_service_months' => 6, 'deducts_annual_balance' => true, 'is_paid' => true]);
        DB::table('leave_types')->where('policy_code', 'maternity')->update(['default_days' => 120, 'max_days_per_request' => 120, 'max_occurrences_service' => 3, 'requires_document' => true, 'is_paid' => true]);
        DB::table('leave_types')->where('policy_code', 'childcare')->update(['max_days_per_request' => 730, 'max_occurrences_service' => 3, 'min_service_months' => 12, 'min_employees' => 50, 'requires_document' => true, 'is_paid' => false]);
        DB::table('leave_types')->where('policy_code', 'newborn')->update(['max_days_per_request' => 1, 'max_occurrences_service' => 3, 'requires_document' => true, 'is_paid' => true]);
        DB::table('leave_types')->where('policy_code', 'pilgrimage')->update(['max_days_per_request' => 30, 'max_occurrences_service' => 1, 'min_service_months' => 60, 'requires_document' => true, 'is_paid' => true]);
        DB::table('leave_types')->where('policy_code', 'exams')->update(['requires_document' => true, 'is_paid' => true]);
    }

    public function down(): void
    {
        DB::table('leave_types')->whereIn('name', ['سنوية', 'مرضية', 'عارضة', 'بدون أجر'])->update(['is_active' => true]);
    }
};
