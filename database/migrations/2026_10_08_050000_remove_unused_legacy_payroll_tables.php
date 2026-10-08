<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            // Replaced by payroll_calculation_settings, tax_categories and tax_brackets.
            'payroll_settings',
            'tax_settings',
            'insurance_settings',
            'tax_year_settings',
            // Empty legacy tables with no active model, controller, or service consumer.
            'employee_allowances',
            'employee_tax_years',
            'salary_history',
            'payroll_snapshots',
            'payroll_calculation_snapshots',
            'payroll_items',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // This is a deliberate retirement of obsolete data structures.
    }
};
