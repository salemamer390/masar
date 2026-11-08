<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The disciplinary policy may also deduct the wage of the lost time itself
        // (gross / 240 per hour, prorated by minutes) as a separate line.
        if (Schema::hasTable('attendance_penalty_policies') && !Schema::hasColumn('attendance_penalty_policies', 'deduct_time_wage')) {
            Schema::table('attendance_penalty_policies', function (Blueprint $table) {
                $table->boolean('deduct_time_wage')->default(false)->after('manual_replaces_types');
            });
        }
        if (Schema::hasTable('payroll_records') && !Schema::hasColumn('payroll_records', 'attendance_time_deduction')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('attendance_time_deduction', 12, 2)->default(0)->after('attendance_deduction');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_penalty_policies') && Schema::hasColumn('attendance_penalty_policies', 'deduct_time_wage')) {
            Schema::table('attendance_penalty_policies', fn (Blueprint $table) => $table->dropColumn('deduct_time_wage'));
        }
        if (Schema::hasTable('payroll_records') && Schema::hasColumn('payroll_records', 'attendance_time_deduction')) {
            Schema::table('payroll_records', fn (Blueprint $table) => $table->dropColumn('attendance_time_deduction'));
        }
    }
};
