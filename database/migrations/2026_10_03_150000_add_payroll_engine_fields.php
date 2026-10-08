<?php

use App\Services\PayrollCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('salaries', 'tax_deductible_contributions')) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->decimal('tax_deductible_contributions', 12, 2)->default(0)->after('employee_cost');
            });
        }
        if (!Schema::hasColumn('salaries', 'annual_tax')) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->decimal('annual_tax', 12, 2)->default(0)->after('tax_deductible_contributions');
            });
        }
        if (!Schema::hasColumn('salaries', 'tax_category')) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->string('tax_category', 30)->nullable()->after('annual_tax');
            });
        }

        if (!Schema::hasColumn('payroll_records', 'salary_id')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->unsignedBigInteger('salary_id')->nullable()->after('employee_id');
            });
        }
        if (!Schema::hasColumn('payroll_records', 'agreed_net_salary')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('agreed_net_salary', 12, 2)->default(0)->after('salary_id');
            });
        }
        if (!Schema::hasColumn('payroll_records', 'tax_deductible_contributions')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('tax_deductible_contributions', 12, 2)->default(0)->after('agreed_net_salary');
            });
        }
        if (!Schema::hasColumn('payroll_records', 'taxable_annual')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('taxable_annual', 12, 2)->default(0)->after('tax_deductible_contributions');
            });
        }
        if (!Schema::hasColumn('payroll_records', 'annual_tax')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('annual_tax', 12, 2)->default(0)->after('taxable_annual');
            });
        }
        if (!Schema::hasColumn('payroll_records', 'tax_category')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->string('tax_category', 30)->nullable()->after('annual_tax');
            });
        }

        if (Schema::hasColumn('payroll_records', 'salary_id') && ! $this->foreignKeyExists('payroll_records', 'fk_payroll_salary')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->foreign('salary_id', 'fk_payroll_salary')->references('id')->on('salaries')->nullOnDelete();
            });
        }

        // Rebuild existing salary snapshots using the same engine that new records use.
        $calculator = app(PayrollCalculator::class);
        if (Schema::hasTable('salaries')) {
            foreach (DB::table('salaries')->get() as $row) {
                $input = (float)($row->salary_input ?? $row->net_salary ?? 0);
                if ($input <= 0) continue;
                $result = $calculator->calculate('net', $input, null, (bool)($row->no_insurance ?? false), 25, null, 'egyptian', (float)($row->tax_deductible_contributions ?? 0));
                $allowances = (float)($row->allowances ?? 0);
                DB::table('salaries')->where('id', $row->id)->update([
                    'salary_type' => 'net',
                    'salary_input' => $input,
                    'basic_salary' => round($result['gross'] - $allowances, 2),
                    'gross_salary' => $result['gross'],
                    'insurance_wage' => $result['insurance_wage'],
                    'employee_insurance' => $result['employee_insurance'],
                    'income_tax' => $result['income_tax'],
                    'taxable_annual' => $result['taxable_annual'],
                    'annual_tax' => $result['annual_tax'],
                    'tax_category' => $result['tax_category'],
                    'martyrs_fund' => $result['martyrs_fund'],
                    'total_deductions' => round($result['statutory_deductions'] + (float)($row->fixed_deductions ?? 0), 2),
                    'net_salary' => round(max(0, $result['net'] - (float)($row->fixed_deductions ?? 0)), 2),
                    'employer_insurance' => $result['employer_insurance'],
                    'employee_cost' => $result['employee_cost'],
                    'excluded_allowance_pct' => 0,
                    'personal_exemption' => $result['personal_exemption'],
                    'tax_treatment' => 'egyptian',
                    'tax_deductible_contributions' => $result['tax_deductible_contributions'],
                    'allowance_limit_pct' => 25,
                    'allowance_limit' => round($input * 0.25, 2),
                    'allowance_remaining' => round($input * 0.25 - $allowances, 2),
                ]);
            }
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') return false;
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_records') && $this->foreignKeyExists('payroll_records', 'fk_payroll_salary')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->dropForeign('fk_payroll_salary');
            });
        }
        foreach (['tax_category','annual_tax','tax_deductible_contributions'] as $column) {
            if (Schema::hasColumn('salaries', $column)) Schema::table('salaries', fn(Blueprint $table) => $table->dropColumn($column));
        }
        foreach (['tax_category','annual_tax','taxable_annual','tax_deductible_contributions','agreed_net_salary','salary_id'] as $column) {
            if (Schema::hasColumn('payroll_records', $column)) Schema::table('payroll_records', fn(Blueprint $table) => $table->dropColumn($column));
        }
    }
};
