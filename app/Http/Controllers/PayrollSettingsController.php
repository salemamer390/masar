<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\LeaveType;

class PayrollSettingsController extends Controller
{
    public function index()
    {
        $this->ensureAnnualFixedHolidays();
        $companyId = auth()->user()->company_id;
        $operationalSettings = DB::table('hr_operational_settings')->where('company_id',$companyId)->pluck('setting_value', 'setting_key');
        $holidays = DB::table('hr_holidays')->where('company_id',$companyId)->orderBy('holiday_date')->get();
        $settings = DB::table('payroll_calculation_settings')->where('company_id',$companyId)->where('is_active', true)->get()
            ->keyBy(fn ($item) => "$item->setting_group.$item->setting_key");
        $allowances = DB::table('payroll_allowance_settings')->where('company_id',$companyId)->where('is_active', true)->orderBy('sort_order')->get();
        $history = DB::table('payroll_setting_changes')->leftJoin('users', 'users.id', '=', 'payroll_setting_changes.changed_by')
            ->select('payroll_setting_changes.*', 'users.name as changed_by_name')->latest('payroll_setting_changes.id')->limit(20)->get();
        $salarySummary = [
            'active' => Salary::where('status', 'Active')->count(),
            'total_net' => Salary::where('status', 'Active')->sum('net_salary'),
            'allowance_review' => Salary::where('status', 'Active')->where('allowance_remaining', '<', 0)->count(),
        ];
        $leavePolicies = DB::table('leave_policy_settings')->where('company_id',$companyId)->orderBy('id')->get()->keyBy('setting_key');
        $leavePolicyLabels = [
            'annual_first_year' => 'رصيد السنة الأولى', 'annual_second_year' => 'رصيد ما بعد السنة الأولى',
            'annual_long_service' => 'رصيد 10 سنوات / 50 سنة', 'annual_long_service_years' => 'سنوات الخدمة للرصيد الأعلى',
            'annual_age_threshold' => 'سن الرصيد الأعلى', 'annual_disability_days' => 'رصيد ذوي الإعاقة والأقزام',
            'annual_hazardous_extra_days' => 'إضافة العمل الخطر أو النائي', 'annual_wait_months' => 'مدة الانتظار (شهر)',
            'annual_prorate_first_year' => 'احتساب السنة الأولى نسبيًا (1 نعم / 0 لا)', 'allow_early_leave' => 'السماح بمنح استثنائي (1 نعم / 0 لا)',
            'casual_max_days_year' => 'حد العارضة السنوي', 'casual_max_days_request' => 'حد العارضة للطلب الواحد',
            'maternity_days' => 'مدة إجازة الوضع (يوم)', 'maternity_min_postpartum_days' => 'الحد الأدنى بعد الولادة (يوم)',
            'maternity_max_occurrences' => 'الحد الأقصى لمرات الوضع', 'pregnancy_reduced_hours_from_month' => 'بدء تخفيض ساعات الحامل (الشهر)',
            'pregnancy_reduced_hours_per_day' => 'تخفيض ساعات الحامل يوميًا', 'pregnancy_overtime_protection_months' => 'حماية منع الإضافي بعد الوضع (شهر)',
            'nursing_sessions_per_day' => 'فترات الرضاعة يوميًا', 'nursing_minutes_per_session' => 'مدة فترة الرضاعة (دقيقة)',
            'childcare_max_years_per_request' => 'رعاية الطفل (سنوات للطلب)', 'childcare_max_occurrences' => 'مرات رعاية الطفل بالخدمة',
            'pilgrimage_min_service_months' => 'خدمة الحج / القدس (شهر)', 'newborn_max_occurrences' => 'مرات إجازة المولود بالخدمة',
        ];
        $leaveTypes = LeaveType::where('is_active', true)->orderBy('id')->get();

        return view('payroll.settings', compact('settings', 'allowances', 'history', 'salarySummary', 'operationalSettings', 'holidays', 'leavePolicies', 'leavePolicyLabels', 'leaveTypes'));
    }

    public function updateLeavePolicy(Request $request)
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*' => ['nullable', 'numeric', 'min:0'],
            'types' => ['required', 'array'],
            'types.*.default_days' => ['nullable', 'numeric', 'min:0'],
            'types.*.max_days_per_request' => ['nullable', 'numeric', 'min:0'],
            'types.*.max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'types.*.min_service_months' => ['nullable', 'integer', 'min:0'],
            'types.*.max_occurrences_service' => ['nullable', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($data): void {
            foreach ($data['policies'] as $key => $value) {
                DB::table('leave_policy_settings')->where('company_id', auth()->user()->company_id)->where('setting_key', $key)->update(['setting_value' => (string) $value, 'updated_at' => now()]);
            }
            foreach ($data['types'] as $id => $values) {
                LeaveType::whereKey($id)->update(array_intersect_key($values, array_flip(['default_days', 'max_days_per_request', 'max_days_per_year', 'min_service_months', 'max_occurrences_service'])));
            }
        });
        ActivityLog::record('leave_policy_update', 'Leave policy settings updated.');
        return $request->wantsJson() ? response()->json(['success' => true, 'message' => 'Leave policy saved.']) : back()->with('toast', 'تم حفظ إعدادات الإجازات بنجاح.');
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
                ['company_id' => auth()->user()->company_id, 'setting_key' => $key],
                ['setting_value' => (string) $value, 'label' => $labels[$key], 'updated_at' => now(), 'created_at' => now()]
            );
        }
        ActivityLog::record('hr_operational_settings_update', 'تم تحديث أوقات الدوام والراحة الأسبوعية.');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Operational settings updated.', 'settings' => $data])
            : back()->with('toast', 'تم حفظ إعدادات أوقات الدوام والراحة.');
    }

    public function storeHoliday(Request $request)
    {
        $data = $request->validate(['holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:150']]);
        DB::table('hr_holidays')->updateOrInsert(['company_id'=>auth()->user()->company_id, 'holiday_date' => $data['holiday_date']], ['name' => $data['name'], 'updated_at' => now(), 'created_at' => now()]);
        if ($request->wantsJson()) {
            $holiday = DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('holiday_date', $data['holiday_date'])->first();
            return response()->json(['success' => true, 'message' => 'Holiday saved.', 'holiday' => $holiday]);
        }

        return back()->with('toast', 'تم حفظ العطلة الرسمية.');
    }

    public function destroyHoliday(int $holiday, Request $request)
    {
        DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('id', $holiday)->delete();
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Holiday deleted.', 'holiday_id' => $holiday])
            : back()->with('toast', 'تم حذف العطلة الرسمية.');
    }

    public function updateHoliday(Request $request, int $holiday)
    {
        $data = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('hr_holidays', 'holiday_date')->where(fn ($query) => $query->where('company_id', auth()->user()->company_id))->ignore($holiday)],
            'name' => ['required', 'string', 'max:150'],
        ]);

        DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('id', $holiday)->update([
            'holiday_date' => $data['holiday_date'],
            'name' => $data['name'],
            'updated_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Holiday updated.',
                'holiday' => DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('id', $holiday)->first(),
            ]);
        }

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
                    'company_id' => auth()->user()->company_id,
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
                'settings' => DB::table('payroll_calculation_settings')->where('company_id',auth()->user()->company_id)->where('is_active', true)->get(),
                'allowances' => DB::table('payroll_allowance_settings')->where('company_id',auth()->user()->company_id)->where('is_active', true)->get(),
            ];
            foreach ($data['settings'] as $name => $value) {
                [$group, $key] = explode('.', $name, 2);
                DB::table('payroll_calculation_settings')->where('company_id',auth()->user()->company_id)->where('setting_group', $group)->where('setting_key', $key)->where('is_active', true)
                    ->update(['setting_value' => $value, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            foreach ($data['allowances'] as $key => $percentage) {
                DB::table('payroll_allowance_settings')->where('company_id',auth()->user()->company_id)->where('allowance_key', $key)->where('is_active', true)
                    ->update(['percentage' => $percentage, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            $after = [
                'settings' => DB::table('payroll_calculation_settings')->where('company_id',auth()->user()->company_id)->where('is_active', true)->get(),
                'allowances' => DB::table('payroll_allowance_settings')->where('company_id',auth()->user()->company_id)->where('is_active', true)->get(),
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
