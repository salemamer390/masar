<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $salaryColumns = [
            'salary_type' => "VARCHAR(20) NOT NULL DEFAULT 'net'",
            'salary_input' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'fixed_deductions' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'insurance_wage' => 'DECIMAL(12,2) NULL',
            'no_insurance' => "TINYINT(1) NOT NULL DEFAULT 0",
            'excluded_allowance_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 30',
            'personal_exemption' => 'DECIMAL(12,2) NOT NULL DEFAULT 20000',
            'tax_treatment' => "VARCHAR(30) NOT NULL DEFAULT 'egyptian'",
        ];

        foreach ($salaryColumns as $column => $definition) {
            if (!Schema::hasColumn('salaries', $column)) {
                DB::unprepared("ALTER TABLE salaries ADD COLUMN {$column} {$definition}");
            }
        }

        $payrollColumns = [
            'gross_salary' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'insurance_wage' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'employee_insurance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'income_tax' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'martyrs_fund' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'employer_insurance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'employee_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'fixed_deductions' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'attendance_deduction' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        ];

        foreach ($payrollColumns as $column => $definition) {
            if (!Schema::hasColumn('payroll_records', $column)) {
                DB::unprepared("ALTER TABLE payroll_records ADD COLUMN {$column} {$definition}");
            }
        }

        // Existing salary rows in this project represent the agreed net salary.
        if (Schema::hasColumn('salaries', 'salary_type')) {
            DB::table('salaries')->whereNull('salary_type')->orWhere('salary_type', '')->update(['salary_type' => 'net']);
            DB::statement("UPDATE salaries SET salary_input = net_salary WHERE salary_input = 0 OR salary_input IS NULL");

            $calculator = new \App\Services\PayrollCalculator();
            foreach (DB::table('salaries')->get() as $row) {
                $result = $calculator->calculate(
                    $row->salary_type ?: 'net',
                    (float)$row->salary_input,
                    $row->insurance_wage !== null ? (float)$row->insurance_wage : null,
                    (bool)$row->no_insurance,
                    (float)$row->excluded_allowance_pct,
                    (float)$row->personal_exemption,
                    $row->tax_treatment ?: 'egyptian'
                );
                DB::table('salaries')->where('id', $row->id)->update([
                    'gross_salary' => $result['gross'],
                    'net_salary' => $result['net'] - (float)($row->fixed_deductions ?? 0),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['salary_type','salary_input','fixed_deductions','insurance_wage','no_insurance','excluded_allowance_pct','personal_exemption','tax_treatment'] as $column) {
            if (Schema::hasColumn('salaries', $column)) Schema::table('salaries', fn($table) => $table->dropColumn($column));
        }
        foreach (['gross_salary','insurance_wage','employee_insurance','income_tax','martyrs_fund','employer_insurance','employee_cost','fixed_deductions','attendance_deduction'] as $column) {
            if (Schema::hasColumn('payroll_records', $column)) Schema::table('payroll_records', fn($table) => $table->dropColumn($column));
        }
    }
};
