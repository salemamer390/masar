<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'annual_long_service' => 'رصيد 10 سنوات / تجاوز 50 سنة', 'annual_long_service_years' => 'سنوات الخدمة للرصيد الأعلى',
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
        $leavePolicyLabels += array_map(fn ($r) => $r['label'], \App\Support\LeaveLegalReference::policyRules());
        // Effective limits per type for THIS company (own copy of a shared type, casual per-request/year from the company policy).
        $leaveBalances = app(\App\Services\LeaveBalanceService::class);
        $leaveTypes = LeaveType::where(fn ($q) => $q->where('is_active', true)->orWhere('policy_code', 'contagious_contact')->orWhere('policy_code', 'like', 'contagious_contact_company_%')
            // Statutory shared types stay listed even if deactivated wholesale, so a company can switch them back on.
            ->orWhere(fn ($q2) => $q2->whereNull('company_id')->whereIn('policy_code', ['annual', 'casual', 'sick', 'exams', 'newborn', 'pilgrimage', 'maternity', 'childcare']))
            // The company's own types stay listed while inactive so they can be reactivated.
            // Types archived through the delete action stay gone: archiving is a deliberate
            // removal that keeps history, not an ordinary off switch.
            ->when($companyId, fn ($q2) => $q2->orWhere(fn ($q3) => $q3->where('company_id', $companyId)->whereNull('archived_at'))))->orderBy('id')->get()->each(function (LeaveType $type) use ($leaveBalances, $companyId, $leavePolicies): void {
            $limits = $leaveBalances->limitsFor($type, $companyId ? (int) $companyId : null);
            if ($type->policy_code === 'casual') {
                $perRequest = $leavePolicies['casual_max_days_request']->setting_value ?? null;
                $limits['max_days_per_request'] = $perRequest !== null ? (float) $perRequest : null;
                $perYear = $leavePolicies['casual_max_days_year']->setting_value ?? null;
                if ($perYear !== null && ($limits['max_days_per_year'] === null || (float) $perYear < $limits['max_days_per_year'])) $limits['max_days_per_year'] = (float) $perYear;
            }
            $type->setAttribute('limits', $limits);
        });
        $rules = DB::table('payroll_calculation_rules')->where('company_id', $companyId)->where('is_active', true)->get()
            ->keyBy(fn ($item) => "$item->rule_group.$item->rule_key");
        $taxCategories = DB::table('tax_categories')->where('company_id', $companyId)->orderBy('sort_order')->get()
            ->map(function ($category) {
                $category->brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get();
                return $category;
            });

        $penaltyService = app(\App\Services\AttendancePenaltyPolicyService::class);
        if ($companyId) $penaltyService->ensureSuggested((int) $companyId);
        $attendancePenaltyPolicy = $penaltyService->forCompany($companyId ? (int) $companyId : null);

        $suggestedPenaltyTiers = \App\Services\AttendancePenaltyPolicyService::suggestedTiers();

        // When the proration column is missing (migration not run yet) the save
        // silently skips the flags; surface that instead of showing a dead toggle.
        $allowanceProrationReady = Schema::hasColumn('payroll_allowance_settings', 'is_prorated');

        return view('payroll.settings', compact('attendancePenaltyPolicy', 'settings', 'allowances', 'history', 'salarySummary', 'operationalSettings', 'holidays', 'leavePolicies', 'leavePolicyLabels', 'leaveTypes', 'rules', 'taxCategories', 'suggestedPenaltyTiers', 'allowanceProrationReady'));
    }

    /** Lateness / early-departure penalty tiers of this company. Saving rebuilds open payroll drafts so the policy reaches the salary. */
    public function updateAttendancePenalties(Request $request, \App\Services\AttendancePenaltyPolicyService $policy, PayrollRunService $payrollRuns)
    {
        $companyId = $this->companyIdOrFail();
        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'tiers' => ['nullable', 'array', 'max:60'],
            'tiers.*.kind' => ['required', 'in:late,early_leave'],
            'tiers.*.from_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'tiers.*.to_minutes' => ['nullable', 'integer', 'min:1', 'max:1440', 'gte:tiers.*.from_minutes'],
            'tiers.*.occurrence_from' => ['nullable', 'integer', 'min:1', 'max:400'],
            'tiers.*.occurrence_to' => ['nullable', 'integer', 'min:1', 'max:400', 'gte:tiers.*.occurrence_from'],
            'tiers.*.deduction_days' => ['required', 'numeric', 'gte:0', 'max:1440'],
            'tiers.*.unit' => ['nullable', 'in:days,minutes'],
        ], [
            'tiers.*.to_minutes.gte' => '«إلى دقيقة» لازم تكون أكبر من أو تساوي «من دقيقة».',
            'tiers.*.occurrence_to.gte' => '«إلى المرة» لازم تكون أكبر من أو تساوي «من المرة».',
            'tiers.*.from_minutes.required' => __('حدد «من دقيقة» لكل شريحة.'),
            'tiers.*.deduction_days.required' => __('حدد قيمة الجزاء لكل شريحة.'),
            'tiers.*.deduction_days.gte' => 'قيمة الجزاء لا تقل عن صفر (صفر يعني إنذار بدون خصم).',
        ]);
        $tiers = collect($data['tiers'] ?? [])->map(fn ($tier) => [
            'kind' => $tier['kind'], 'from_minutes' => (int) $tier['from_minutes'],
            'to_minutes' => isset($tier['to_minutes']) && $tier['to_minutes'] !== '' ? (int) $tier['to_minutes'] : null,
            'occurrence_from' => (int) ($tier['occurrence_from'] ?? 1) ?: 1,
            'occurrence_to' => isset($tier['occurrence_to']) && $tier['occurrence_to'] !== '' ? (int) $tier['occurrence_to'] : null,
            'deduction_days' => round((float) $tier['deduction_days'], 2),
            'unit' => ($tier['unit'] ?? 'days') === 'minutes' ? 'minutes' : 'days',
        ])->all();

        // Both violations of a day are always charged, the lost-time wage is always deducted,
        // and no manual penalty replaces the automatic one (fixed rules, not options).
        $policy->save($companyId, (bool) ($data['is_enabled'] ?? false), $data['effective_from'] ?? null, $tiers);
        $drafts = $payrollRuns->refreshOpenDraftsForCompany($companyId);
        ActivityLog::record('attendance_penalty_policy_update', 'تم تحديث لائحة الجزاءات.');

        return redirect()->route('payroll.settings.index')->with('toast', "تم حفظ لائحة الجزاءات وإعادة حساب {$drafts} مسير مسودة.");
    }

    public function updateLeavePolicy(Request $request)
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*' => ['required', 'numeric', 'min:0'],
            'types' => ['nullable', 'array'],
            'types.*.max_days_per_request' => ['nullable', 'numeric', 'min:0'],
            'types.*.max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'types.*.min_service_months' => ['nullable', 'integer', 'min:0'],
            'types.*.max_occurrences_service' => ['nullable', 'integer', 'min:0'],
            'types.*.requires_document' => ['nullable', 'boolean'],
            'types.*.is_active' => ['nullable', 'boolean'],
            'types.*.is_paid' => ['nullable', 'boolean'],
            'types.*.deducts_annual_balance' => ['nullable', 'boolean'],
            'types.*.min_employees' => ['nullable', 'integer', 'min:0'],
            'sick_pay_basis' => ['nullable', 'in:ins,full,none'],
            'confirm_below_reference' => ['sometimes', 'accepted'],
            'contact_pay' => ['nullable', 'in:paid,unpaid'],
            'contact_active' => ['nullable', 'boolean'],
        ]);
        if (!Schema::hasTable('leave_type_company_limits') || !Schema::hasColumn('leave_type_company_limits', 'requires_document') || !Schema::hasColumn('leave_type_company_limits', 'is_active') || !Schema::hasColumn('leave_type_company_limits', 'is_paid') || !Schema::hasColumn('leave_type_company_limits', 'deducts_annual_balance') || !Schema::hasColumn('leave_type_company_limits', 'min_employees')) {
            $message = __('لازم تشغّل php artisan migrate قبل حفظ حدود الإجازات.');
            return $request->wantsJson() ? response()->json(['success' => false, 'message' => $message], 422) : back()->withErrors(['leave' => $message]);
        }
        $companyId = (int) auth()->user()->company_id;
        // Every declared policy is editable per company; the UI identifies stored-only values.
        $allowedPolicies = array_merge(array_keys(\App\Support\LeaveLegalReference::policyRules()), ['annual_wait_months', 'allow_early_leave']);
        $data['policies'] = array_intersect_key($data['policies'], array_flip($allowedPolicies));
        foreach (['weekly_enabled', 'holidays_enabled', 'jobsearch_enabled', 'nursing_enabled', 'pregnant_enabled', 'annual_prorate_first_year', 'weekly_rest_paid', 'jobsearch_paid', 'nursing_paid_full', 'holidays_paid', 'holidays_alt_day_allowed'] as $key) {
            if (isset($data['policies'][$key]) && !in_array((string) $data['policies'][$key], ['0', '1'], true)) throw \Illuminate\Validation\ValidationException::withMessages(['policies.'.$key => __('القيمة يجب أن تكون 0 أو 1.')]);
        }
        foreach (['nursing_window_months', 'maternity_months', 'childcare_max_years_per_request', 'childcare_min_gap_years', 'exam_notice_days', 'exam_annual_leave_notice_days', 'annual_min_consecutive_days', 'leave_settlement_planning_years', 'weekly_rest_accumulate_weeks', 'jobsearch_weekly_hours', 'pregnancy_reduced_hours_from_month', 'contagious_max_months', 'maternity_min_postpartum_days', 'sick_phase1_months', 'sick_phase1_pct', 'sick_phase2_months', 'sick_phase2_pct', 'sick_phase3_months', 'sick_phase3_pct', 'sick_cycle_years', 'sick_termination_notice_days'] as $key) {
            if (isset($data['policies'][$key]) && (float) $data['policies'][$key] != (int) $data['policies'][$key]) throw \Illuminate\Validation\ValidationException::withMessages(['policies.'.$key => __('القيمة يجب أن تكون عددًا صحيحًا.')]);
        }
        if ($request->boolean('contact_active') && !$request->filled('contact_pay')) throw \Illuminate\Validation\ValidationException::withMessages(['contact_pay' => __('اختر سياسة الأجر أولًا قبل تفعيل إجازة المخالطة. المادة 132 لا تحدد الأجر.')]);
        if ($request->has('contact_active') && !$request->filled('contact_pay') && LeaveType::where('company_id', $companyId)->where('policy_code', 'contagious_contact_company_'.$companyId)->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['contact_pay' => __('اختر سياسة الأجر للنوع المسجل، أو ألغِ تفعيله مع الإبقاء على سياسة أجره.')]);
        $currentPolicies = DB::table('leave_policy_settings')->where('company_id', $companyId)->pluck('setting_value', 'setting_key')->all();
        $nextPolicies = array_replace($currentPolicies, $data['policies']);
        $warnings = [];
        foreach ($data['policies'] as $key => $value) {
            if ((string) ($currentPolicies[$key] ?? '') !== (string) $value && \App\Support\LeaveLegalReference::policyStatus($key, $value) === 'below') $warnings[] = \App\Support\LeaveLegalReference::policyHint($key);
        }
        $balances = app(\App\Services\LeaveBalanceService::class);
        foreach (($data['types'] ?? []) as $id => $values) {
            $type = LeaveType::find($id);
            if (!$type) abort(422);
            $current = $balances->limitsFor($type, $companyId);
            $next = array_replace($current, $values);
            if ($type->policy_code === 'casual') {
                foreach (['max_days_per_request' => 'casual_max_days_request', 'max_days_per_year' => 'casual_max_days_year'] as $field => $key) {
                    if (isset($values[$field]) && (float) $values[$field] != (float) $current[$field] && \App\Support\LeaveLegalReference::policyStatus($key, $values[$field]) === 'below') $warnings[] = \App\Support\LeaveLegalReference::policyHint($key);
                }
            }
            if ($next != $current) {
                $comparison = \App\Support\LeaveLegalReference::typeStatus($type->policy_code, $next, (bool) ($next['is_paid'] ?? $type->is_paid), (bool) ($next['requires_document'] ?? $type->requires_document), $nextPolicies);
                if ($comparison['status'] === 'below') $warnings = array_merge($warnings, $comparison['notes']);
            }
        }
        if ($warnings && !$request->boolean('confirm_below_reference')) throw \Illuminate\Validation\ValidationException::withMessages(['below_reference' => array_values(array_unique($warnings))]);
        $before = ['policies' => $currentPolicies, 'types' => []];
        foreach (($data['types'] ?? []) as $id => $values) { $type = LeaveType::find($id); if ($type) $before['types'][$id] = $balances->limitsFor($type, $companyId); }
        $limitKeys = ['max_days_per_request', 'max_days_per_year', 'min_service_months', 'max_occurrences_service', 'min_employees', 'requires_document', 'is_active', 'is_paid', 'deducts_annual_balance'];
        $balances = app(\App\Services\LeaveBalanceService::class);
        DB::transaction(function () use ($data, $companyId, $limitKeys, $balances): void {
            if (!empty($data['sick_pay_basis'])) {
                $basisWhere = [['company_id', '=', $companyId], ['setting_key', '=', 'sick_pay_basis']];
                $existsBasis = DB::table('leave_policy_settings')->where($basisWhere)->exists();
                if ($existsBasis) DB::table('leave_policy_settings')->where($basisWhere)->update(['setting_value' => $data['sick_pay_basis'], 'updated_at' => now()]);
                else DB::table('leave_policy_settings')->insert(['company_id' => $companyId, 'setting_key' => 'sick_pay_basis', 'setting_value' => $data['sick_pay_basis'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if (!empty($data['contact_pay'])) {
                LeaveType::updateOrCreate(['company_id' => $companyId, 'policy_code' => 'contagious_contact_company_'.$companyId], ['name' => 'إجازة مخالطة مريض بمرض معد', 'is_paid' => $data['contact_pay'] === 'paid', 'is_active' => (bool) ($data['contact_active'] ?? false), 'deducts_annual_balance' => false, 'requires_document' => true]);
            }
            $setPolicy = function (string $key, $value) use ($companyId): void {
                $where = [['company_id', '=', $companyId], ['setting_key', '=', $key]];
                if (DB::table('leave_policy_settings')->where($where)->exists()) DB::table('leave_policy_settings')->where($where)->update(['setting_value' => (string) $value, 'updated_at' => now()]);
                else DB::table('leave_policy_settings')->insert(['company_id' => $companyId, 'setting_key' => $key, 'setting_value' => (string) $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
            };
            foreach ($data['policies'] as $key => $value) {
                $setPolicy($key, $value);
            }
            foreach (($data['types'] ?? []) as $id => $values) {
                $type = LeaveType::find($id);
                if (!$type) continue;
                // Archived custom types are history-preserved removals: a settings save never resurrects them.
                if ($type->archived_at) continue;
                $values = array_intersect_key($values, array_flip($limitKeys));
                // Single source per rule: casual per-request/per-year live in this table but are kept in the company policy.
                if ($type->policy_code === 'casual') {
                    if (isset($values['max_days_per_request']) && $values['max_days_per_request'] !== '') $setPolicy('casual_max_days_request', $values['max_days_per_request']);
                    if (isset($values['max_days_per_year']) && $values['max_days_per_year'] !== '') $setPolicy('casual_max_days_year', $values['max_days_per_year']);
                    unset($values['max_days_per_request']);
                }
                // Annual / casual waiting time is the «مدة الانتظار» setting, not a per-type field; annual request size is limited by the balance.
                if (in_array($type->policy_code, ['annual', 'casual'], true)) unset($values['min_service_months']);
                if ($type->policy_code === 'annual') unset($values['max_days_per_request']);
                if (!$values) continue;
                if ((int) $type->company_id === $companyId && $companyId) {
                    $type->update($values);
                } elseif (!$type->company_id && $companyId) {
                    // Shared type: save this company's own copy, never the shared row other companies use.
                    // Even switching on a wholesale-inactive shared type only writes this company's limits;
                    // company-effective activation (limits win over the shared row) restores it for this company alone.
                    $merged = array_merge($balances->limitsFor($type, $companyId), $values);
                    $row = array_intersect_key($merged, array_flip($limitKeys)) + ['updated_at' => now()];
                    $exists = DB::table('leave_type_company_limits')->where('company_id', $companyId)->where('leave_type_id', $type->id)->exists();
                    if ($exists) DB::table('leave_type_company_limits')->where('company_id', $companyId)->where('leave_type_id', $type->id)->update($row);
                    else DB::table('leave_type_company_limits')->insert($row + ['company_id' => $companyId, 'leave_type_id' => $type->id, 'created_at' => now()]);
                }
            }
        });
        ActivityLog::record('leave_policy_update', 'Leave policy settings updated.', null, $before, ['policies' => $data['policies'], 'types' => $data['types'] ?? [], 'below_reference_confirmed' => $request->boolean('confirm_below_reference'), 'warnings' => $warnings, 'contact_pay' => $data['contact_pay'] ?? null, 'contact_active' => $data['contact_active'] ?? false], 'leaves');
        return $request->wantsJson() ? response()->json(['success' => true, 'message' => 'Leave policy saved.']) : back()->with('toast', __('تم حفظ إعدادات الإجازات بنجاح.'));
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
            $message = __('تغيير بداية أو نهاية فترة المسير هيقفل ويحفظ سجل الحضور للفترات اللي لسه في مهلة التصحيح، وبعدها مش هيتعدل. ما اتحفظش أي تغيير لحد ما تأكد.');
            return $request->wantsJson()
                ? response()->json(['success' => false, 'needs_confirmation' => true, 'message' => $message, 'periods' => $needsConfirm], 409)
                : back()->withInput()->with('period_confirm', ['message' => $message, 'periods' => $needsConfirm]);
        }
        if ($blocked) {
            $hrSettings->flush();
            $message = __('ما اتحفظش تغيير بداية ونهاية فترة المسير لأن الفترة الجديدة فيها أيام اتحسبت قبل كده. الإعدادات الحالية اتساب زي ما هي، ومفيش أي مسير أو سجل اتغيّر.');
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
            : back()->with('toast', __('تم حفظ إعدادات أوقات الدوام والراحة.'))->with('period_notes', array_values(array_filter(array_merge($transitionNote ? [$transitionNote] : [], array_column($notes, 'text')))));
    }

    public function storeHoliday(Request $request)
    {
        $data = $request->validate(['holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:150']]);
        DB::table('hr_holidays')->updateOrInsert(['company_id'=>auth()->user()->company_id, 'holiday_date' => $data['holiday_date']], ['name' => $data['name'], 'updated_at' => now(), 'created_at' => now()]);
        if ($request->wantsJson()) {
            $holiday = DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('holiday_date', $data['holiday_date'])->first();
            return response()->json(['success' => true, 'message' => 'Holiday saved.', 'holiday' => $holiday]);
        }

        return back()->with('toast', __('تم حفظ العطلة الرسمية.'));
    }

    public function storeLeaveType(Request $request)
    {
        $companyId = $this->companyIdOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'max_days_per_request' => ['nullable', 'numeric', 'min:0'],
            'max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'min_service_months' => ['nullable', 'integer', 'min:0'],
            'max_occurrences_service' => ['nullable', 'integer', 'min:0'],
            'is_paid' => ['nullable', 'boolean'],
            'deducts_annual_balance' => ['nullable', 'boolean'],
            'requires_document' => ['nullable', 'boolean'],
        ]);
        $name = trim($data['name']);
        $clash = LeaveType::where('name', $name)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->exists();
        if ($clash) throw \Illuminate\Validation\ValidationException::withMessages(['name' => __('يوجد نوع إجازة بهذا الاسم بالفعل.')]);
        $type = LeaveType::create([
            'name' => $name,
            'company_id' => $companyId,
            'policy_code' => null,
            'is_active' => true,
            'is_paid' => $request->boolean('is_paid'),
            'deducts_annual_balance' => $request->boolean('deducts_annual_balance'),
            'requires_document' => $request->boolean('requires_document'),
            'max_days_per_request' => $data['max_days_per_request'] ?? null,
            'max_days_per_year' => $data['max_days_per_year'] ?? null,
            'min_service_months' => $data['min_service_months'] ?? null,
            'max_occurrences_service' => $data['max_occurrences_service'] ?? null,
        ]);
        ActivityLog::record('leave_type_create', 'Custom leave type created.', null, null, $type->toArray(), 'leaves');
        return back()->with('toast', __('تمت إضافة نوع الإجازة بنجاح — أصبح متاحًا في طلبات الإجازة.'));
    }

    /**
     * Delete a company-owned custom leave type. Shared (statutory) types are never
     * touched. A type that already has requests is archived instead: it leaves the
     * request choices but its records, balances and payroll history stay intact.
     */
    public function destroyLeaveType(int $leaveType, Request $request)
    {
        $companyId = $this->companyIdOrFail();
        $type = LeaveType::where('id', $leaveType)->where('company_id', $companyId)->first();
        if (!$type) {
            $message = __('نوع الإجازة غير موجود ضمن أنواع شركتك الخاصة.');
            return $request->wantsJson() ? response()->json(['success' => false, 'message' => $message], 404) : back()->withErrors(['leave' => $message]);
        }
        if ($type->leaves()->exists()) {
            $type->is_active = false;
            $type->archived_at = now();
            $type->save();
            ActivityLog::record('leave_type_archive', 'Custom leave type archived.', null, null, $type->toArray(), 'leaves');
            $message = __('للنوع طلبات سابقة — أُزيل من الاختيارات الجديدة مع حفظ سجلاته كاملة.');
            return $request->wantsJson() ? response()->json(['success' => true, 'archived' => true, 'message' => $message]) : back()->with('toast', $message);
        }
        $snapshot = $type->toArray();
        DB::table('leave_type_company_limits')->where('leave_type_id', $type->id)->delete();
        $type->delete();
        ActivityLog::record('leave_type_delete', 'Custom leave type deleted.', null, null, $snapshot, 'leaves');
        $message = __('تم حذف نوع الإجازة نهائيًا — لم تكن له أي طلبات.');
        return $request->wantsJson() ? response()->json(['success' => true, 'archived' => false, 'message' => $message]) : back()->with('toast', $message);
    }

    public function destroyHoliday(int $holiday, Request $request)
    {
        DB::table('hr_holidays')->where('company_id',auth()->user()->company_id)->where('id', $holiday)->delete();
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Holiday deleted.', 'holiday_id' => $holiday])
            : back()->with('toast', __('تم حذف العطلة الرسمية.'));
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

        return back()->with('toast', __('تم تحديث العطلة الرسمية.'));
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
        abort_if(!$companyId, 403, __('إعدادات الرواتب تخص شركة محددة.'));

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
            'allowance_prorated' => ['nullable', 'array'],
            'allowance_prorated.*' => ['nullable', 'boolean'],
        ]);

        abort_if(array_diff(array_keys($data['settings']), self::SETTING_KEYS) !== [], 422, 'Invalid payroll setting.');
        foreach ($data['settings'] as $value) {
            if ($value !== null && $value !== '' && (!is_numeric($value) || (float) $value < 0)) {
                return back()->withErrors(['settings' => __('كل قيم الإعدادات يجب أن تكون أرقامًا موجبة.')])->withInput();
            }
        }
        // The last allowance (by sort order) takes the remaining share of the
        // pool automatically, so the split always totals 100%.
        $activeAllowances = DB::table('payroll_allowance_settings')->where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order')->get();
        abort_if($activeAllowances->isEmpty(), 422, 'Invalid payroll allowance.');
        $knownKeys = $activeAllowances->pluck('allowance_key')->all();
        abort_if(array_diff(array_keys($data['allowances']), $knownKeys) !== [], 422, 'Invalid payroll allowance.');
        abort_if(array_diff(array_keys($data['allowance_prorated'] ?? []), $knownKeys) !== [], 422, 'Invalid payroll allowance.');
        $lastKey = $activeAllowances->last()->allowance_key;
        $othersSum = round(array_sum(array_map('floatval', collect($data['allowances'])->except($lastKey)->all())), 4);
        if ($othersSum - 100 > 0.0001) {
            return back()->withErrors(['allowances' => __('مجموع البدلات قبل الأخير لا يمكن أن يتجاوز 100%.')])->withInput();
        }
        $data['allowances'][$lastKey] = round(100 - $othersSum, 4);

        app(PayrollDefaultsService::class)->ensureForCompany($companyId);
        $proratedFlags = $data['allowance_prorated'] ?? [];
        DB::transaction(function () use ($data, $companyId, $proratedFlags) {
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
                $row = ['percentage' => $percentage, 'updated_at' => now()];
                if (Schema::hasColumn('payroll_allowance_settings', 'effective_from')) {
                    $row['effective_from'] = $data['effective_from'];
                }
                if (Schema::hasColumn('payroll_allowance_settings', 'is_prorated') && $proratedFlags !== []) {
                    $row['is_prorated'] = !empty($proratedFlags[$key]) ? 1 : 0;
                }
                DB::table('payroll_allowance_settings')->where('company_id', $companyId)->where('allowance_key', $key)->where('is_active', true)
                    ->update($row);
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
                return back()->withErrors(['tax' => __('آخر فئة فقط تُترك بدون حد أعلى، وباقي الفئات لازم لها حد أعلى.')])->withInput();
            }
            if ($max !== null && $previousMax !== null && $max <= $previousMax) {
                return back()->withErrors(['tax' => __('حدود الفئات لازم تكون تصاعدية.')])->withInput();
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
