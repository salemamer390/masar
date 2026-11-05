<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // The date this run finally closes (period end + the correction grace in force when set).
            $table->date('closes_at')->nullable()->after('locked_at');
            // Runs auto-created to bridge a payroll-cycle change.
            $table->boolean('is_transition')->default(false)->after('closes_at');
        });

        // Backfill the close date of still-open runs from their stored period and the
        // company correction grace (default 3 days), so existing runs close on the
        // same unified schedule without any manual action.
        $runs = DB::table('payroll_runs')->whereNull('closes_at')->whereNotNull('period_end')
            ->whereIn('status', ['Draft', 'Approved'])->get(['id', 'company_id', 'period_end']);
        foreach ($runs as $run) {
            $grace = (int) (DB::table('hr_operational_settings')
                ->where('company_id', $run->company_id)->where('setting_key', 'attendance_correction_days')
                ->value('setting_value') ?? 3);
            DB::table('payroll_runs')->where('id', $run->id)
                ->update(['closes_at' => date('Y-m-d', strtotime($run->period_end . ' +' . (max(0, $grace) + 1) . ' days'))]);
        }
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['closes_at', 'is_transition']);
        });
    }
};
