<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_calculation_settings')) {
            return;
        }

        // The policy is fixed: profit share is fully exempt; all other
        // variable earnings remain taxable in PayrollCalculator.
        DB::table('payroll_calculation_settings')
            ->where('setting_group', 'tax')
            ->where('setting_key', 'profit_share_exempt_percent')
            ->update(['setting_value' => '100', 'is_active' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not restore an unknown previous tax policy.
    }
};
