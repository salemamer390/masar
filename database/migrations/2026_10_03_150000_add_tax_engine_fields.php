<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['salaries', 'payroll_records'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'annual_tax')) {
                    $t->decimal('annual_tax', 12, 2)->default(0)->after('income_tax');
                }
                if (!Schema::hasColumn($table, 'taxable_annual')) {
                    $t->decimal('taxable_annual', 12, 2)->default(0)->after('annual_tax');
                }
                if (!Schema::hasColumn($table, 'tax_category')) {
                    $t->string('tax_category', 50)->nullable()->after('taxable_annual');
                }
                if (!Schema::hasColumn($table, 'tax_deductible_contributions')) {
                    $t->decimal('tax_deductible_contributions', 12, 2)->default(0)->after('tax_category');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['salaries', 'payroll_records'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $columns = [];
                foreach (['annual_tax','taxable_annual','tax_category','tax_deductible_contributions'] as $c) {
                    if (Schema::hasColumn($table, $c)) $columns[] = $c;
                }
                if ($columns) $t->dropColumn($columns);
            });
        }
    }
};
