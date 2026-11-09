@push('scripts')
<script data-request-create-runtime>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  // Status is controlled by the request workflow and must never be an edit
  // field. Remove any stale field left by an older cached template/script.
  const removeWorkflowStatusField = () => {
    form.querySelectorAll('[name="status"], [name="request_status"], [data-request-status-field]').forEach((field) => {
      const group = field.closest('.mb-3, .form-group, .row, .request-status-field');
      if (group && group !== form) group.remove(); else field.remove();
    });
  };
  removeWorkflowStatusField();
  form.querySelector('[name="details"]')?.setAttribute('required', 'required');
  const type = form.querySelector('[name="request_type_id"]');
  const modalFooter = form.querySelector('.modal-footer');
  const oldFields = form.querySelector('.row.g-2.mt-2');
  oldFields?.remove();
  const overtimeTypeIds = @json($requestTypes->filter(fn ($item) => $item->source_key === 'overtime')->pluck('id')->values());
  const fields = document.createElement('div');
  fields.className = 'request-conditional-card overtime-request-fields';
  fields.hidden = true;
  fields.innerHTML = '<div class="col-12"><label class="form-label">تاريخ الإضافي</label><input type="date" name="overtime_request_date" class="form-control"></div><div class="col-12"><label class="form-label">نوع ساعات الإضافي</label><select name="overtime_period" class="form-select"><option value="">اختر نوع الساعات</option><option value="day">ساعات عمل نهاري</option><option value="night">ساعات عمل ليلي</option></select></div><div class="col-12"><label class="form-label">عدد الساعات (تُحسب تلقائيًا من المدة)</label><input type="number" step="0.25" name="day_overtime_hours" class="form-control" readonly tabindex="-1"></div><div class="col-12"><div class="d-flex gap-2"><div class="flex-fill"><label class="form-label">من الساعة</label><input type="time" name="from_time" class="form-control" dir="ltr"></div><div class="flex-fill"><label class="form-label">إلى الساعة</label><input type="time" name="to_time" class="form-control" dir="ltr"></div></div></div>';
  modalFooter?.before(fields);
  const date = fields.querySelector('input[type="date"]');
  const period = fields.querySelector('[name="overtime_period"]');
  const hours = fields.querySelector('input[type="number"]');
  const fromTime = fields.querySelector('[name="from_time"]');
  const toTime = fields.querySelector('[name="to_time"]');
  period?.addEventListener('change', () => { hours.name = period.value === 'night' ? 'night_overtime_hours' : 'day_overtime_hours'; });
  // Hours are derived from the from/to window; an end at or before the
  // start crosses midnight (overnight overtime).
  const syncHoursFromWindow = () => {
    if (!fromTime?.value || !toTime?.value) return;
    const [fh, fm] = fromTime.value.split(':').map(Number);
    const [th, tm] = toTime.value.split(':').map(Number);
    let minutes = (th * 60 + tm) - (fh * 60 + fm);
    if (minutes <= 0) minutes += 1440;
    hours.value = String(Math.round((minutes / 60) * 100) / 100);
  };
  [fromTime, toTime].forEach((input) => { input?.addEventListener('input', syncHoursFromWindow); input?.addEventListener('change', syncHoursFromWindow); });
  const sync = () => {
    const isOvertime = overtimeTypeIds.map(String).includes(String(type?.value || ''));
    fields.hidden = !isOvertime;
    [date, period, hours, fromTime, toTime].forEach((input) => { if (input) input.required = isOvertime; });
    if (isOvertime && !date.value) date.value = @json(today()->toDateString());
    if (!isOvertime) { date.value = ''; period.value = ''; hours.value = ''; if (fromTime) fromTime.value = ''; if (toTime) toTime.value = ''; }
  };
  type?.addEventListener('change', sync);
  sync();
})();
</script>
@endpush



@push('styles')
<link rel="stylesheet" href="{{ asset('css/pages/requests-index-02.css') }}?v={{ filemtime(public_path('css/pages/requests-index-02.css')) }}">
@endpush
@push('scripts')
<script data-request-create-runtime>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  // The edit form can be opened through AJAX after this script initializes,
  // so read the editing state dynamically instead of capturing its initial value.
  const isEditingRequest = () => form.dataset.editing === '1';
  const employees = @json($employees->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->full_name, 'code' => $employee->employee_code])->values());
  const employeeGrossSalaries = @json($employeeGrossSalaries);
  const employeeRequestMonths = @json($employeeRequestMonths);
  const employeeOvertimeDays = @json($employeeOvertimeDays);
  const employeeAdvanceWarnings = @json($employeeAdvanceWarnings);
  // Keep the client-side warnings synchronized after an AJAX deletion. The
  // page must not continue showing a warning for a request that was removed.
  document.addEventListener('masar:request-deleted', (event) => {
    const detail = event.detail || {};
    const employeeId = String(detail.employee_id || '');
    if (!employeeId) return;
    const remaining = detail.remaining_advance || {};
    if (Number(remaining.amount || 0) > 0) employeeAdvanceWarnings[employeeId] = remaining;
    else delete employeeAdvanceWarnings[employeeId];
    const month = detail.request_date ? String(detail.request_date).slice(0, 7) : '';
    if (month && Array.isArray(employeeRequestMonths[employeeId])) {
      employeeRequestMonths[employeeId] = employeeRequestMonths[employeeId].filter((value) => value !== month);
    }
  });
  const employeeSelect = form.querySelector('select[name="employee_id"]');
  if (employeeSelect) {
    const wrapper = employeeSelect.parentElement;
    const lookup = document.createElement('input'); lookup.className = 'form-control'; lookup.name = 'employee_lookup'; lookup.setAttribute('list','requestEmployeesList'); lookup.placeholder = 'ابحث باسم الموظف أو كوده'; lookup.required = true;
    const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'employee_id'; hidden.required = true;
    const list = document.createElement('datalist'); list.id = 'requestEmployeesList';
    list.innerHTML = employees.map((employee) => `<option value="${employee.name} — #${employee.code}"></option>`).join('');
    employeeSelect.removeAttribute('name'); employeeSelect.removeAttribute('required'); employeeSelect.hidden = true;
    wrapper.append(lookup, hidden, list);
    const syncEmployee = () => {
      const query = lookup.value.trim().toLocaleLowerCase();
      const found = employees.find((employee) => `${employee.name} — #${employee.code}`.toLocaleLowerCase() === query || String(employee.code || '').toLocaleLowerCase() === query || String(employee.name || '').toLocaleLowerCase() === query);
      hidden.value = found?.id || '';
      hidden.dispatchEvent(new Event('change'));
    };
    lookup.addEventListener('input', syncEmployee); lookup.addEventListener('change', syncEmployee);
  }
  const type = form.querySelector('[name="request_type_id"]');
  const title = form.querySelector('[name="title"]');
  const typeMeta = @json($requestTypes->mapWithKeys(fn ($item) => [$item->id => ['label' => $item->display_name, 'source' => $item->source_key]]));
  let generatedTitle = '';
  const syncTitle = () => { const meta = typeMeta[type?.value]; if (!meta) return; if (!title.value.trim() || title.value === generatedTitle) title.value = meta.label; generatedTitle = meta.label; };
  type?.addEventListener('change', syncTitle); syncTitle();
  const overtimeCard = form.querySelector('.overtime-request-fields');
  const overtimeWarning = document.createElement('div');
  overtimeWarning.className = 'overtime-existing-notice';
  overtimeWarning.hidden = true;
  overtimeCard?.append(overtimeWarning);
  const syncOvertimeWarning = () => {
    const employeeId = String(employeeIdInput?.value || form.querySelector('input[name="employee_id"]')?.value || '');
    const date = overtimeCard?.querySelector('[name="overtime_request_date"]')?.value || '';
    const period = overtimeCard?.querySelector('[name="overtime_period"]')?.value || '';
    const existing = employeeOvertimeDays[employeeId]?.[date] || [];
    const labels = { day: 'إضافي نهاري', night: 'إضافي ليلي' };
    if (!employeeId || !date || !period || form.dataset.editingId) { overtimeWarning.hidden = true; return; }
    const hasSame = existing.includes(period);
    const hasOther = existing.filter((value) => value !== period).map((value) => labels[value] || value);
    overtimeWarning.hidden = !hasSame && !hasOther.length;
    overtimeWarning.className = 'overtime-existing-notice is-info';
    overtimeWarning.innerHTML = hasSame
      ? `<i class="bi bi-exclamation-circle"></i> يوجد ${labels[period] || 'إضافي'} لهذا الموظف في نفس اليوم؛ سيُسجَّل الطلب مع تنبيه بالتكرار.`
      : `<i class="bi bi-info-circle"></i> يوجد ${hasOther.join(' و ')} في هذا اليوم. يمكنك إضافة ${labels[period] || 'النوع الآخر'} أيضًا.`;
  };
  overtimeCard?.querySelector('[name="overtime_request_date"]')?.addEventListener('change', syncOvertimeWarning);
  overtimeCard?.querySelector('[name="overtime_period"]')?.addEventListener('change', syncOvertimeWarning);
  const otherCard = document.createElement('div'); otherCard.className = 'request-conditional-card'; otherCard.hidden = true;
  otherCard.innerHTML = '<div class="mb-3"><label class="form-label">تاريخ الطلب المؤثر</label><input type="date" name="request_date" class="form-control"></div><div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="affects_payroll" value="1" id="requestAffectsPayroll"><label class="form-check-label" for="requestAffectsPayroll">يؤثر على دورة الرواتب</label></div><div class="row g-2" data-payroll-impact-fields hidden><div class="col-md-6"><label class="form-label">المبلغ</label><input type="number" name="impact_amount" min="0.01" step="0.01" class="form-control"></div><div class="col-md-6"><label class="form-label">نوع التأثير</label><select name="impact_type" class="form-select"><option value="">اختر نوع التأثير</option><option value="earning">مستحق</option><option value="deduction">مستقطع</option></select></div></div>';
  form.querySelector('.modal-footer')?.before(otherCard);
  const otherDate = otherCard.querySelector('[name="request_date"]'); const affects = otherCard.querySelector('[name="affects_payroll"]'); const impactFields = otherCard.querySelector('[data-payroll-impact-fields]'); const impactAmount = otherCard.querySelector('[name="impact_amount"]'); const impactType = otherCard.querySelector('[name="impact_type"]');
  const syncImpact = () => { const active = affects.checked; impactFields.hidden = !active; impactAmount.required = active; impactType.required = active; if (!active) { impactAmount.value = ''; impactType.value = ''; } };
  affects.addEventListener('change', syncImpact);
  const syncOther = () => { const isOther = typeMeta[type?.value]?.source === 'other'; otherCard.hidden = !isOther; otherDate.required = isOther; if (isOther && !otherDate.value) otherDate.value = @json(today()->toDateString()); if (!isOther) { otherDate.value = ''; affects.checked = false; syncImpact(); } };
  type?.addEventListener('change', syncOther); syncOther(); syncImpact();

  const financialSources = ['bonus', 'incentive', 'commission', 'profit_share'];
  const financialCard = document.createElement('div');
  financialCard.className = 'request-conditional-card financial-request-fields';
  financialCard.hidden = true;
  financialCard.innerHTML = '<div class="row g-3"><div class="col-md-6"><label class="form-label">تاريخ الطلب</label><input type="date" name="financial_request_date" class="form-control"></div><div class="col-md-6"><label class="form-label">المبلغ (جنيه)</label><input type="number" name="financial_amount" class="form-control" min="0.01" step="0.01"></div></div>';
  form.querySelector('.modal-footer')?.before(financialCard);
  const financialDate = financialCard.querySelector('[name="financial_request_date"]');
  const financialAmount = financialCard.querySelector('[name="financial_amount"]');
  const syncFinancial = () => {
    const active = financialSources.includes(typeMeta[type?.value]?.source);
    financialCard.hidden = !active;
    financialDate.required = active;
    financialAmount.required = active;
    if (active && !financialDate.value) financialDate.value = @json(today()->toDateString());
    if (!active) { financialDate.value = ''; financialAmount.value = ''; }
  };
  type?.addEventListener('change', syncFinancial); syncFinancial();

  const advanceTypeIds = Object.entries(typeMeta).filter(([, meta]) => meta.source === 'advance').map(([id]) => String(id));
  const advanceCard = document.createElement('div');
  advanceCard.className = 'request-conditional-card advance-request-fields';
  advanceCard.hidden = true;
  advanceCard.innerHTML = '<div class="advance-field-grid"><div><label class="form-label">تاريخ طلب السلفة</label><input type="date" name="request_date" class="form-control" required></div><div><label class="form-label">مبلغ السلفة (جنيه)</label><input type="number" name="advance_amount" class="form-control" min="0.01" step="0.01"></div><div class="advance-limit-box"><span>الحد الافتراضي للسلفة</span><strong data-advance-limit>—</strong><small>10% من الراتب الإجمالي</small></div></div><div class="advance-warning" data-advance-warning hidden><i class="bi bi-exclamation-triangle"></i><span>المبلغ يتجاوز 10% من الراتب الإجمالي. يلزم تأكيد المتابعة.</span></div><div class="form-check advance-confirm" data-advance-confirm hidden><input class="form-check-input" type="checkbox" name="advance_over_limit_confirm" value="1" id="advanceOverLimitConfirm"><label class="form-check-label" for="advanceOverLimitConfirm">أؤكد أن مبلغ السلفة يتجاوز الحد الافتراضي وأرغب في المتابعة.</label></div>';
  form.querySelector('.modal-footer')?.before(advanceCard);
  const advanceAmount = advanceCard.querySelector('[name="advance_amount"]');
  const advanceDate = advanceCard.querySelector('[name="request_date"]');
  const advanceLimit = advanceCard.querySelector('[data-advance-limit]');
  const advanceWarning = advanceCard.querySelector('[data-advance-warning]');
  const advanceConfirm = advanceCard.querySelector('[name="advance_over_limit_confirm"]');
  const employeeIdInput = form.querySelector('input[name="employee_id"]');
  employeeIdInput?.addEventListener('change', syncOvertimeWarning);
  type?.addEventListener('change', syncOvertimeWarning);
  const existingAdvanceNotice = document.createElement('div');
  existingAdvanceNotice.className = 'advance-existing-notice';
  existingAdvanceNotice.hidden = true;
  advanceCard.append(existingAdvanceNotice);
  const existingAdvanceConfirm = document.createElement('input');
  existingAdvanceConfirm.type = 'checkbox';
  existingAdvanceConfirm.name = 'advance_existing_confirm';
  existingAdvanceConfirm.value = '1';
  existingAdvanceConfirm.required = false;
  const existingAdvanceConfirmLabel = document.createElement('label');
  existingAdvanceConfirmLabel.className = 'form-check-label';
  existingAdvanceConfirmLabel.textContent = 'أؤكد أنني أريد إنشاء سلفة جديدة رغم وجود رصيد سابق غير مسدد.';
  const existingAdvanceConfirmWrap = document.createElement('div');
  existingAdvanceConfirmWrap.className = 'form-check advance-existing-confirm';
  existingAdvanceConfirmWrap.hidden = true;
  existingAdvanceConfirmWrap.append(existingAdvanceConfirm, existingAdvanceConfirmLabel);
  advanceCard.append(existingAdvanceConfirmWrap);
  // Laravel's `accepted` rule accepts "yes" consistently across versions;
  // keep the submitted value explicit instead of relying on browser defaults.
  existingAdvanceConfirm.value = 'yes';
  const previousAdvanceForEmployee = () => {
    const employeeId = String(employeeIdInput?.value || '');
    return employeeAdvanceWarnings[employeeId] || employeeAdvanceWarnings[Number(employeeId)] || null;
  };
  const formatMoney = (value) => `${Number(value || 0).toLocaleString('ar-EG', {maximumFractionDigits: 2})} ج.م`;
  const syncAdvance = () => {
    const active = advanceTypeIds.includes(String(type?.value || ''));
    advanceCard.hidden = !active;
    if (!active) { advanceDate.value = ''; advanceDate.required = false; advanceAmount.value = ''; advanceAmount.required = false; advanceWarning.hidden = true; advanceConfirm.checked = false; advanceConfirm.required = false; return; }
    advanceDate.required = true;
    if (!advanceDate.value) advanceDate.value = @json(today()->toDateString());
    advanceAmount.required = true;
    const gross = Number(employeeGrossSalaries[employeeIdInput?.value] || 0);
    const limit = gross * 0.10;
    advanceLimit.textContent = gross > 0 ? formatMoney(limit) : 'اختر الموظف أولًا';
    const overLimit = Number(advanceAmount.value || 0) > limit;
    advanceWarning.hidden = !overLimit;
    advanceConfirm.closest('.advance-confirm').hidden = !overLimit;
    advanceConfirm.required = overLimit;
    if (!overLimit) advanceConfirm.checked = false;
    const previous = previousAdvanceForEmployee();
    if (isEditingRequest()) {
      existingAdvanceNotice.hidden = true;
      existingAdvanceConfirmWrap.hidden = true;
      existingAdvanceConfirm.required = false;
      existingAdvanceConfirm.checked = false;
      return;
    }
    existingAdvanceNotice.hidden = !previous;
    existingAdvanceNotice.innerHTML = previous
        ? `<i class="bi bi-info-circle"></i> سبق للموظف الحصول على سلفة بقيمة ${formatMoney(previous.original_amount)} بتاريخ ${previous.date || '—'}، والمتبقي منها ${formatMoney(previous.amount)}.`
        : '';
    existingAdvanceConfirmWrap.hidden = !previous;
    // Keep the confirmation in sync with visibility.  A hidden required
    // checkbox causes the browser/AJAX request to fail with the generic
    // "field must be accepted" message before the user can act on it.
    // The form is submitted through the global AJAX handler, so a dynamic
    // required checkbox can make reportValidity() silently stop the submit.
    // We validate it in the cancellable before-submit hook below instead.
    existingAdvanceConfirm.required = false;
    if (!previous) existingAdvanceConfirm.checked = false;
  };
  advanceAmount.addEventListener('input', syncAdvance);
  employeeIdInput?.addEventListener('change', syncAdvance);
  type?.addEventListener('change', syncAdvance);
  syncAdvance();
  syncOvertimeWarning();

  // Prevent accidental value changes when the pointer wheel is over a
  // numeric field; values remain editable by keyboard or direct input.
  form.querySelectorAll('input[type="number"]').forEach((input) => {
    input.addEventListener('wheel', (event) => {
      event.preventDefault();
      input.blur();
    }, { passive: false });
  });

  // Give the user a clear, local explanation instead of submitting a request
  // that the server must reject when an earlier advance is still outstanding.
  form.addEventListener('submit', (event) => {
    const active = advanceTypeIds.includes(String(type?.value || ''));
    const previous = previousAdvanceForEmployee();
    if (!isEditingRequest() && active && previous && !existingAdvanceConfirm.checked) {
      event.preventDefault();
      existingAdvanceConfirmWrap.hidden = false;
      existingAdvanceConfirm.required = false;
      existingAdvanceNotice.hidden = false;
      existingAdvanceConfirm.focus();
      window.showToast?.('يوجد رصيد سلفة سابق غير مسدد. فعّل مربع التأكيد للمتابعة أو اختر موظفًا آخر.');
    }
  }, true);

  form.addEventListener('masar:before-submit', (event) => {
    const active = advanceTypeIds.includes(String(type?.value || ''));
    const previous = previousAdvanceForEmployee();
    if (isEditingRequest() || !active || !previous || existingAdvanceConfirm.checked) return;
    event.preventDefault();
    existingAdvanceConfirmWrap.hidden = false;
    existingAdvanceConfirm.required = false;
    existingAdvanceNotice.hidden = false;
    existingAdvanceConfirm.focus();
    window.showToast?.('يوجد رصيد سلفة سابق غير مسدد. فعّل مربع التأكيد للمتابعة.');
  });

  // Multiple conditional sections use request_date. Submit only the date
  // belonging to the selected request type so hidden sections cannot
  // overwrite the active value with an empty one.
  form.addEventListener('submit', () => {
    const source = typeMeta[type?.value]?.source;
    const activeDate = source === 'advance'
      ? advanceDate
      : source === 'overtime'
        ? overtimeCard?.querySelector('[name="overtime_request_date"], [name="request_date"]')
        : source === 'other'
          ? otherDate
          : ['permission', 'mission'].includes(source)
            ? form.querySelector('[data-excuse-card] [name="request_date"]')
            : null;
    form.querySelectorAll('[name="request_date"]').forEach((input) => {
      input.disabled = input !== activeDate;
    });
    if (activeDate) activeDate.disabled = false;
  });
})();
</script>
@endpush

@push('scripts')
<script data-request-create-runtime>
(() => {
  const form = document.querySelector('#requestCreateModal form');
  if (!form) return;
  const overtime = form.querySelector('.overtime-request-fields');
  const advance = form.querySelector('.advance-request-fields');
  const other = [...form.querySelectorAll('.request-conditional-card')].find((card) => card !== overtime && card !== advance);
  const overtimeDate = overtime?.querySelector('input[type="date"]');
  const overtimePeriod = overtime?.querySelector('[name="overtime_period"]');
  const overtimeHours = overtime?.querySelector('input[type="number"]');
  if (overtimeDate) overtimeDate.name = 'overtime_request_date';
  if (overtimeHours) {
    const syncOvertimeHoursName = () => { overtimeHours.name = overtimePeriod?.value === 'night' ? 'night_overtime_hours' : 'day_overtime_hours'; };
    overtimePeriod?.addEventListener('change', syncOvertimeHoursName);
    syncOvertimeHoursName();
  }
  const advanceDate = advance?.querySelector('input[type="date"]');
  if (advanceDate) advanceDate.name = 'advance_request_date';
  const otherDate = other?.querySelector('input[type="date"]');
  if (otherDate) otherDate.name = 'other_request_date';
})();
</script>
@endpush
