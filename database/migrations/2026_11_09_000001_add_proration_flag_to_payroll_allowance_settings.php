<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-allowance proration control: whether the allowance is prorated with the
 * worked part of the month (hire / termination / accrued-to-date) or paid in
 * full. Existing rows keep the current behavior (prorated).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payroll_allowance_settings', 'is_prorated')) {
            Schema::table('payroll_allowance_settings', function ($table) {
                $table->boolean('is_prorated')->default(true)->after('percentage');
            });
        }
        DB::table('payroll_allowance_settings')->whereNull('is_prorated')->update(['is_prorated' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_allowance_settings', 'is_prorated')) {
            Schema::table('payroll_allowance_settings', function ($table) {
                $table->dropColumn('is_prorated');
            });
        }
    }
};
