<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * payroll_calculation_rules gained an effective_from column in production so the
     * settings screen can date each rule change; environments that created the table
     * before that change lack the column and the rules save fails there.
     */
    public function up(): void
    {
        if (Schema::hasTable('payroll_calculation_rules') && !Schema::hasColumn('payroll_calculation_rules', 'effective_from')) {
            Schema::table('payroll_calculation_rules', function (Blueprint $table) {
                $table->date('effective_from')->nullable()->after('rule_value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_calculation_rules') && Schema::hasColumn('payroll_calculation_rules', 'effective_from')) {
            Schema::table('payroll_calculation_rules', function (Blueprint $table) {
                $table->dropColumn('effective_from');
            });
        }
    }
};
