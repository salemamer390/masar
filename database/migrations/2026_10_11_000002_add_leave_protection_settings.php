<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'annual_prorate_first_year' => '0', 'maternity_days' => '120', 'maternity_min_postpartum_days' => '45',
            'maternity_max_occurrences' => '3', 'pregnancy_reduced_hours_from_month' => '6',
            'pregnancy_reduced_hours_per_day' => '1', 'pregnancy_overtime_protection_months' => '6',
            'nursing_sessions_per_day' => '2', 'nursing_minutes_per_session' => '30',
            'childcare_max_years_per_request' => '2', 'childcare_max_occurrences' => '3',
            'pilgrimage_min_service_months' => '60', 'newborn_max_occurrences' => '3',
        ] as $key => $value) {
            DB::table('leave_policy_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => $value, 'label' => $key, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('leave_policy_settings')->whereIn('setting_key', [
            'maternity_days', 'maternity_min_postpartum_days', 'maternity_max_occurrences', 'pregnancy_reduced_hours_from_month',
            'pregnancy_reduced_hours_per_day', 'pregnancy_overtime_protection_months', 'nursing_sessions_per_day',
            'nursing_minutes_per_session', 'childcare_max_years_per_request', 'childcare_max_occurrences',
            'pilgrimage_min_service_months', 'newborn_max_occurrences',
        ])->delete();
    }
};
