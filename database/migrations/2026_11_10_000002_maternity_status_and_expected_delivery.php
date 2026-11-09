<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maternity guided flow (Amer's 2026-11-09 answer): an employee status named
 * «اجازة وضع» plus the expected delivery date stored on the maternity leave.
 * The expected date (plan) stays a separate column from birth_date (fact);
 * pregnancy rules read the expected date, nursing and postpartum rules read
 * the recorded birth date, and the two are never conflated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leaves', 'expected_delivery_date')) {
            Schema::table('leaves', function (Blueprint $table) {
                $table->date('expected_delivery_date')->nullable()->after('birth_date');
            });
        }
        foreach (['expected_delivery_date', 'pregnancy_reduction_start'] as $column) {
            if (!Schema::hasColumn('employees', $column)) {
                Schema::table('employees', function (Blueprint $table) use ($column) {
                    // Pregnancy dates HR records from the medical certificate on
                    // the employee file (Amer 2026-11-09): rights apply while she
                    // keeps working, before any maternity leave exists. The
                    // guided maternity action copies them onto the leave and
                    // clears them here, so stale data cannot re-activate later.
                    $table->date($column)->nullable();
                });
            }
        }
        if (!Schema::hasColumn('leaves', 'pregnancy_reduction_start')) {
            Schema::table('leaves', function (Blueprint $table) {
                // Optional medically grounded start of the pregnancy hour
                // reduction, entered from the medical certificate. When absent
                // the reduction start is computed from the expected date (a
                // documented estimate, labeled as such in the UI).
                $table->date('pregnancy_reduction_start')->nullable()->after('expected_delivery_date');
            });
        }
        if (!DB::table('employee_statuses')->where('name', 'اجازة وضع')->exists()) {
            DB::table('employee_statuses')->insert(['name' => 'اجازة وضع']);
        }
    }

    public function down(): void
    {
        foreach (['pregnancy_reduction_start', 'expected_delivery_date'] as $column) {
            if (Schema::hasColumn('leaves', $column)) {
                Schema::table('leaves', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
        foreach (['pregnancy_reduction_start', 'expected_delivery_date'] as $column) {
            if (Schema::hasColumn('employees', $column)) {
                Schema::table('employees', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
        DB::table('employee_statuses')->where('name', 'اجازة وضع')->delete();
    }
};
