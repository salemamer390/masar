<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('value');
                $table->string('label')->nullable();
                $table->timestamps();
            });
        }

        $defaults = [
            'allowance_rate' => ['0.25','نسبة البدلات'],
            'employee_insurance_rate' => ['0.11','نسبة تأمين الموظف'],
            'employer_insurance_rate' => ['0.1875','نسبة تأمين صاحب العمل'],
            'martyrs_fund_rate' => ['0.0005','نسبة صندوق الشهداء'],
            'personal_exemption' => ['20000','الإعفاء الشخصي السنوي'],
            'insurance_minimum' => ['2700','الحد الأدنى لأجر التأمين'],
            'insurance_maximum' => ['16700','الحد الأقصى لأجر التأمين'],
            'day_overtime_multiplier' => ['1.35','معامل الإضافي النهاري'],
            'night_overtime_multiplier' => ['1.70','معامل الإضافي الليلي'],
            'official_holiday_multiplier' => ['2','معامل الإجازة الرسمية'],
            'weekly_holiday_multiplier' => ['1','معامل الإجازة الأسبوعية'],
            'hours_divisor' => ['240','مقسم سعر الساعة'],
            'days_divisor' => ['30','مقسم سعر اليوم'],
            'tax_categories' => [json_encode([600000,700000,800000,900000,1200000]),'حدود فئات الدخل الضريبي'],
            'tax_brackets_1' => [json_encode([[0,40000,0],[40000,55000,.10],[55000,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]]),'شرائح الفئة الضريبية 1'],
            'tax_brackets_2' => [json_encode([[0,55000,.10],[55000,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]]),'شرائح الفئة الضريبية 2'],
            'tax_brackets_3' => [json_encode([[0,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]]),'شرائح الفئة الضريبية 3'],
            'tax_brackets_4' => [json_encode([[0,200000,.20],[200000,400000,.225],[400000,null,.25]]),'شرائح الفئة الضريبية 4'],
            'tax_brackets_5' => [json_encode([[0,400000,.225],[400000,null,.25]]),'شرائح الفئة الضريبية 5'],
            'tax_brackets_6' => [json_encode([[0,1200000,.25],[1200000,null,.275]]),'شرائح الفئة الضريبية 6'],
        ];

        foreach ($defaults as $key => [$value, $label]) {
            DB::table('payroll_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'label' => $label, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $salaryColumns = [
            'clothing_allowance' => fn(Blueprint $t) => $t->decimal('clothing_allowance',12,2)->default(0),
        ];

        foreach ($salaryColumns as $name => $definition) {
            if (!Schema::hasColumn('salaries', $name)) {
                Schema::table('salaries', $definition);
            }
        }

        $payrollColumns = [
            'clothing_allowance'=>fn(Blueprint $t)=>$t->decimal('clothing_allowance',12,2)->default(0),
            'incentives'=>fn(Blueprint $t)=>$t->decimal('incentives',12,2)->default(0),
            'commission'=>fn(Blueprint $t)=>$t->decimal('commission',12,2)->default(0),
            'profit_share'=>fn(Blueprint $t)=>$t->decimal('profit_share',12,2)->default(0),
            'other_earnings'=>fn(Blueprint $t)=>$t->decimal('other_earnings',12,2)->default(0),
            'day_overtime_hours'=>fn(Blueprint $t)=>$t->decimal('day_overtime_hours',10,2)->default(0),
            'day_overtime_value'=>fn(Blueprint $t)=>$t->decimal('day_overtime_value',12,2)->default(0),
            'night_overtime_hours'=>fn(Blueprint $t)=>$t->decimal('night_overtime_hours',10,2)->default(0),
            'night_overtime_value'=>fn(Blueprint $t)=>$t->decimal('night_overtime_value',12,2)->default(0),
            'official_holiday_days'=>fn(Blueprint $t)=>$t->decimal('official_holiday_days',10,2)->default(0),
            'official_holiday_value'=>fn(Blueprint $t)=>$t->decimal('official_holiday_value',12,2)->default(0),
            'weekly_holiday_days'=>fn(Blueprint $t)=>$t->decimal('weekly_holiday_days',10,2)->default(0),
            'weekly_holiday_value'=>fn(Blueprint $t)=>$t->decimal('weekly_holiday_value',12,2)->default(0),
            'total_overtime'=>fn(Blueprint $t)=>$t->decimal('total_overtime',12,2)->default(0),
            'total_earnings'=>fn(Blueprint $t)=>$t->decimal('total_earnings',12,2)->default(0),
            'annual_gross'=>fn(Blueprint $t)=>$t->decimal('annual_gross',14,2)->default(0),
            'annual_employee_insurance'=>fn(Blueprint $t)=>$t->decimal('annual_employee_insurance',14,2)->default(0),
            'personal_exemption'=>fn(Blueprint $t)=>$t->decimal('personal_exemption',12,2)->default(20000),
            'tax_deductible_contributions'=>fn(Blueprint $t)=>$t->decimal('tax_deductible_contributions',12,2)->default(0),
            'profit_share_tax_adjustment'=>fn(Blueprint $t)=>$t->decimal('profit_share_tax_adjustment',12,2)->default(0),
            'taxable_income'=>fn(Blueprint $t)=>$t->decimal('taxable_income',14,2)->default(0),
            'tax_category'=>fn(Blueprint $t)=>$t->unsignedTinyInteger()->default(1),
            'annual_tax'=>fn(Blueprint $t)=>$t->decimal('annual_tax',14,2)->default(0),
            'monthly_tax'=>fn(Blueprint $t)=>$t->decimal('monthly_tax',12,2)->default(0),
            'penalties'=>fn(Blueprint $t)=>$t->decimal('penalties',12,2)->default(0),
            'advances'=>fn(Blueprint $t)=>$t->decimal('advances',12,2)->default(0),
            'other_deductions'=>fn(Blueprint $t)=>$t->decimal('other_deductions',12,2)->default(0),
            'calculation_snapshot'=>fn(Blueprint $t)=>$t->json('calculation_snapshot')->nullable(),
        ];

        foreach ($payrollColumns as $name => $definition) {
            if (!Schema::hasColumn('payroll_records', $name)) {
                Schema::table('payroll_records', $definition);
            }
        }

        $salaryExtra = [
            'tax_category'=>fn(Blueprint $t)=>$t->unsignedTinyInteger()->default(1),
            'annual_tax'=>fn(Blueprint $t)=>$t->decimal('annual_tax',14,2)->default(0),
            'monthly_tax'=>fn(Blueprint $t)=>$t->decimal('monthly_tax',12,2)->default(0),
            'calculation_snapshot'=>fn(Blueprint $t)=>$t->json('calculation_snapshot')->nullable(),
        ];

        foreach ($salaryExtra as $name => $definition) {
            if (!Schema::hasColumn('salaries', $name)) {
                Schema::table('salaries', $definition);
            }
        }

        /*
         * Recalculate existing salary structures using the actual PayrollCalculator.
         *
         * IMPORTANT:
         * The previous version called:
         *     calculateGrossForNet()
         * which does not exist in PayrollCalculator.
         *
         * The existing calculator already performs the approved Gross-up logic
         * internally when calculate('net', ...) is called.
         */
        if (Schema::hasTable('salaries')) {
            $calculator = new \App\Services\PayrollCalculator();

            foreach (DB::table('salaries')->get() as $row) {
                $net = (float)($row->salary_input ?? 0);

                if ($net <= 0) {
                    $net = (float)($row->net_salary ?? 0);
                }

                if ($net <= 0) {
                    continue;
                }

                $manualInsuranceWage = null;

                if (
                    isset($row->manual_insurance_wage) &&
                    $row->manual_insurance_wage !== null &&
                    $row->manual_insurance_wage !== ''
                ) {
                    $manualInsuranceWage = (float)$row->manual_insurance_wage;
                }

                $noInsurance = (bool)($row->no_insurance ?? false);

                $excludedAllowancePct = isset($row->excluded_allowance_pct)
                    ? (float)$row->excluded_allowance_pct
                    : 25.0;

                $personalExemption = isset($row->personal_exemption) &&
                    $row->personal_exemption !== null &&
                    $row->personal_exemption !== ''
                    ? (float)$row->personal_exemption
                    : 20000.0;

                $taxTreatment = (string)($row->tax_treatment ?? 'egyptian');

                $taxDeductibleContributions =
                    (float)($row->tax_deductible_contributions ?? 0);

                $result = $calculator->calculate(
                    'net',
                    $net,
                    $manualInsuranceWage,
                    $noInsurance,
                    $excludedAllowancePct,
                    $personalExemption,
                    $taxTreatment,
                    $taxDeductibleContributions
                );

                $allowances = (float)($row->allowances ?? 0);

                DB::table('salaries')->where('id', $row->id)->update([
                    'salary_type' => 'net',
                    'salary_input' => $net,

                    'allowance_limit_pct' => 25,
                    'allowance_limit' => round($net * 0.25, 2),
                    'allowance_remaining' => round(($net * 0.25) - $allowances, 2),

                    'gross_salary' => $result['gross'],
                    'basic_salary' => round(max(0, $result['gross'] - $allowances), 2),

                    'insurance_wage' => $result['insurance_wage'],
                    'employee_insurance' => $result['employee_insurance'],

                    'income_tax' => $result['income_tax'],
                    'taxable_annual' => $result['taxable_annual'],
                    'tax_category' => $result['tax_category'],
                    'annual_tax' => $result['annual_tax'],
                    'monthly_tax' => $result['income_tax'],

                    'martyrs_fund' => $result['martyrs_fund'],
                    'total_deductions' => $result['statutory_deductions'],

                    'net_salary' => $result['net'],

                    'employer_insurance' => $result['employer_insurance'],
                    'employee_cost' => $result['employee_cost'],

                    /*
                     * Save the complete calculation result so the old salary
                     * structure has a record of the values used during migration.
                     */
                    'calculation_snapshot' => json_encode($result),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (
            ['clothing_allowance','tax_category','annual_tax','monthly_tax','calculation_snapshot']
            as $c
        ) {
            if (Schema::hasColumn('salaries', $c)) {
                Schema::table('salaries', fn(Blueprint $t) => $t->dropColumn($c));
            }
        }

        foreach (
            [
                'clothing_allowance',
                'incentives',
                'commission',
                'profit_share',
                'other_earnings',
                'day_overtime_hours',
                'day_overtime_value',
                'night_overtime_hours',
                'night_overtime_value',
                'official_holiday_days',
                'official_holiday_value',
                'weekly_holiday_days',
                'weekly_holiday_value',
                'total_overtime',
                'total_earnings',
                'annual_gross',
                'annual_employee_insurance',
                'personal_exemption',
                'tax_deductible_contributions',
                'profit_share_tax_adjustment',
                'taxable_income',
                'tax_category',
                'annual_tax',
                'monthly_tax',
                'penalties',
                'advances',
                'other_deductions',
                'calculation_snapshot'
            ] as $c
        ) {
            if (Schema::hasColumn('payroll_records', $c)) {
                Schema::table(
                    'payroll_records',
                    fn(Blueprint $t) => $t->dropColumn($c)
                );
            }
        }

        Schema::dropIfExists('payroll_settings');
    }
};
