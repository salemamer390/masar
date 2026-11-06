<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\LeaveType;
use App\Services\PayrollCalculator;
use App\Services\PayrollDefaultsService;
use App\Services\AttendanceMetricsService;
use App\Services\PayrollRunService;
use App\Services\HrOperationalSettingsService;

class PayrollSettingsController extends Controller
{
    public function index()
    {
        $this->ensureAnnualFixedHolidays();
        $companyId = auth()->user()->company_id;
        if ($companyId) {
            // A company that never had payroll rows gets its own independent copy now.
            app(PayrollDefaultsService::class)->ensureForCompany((int) $companyId);
        }
        $operationalSettings = DB::table('hr_operational_settings')->where('company_id',$companyId)->pluck('setting_value', 'setting_key');
        $holidays = DB::table('hr_holidays')->where('company_id',$companyId)->orderBy('holiday_date')->get();
        $settings = DB::table('payroll_calculation_settings')->where('company_id',$companyId)->where('is_active', true)->get()
            ->keyBy(fn ($item) => "$item->setting_group.$item->setting_key");
        $allowances = DB::table('payroll_allowance_settings')->where('company_id',$companyId)->where('is_active', true)->orderBy('sort_order')->get();
        $history = DB::table('payroll_setting_changes')->leftJoin('users', 'users.id', '=', 'payroll_setting_changes.changed_by')
            ->where('payroll_setting_changes.company_id', $companyId)
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
        $rules = DB::table('payroll_calculation_rules')->where('company_id', $companyId)->where('is_active', true)->get()
            ->keyBy(fn ($item) => "$item->rule_group.$item->rule_key");
        $taxCategories = DB::table('tax_categories')->where('company_id', $companyId)->orderBy('sort_order')->get()
            ->map(function ($category) {
                $category->brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get();
                return $category;
            });

        return view('payroll.settings', compact('settings', 'allowances', 'history', 'salarySummary', 'operationalSettings', 'holidays', 'leavePolicies', 'leavePolicyLabels', 'leaveTypes', 'rules', 'taxCategories'));
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

    public function updateOperational(Request $request, AttendanceMetricsService $attendanceMetrics, PayrollRunService $payrollRuns, HrOperationalSettingsService $hrSettings)
    {
        $data = $request->validate([
            'default_shift_start' => ['required', 'date_format:H:i'],
            'default_shift_end' => ['required', 'date_format:H:i'],
            'absence_cutoff_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'attendance_correction_days' => ['nullable', 'integer', 'min:0', function ($attribute, $value, $fail) {
                // The only real limit: the closing date (period end + days + 1) must stay a valid database date (year <= 9999).
                try { $ok = \Carbon\Carbon::today()->addDays((int) $value + 62)->year <= 9999; } catch (\Throwable) { $ok = false; }
                if (!$ok) $fail('عدد أيام التصحيح كبير لدرجة إن تاريخ القفل مش هيكون تاريخ صحيح.');
            }],
            'weekly_rest_pattern' => ['required', 'regex:/^[01]{7}$/'],
            'night_start' => ['nullable', 'date_format:H:i'],
            'night_end' => ['nullable', 'date_format:H:i'],
            'payroll_period_start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payroll_period_end_day' => ['required', 'integer', 'min:1', 'max:31'],
            'unpaid_leave_as_deduction' => ['nullable', 'in:0,1'],
            'confirm_close_pending' => ['nullable', 'in:0,1'],
        ]);
        $confirmClose = ($data['confirm_close_pending'] ?? '0') === '1';
        unset($data['confirm_close_pending']);
        $labels = [
            'default_shift_start' => 'بداية الدوام الافتراضية',
            'default_shift_end' => 'نهاية الدوام الافتراضية',
            'absence_cutoff_time' => 'وقت احتساب الغياب اليومي',
            'grace_minutes' => 'فترة السماح بالدقائق',
            'attendance_correction_days' => 'أيام تصحيح الحضور بعد نهاية الفترة',
            'weekly_rest_pattern' => 'الراحة الأسبوعية (الإثنين إلى الأحد)',
            'night_start' => 'بداية الفترة الليلية للإضافي',
            'night_end' => 'نهاية الفترة الليلية للإضافي',
            'payroll_period_start_day' => 'بداية فترة المسير (يوم الشهر)',
            'payroll_period_end_day' => 'نهاية فترة المسير (يوم الشهر)',
            'unpaid_leave_as_deduction' => 'خصم الإجازة بدون أجر من الراتب',
        ];
        $companyId = (int) auth()->user()->company_id;
        $blocked = null;
        $needsConfirm = null;
        $notes = [];
        $transitionNote = null;
        try { DB::transaction(function () use ($companyId, $data, $labels, $hrSettings, $confirmClose, &$blocked, &$needsConfirm, &$notes, &$transitionNote) {
            // One writer per company: settings saves, the archive job and attendance writers queue here.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $hrSettings->flush();
            [$oldStart, $oldEnd] = \App\Support\CompanyContext::run($companyId, fn () => [$hrSettings->payrollPeriodStartDay(), $hrSettings->payrollPeriodEndDay()]);
            // Finished periods are saved with their current boundaries before new settings can apply.
            app(\App\Services\AttendancePeriodArchiveService::class)->archiveEndedPeriods($companyId);
            $daysChanged = $oldStart !== (int) $data['payroll_period_start_day'] || $oldEnd !== (int) $data['payroll_period_end_day'];
            if ($daysChanged) {
                $impact = app(\App\Services\PeriodChangeImpactService::class)->detect($companyId, (int) $data['payroll_period_start_day'], (int) $data['payroll_period_end_day']);
                $notes = $impact['notes'];
                $attendanceBlocking = array_values(array_filter($impact['blocking'], fn ($item) => ($item['source'] ?? null) === 'attendance_archive'));
                if ($attendanceBlocking) {
                    $blocked = $attendanceBlocking;
                    throw new \DomainException('period-change-rejected'); // rolls the whole transaction back
                }
            }
            if ($daysChanged) {
                // The user confirms that periods still in their correction window are closed and saved now.
                $archives = app(\App\Services\AttendancePeriodArchiveService::class);
                $pending = $archives->pendingPeriods($companyId);
                if ($pending->isNotEmpty()) {
                    if (!$confirmClose) {
                        $needsConfirm = $pending->map(fn ($a) => $a->period_start->format('d/m/Y') . ' - ' . $a->period_end->format('d/m/Y'))->all();
                        throw new \DomainException('period-change-needs-confirmation');
                    }
                    foreach ($pending as $archive) $archives->close($archive);
                }
            }
            foreach ($data as $key => $value) {
                if ($value === null) continue;
                DB::table('hr_operational_settings')->updateOrInsert(
                    ['company_id' => $companyId, 'setting_key' => $key],
                    ['setting_value' => (string) $value, 'label' => $labels[$key], 'updated_at' => now(), 'created_at' => now()]
                );
            }
            if ($daysChanged) {
                // Read the just-saved days (and grace) inside the same transaction.
                $hrSettings->flush();
                $this->recordChange($companyId, now()->toDateString(),
                    ['payroll_period_start_day' => $oldStart, 'payroll_period_end_day' => $oldEnd],
                    ['payroll_period_start_day' => (int) $data['payroll_period_start_day'], 'payroll_period_end_day' => (int) $data['payroll_period_end_day']]);
                $transition = app(\App\Services\PayrollTransitionService::class)->createForCycleChange(
                    $companyId, (int) $data['payroll_period_start_day'], (int) $data['payroll_period_end_day'], $oldStart, $oldEnd);
                if ($transition) {
                    // The transition run covers the bridged days, so the "uncovered days" notes no longer apply.
                    $notes = [];
                    $transitionNote = 'اتعمل مسير انتقالي من ' . $transition->period_start->format('d/m/Y') . ' إلى ' . $transition->period_end->format('d/m/Y') . ' بالأيام الفعلية، عشان ميبقاش في فجوة ولا تداخل بين الدورتين.';
                }
            }
        }); } catch (\DomainException $rejected) {
            if (!$blocked && !$needsConfirm) throw $rejected;
        }
        if ($needsConfirm && !$blocked) {
            $hrSettings->flush();
            $message = 'تغيير بداية أو نهاية فترة المسير هيقفل ويحفظ سجل الحضور للفترات اللي لسه في مهلة التصحيح، وبعدها مش هيتعدل. ما اتحفظش أي تغيير لحد ما تأكد.';
            return $request->wantsJson()
                ? response()->json(['success' => false, 'needs_confirmation' => true, 'message' => $message, 'periods' => $needsConfirm], 409)
                : back()->withInput()->with('period_confirm', ['message' => $message, 'periods' => $needsConfirm]);
        }
        if ($blocked) {
            $hrSettings->flush();
            $message = 'ما اتحفظش تغيير بداية ونهاية فترة المسير لأن الفترة الجديدة فيها أيام اتحسبت قبل كده. الإعدادات الحالية اتساب زي ما هي، ومفيش أي مسير أو سجل اتغيّر.';
            $lines = array_column($blocked, 'text');
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $message, 'overlaps' => $blocked], 422)
                : back()->withInput()->withErrors(['payroll_period' => array_merge([$message], $lines)]);
        }
        ActivityLog::record('hr_operational_settings_update', 'تم تحديث أوقات الدوام والراحة الأسبوعية.');
        $hrSettings->flush();
        $attendanceCount = $attendanceMetrics->recalculateCompany($companyId);
        $draftCount = $payrollRuns->refreshOpenDraftsForCompany($companyId);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Operational settings updated and calculations refreshed.', 'settings' => $data, 'attendance_updated' => $attendanceCount, 'drafts_refreshed' => $draftCount])
            : back()->with('toast', 'تم حفظ إعدادات أوقات الدوام والراحة.')->with('period_notes', array_values(array_filter(array_merge($transitionNote ? [$transitionNote] : [], array_column($notes, 'text')))));
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

    private const SETTING_KEYS = [
        'general.allowance_rate', 'general.proration_days',
        'insurance.employee_insurance_rate', 'insurance.employer_insurance_rate', 'insurance.martyrs_fund_rate',
        'insurance.insurance_minimum', 'insurance.insurance_maximum',
        'overtime.hours_divisor', 'overtime.days_divisor', 'overtime.day_multiplier', 'overtime.night_multiplier',
        'overtime.official_holiday_multiplier', 'overtime.weekly_rest_multiplier',
        'tax.personal_exemption', 'tax.tax_deductible_contributions', 'tax.profit_share_exempt_percent',
        'absence.daily_divisor', 'penalty.daily_divisor', 'penalty.max_days_per_month', 'advance.max_percent_of_gross',
    ];

    /** Form field => [rule group, rule key] for the four calculation basis rules. */
    private const RULE_FIELDS = [
        'absence_basis' => ['absence', 'calculation_basis'],
        'penalty_basis' => ['penalty', 'calculation_basis'],
        'overtime_basis' => ['overtime', 'calculation_basis'],
        'insurance_basis' => ['insurance', 'automatic_calculation_basis'],
    ];

    private function companyIdOrFail(): int
    {
        $companyId = auth()->user()?->company_id;
        abort_if(!$companyId, 403, 'إعدادات الرواتب تخص شركة محددة.');

        return (int) $companyId;
    }

    private function recalculateSalaries(int $companyId): int
    {
        // New instances so no value cached before the change is reused.
        return app(SalaryController::class)->recalculateCompany(new PayrollCalculator(), $companyId);
    }

    private function recordChange(int $companyId, string $effectiveFrom, array $before, array $after): void
    {
        DB::table('payroll_setting_changes')->insert([
            'company_id' => $companyId,
            'effective_from' => $effectiveFrom, 'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'changed_by' => auth()->id(),
            'ip_address' => request()->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function update(Request $request)
    {
        $companyId = $this->companyIdOrFail();
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'settings' => ['required', 'array'],
            'allowances' => ['required', 'array', 'min:1'],
            'allowances.*' => ['numeric', 'min:0', 'max:100'],
        ]);

        abort_if(array_diff(array_keys($data['settings']), self::SETTING_KEYS) !== [], 422, 'Invalid payroll setting.');
        foreach ($data['settings'] as $value) {
            if ($value !== null && $value !== '' && (!is_numeric($value) || (float) $value < 0)) {
                return back()->withErrors(['settings' => 'كل قيم الإعدادات يجب أن تكون أرقامًا موجبة.'])->withInput();
            }
        }
        if (abs(round(array_sum($data['allowances']), 4) - 100) > 0.0001) {
            return back()->withErrors(['allowances' => 'يجب أن يساوي مجموع توزيع البدلات 100%.'])->withInput();
        }

        app(PayrollDefaultsService::class)->ensureForCompany($companyId);
        DB::transaction(function () use ($data, $companyId) {
            $snapshot = fn () => [
                'settings' => DB::table('payroll_calculation_settings')->where('company_id', $companyId)->where('is_active', true)->get(),
                'allowances' => DB::table('payroll_allowance_settings')->where('company_id', $companyId)->where('is_active', true)->get(),
            ];
            $before = $snapshot();
            foreach ($data['settings'] as $name => $value) {
                if ($value === null || $value === '') continue;
                [$group, $key] = explode('.', $name, 2);
                DB::table('payroll_calculation_settings')->where('company_id', $companyId)->where('setting_group', $group)->where('setting_key', $key)
                    ->update(['setting_value' => $value, 'is_active' => 1, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            foreach ($data['allowances'] as $key => $percentage) {
                DB::table('payroll_allowance_settings')->where('company_id', $companyId)->where('allowance_key', $key)->where('is_active', true)
                    ->update(['percentage' => $percentage, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            $this->recordChange($companyId, $data['effective_from'], $before, $snapshot());
        });

        $count = $this->recalculateSalaries($companyId);
        ActivityLog::record('payroll_settings_update', 'تم تحديث إعدادات الرواتب وتوزيع البدلات.');
        return redirect()->route('payroll.settings.index')->with('toast', "تم حفظ إعدادات الرواتب لشركتك فقط وإعادة حساب {$count} هيكل راتب. أعد توليد مسودة الشهر الحالي لتظهر النتائج.");
    }

    /** The four calculation basis rules: insurance, overtime, absence and penalty. */
    public function updateRules(Request $request)
    {
        $companyId = $this->companyIdOrFail();
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'rules' => ['required', 'array'],
            'rules.*' => ['required', 'in:Gross,Basic'],
            'absence_deducted_allowances' => ['nullable', 'array'],
            'absence_deducted_allowances.*' => ['in:housing,transportation,meal,clothing,other'],
        ]);
        abort_if(array_diff(array_keys($data['rules']), array_keys(self::RULE_FIELDS)) !== [], 422, 'Invalid payroll rule.');

        app(PayrollDefaultsService::class)->ensureForCompany($companyId);
        DB::transaction(function () use ($data, $companyId) {
            $snapshot = fn () => ['rules' => DB::table('payroll_calculation_rules')->where('company_id', $companyId)->get()];
            $before = $snapshot();
            foreach ($data['rules'] as $field => $value) {
                [$group, $key] = self::RULE_FIELDS[$field];
                DB::table('payroll_calculation_rules')->where('company_id', $companyId)->where('rule_group', $group)->where('rule_key', $key)
                    ->update(['rule_value' => $value, 'is_active' => 1, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            }
            // The allowances whose daily value joins the absence-day deduction under the Basic basis.
            $deductedAllowances = implode(',', $data['absence_deducted_allowances'] ?? []);
            DB::table('payroll_calculation_rules')->where('company_id', $companyId)->where('rule_group', 'absence')->where('rule_key', 'deducted_allowances')
                ->update(['rule_value' => $deductedAllowances, 'is_active' => 1, 'effective_from' => $data['effective_from'], 'updated_at' => now()]);
            $this->recordChange($companyId, $data['effective_from'], $before, $snapshot());
        });

        $count = $this->recalculateSalaries($companyId);
        ActivityLog::record('payroll_rules_update', 'تم تحديث قواعد أساس حساب الرواتب.');
        return redirect()->route('payroll.settings.index')->with('toast', "تم حفظ قواعد الحساب لشركتك فقط وإعادة حساب {$count} هيكل راتب.");
    }

    /** Income-tax categories and brackets of this company, editable when the law changes. */
    public function updateTax(Request $request)
    {
        $companyId = $this->companyIdOrFail();
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.label' => ['required', 'string', 'max:150'],
            'categories.*.max_income' => ['nullable', 'numeric', 'min:0'],
            'categories.*.brackets' => ['required', 'array', 'min:1'],
            'categories.*.brackets.*.from' => ['required', 'numeric', 'min:0'],
            'categories.*.brackets.*.to' => ['nullable', 'numeric', 'min:0'],
            'categories.*.brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $categories = array_values($data['categories']);
        $previousMax = null;
        foreach ($categories as $index => $category) {
            $isLastCategory = $index === count($categories) - 1;
            $max = ($category['max_income'] ?? null) === null || $category['max_income'] === '' ? null : (float) $category['max_income'];
            if ($isLastCategory !== ($max === null)) {
                return back()->withErrors(['tax' => 'آخر فئة فقط تُترك بدون حد أعلى، وباقي الفئات لازم لها حد أعلى.'])->withInput();
            }
            if ($max !== null && $previousMax !== null && $max <= $previousMax) {
                return back()->withErrors(['tax' => 'حدود الفئات لازم تكون تصاعدية.'])->withInput();
            }
            $previousMax = $max;

            $brackets = array_values($category['brackets']);
            $expectedFrom = 0.0;
            foreach ($brackets as $bracketIndex => $bracket) {
                $isLastBracket = $bracketIndex === count($brackets) - 1;
                $from = (float) $bracket['from'];
                $to = ($bracket['to'] ?? null) === null || $bracket['to'] === '' ? null : (float) $bracket['to'];
                if (abs($from - $expectedFrom) > 0.005) {
                    return back()->withErrors(['tax' => "شرائح «{$category['label']}» لازم تكون متصلة: الشريحة رقم " . ($bracketIndex + 1) . ' يجب أن تبدأ من ' . number_format($expectedFrom, 2) . '.'])->withInput();
                }
                if ($isLastBracket !== ($to === null)) {
                    return back()->withErrors(['tax' => "آخر شريحة فقط في «{$category['label']}» تُترك بدون حد أعلى."])->withInput();
                }
                if ($to !== null && $to <= $from) {
                    return back()->withErrors(['tax' => "حد أعلى الشريحة لازم يكون أكبر من بدايتها في «{$category['label']}»."])->withInput();
                }
                $expectedFrom = $to ?? 0.0;
            }
        }

        DB::transaction(function () use ($categories, $data, $companyId) {
            $snapshot = function () use ($companyId) {
                $rows = DB::table('tax_categories')->where('company_id', $companyId)->orderBy('sort_order')->get();
                return ['categories' => $rows->map(fn ($row) => (array) $row)->all(),
                    'brackets' => DB::table('tax_brackets')->whereIn('tax_category_id', $rows->pluck('id')->all())->get()->map(fn ($row) => (array) $row)->all()];
            };
            $before = $snapshot();
            $oldIds = DB::table('tax_categories')->where('company_id', $companyId)->pluck('id')->all();
            if ($oldIds) {
                DB::table('tax_brackets')->whereIn('tax_category_id', $oldIds)->delete();
                DB::table('tax_categories')->whereIn('id', $oldIds)->delete();
            }
            $minIncome = 0.0;
            foreach ($categories as $index => $category) {
                $max = ($category['max_income'] ?? null) === null || $category['max_income'] === '' ? null : (float) $category['max_income'];
                $categoryId = DB::table('tax_categories')->insertGetId([
                    'company_id' => $companyId, 'category_key' => 'category_' . ($index + 1), 'label' => $category['label'],
                    'min_income' => $minIncome, 'max_income' => $max, 'sort_order' => $index + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (array_values($category['brackets']) as $bracketIndex => $bracket) {
                    DB::table('tax_brackets')->insert([
                        'tax_category_id' => $categoryId, 'from_amount' => (float) $bracket['from'],
                        'to_amount' => ($bracket['to'] ?? null) === null || $bracket['to'] === '' ? null : (float) $bracket['to'],
                        'rate' => round(((float) $bracket['rate']) / 100, 4), 'sort_order' => $bracketIndex + 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $minIncome = $max === null ? 0.0 : round($max + 0.01, 2);
            }
            $this->recordChange($companyId, $data['effective_from'], $before, $snapshot());
        });

        $count = $this->recalculateSalaries($companyId);
        ActivityLog::record('payroll_tax_update', 'تم تحديث شرائح ضريبة كسب العمل.');
        return redirect()->route('payroll.settings.index')->with('toast', "تم حفظ شرائح الضرائب لشركتك فقط وإعادة حساب {$count} هيكل راتب.");
    }
}
