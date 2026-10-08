<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'allowance_limit_pct' => fn (Blueprint $t) => $t->decimal('allowance_limit_pct', 5, 2)->default(25)->after('allowances'),
            'allowance_limit' => fn (Blueprint $t) => $t->decimal('allowance_limit', 12, 2)->default(0)->after('allowance_limit_pct'),
            'allowance_remaining' => fn (Blueprint $t) => $t->decimal('allowance_remaining', 12, 2)->default(0)->after('allowance_limit'),
            'meal_allowance' => fn (Blueprint $t) => $t->decimal('meal_allowance', 12, 2)->default(0)->after('transportation_allowance'),
            'employee_insurance' => fn (Blueprint $t) => $t->decimal('employee_insurance', 12, 2)->default(0)->after('insurance_wage'),
            'income_tax' => fn (Blueprint $t) => $t->decimal('income_tax', 12, 2)->default(0)->after('employee_insurance'),
            'taxable_annual' => fn (Blueprint $t) => $t->decimal('taxable_annual', 12, 2)->default(0)->after('income_tax'),
            'martyrs_fund' => fn (Blueprint $t) => $t->decimal('martyrs_fund', 12, 2)->default(0)->after('taxable_annual'),
            'total_deductions' => fn (Blueprint $t) => $t->decimal('total_deductions', 12, 2)->default(0)->after('martyrs_fund'),
            'employer_insurance' => fn (Blueprint $t) => $t->decimal('employer_insurance', 12, 2)->default(0)->after('total_deductions'),
            'employee_cost' => fn (Blueprint $t) => $t->decimal('employee_cost', 12, 2)->default(0)->after('employer_insurance'),
        ];

        foreach ($columns as $name => $definition) {
            if (!Schema::hasColumn('salaries', $name)) {
                Schema::table('salaries', $definition);
            }
        }

        // All agreed salaries currently supplied for Tazamun are Net salaries.
        if (Schema::hasColumn('salaries', 'salary_type')) {
            DB::table('salaries')->update(['salary_type' => 'net']);
        }
    }

    public function down(): void
    {
        foreach (array_keys([
            'allowance_limit_pct' => true, 'allowance_limit' => true, 'allowance_remaining' => true,
            'meal_allowance' => true, 'employee_insurance' => true, 'income_tax' => true,
            'taxable_annual' => true, 'martyrs_fund' => true, 'total_deductions' => true,
            'employer_insurance' => true, 'employee_cost' => true,
        ]) as $column) {
            if (Schema::hasColumn('salaries', $column)) {
                Schema::table('salaries', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
