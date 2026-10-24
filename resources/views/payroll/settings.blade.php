@extends('layouts.app')
@section('title', 'الإعدادات')
@section('content')
<div class="page-head"><div><h1>الإعدادات</h1><p>إدارة قواعد الحساب وهياكل الرواتب من مكان واحد مع سجل تاريخي لكل تعديل.</p></div><a class="btn btn-outline-primary" href="{{ route('salaries.index') }}"><i class="bi bi-cash-stack"></i> هياكل الرواتب</a></div>
<div class="stat-grid compact">@foreach([['هياكل نشطة',$salarySummary['active'],'blue','cash-stack'],['إجمالي صافي الهياكل',format_money($salarySummary['total_net']).' ج.م','green','wallet2'],['هياكل تحتاج مراجعة بدلات',$salarySummary['allowance_review'],'orange','exclamation-triangle'],['آخر تعديلات',count($history),'purple','clock-history']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>بيانات مباشرة</small></div></div>@endforeach</div>
<div class="alert alert-warning mb-3"><i class="bi bi-shield-check ms-1"></i> التعديلات تطبق على الحسابات الجديدة فقط. المسيرات المعتمدة أو المقفلة تحتفظ بلقطة مستقلة من قواعدها.</div>
<nav class="settings-tabs" aria-label="أقسام الإعدادات"><button type="button" class="settings-tab active" data-settings-tab="payrollRules"><i class="bi bi-cash-stack"></i> قواعد الرواتب</button><button type="button" class="settings-tab" data-settings-tab="operational"><i class="bi bi-clock-history"></i> أوقات الدوام</button><button type="button" class="settings-tab" data-settings-tab="holidays"><i class="bi bi-calendar-event"></i> العطلات الرسمية</button><button type="button" class="settings-tab" data-settings-tab="history"><i class="bi bi-clock-history"></i> سجل التعديلات</button></nav>
<button type="button" class="settings-tab" data-settings-tab="leavePolicy"><i class="bi bi-calendar2-heart"></i> إعدادات الإجازات</button>
<button type="button" class="settings-tab" data-settings-tab="calcRules"><i class="bi bi-sliders"></i> قواعد أساس الحساب</button>
<button type="button" class="settings-tab" data-settings-tab="taxBrackets"><i class="bi bi-percent"></i> شرائح الضرائب</button>
<script>document.querySelector('.settings-tabs')?.append(document.querySelector('[data-settings-tab="leavePolicy"]'),document.querySelector('[data-settings-tab="calcRules"]'),document.querySelector('[data-settings-tab="taxBrackets"]'));</script>
<form id="payrollRules" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.update') }}">@csrf @method('PUT')
<div class="panel mb-3"><div class="panel-head"><div><h3>سريان التعديل</h3><span>حدد التاريخ الذي تبدأ منه القواعد الجديدة.</span></div></div><div class="p-3"><div class="col-md-4"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ old('effective_from', now()->toDateString()) }}" class="form-control" required></div></div></div>
<div class="panel mb-3"><div class="panel-head"><div><h3>النسب والحدود</h3><span>قيم هذه الصفحة هي مصدر الحساب لخدمات الرواتب؛ التعديل يُحفظ بسجل تاريخي.</span></div></div><div class="p-3 row g-3">@php($fields=['general.allowance_rate'=>'نسبة البدلات من الأساسي (%)','general.proration_days'=>'أيام الشهر لتسوية الراتب الجزئي','insurance.employee_insurance_rate'=>'تأمين الموظف (%)','insurance.employer_insurance_rate'=>'تأمين صاحب العمل (%)','insurance.martyrs_fund_rate'=>'صندوق أسر الشهداء (%)','insurance.insurance_minimum'=>'الحد الأدنى للأجر التأميني','insurance.insurance_maximum'=>'الحد الأقصى للأجر التأميني','tax.personal_exemption'=>'الإعفاء الشخصي الشهري','tax.tax_deductible_contributions'=>'اشتراكات قابلة للخصم ضريبيًا (سنويًا)','tax.profit_share_exempt_percent'=>'نسبة نصيب الأرباح المعفاة من الضريبة (%)','overtime.hours_divisor'=>'أساس ساعة الإضافي (÷)','overtime.days_divisor'=>'أيام الشهر لأجر يوم الراحة والعطلات','overtime.day_multiplier'=>'معامل الإضافي النهاري','overtime.night_multiplier'=>'معامل الإضافي الليلي','overtime.official_holiday_multiplier'=>'معامل العطلة الرسمية (2 = يومين)','overtime.weekly_rest_multiplier'=>'معامل الراحة الأسبوعية (1 = بنفس سعر اليوم)','absence.daily_divisor'=>'مقسم الغياب اليومي','penalty.daily_divisor'=>'مقسم الجزاء اليومي','penalty.max_days_per_month'=>'سقف أيام الجزاءات في الشهر (0 = بدون سقف)','advance.max_percent_of_gross'=>'سقف السلفة والقسط من الإجمالي (%)'])@foreach($fields as $key=>$label)<div class="col-md-4"><label class="form-label">{{ $label }}</label><input type="number" step="0.000001" min="0" name="settings[{{ $key }}]" value="{{ format_number(old('settings.'.$key,$settings[$key]->setting_value ?? '')) }}" class="form-control" required></div>@endforeach</div></div>
<div class="panel mb-3"><div class="panel-head"><div><h3>توزيع البدلات</h3><span>يجب أن يكون المجموع 100% قبل الحفظ.</span></div></div><div class="p-3 row g-3">@foreach($allowances as $allowance)<div class="col-md-3"><label class="form-label">{{ $allowance->allowance_name }} (%)</label><input type="number" step="0.0001" min="0" max="100" name="allowances[{{ $allowance->allowance_key }}]" value="{{ format_number(old('allowances.'.$allowance->allowance_key,$allowance->percentage)) }}" class="form-control allowance-input" required></div>@endforeach<div class="col-md-3"><label class="form-label">المجموع</label><input id="allowance-total" class="form-control" readonly></div></div></div>
<button class="btn btn-primary"><i class="bi bi-save"></i> حفظ الإعدادات</button></form>

<form id="calcRules" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.rules') }}" hidden>@csrf @method('PUT')
<div class="panel mb-3"><div class="panel-head"><div><h3>قواعد أساس الحساب</h3><span>اختر الأساس الذي يُحسب عليه كل بند: الأساسي (Basic) أو الإجمالي (Gross). تسري على موظفي شركتك فقط، وتُعاد حسابات هياكل الرواتب فور الحفظ.</span></div></div>
<div class="p-3 row g-3">
<div class="col-md-4"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div>
<div class="col-12"></div>
@foreach(['insurance_basis'=>['insurance.automatic_calculation_basis','أساس الأجر التأميني التلقائي'],'overtime_basis'=>['overtime.calculation_basis','أساس أجر ساعة الإضافي وأجر يوم الراحة والعطلات'],'absence_basis'=>['absence.calculation_basis','أساس خصم الغياب'],'penalty_basis'=>['penalty.calculation_basis','أساس خصم الجزاءات']] as $ruleField=>$ruleMeta)
<div class="col-md-6"><label class="form-label">{{ $ruleMeta[1] }}</label><select name="rules[{{ $ruleField }}]" class="form-select" required>@foreach(['Basic'=>'الأساسي (Basic)','Gross'=>'الإجمالي (Gross)'] as $ruleValue=>$ruleText)<option value="{{ $ruleValue }}" @selected(($rules[$ruleMeta[0]]->rule_value ?? null) === $ruleValue)>{{ $ruleText }}</option>@endforeach</select><small class="text-muted">{{ $rules[$ruleMeta[0]]->description ?? '' }}</small></div>
@endforeach
</div></div>
<button class="btn btn-primary"><i class="bi bi-save"></i> حفظ القواعد</button></form>

<form id="taxBrackets" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.tax') }}" hidden>@csrf @method('PUT')
@error('tax')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="panel mb-3"><div class="panel-head"><div><h3>شرائح ضريبة كسب العمل</h3><span>عدّل الفئات والشرائح عند تغيّر القانون. كل شريحة تبدأ من حيث انتهت السابقة، وآخر شريحة بدون حد أعلى. التغيير يخص شركتك فقط.</span></div></div>
<div class="p-3">
<div class="col-md-4 mb-3"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div>
<div id="taxCategories">
@foreach($taxCategories as $ci=>$category)
<div class="tax-category border rounded p-3 mb-3">
<div class="row g-2 mb-2"><div class="col-md-6"><label class="form-label">اسم الفئة</label><input data-field="label" name="categories[{{ $ci }}][label]" value="{{ $category->label }}" class="form-control" required></div>
<div class="col-md-4"><label class="form-label">الحد الأعلى للدخل السنوي (فارغ للفئة الأخيرة)</label><input type="number" step="0.01" min="0" data-field="max_income" name="categories[{{ $ci }}][max_income]" value="{{ $category->max_income !== null ? format_number($category->max_income) : '' }}" class="form-control"></div>
<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-category">حذف الفئة</button></div></div>
<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>من</th><th>إلى (فارغ = بلا حد)</th><th>النسبة %</th><th></th></tr></thead><tbody>
@foreach($category->brackets as $bi=>$bracket)
<tr><td><input type="number" step="0.01" min="0" data-field="from" name="categories[{{ $ci }}][brackets][{{ $bi }}][from]" value="{{ format_number($bracket->from_amount) }}" class="form-control form-control-sm" required></td><td><input type="number" step="0.01" min="0" data-field="to" name="categories[{{ $ci }}][brackets][{{ $bi }}][to]" value="{{ $bracket->to_amount !== null ? format_number($bracket->to_amount) : '' }}" class="form-control form-control-sm"></td><td><input type="number" step="0.01" min="0" max="100" data-field="rate" name="categories[{{ $ci }}][brackets][{{ $bi }}][rate]" value="{{ round((float) $bracket->rate * 100, 4) }}" class="form-control form-control-sm" required></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-bracket">حذف</button></td></tr>
@endforeach
</tbody></table></div>
<button type="button" class="btn btn-sm btn-outline-primary add-bracket"><i class="bi bi-plus"></i> إضافة شريحة</button>
</div>
@endforeach
</div>
<button type="button" class="btn btn-outline-primary" id="addTaxCategory"><i class="bi bi-plus-circle"></i> إضافة فئة</button>
</div></div>
<button class="btn btn-primary"><i class="bi bi-save"></i> حفظ شرائح الضرائب</button></form>
<script>
(function () {
    const wrap = document.getElementById('taxCategories');
    const form = document.getElementById('taxBrackets');
    if (!wrap || !form) return;
    const rowHtml = '<tr><td><input type="number" step="0.01" min="0" data-field="from" class="form-control form-control-sm" required></td><td><input type="number" step="0.01" min="0" data-field="to" class="form-control form-control-sm"></td><td><input type="number" step="0.01" min="0" max="100" data-field="rate" class="form-control form-control-sm" required></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-bracket">حذف</button></td></tr>';
    const categoryHtml = '<div class="tax-category border rounded p-3 mb-3"><div class="row g-2 mb-2"><div class="col-md-6"><label class="form-label">اسم الفئة</label><input data-field="label" class="form-control" required></div><div class="col-md-4"><label class="form-label">الحد الأعلى للدخل السنوي (فارغ للفئة الأخيرة)</label><input type="number" step="0.01" min="0" data-field="max_income" class="form-control"></div><div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-category">حذف الفئة</button></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>من</th><th>إلى (فارغ = بلا حد)</th><th>النسبة %</th><th></th></tr></thead><tbody>' + rowHtml + '</tbody></table></div><button type="button" class="btn btn-sm btn-outline-primary add-bracket"><i class="bi bi-plus"></i> إضافة شريحة</button></div>';
    function reindex() {
        wrap.querySelectorAll('.tax-category').forEach(function (category, ci) {
            category.querySelectorAll('[data-field]').forEach(function (input) {
                const field = input.dataset.field;
                if (field === 'label' || field === 'max_income') {
                    input.name = 'categories[' + ci + '][' + field + ']';
                } else {
                    const row = input.closest('tr');
                    const bi = Array.prototype.indexOf.call(row.parentNode.children, row);
                    input.name = 'categories[' + ci + '][brackets][' + bi + '][' + field + ']';
                }
            });
        });
    }
    wrap.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.classList.contains('add-bracket')) { button.closest('.tax-category').querySelector('tbody').insertAdjacentHTML('beforeend', rowHtml); reindex(); }
        if (button.classList.contains('remove-bracket')) { button.closest('tr').remove(); reindex(); }
        if (button.classList.contains('remove-category')) { button.closest('.tax-category').remove(); reindex(); }
    });
    document.getElementById('addTaxCategory').addEventListener('click', function () { wrap.insertAdjacentHTML('beforeend', categoryHtml); reindex(); });
    form.addEventListener('submit', reindex);
})();
</script>
<div id="leavePolicy" class="panel table-panel mt-4 settings-tab-pane" hidden>
<div class="panel-head"><div><h3>إدارة أرصدة وقواعد الإجازات</h3><span>حدد الاستحقاقات، مدة الانتظار، وحدود كل نوع. لا يتم خصم الرصيد إلا عند الاعتماد النهائي.</span></div></div>
<form method="POST" action="{{ route('payroll.settings.leaves.update') }}" class="p-3" data-ajax="true">
@csrf @method('PUT')
<div class="row g-3">
@foreach($leavePolicies as $key => $policy)<div class="col-md-3"><label class="form-label">{{ $leavePolicyLabels[$key] ?? ($policy->label ?: $key) }}</label><input type="number" step="0.01" min="0" name="policies[{{ $key }}]" value="{{ $policy->setting_value }}" class="form-control"></div>@endforeach
</div>
<h5 class="mt-4">حدود أنواع الإجازات</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>النوع</th><th>الحد في الطلب</th><th>الحد السنوي</th><th>الحد الأدنى للخدمة (شهر)</th><th>عدد المرات بالخدمة</th></tr></thead><tbody>
@foreach($leaveTypes as $type)<tr><td>{{ $type->name }}</td><td><input type="number" min="0" step="0.5" name="types[{{ $type->id }}][max_days_per_request]" value="{{ $type->max_days_per_request }}" class="form-control"></td><td><input type="number" min="0" step="0.5" name="types[{{ $type->id }}][max_days_per_year]" value="{{ $type->max_days_per_year }}" class="form-control"></td><td><input type="number" min="0" name="types[{{ $type->id }}][min_service_months]" value="{{ $type->min_service_months }}" class="form-control"></td><td><input type="number" min="0" name="types[{{ $type->id }}][max_occurrences_service]" value="{{ $type->max_occurrences_service }}" class="form-control"></td></tr>@endforeach
</tbody></table></div><button class="btn btn-primary"><i class="bi bi-save"></i> حفظ إعدادات الإجازات</button></form></div>
@php($restPattern = str_pad((string) ($operationalSettings['weekly_rest_pattern'] ?? '0000100'), 7, '0'))
<div id="operational" class="panel table-panel mt-4 settings-tab-pane" hidden>
    <div class="panel-head"><div><h3>إدارة أوقات الدوام والراحة</h3><span>هذه الإعدادات تحدد أوقات العمل وأيام الراحة المستخدمة في الحضور والإجازات.</span></div></div>
    <form method="POST" action="{{ route('payroll.settings.operational.update') }}" class="p-3">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">بداية الدوام</label><input type="time" name="default_shift_start" class="form-control" value="{{ old('default_shift_start', $operationalSettings['default_shift_start'] ?? '09:00') }}" required></div>
            <div class="col-md-3"><label class="form-label">نهاية الدوام</label><input type="time" name="default_shift_end" class="form-control" value="{{ old('default_shift_end', $operationalSettings['default_shift_end'] ?? '17:00') }}" required></div>
            <div class="col-md-3"><label class="form-label">وقت احتساب الغياب</label><input type="time" name="absence_cutoff_time" class="form-control" value="{{ old('absence_cutoff_time', $operationalSettings['absence_cutoff_time'] ?? '17:15') }}" required></div>
            <div class="col-md-3"><label class="form-label">فترة السماح (دقيقة)</label><input type="number" min="0" max="1440" name="grace_minutes" class="form-control" value="{{ old('grace_minutes', $operationalSettings['grace_minutes'] ?? 0) }}" required></div>
            <div class="col-md-3"><label class="form-label">بداية الفترة الليلية للإضافي</label><input type="time" name="night_start" class="form-control" value="{{ old('night_start', $operationalSettings['night_start'] ?? '19:00') }}"></div>
            <div class="col-md-3"><label class="form-label">نهاية الفترة الليلية للإضافي</label><input type="time" name="night_end" class="form-control" value="{{ old('night_end', $operationalSettings['night_end'] ?? '07:00') }}"></div>
            <div class="col-md-3"><label class="form-label">خصم الإجازة بدون أجر من الراتب</label><select name="unpaid_leave_as_deduction" class="form-select"><option value="1" @selected(($operationalSettings['unpaid_leave_as_deduction'] ?? '1') == '1')>نعم</option><option value="0" @selected(($operationalSettings['unpaid_leave_as_deduction'] ?? '1') == '0')>لا</option></select></div>
            <div class="col-12"><label class="form-label">أيام الراحة الأسبوعية</label><input type="hidden" name="weekly_rest_pattern" id="weekly-rest-pattern" value="{{ $restPattern }}"><div class="operational-week">@foreach(['الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت','الأحد'] as $dayIndex => $dayName)<label><input type="checkbox" class="weekly-rest-toggle" data-index="{{ $dayIndex }}" @checked(($restPattern[$dayIndex] ?? '0') === '1')>{{ $dayName }}</label>@endforeach</div><small class="text-muted d-block mt-2">حدد الأيام التي لا يُحتسب فيها دوام اعتيادي.</small></div>
        </div>
        <button class="btn btn-primary mt-3"><i class="bi bi-clock"></i> حفظ أوقات الدوام</button>
    </form>
</div>
<div id="holidays" class="panel table-panel mt-4 settings-tab-pane" hidden>
    <div class="panel-head"><div><h3>العطلات الرسمية</h3><span>تُستبعد تلقائياً من احتساب أيام العمل وفق إعدادات النظام.</span></div></div>
    <div class="px-3 pt-3 holiday-variable-list"><strong class="d-block mb-2">العطلات التي يحدد تاريخها سنويًا بقرار رسمي</strong><div class="d-flex flex-wrap gap-2">@foreach(['عيد الفطر المبارك','شم النسيم','وقفة عرفات','عيد الأضحى المبارك (اليوم الأول)','عيد الأضحى المبارك (اليوم الثاني)','عيد الأضحى المبارك (اليوم الثالث)','رأس السنة الهجرية','المولد النبوي الشريف'] as $variableHoliday)<button type="button" class="btn btn-sm btn-outline-secondary holiday-template" data-name="{{ $variableHoliday }}">{{ $variableHoliday }} <i class="bi bi-plus-lg"></i></button>@endforeach</div><small class="text-muted d-block mt-2">اختر العطلة لتعبئة اسمها، ثم حدّد تاريخها الرسمي المعتمد وأضفها للقائمة.</small></div>
    <form method="POST" action="{{ route('payroll.settings.holidays.store') }}" class="row g-3 p-3 align-items-end">
        @csrf
        <div class="col-md-3"><label class="form-label">تاريخ العطلة</label><input type="date" name="holiday_date" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">اسم العطلة</label><input type="text" name="name" class="form-control" maxlength="150" placeholder="مثال: عيد العمال" required></div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> إضافة عطلة</button></div>
    </form>
    <div class="px-3 pb-3">@forelse($holidays as $holiday)<div class="holiday-row" data-holiday-id="{{ $holiday->id }}"><div><strong>{{ $holiday->name }}</strong><small class="text-muted d-block">{{ \Carbon\Carbon::parse($holiday->holiday_date)->format('d/m/Y') }}</small></div><form method="POST" action="{{ route('payroll.settings.holidays.destroy', $holiday->id) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف</button></form></div>@empty<div class="text-muted py-3">لا توجد عطلات رسمية مسجلة.</div>@endforelse</div>
</div>
<div class="panel table-panel mt-4"><div class="panel-head"><div><h3>سجل تعديلات الإعدادات</h3><span>مرجع المراجعة والمساءلة.</span></div></div><div class="table-responsive"><table class="table"><thead><tr><th>تاريخ السريان</th><th>المستخدم</th><th>وقت الحفظ</th><th>IP</th></tr></thead><tbody>@forelse($history as $item)<tr><td>{{ $item->effective_from }}</td><td>{{ $item->changed_by_name ?? 'النظام' }}</td><td>{{ $item->created_at }}</td><td>{{ $item->ip_address }}</td></tr>@empty<tr><td colspan="4" class="text-center py-4">لا توجد تعديلات مسجلة بعد.</td></tr>@endforelse</tbody></table></div></div>
<div class="modal fade" id="editHolidayModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">تعديل العطلة الرسمية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><form method="POST" id="editHolidayForm">@csrf @method('PUT')<div class="modal-body"><div class="mb-3"><label class="form-label">تاريخ العطلة</label><input type="date" name="holiday_date" id="editHolidayDate" class="form-control" required></div><div><label class="form-label">اسم العطلة</label><input type="text" name="name" id="editHolidayName" class="form-control" maxlength="150" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>
@endsection
@push('scripts')<script>setTimeout(()=>document.querySelectorAll('.holiday-row').forEach(row=>{const form=row.querySelector('form[action*="/settings/holidays/"]');const edit=row.querySelector('.btn-outline-primary');if(form&&edit&&!row.querySelector('.holiday-actions')){const actions=document.createElement('div');actions.className='holiday-actions';row.append(actions);actions.append(edit,form)}}),0);</script>@endpush
@push('scripts')<script>const historyPane=[...document.querySelectorAll('.panel.table-panel.mt-4')].pop();if(historyPane){historyPane.id='history';historyPane.classList.add('settings-tab-pane');historyPane.hidden=true}document.querySelectorAll('.holiday-row').forEach(row=>row.parentElement?.classList.add('holiday-grid'));document.querySelectorAll('.settings-tab').forEach(tab=>tab.addEventListener('click',()=>{document.querySelectorAll('.settings-tab').forEach(item=>item.classList.toggle('active',item===tab));document.querySelectorAll('.settings-tab-pane').forEach(pane=>pane.hidden=pane.id!==tab.dataset.settingsTab);localStorage.setItem('masar_settings_tab',tab.dataset.settingsTab)}));const savedSettingsTab=localStorage.getItem('masar_settings_tab');if(savedSettingsTab)document.querySelector(`.settings-tab[data-settings-tab="${savedSettingsTab}"]`)?.click();</script>@endpush

@push('styles')
<style>.settings-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:6px;background:#fff;border:1px solid var(--color-border,#e5eaf2);border-radius:14px;box-shadow:0 5px 16px rgba(30,60,100,.05)}.settings-tab{border:0;background:transparent;color:#64748b;border-radius:10px;padding:10px 16px;font-weight:700;font-size:12px;cursor:pointer}.settings-tab.active{background:#1769e0;color:#fff;box-shadow:0 5px 12px rgba(23,105,224,.2)}.holiday-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 22px}.holiday-grid .holiday-row{min-width:0}@media(max-width:800px){.holiday-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:540px){.settings-tabs{overflow:auto;flex-wrap:nowrap}.settings-tab{white-space:nowrap}.holiday-grid{grid-template-columns:1fr}}</style>
<style>.operational-week{display:grid;grid-template-columns:repeat(7,1fr);gap:7px}.operational-week label{border:1px solid var(--color-border);border-radius:10px;padding:9px 5px;text-align:center;font-size:11px;cursor:pointer}.operational-week input{display:block;margin:0 auto 5px}.holiday-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #eef2f7}.holiday-row:last-child{border-bottom:0}.holiday-actions{display:flex;align-items:center;gap:7px;flex-shrink:0}.holiday-actions form{margin:0}.holiday-actions .btn{white-space:nowrap}@media(max-width:600px){.operational-week{grid-template-columns:repeat(4,1fr)}.holiday-row{align-items:flex-start}.holiday-actions{flex-direction:column;align-items:stretch}}</style>
@endpush
@push('scripts')<script>document.querySelectorAll('.holiday-row').forEach(row=>{const deleteForm=row.querySelector('form[action*="/settings/holidays/"]');if(!deleteForm)return;const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-primary';button.innerHTML='<i class="bi bi-pencil-square"></i> تعديل';button.addEventListener('click',()=>{const dateText=row.querySelector('small')?.textContent.trim()||'';const parts=dateText.split('/');document.getElementById('editHolidayDate').value=parts.length===3?`${parts[2]}-${parts[1]}-${parts[0]}`:'';document.getElementById('editHolidayName').value=row.querySelector('strong')?.textContent.trim()||'';document.getElementById('editHolidayForm').action=deleteForm.action;bootstrap.Modal.getOrCreateInstance(document.getElementById('editHolidayModal')).show()});deleteForm.parentElement.classList.add('d-flex','gap-2');deleteForm.parentElement.insertBefore(button,deleteForm)});const cleanNumber=value=>{const number=Number(value);return Number.isFinite(number)?String(number):''};const updateAllowanceTotal=()=>document.getElementById('allowance-total').value=cleanNumber([...document.querySelectorAll('.allowance-input')].reduce((sum,input)=>sum+(parseFloat(input.value)||0),0))+'%';document.querySelectorAll('.allowance-input').forEach(input=>input.addEventListener('input',updateAllowanceTotal));updateAllowanceTotal();const restField=document.getElementById('weekly-rest-pattern');const restToggles=[...document.querySelectorAll('.weekly-rest-toggle')];const syncRestPattern=()=>{if(!restField)return;const chars=Array(7).fill('0');restToggles.forEach(toggle=>{chars[Number(toggle.dataset.index)]=toggle.checked?'1':'0'});restField.value=chars.join('')};restToggles.forEach(toggle=>toggle.addEventListener('change',syncRestPattern));syncRestPattern();</script>@endpush
@push('scripts')
<script>
  // Operational settings and holidays are independent, safe AJAX updates.
  const holidayBaseUrl = @json(url('/payroll/settings/holidays'));
  const holidaysList = document.querySelector('#holidays .px-3.pb-3');
  const escapeHolidayHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const holidayDate = (value) => { const parts = String(value || '').slice(0, 10).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : '—'; };
  const holidayRow = (holiday) => `<div class="holiday-row" data-holiday-id="${holiday.id}"><div><strong>${escapeHolidayHtml(holiday.name)}</strong><small class="text-muted d-block">${holidayDate(holiday.holiday_date)}</small></div><div class="holiday-actions"><button type="button" class="btn btn-sm btn-outline-primary holiday-edit-ajax"><i class="bi bi-pencil-square"></i> تعديل</button><form method="POST" action="${holidayBaseUrl}/${holiday.id}"><input type="hidden" name="_token" value="${escapeHolidayHtml(csrfToken)}"><input type="hidden" name="_method" value="DELETE"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف</button></form></div></div>`;
  const wireSettingsAjaxForms = (root = document) => {
    root.querySelectorAll('form[action$="/operational"], form[action$="/holidays"][method="POST"], #editHolidayForm').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحفظ'; });
    root.querySelectorAll('form[action*="/settings/holidays/"] input[name="_method"][value="DELETE"]').forEach((input) => { const form = input.form; if (!form) return; form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحذف'; form.dataset.ajaxConfirm = 'هل تريد حذف هذه العطلة؟'; });
  };
  wireSettingsAjaxForms();
  document.addEventListener('click', (event) => { const button = event.target.closest('.holiday-edit-ajax'); if (!button) return; const row = button.closest('.holiday-row'); const id = row?.dataset.holidayId; if (!id) return; document.getElementById('editHolidayDate').value = (() => { const parts = row.querySelector('small')?.textContent.trim().split('/') || []; return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : ''; })(); document.getElementById('editHolidayName').value = row.querySelector('strong')?.textContent.trim() || ''; document.getElementById('editHolidayForm').action = `${holidayBaseUrl}/${id}`; bootstrap.Modal.getOrCreateInstance(document.getElementById('editHolidayModal')).show(); });
  document.addEventListener('masar:success', (event) => {
    const form = event.target; if (!(form instanceof HTMLFormElement)) return;
    const holiday = event.detail?.holiday;
    if (holiday && holidaysList) { const oldRow = holidaysList.querySelector(`.holiday-row[data-holiday-id="${holiday.id}"]`); if (oldRow) oldRow.outerHTML = holidayRow(holiday); else { holidaysList.querySelector('.text-muted.py-3')?.remove(); holidaysList.insertAdjacentHTML('afterbegin', holidayRow(holiday)); } wireSettingsAjaxForms(holidaysList); }
    if (form.id === 'editHolidayForm') bootstrap.Modal.getInstance(document.getElementById('editHolidayModal'))?.hide();
    if (form.querySelector('input[name="_method"][value="DELETE"]')) form.closest('.holiday-row')?.remove();
    if (form.action === holidayBaseUrl && form.method.toUpperCase() === 'POST') form.reset();
  });
</script>
@endpush
