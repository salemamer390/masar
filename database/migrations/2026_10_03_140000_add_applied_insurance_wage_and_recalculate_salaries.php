<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // insurance_wage = الأجر التأميني اليدوي (اختياري)، applied_insurance_wage = الأجر التأميني المطبَّق فعلياً بعد الحساب.
        if (!Schema::hasColumn('salaries', 'applied_insurance_wage')) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->decimal('applied_insurance_wage', 12, 2)->nullable()->after('insurance_wage');
            });
        }

        // إعادة حساب الهياكل السارية بمنطق الضريبة الجديد (فئات الدخل + تقريب الوعاء لأقرب 10 جنيهات).
        $calculator = new \App\Services\PayrollCalculator();
        foreach (DB::table('salaries')->where('status', 'Active')->get() as $row) {
            if ((float)$row->salary_input <= 0) continue;

            $structure = $calculator->structure([
                'salary_type' => $row->salary_type ?: 'net',
                'salary_input' => (float)$row->salary_input,
                'insurance_wage' => $row->insurance_wage,
                'no_insurance' => (bool)$row->no_insurance,
                'excluded_allowance_pct' => $row->excluded_allowance_pct ?? \App\Services\PayrollCalculator::DEFAULT_EXCLUDED_PCT,
                'personal_exemption' => $row->personal_exemption ?? \App\Services\PayrollCalculator::PERSONAL_EXEMPTION,
                'tax_treatment' => $row->tax_treatment ?: 'egyptian',
                'housing_allowance' => $row->housing_allowance ?? 0,
                'transportation_allowance' => $row->transportation_allowance ?? 0,
                'meal_allowance' => $row->meal_allowance ?? 0,
                'fixed_deductions' => $row->fixed_deductions ?? 0,
            ]);
            DB::table('salaries')->where('id', $row->id)->update($calculator->persistFields($structure));
        }

        // مزامنة الأجر التأميني في سجلات التأمين من آخر هيكل ساري.
        foreach (DB::table('insurance_records')->get() as $record) {
            $latest = DB::table('salaries')->where('employee_id', $record->employee_id)->where('status', 'Active')
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            DB::table('insurance_records')->where('id', $record->id)->update([
                'insured_salary' => ($latest && !$latest->no_insurance) ? $latest->applied_insurance_wage : null,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salaries', 'applied_insurance_wage')) {
            Schema::table('salaries', fn (Blueprint $table) => $table->dropColumn('applied_insurance_wage'));
        }
    }
};
