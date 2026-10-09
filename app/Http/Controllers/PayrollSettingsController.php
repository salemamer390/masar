<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollSettingsController extends Controller
{
    public function index()
    {
        $this->ensureAnnualFixedHolidays();
        $operationalSettings = DB::table('hr_operational_settings')->pluck('setting_value', 'setting_key');
        $holidays = DB::table('hr_holidays')->orderBy('holiday_date')->get();
        $settings = DB::table('payroll_calculation_settings')->where('is_active', true)->get()
            ->keyBy(fn ($item) => "$item->setting_group.$item->setting_key");
        $allowances = DB::table('payroll_allowance_settings')->where('is_active', true)->orderBy('sort_order')->get();
        $history = DB::table('payroll_setting_changes')->leftJoin('users', 'users.id', '=', 'payroll_setting_changes.changed_by')
            ->select('payroll_setting_changes.*', 'users.name as changed_by_name')->latest('payroll_setting_changes.id')->limit(20)->get();
        $salarySummary = [
            'active' => Salary::where('status', 'Active')->count(),
            'total_net' => Salary::where('status', 'Active')->sum('net_salary'),
            'allowance_review' => Salary::where('status', 'Active')->where('allowance_remaining', '<', 0)->count(),
        ];

        return view('payroll.settings', compact('settings', 'allowances', 'history', 'salarySummary', 'operationalSettings', 'holidays'));
    }

    public function updateOperational(Request $request)
    {
        $data = $request->validate([
            'default_shift_start' => ['required', 'date_format:H:i'],
            'default_shift_end' => ['required', 'date_format:H:i'],
            'absence_cutoff_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'weekly_rest_pattern' => ['required', 'regex:/^[01]{7}$/'],
        ]);
        $labels = [
            'default_shift_start' => 'بداية الدوام الافتراضية',
            'default_shift_end' => 'نهاية الدوام الافتراضية',
            'absence_cutoff_time' => 'وقت احتساب الغياب اليومي',
            'grace_minutes' => 'فترة السماح بالدقائق',
            'weekly_rest_pattern' => 'الراحة الأسبوعية (الإثنين إلى الأحد)',
        ];
        foreach ($data as $key => $value) {
            DB::table('hr_operational_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => (string) $value, 'label' => $labels[$key], 'updated_at' => now(), 'created_at' => now()]
            );
        }
        ActivityLog::record('hr_operational_settings_update', 'تم تحديث أوقات الدوام والراحة الأسبوعية.');
        return back()->with('toast', 'تم حفظ إعدادات أوقات الدوام والراحة.');
    }

    public function storeHoliday(Request $request)
    {
        $data = $request->validate(['holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:150']]);
        DB::table('hr_holidays')->updateOrInsert(['holiday_date' => $data['holiday_date']], ['name' => $data['name'], 'updated_at' => now(), 'created_at' => now()]);
        return back()->with('toast', 'تم حفظ العطلة الرسمية.');
    }

    public function destroyHoliday(int $holiday)
    {
        DB::table('hr_holidays')->where('id', $holiday)->delete();
        return back()->with('toast', 'تم حذف العطلة الرسمية.');
    }

    public function updateHoliday(Request $request, int $holiday)
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('hr_holidays', 'holiday_date')->ignore($holiday)],
            'name' => ['required', 'string', 'max:150'],
        ]);

        DB::table('hr_holidays')->where('id', $holiday)->update([
            'holiday_date' => $data['holiday_date'],
            'name' => $data['name'],
            'updated_at' => now(),
        ]);

        return back()->with('toast', 'تم تحديث العطلة الرسمية.');
    }

    private function ensureAnnualFixedHolidays(): void
    {
        foreach ([now()->year, now()->addYear()->year] as $year) {
            $holidays = [
                ["{$year}-01-07", 'عيد الميلاد المجيد'],
                ["{$year}-01-25", 'عيد الشرطة وثورة 25 يناير'],
                ["{$year}-04-25", 'عيد تحرير سيناء'],
                ["{$year}-05-01", 'عيد العمال'],
                ["{$year}-06-30", 'ذكرى ثورة 30 يونيو'],
                ["{$year}-07-23", 'ذكرى ثورة 23 يوليو'],
                ["{$year}-10-06", 'عيد القوات المسلحة ونصر أكتوبر'],
            ];

            foreach ($holidays as [$date, $name]) {
                DB::table('hr_holidays')->insertOrIgnore([
                    'holiday_date' => $date,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['required', 'array', 'min:1'],
            'allowances.*' => ['numeric', 'min:0', 'max:100'],
        ]);

        $allowed = [
            'general.allowance_rate', 'insurance.employee_insurance_rate', 'insurance.employer_insurance_rate',
            'insurance.martyrs_fund_rate', 'insurance.insurance_minimum', 'insurance.insurance_maximum',
            'overtime.hours_divisor', 'overtime.days_divisor', 'overtime.day_multiplier', 'overtime.night_multiplier',
            'overtime.official_holiday_multiplier', 'overtime.weekly_rest_multiplier', 'tax.personal_exemption',
            'tax.tax_deductible_contributions', 'tax.profit_share_tax_exempt', 'absence.daily_divisor', 'penalty.daily_divisor',
        ];
        abort_if(array_diff(array_keys($data['settings']), $allowed) !== [], 422, 'Invalid payroll setting.');

        if (abs(round(array_sum($data['allowances']), 4) - 100) > 0.0001) {
            return back()->withErrors(['allowances' => 'يجب أن يساوي مجموع توزيع البدلات 100%.'])->withInput();
        }

        DB::transaction(function () use ($data) {
            $before = [
                'settings' => DB::table('payroll_calculation_settings')->where('is_active', true)->get(),
                'allowances' => DB::table('payroll_allowance_settings')->where('is_active', true)->get(),
            ];
            foreach ($data['settings'] as $name => $value) {
                [$group, $key] = explode('.', $name, 2);
                DB::table('payroll_calculation_settings')->where('setting_group', $group)->where('setting_key', $key)->where('is_active', true)
                    ->update(['setting_value' => $value, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            foreach ($data['allowances'] as $key => $percentage) {
                DB::table('payroll_allowance_settings')->where('allowance_key', $key)->where('is_active', true)
                    ->update(['percentage' => $percentage, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            $after = [
                'settings' => DB::table('payroll_calculation_settings')->where('is_active', true)->get(),
                'allowances' => DB::table('payroll_allowance_settings')->where('is_active', true)->get(),
            ];
            DB::table('payroll_setting_changes')->insert([
                'effective_from' => $data['effective_from'], 'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'changed_by' => auth()->id(),
                'ip_address' => request()->ip(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        ActivityLog::record('payroll_settings_update', 'تم تحديث إعدادات الرواتب وتوزيع البدلات.');
        return redirect()->route('payroll.settings.index')->with('toast', 'تم حفظ إعدادات الرواتب وتسجيل نسخة تاريخية منها.');
    }
}
