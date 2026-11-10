<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove the legacy shared leave types that have no basis in Labor Law 14/2025
     * (Mission, Bereavement) from the settings UI and new request choices.
     * Rows stay in place: previous leave records and payroll history keep working.
     * Unpaid stays active on purpose: it has no settings card anymore, but new
     * unpaid requests and the payroll deduction still need the type available.
     */
    private const LEGACY_NAMES = ['Mission', 'Bereavement', 'مهمة عمل (Mission)', 'إجازة وفاة (Bereavement)'];

    public function up(): void
    {
        DB::table('leave_types')->whereNull('company_id')->whereIn('name', self::LEGACY_NAMES)->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('leave_types')->whereNull('company_id')->whereIn('name', self::LEGACY_NAMES)->update(['is_active' => true]);
    }
};
