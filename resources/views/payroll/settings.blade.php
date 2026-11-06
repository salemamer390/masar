@extends('layouts.app')
@section('title', 'الإعدادات')
@section('content')
<div class="page-head"><div><h1>الإعدادات</h1><p>إدارة قواعد الحساب وهياكل الرواتب من مكان واحد مع سجل تاريخي لكل تعديل.</p></div><a class="btn btn-outline-primary" href="{{ route('salaries.index') }}"><i class="bi bi-cash-stack"></i> هياكل الرواتب</a></div>
<div class="alert alert-warning mb-3"><i class="bi bi-shield-check ms-1"></i> التعديلات تطبق على الحسابات الجديدة فقط. المسيرات المعتمدة أو المقفلة تحتفظ بلقطة مستقلة من قواعدها.</div>
<nav class="settings-tabs" aria-label="أقسام الإعدادات"><button type="button" class="settings-tab active" data-settings-tab="payrollRules"><i class="bi bi-cash-stack"></i> قواعد الرواتب</button><button type="button" class="settings-tab" data-settings-tab="operational"><i class="bi bi-clock-history"></i> أوقات الدوام</button><button type="button" class="settings-tab" data-settings-tab="holidays"><i class="bi bi-calendar-event"></i> العطلات الرسمية</button><button type="button" class="settings-tab" data-settings-tab="history"><i class="bi bi-clock-history"></i> سجل التعديلات</button><button type="button" class="settings-tab" data-settings-tab="leavePolicy"><i class="bi bi-calendar2-heart"></i> إعدادات الإجازات</button><button type="button" class="settings-tab" data-settings-tab="calcRules"><i class="bi bi-sliders"></i> قواعد أساس الحساب</button><button type="button" class="settings-tab" data-settings-tab="taxBrackets"><i class="bi bi-percent"></i> شرائح الضرائب</button></nav>

<form id="payrollRules" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.update') }}" data-track-dirty="true">@csrf @method('PUT')
<div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> التعديلات الجديدة تسري من التاريخ المحدد</strong><span>حدد التاريخ الذي تبدأ منه القواعد الجديدة.</span></div><div class="settings-effective-field"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ old('effective_from', now()->toDateString()) }}" class="form-control" required></div></div>
@php($settingsCards=[
['title'=>'التأمينات الاجتماعية','subtitle'=>'نسب الاشتراكات وحدود الأجر التأميني','fields'=>[
['key'=>'insurance.employee_insurance_rate','label'=>'تأمين الموظف','unit'=>'٪'],
['key'=>'insurance.employer_insurance_rate','label'=>'تأمين صاحب العمل','unit'=>'٪'],
['key'=>'insurance.martyrs_fund_rate','label'=>'صندوق أسر الشهداء','unit'=>'٪'],
['key'=>'insurance.insurance_minimum','label'=>'الحد الأدنى للأجر التأميني','unit'=>'ج.م'],
['key'=>'insurance.insurance_maximum','label'=>'الحد الأقصى للأجر التأميني','unit'=>'ج.م']]],
['title'=>'الضرائب','subtitle'=>'الإعفاءات والخصومات الضريبية','fields'=>[
['key'=>'tax.personal_exemption','label'=>'الإعفاء الشخصي الشهري','unit'=>'ج.م'],
['key'=>'tax.tax_deductible_contributions','label'=>'اشتراكات قابلة للخصم ضريبيًا','unit'=>'ج.م','hint'=>'سنويًا'],
['key'=>'tax.profit_share_exempt_percent','label'=>'نسبة نصيب الأرباح المعفاة من الضريبة','unit'=>'٪']]],
['title'=>'الإضافي والعطلات','subtitle'=>'معاملات الضرب على أجر الساعة واليوم','fields'=>[
['key'=>'overtime.hours_divisor','label'=>'أساس ساعة الإضافي','unit'=>'ساعة','hint'=>'عدد ساعات الشهر المستخدمة لحساب أجر الساعة'],
['key'=>'overtime.day_multiplier','label'=>'معامل الإضافي النهاري','unit'=>'×'],
['key'=>'overtime.night_multiplier','label'=>'معامل الإضافي الليلي','unit'=>'×','hint'=>'يُضرب في أجر الساعة'],
['key'=>'overtime.official_holiday_multiplier','label'=>'معامل العطلة الرسمية','unit'=>'×','hint'=>'2 = يومين'],
['key'=>'overtime.weekly_rest_multiplier','label'=>'معامل الراحة الأسبوعية','unit'=>'×','hint'=>'1 = بنفس سعر اليوم'],
['key'=>'overtime.days_divisor','label'=>'أيام الشهر لأجر يوم الراحة والعطلات','unit'=>'يوم']]],
['title'=>'الغياب والجزاءات','subtitle'=>'مقسوم الخصم وحدود الجزاءات','fields'=>[
['key'=>'absence.daily_divisor','label'=>'مقسوم الغياب اليومي','unit'=>'يوم'],
['key'=>'penalty.daily_divisor','label'=>'مقسوم الجزاء اليومي','unit'=>'يوم'],
['key'=>'penalty.max_days_per_month','label'=>'سقف أيام الجزاءات في الشهر','unit'=>'يوم','hint'=>'0 = بدون سقف']]],
['title'=>'السلف والأقساط','subtitle'=>'الحد الأقصى للاستقطاع الشهري','fields'=>[
['key'=>'advance.max_percent_of_gross','label'=>'سقف السلفة والقسط من الإجمالي','unit'=>'٪']]],
['title'=>'الأساس والبدلات','subtitle'=>'نسب البدلات وأيام الشهر المعتمدة','fields'=>[
['key'=>'general.allowance_rate','label'=>'نسبة البدلات من الأساسي','unit'=>'٪'],
['key'=>'general.proration_days','label'=>'أيام الشهر لتسوية الراتب الجزئي','unit'=>'يوم']]]])
<div class="settings-cards">
@foreach($settingsCards as $settingsCard)<div class="settings-card"><div class="settings-card-head"><h3>{{ $settingsCard['title'] }}</h3><span>{{ $settingsCard['subtitle'] }}</span></div><div class="settings-card-grid">@foreach($settingsCard['fields'] as $field)<div class="settings-field"><label class="form-label">{{ $field['label'] }}</label><div class="input-group settings-unit"><input type="number" step="0.000001" min="0" name="settings[{{ $field['key'] }}]" value="{{ format_number(old('settings.'.$field['key'],$settings[$field['key']]->setting_value ?? '')) }}" class="form-control" required><span class="input-group-text">{{ $field['unit'] }}</span></div>@if(!empty($field['hint']))<small class="settings-hint">{{ $field['hint'] }}</small>@endif</div>@endforeach</div></div>@endforeach
<div class="settings-card wide"><div class="settings-card-head"><h3>توزيع البدلات</h3><span>يجب أن يكون المجموع 100% قبل الحفظ.</span></div><div class="settings-card-grid">@foreach($allowances as $allowance)<div class="settings-field"><label class="form-label">{{ $allowance->allowance_name }}</label><div class="input-group settings-unit"><input type="number" step="0.0001" min="0" max="100" name="allowances[{{ $allowance->allowance_key }}]" value="{{ format_number(old('allowances.'.$allowance->allowance_key,$allowance->percentage)) }}" class="form-control allowance-input" required><span class="input-group-text">٪</span></div></div>@endforeach<div class="settings-field"><label class="form-label">المجموع</label><div class="input-group settings-unit"><input id="allowance-total" class="form-control" readonly><span class="input-group-text">٪</span></div></div></div></div>
</div>
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
</form>

<form id="calcRules" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.rules') }}" data-track-dirty="true" hidden>@csrf @method('PUT')
<div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> التعديلات الجديدة تسري من التاريخ المحدد</strong><span>حدد التاريخ الذي تبدأ منه القواعد الجديدة.</span></div><div class="settings-effective-field"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div></div>
<div class="settings-cards"><div class="settings-card wide"><div class="settings-card-head"><h3>قواعد أساس الحساب</h3><span>اختر الأساس الذي يُحسب عليه كل بند: الأساسي (Basic) أو الإجمالي (Gross)، ولخصم الغياب يمكن اختيار «الأساسي + بدلات محددة» مع تحديد البدلات المخصومة. تسري على موظفي شركتك فقط، وتُعاد حسابات هياكل الرواتب فور الحفظ.</span></div><div class="settings-card-grid two">
@foreach(['insurance_basis'=>['insurance.automatic_calculation_basis','أساس الأجر التأميني التلقائي'],'overtime_basis'=>['overtime.calculation_basis','أساس أجر ساعة الإضافي وأجر يوم الراحة والعطلات'],'penalty_basis'=>['penalty.calculation_basis','أساس خصم الجزاءات']] as $ruleField=>$ruleMeta)
<div class="settings-field"><label class="form-label">{{ $ruleMeta[1] }}</label><select name="rules[{{ $ruleField }}]" class="form-select" required>@foreach(['Basic'=>'الأساسي (Basic)','Gross'=>'الإجمالي (Gross)'] as $ruleValue=>$ruleText)<option value="{{ $ruleValue }}" @selected(($rules[$ruleMeta[0]]->rule_value ?? null) === $ruleValue)>{{ $ruleText }}</option>@endforeach</select><small class="settings-hint">{{ $rules[$ruleMeta[0]]->description ?? '' }}</small></div>
@endforeach
@php($absenceDeducted = array_filter(explode(',', (string) ($rules['absence.deducted_allowances']->rule_value ?? 'meal,transportation'))))
<div class="settings-field"><label class="form-label">أساس خصم الغياب</label><select name="rules[absence_basis]" class="form-select" required>@foreach(['Basic'=>'الأساسي (Basic)','Gross'=>'الإجمالي (Gross)','BasicPlusAllowances'=>'الأساسي + بدلات محددة'] as $ruleValue=>$ruleText)<option value="{{ $ruleValue }}" @selected(($rules['absence.calculation_basis']->rule_value ?? null) === $ruleValue)>{{ $ruleText }}</option>@endforeach</select><small class="settings-hint">{{ $rules['absence.calculation_basis']->description ?? '' }}</small></div>
<div class="settings-field"><label class="form-label">البدلات المخصومة عن يوم الغياب</label><div class="operational-week">@foreach(['housing'=>'بدل المسكن','transportation'=>'بدل الانتقال','meal'=>'بدل الوجبة','clothing'=>'بدل الملبس','other'=>'بدلات أخرى'] as $allowanceKey=>$allowanceLabel)<label><input type="checkbox" name="absence_deducted_allowances[]" value="{{ $allowanceKey }}" @checked(in_array($allowanceKey, $absenceDeducted, true))>{{ $allowanceLabel }}</label>@endforeach</div><small class="settings-hint">تُستخدم عند اختيار أساس «الأساسي + بدلات محددة»: خصم اليوم = (الأساسي + البدلات المحددة) ÷ مقام الغياب، والبدلات غير المحددة لا تُخصم بسبب الغياب.</small></div>
</div></div></div>
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
</form>

<form id="taxBrackets" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.tax') }}" data-track-dirty="true" hidden>@csrf @method('PUT')
@error('tax')<div class="alert alert-danger">{{ $message }}</div>@enderror
<div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> التعديلات الجديدة تسري من التاريخ المحدد</strong><span>عدّل الفئات والشرائح عند تغيّر القانون. كل شريحة تبدأ من حيث انتهت السابقة، وآخر شريحة بدون حد أعلى. التغيير يخص شركتك فقط.</span></div><div class="settings-effective-field"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div></div>
<div id="taxCategories" class="settings-cards">
@foreach($taxCategories as $ci=>$category)
<div class="settings-card tax-category">
<div class="settings-card-grid category-head-grid"><div class="settings-field"><label class="form-label">اسم الفئة</label><input data-field="label" name="categories[{{ $ci }}][label]" value="{{ $category->label }}" class="form-control" required></div>
<div class="settings-field"><label class="form-label">الحد الأعلى للدخل السنوي (فارغ للفئة الأخيرة)</label><div class="input-group settings-unit"><input type="number" step="0.01" min="0" data-field="max_income" name="categories[{{ $ci }}][max_income]" value="{{ $category->max_income !== null ? format_number($category->max_income) : '' }}" class="form-control"><span class="input-group-text">ج.م</span></div></div>
<div class="settings-field d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-category">حذف الفئة</button></div></div>
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
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
</form>
<script>
(function () {
    const wrap = document.getElementById('taxCategories');
    const form = document.getElementById('taxBrackets');
    if (!wrap || !form) return;
    const rowHtml = '<tr><td><input type="number" step="0.01" min="0" data-field="from" class="form-control form-control-sm" required></td><td><input type="number" step="0.01" min="0" data-field="to" class="form-control form-control-sm"></td><td><input type="number" step="0.01" min="0" max="100" data-field="rate" class="form-control form-control-sm" required></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-bracket">حذف</button></td></tr>';
    const categoryHtml = '<div class="settings-card tax-category"><div class="settings-card-grid category-head-grid"><div class="settings-field"><label class="form-label">اسم الفئة</label><input data-field="label" class="form-control" required></div><div class="settings-field"><label class="form-label">الحد الأعلى للدخل السنوي (فارغ للفئة الأخيرة)</label><div class="input-group settings-unit"><input type="number" step="0.01" min="0" data-field="max_income" class="form-control"><span class="input-group-text">ج.م</span></div></div><div class="settings-field d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-category">حذف الفئة</button></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>من</th><th>إلى (فارغ = بلا حد)</th><th>النسبة %</th><th></th></tr></thead><tbody>' + rowHtml + '</tbody></table></div><button type="button" class="btn btn-sm btn-outline-primary add-bracket"><i class="bi bi-plus"></i> إضافة شريحة</button></div>';
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
    window.masarReindexTax = reindex;
    wrap.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.classList.contains('add-bracket')) { button.closest('.tax-category').querySelector('tbody').insertAdjacentHTML('beforeend', rowHtml); reindex(); form.dispatchEvent(new Event('input', {bubbles: true})); }
        if (button.classList.contains('remove-bracket')) { button.closest('tr').remove(); reindex(); form.dispatchEvent(new Event('input', {bubbles: true})); }
        if (button.classList.contains('remove-category')) { button.closest('.tax-category').remove(); reindex(); form.dispatchEvent(new Event('input', {bubbles: true})); }
    });
    document.getElementById('addTaxCategory').addEventListener('click', function () { wrap.insertAdjacentHTML('beforeend', categoryHtml); reindex(); form.dispatchEvent(new Event('input', {bubbles: true})); });
    form.addEventListener('submit', reindex);
})();
</script>

<div id="leavePolicy" class="settings-tab-pane" hidden>
<form method="POST" action="{{ route('payroll.settings.leaves.update') }}" data-ajax="true" data-track-dirty="true">
@csrf @method('PUT')
<div class="settings-cards">
<div class="settings-card"><div class="settings-card-head"><h3>أرصدة الاستحقاق وقواعد الإجازات</h3><span>حدد الاستحقاقات ومدة الانتظار. لا يتم خصم الرصيد إلا عند الاعتماد النهائي.</span></div><div class="settings-card-grid">
@foreach($leavePolicies as $key => $policy)<div class="settings-field"><label class="form-label">{{ $leavePolicyLabels[$key] ?? ($policy->label ?: $key) }}</label><input type="number" step="0.01" min="0" name="policies[{{ $key }}]" value="{{ $policy->setting_value }}" class="form-control"></div>@endforeach
</div></div>
<div class="settings-card"><div class="settings-card-head"><h3>حدود أنواع الإجازات</h3><span>الحد الأقصى لكل طلب ولكل سنة، وشروط الخدمة.</span></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>النوع</th><th>الحد في الطلب</th><th>الحد السنوي</th><th>الحد الأدنى للخدمة (شهر)</th><th>عدد المرات بالخدمة</th></tr></thead><tbody>
@foreach($leaveTypes as $type)<tr><td>{{ $type->name }}</td><td><input type="number" min="0" step="0.5" name="types[{{ $type->id }}][max_days_per_request]" value="{{ $type->max_days_per_request }}" class="form-control"></td><td><input type="number" min="0" step="0.5" name="types[{{ $type->id }}][max_days_per_year]" value="{{ $type->max_days_per_year }}" class="form-control"></td><td><input type="number" min="0" name="types[{{ $type->id }}][min_service_months]" value="{{ $type->min_service_months }}" class="form-control"></td><td><input type="number" min="0" name="types[{{ $type->id }}][max_occurrences_service]" value="{{ $type->max_occurrences_service }}" class="form-control"></td></tr>@endforeach
</tbody></table></div></div>
</div>
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
</form>
</div>

@php($restPattern = str_pad((string) ($operationalSettings['weekly_rest_pattern'] ?? '0000100'), 7, '0'))
<div id="operational" class="settings-tab-pane" hidden>
    @if($errors->has('payroll_period'))<div class="alert alert-danger" role="alert" id="periodChangeError"><strong>{{ $errors->first('payroll_period') }}</strong><ul class="mb-0 mt-2">@foreach(array_slice($errors->get('payroll_period'), 1) as $line)<li><bdi dir="ltr">{{ $line }}</bdi></li>@endforeach</ul></div>@endif
    @if(session('period_confirm'))<div class="alert alert-warning" role="alert" id="periodCloseConfirm"><strong>{{ session('period_confirm')['message'] }}</strong><ul class="mb-2 mt-2">@foreach(session('period_confirm')['periods'] as $line)<li><bdi dir="ltr">{{ $line }}</bdi></li>@endforeach</ul><label class="d-flex gap-2 align-items-center"><input type="checkbox" form="operationalForm" name="confirm_close_pending" value="1"> <span>أؤكد قفل وحفظ هذه الفترات الآن وحفظ الإعدادات الجديدة</span></label></div>@endif
    @if(session('period_notes'))<div class="alert alert-warning" role="status">@foreach(session('period_notes') as $line)<div>{{ $line }}</div>@endforeach</div>@endif
    @php($cycleStart = (int) ($operationalSettings['payroll_period_start_day'] ?? 1))@php($cycleEnd = (int) ($operationalSettings['payroll_period_end_day'] ?? 30))@php($graceDays = (int) ($operationalSettings['attendance_correction_days'] ?? config('attendance.archive_grace_days', 3)))
    <form method="POST" id="operationalForm" action="{{ route('payroll.settings.operational.update') }}" data-track-dirty="true">
        @csrf @method('PUT')
        <div class="alert alert-info" role="status">الدورة الحالية: من يوم {{ $cycleStart }} إلى يوم {{ $cycleEnd }}@if($cycleStart > $cycleEnd) من الشهر السابق@endif. مهلة تصحيح الحضور {{ $graceDays }} {{ $graceDays === 1 ? 'يوم' : 'أيام' }} بعد نهاية الفترة، وبعدها يُقفل المسير والحضور والملخص تلقائيًا. تغيير اليومين بيُنشئ مسير انتقالي بالأيام الفعلية عشان ميبقاش في فجوة ولا تداخل.</div>
        <div class="settings-cards">
            <div class="settings-card"><div class="settings-card-head"><h3>الدوام اليومي</h3><span>أوقات العمل المعتمدة في الحضور والغياب.</span></div><div class="settings-card-grid">
                <div class="settings-field"><label class="form-label">بداية الدوام</label><input type="time" name="default_shift_start" class="form-control" value="{{ old('default_shift_start', $operationalSettings['default_shift_start'] ?? '09:00') }}" required></div>
                <div class="settings-field"><label class="form-label">نهاية الدوام</label><input type="time" name="default_shift_end" class="form-control" value="{{ old('default_shift_end', $operationalSettings['default_shift_end'] ?? '17:00') }}" required></div>
                <div class="settings-field"><label class="form-label">وقت احتساب الغياب</label><input type="time" name="absence_cutoff_time" class="form-control" value="{{ old('absence_cutoff_time', $operationalSettings['absence_cutoff_time'] ?? '17:15') }}" required></div>
                <div class="settings-field"><label class="form-label">فترة السماح</label><div class="input-group settings-unit"><input type="number" min="0" max="1440" name="grace_minutes" class="form-control" value="{{ old('grace_minutes', $operationalSettings['grace_minutes'] ?? 0) }}" required><span class="input-group-text">دقيقة</span></div></div>
            </div></div>
            <div class="settings-card"><div class="settings-card-head"><h3>الفترة الليلية للإضافي</h3><span>الساعات التي يُحتسب فيها معامل الليل.</span></div><div class="settings-card-grid">
                <div class="settings-field"><label class="form-label">بداية الفترة الليلية للإضافي</label><input type="time" name="night_start" class="form-control" value="{{ old('night_start', $operationalSettings['night_start'] ?? '19:00') }}"></div>
                <div class="settings-field"><label class="form-label">نهاية الفترة الليلية للإضافي</label><input type="time" name="night_end" class="form-control" value="{{ old('night_end', $operationalSettings['night_end'] ?? '07:00') }}"></div>
            </div></div>
            <div class="settings-card"><div class="settings-card-head"><h3>دورة المسير وتصحيح الحضور</h3><span>بداية ونهاية فترة حساب الشهر ومهلة التصحيح.</span></div><div class="settings-card-grid">
                <div class="settings-field"><label class="form-label">بداية فترة المسير (يوم الشهر)</label><div class="input-group settings-unit"><input type="number" name="payroll_period_start_day" class="form-control" min="1" max="31" value="{{ old('payroll_period_start_day', $operationalSettings['payroll_period_start_day'] ?? 1) }}" required><span class="input-group-text">يوم</span></div><small class="settings-hint">مثال: 26</small></div>
                <div class="settings-field"><label class="form-label">نهاية فترة المسير (يوم الشهر)</label><div class="input-group settings-unit"><input type="number" name="payroll_period_end_day" class="form-control" min="1" max="31" value="{{ old('payroll_period_end_day', $operationalSettings['payroll_period_end_day'] ?? 30) }}" required><span class="input-group-text">يوم</span></div><small class="settings-hint">مثال: 25</small></div>
                <div class="settings-field"><label class="form-label">أيام تصحيح الحضور بعد نهاية الفترة</label><div class="input-group settings-unit"><input type="number" min="0" name="attendance_correction_days" class="form-control" value="{{ old('attendance_correction_days', $operationalSettings['attendance_correction_days'] ?? config('attendance.archive_grace_days', 3)) }}"><span class="input-group-text">يوم</span></div><small class="settings-hint">بعد آخر يوم من هذه الأيام تتقفل الفترة وتتحفظ في «الشهور السابقة».</small></div>
            </div></div>
            <div class="settings-card"><div class="settings-card-head"><h3>الإجازة بدون أجر وأيام الراحة</h3><span>خصم الإجازة بدون أجر والأيام التي لا يُحتسب فيها دوام اعتيادي.</span></div><div class="settings-card-grid">
                <div class="settings-field"><label class="form-label">خصم الإجازة بدون أجر من الراتب</label><select name="unpaid_leave_as_deduction" class="form-select"><option value="1" @selected(($operationalSettings['unpaid_leave_as_deduction'] ?? '1') == '1')>نعم</option><option value="0" @selected(($operationalSettings['unpaid_leave_as_deduction'] ?? '1') == '0')>لا</option></select></div>
                <div class="settings-field span-all"><label class="form-label">أيام الراحة الأسبوعية</label><input type="hidden" name="weekly_rest_pattern" id="weekly-rest-pattern" value="{{ $restPattern }}"><div class="operational-week">@foreach(['الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت','الأحد'] as $dayIndex => $dayName)<label><input type="checkbox" class="weekly-rest-toggle" data-index="{{ $dayIndex }}" @checked(($restPattern[$dayIndex] ?? '0') === '1')>{{ $dayName }}</label>@endforeach</div><small class="settings-hint">حدد الأيام التي لا يُحتسب فيها دوام اعتيادي.</small></div>
            </div></div>
        </div>
        <div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
    </form>
</div>

<div id="holidays" class="settings-tab-pane" hidden>
    <div class="settings-cards">
    <div class="settings-card wide"><div class="settings-card-head"><h3>إضافة عطلة رسمية</h3><span>تُستبعد تلقائيًا من احتساب أيام العمل وفق إعدادات النظام.</span></div>
    <div class="holiday-variable-list"><strong class="d-block mb-2">العطلات التي يحدد تاريخها سنويًا بقرار رسمي</strong><div class="d-flex flex-wrap gap-2">@foreach(['عيد الفطر المبارك','شم النسيم','وقفة عرفات','عيد الأضحى المبارك (اليوم الأول)','عيد الأضحى المبارك (اليوم الثاني)','عيد الأضحى المبارك (اليوم الثالث)','رأس السنة الهجرية','المولد النبوي الشريف'] as $variableHoliday)<button type="button" class="btn btn-sm btn-outline-secondary holiday-template" data-name="{{ $variableHoliday }}">{{ $variableHoliday }} <i class="bi bi-plus-lg"></i></button>@endforeach</div><small class="settings-hint d-block mt-2">اختر العطلة لتعبئة اسمها، ثم حدّد تاريخها الرسمي المعتمد وأضفها للقائمة.</small></div>
    <form method="POST" action="{{ route('payroll.settings.holidays.store') }}" class="row g-3 mt-1 align-items-end">
        @csrf
        <div class="col-md-3"><label class="form-label">تاريخ العطلة</label><input type="date" name="holiday_date" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">اسم العطلة</label><input type="text" name="name" class="form-control" maxlength="150" placeholder="مثال: عيد العمال" required></div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> إضافة عطلة</button></div>
    </form>
    </div>
    <div class="settings-card wide"><div class="settings-card-head"><h3>العطلات المسجلة</h3><span>تعديل أو حذف كل عطلة يتم فورًا وبشكل مستقل.</span></div>
    <div id="holidaysList">@forelse($holidays as $holiday)<div class="holiday-row" data-holiday-id="{{ $holiday->id }}"><div><strong>{{ $holiday->name }}</strong><small class="text-muted d-block">{{ \Carbon\Carbon::parse($holiday->holiday_date)->format('d/m/Y') }}</small></div><form method="POST" action="{{ route('payroll.settings.holidays.destroy', $holiday->id) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف</button></form></div>@empty<div class="text-muted py-3">لا توجد عطلات رسمية مسجلة.</div>@endforelse</div>
    </div>
    </div>
</div>

<div id="history" class="settings-tab-pane" hidden><div class="settings-card"><div class="settings-card-head"><h3>سجل تعديلات الإعدادات</h3><span>مرجع المراجعة والمساءلة.</span></div><div class="table-responsive"><table class="table"><thead><tr><th>تاريخ السريان</th><th>المستخدم</th><th>وقت الحفظ</th><th>IP</th></tr></thead><tbody>@forelse($history as $item)<tr><td>{{ $item->effective_from }}</td><td>{{ $item->changed_by_name ?? 'النظام' }}</td><td>{{ $item->created_at }}</td><td>{{ $item->ip_address }}</td></tr>@empty<tr><td colspan="4" class="text-center py-4">لا توجد تعديلات مسجلة بعد.</td></tr>@endforelse</tbody></table></div></div></div>

<div class="modal fade" id="editHolidayModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">تعديل العطلة الرسمية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><form method="POST" id="editHolidayForm">@csrf @method('PUT')<div class="modal-body"><div class="mb-3"><label class="form-label">تاريخ العطلة</label><input type="date" name="holiday_date" id="editHolidayDate" class="form-control" required></div><div><label class="form-label">اسم العطلة</label><input type="text" name="name" id="editHolidayName" class="form-control" maxlength="150" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>
@endsection
@push('scripts')<script>setTimeout(()=>document.querySelectorAll('.holiday-row').forEach(row=>{const form=row.querySelector('form[action*="/settings/holidays/"]');const edit=row.querySelector('.btn-outline-primary');if(form&&edit&&!row.querySelector('.holiday-actions')){const actions=document.createElement('div');actions.className='holiday-actions';row.append(actions);actions.append(edit,form)}}),0);</script>@endpush
@push('scripts')<script>document.querySelectorAll('.holiday-row').forEach(row=>row.parentElement?.classList.add('holiday-grid'));document.querySelectorAll('.settings-tab').forEach(tab=>tab.addEventListener('click',()=>{const currentPane=document.querySelector('.settings-tab-pane:not([hidden])');if(currentPane&&currentPane.dataset.dirty==='true'&&currentPane.id!==tab.dataset.settingsTab&&!confirm('لديك تعديلات غير محفوظة في هذا القسم. الانتقال بدون حفظ؟'))return;document.querySelectorAll('.settings-tab').forEach(item=>item.classList.toggle('active',item===tab));document.querySelectorAll('.settings-tab-pane').forEach(pane=>pane.hidden=pane.id!==tab.dataset.settingsTab);localStorage.setItem('masar_settings_tab',tab.dataset.settingsTab)}));const savedSettingsTab=localStorage.getItem('masar_settings_tab');if(savedSettingsTab)document.querySelector(`.settings-tab[data-settings-tab="${savedSettingsTab}"]`)?.click();if(document.getElementById('periodChangeError'))document.querySelector('.settings-tab[data-settings-tab="operational"]')?.click();</script>@endpush

@push('styles')
<style>.settings-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:6px;background:#fff;border:1px solid var(--color-border,#e5eaf2);border-radius:14px;box-shadow:0 5px 16px rgba(30,60,100,.05)}.settings-tab{border:0;background:transparent;color:#64748b;border-radius:10px;padding:10px 16px;font-weight:700;font-size:12px;cursor:pointer}.settings-tab.active{background:#1769e0;color:#fff;box-shadow:0 5px 12px rgba(23,105,224,.2)}.holiday-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0 22px}.holiday-grid .holiday-row{min-width:0}@media(max-width:800px){.holiday-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:540px){.settings-tabs{overflow:auto;flex-wrap:nowrap}.settings-tab{white-space:nowrap}.holiday-grid{grid-template-columns:1fr}}</style>
<style>.operational-week{display:grid;grid-template-columns:repeat(7,1fr);gap:7px}.operational-week label{border:1px solid var(--color-border);border-radius:10px;padding:9px 5px;text-align:center;font-size:11px;cursor:pointer;background:#fff}.operational-week input{display:block;margin:0 auto 5px}.holiday-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #eef2f7}.holiday-row:last-child{border-bottom:0}.holiday-actions{display:flex;align-items:center;gap:7px;flex-shrink:0}.holiday-actions form{margin:0}.holiday-actions .btn{white-space:nowrap}@media(max-width:600px){.operational-week{grid-template-columns:repeat(4,1fr)}.holiday-row{align-items:flex-start}.holiday-actions{flex-direction:column;align-items:stretch}}</style>
<style>
.settings-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.settings-card{background:#fff;border:1px solid var(--color-border,#e5eaf2);border-radius:16px;box-shadow:0 3px 12px rgba(20,35,60,.04);padding:18px 20px}
.settings-card.wide{grid-column:1/-1}
.settings-card-head{position:relative;padding-inline-start:12px;margin-bottom:14px}
.settings-card-head::before{content:'';position:absolute;inset-inline-start:0;top:4px;bottom:4px;width:4px;border-radius:4px;background:#1769e0}
.settings-card-head h3{font-size:15px;font-weight:800;margin:0}
.settings-card-head span{display:block;font-size:11px;color:var(--muted,#7c8aa5);margin-top:3px}
.settings-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
.settings-card-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.settings-card-grid .span-all{grid-column:1/-1}
.settings-field .form-label{font-size:12px;font-weight:600;color:#44536b}
.settings-unit .input-group-text{background:#f6f8fb;color:#64748b;font-size:11px;font-weight:700;border-color:var(--color-border,#dfe5ee)}
.settings-hint{display:block;font-size:10px;color:var(--muted,#7c8aa5);margin-top:4px}
.category-head-grid{grid-template-columns:2fr 2fr 1fr;margin-bottom:12px}
.settings-effective{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:#fff8e6;border:1px solid #f3ddae;border-radius:14px;padding:13px 18px;margin-bottom:16px}
.settings-effective-text strong{display:block;font-size:13px;color:#8a6410}
.settings-effective-text span{display:block;font-size:11px;color:#a5863f;margin-top:2px}
.settings-effective-field{min-width:220px}
.settings-effective-field .form-label{font-size:11px;font-weight:700;color:#8a6410}
.settings-save-bar{position:sticky;bottom:12px;z-index:25;margin-top:18px;background:#121a29;color:#e8eef8;border-radius:14px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 12px 26px rgba(10,20,40,.35)}
.settings-save-text{font-size:12px}
.settings-save-text b{color:#ffd166;margin:0 2px}
.settings-save-actions{display:flex;gap:8px}
@media(max-width:900px){.settings-cards{grid-template-columns:1fr}.settings-card-grid,.settings-card-grid.two,.category-head-grid{grid-template-columns:1fr}.settings-save-bar{flex-direction:column;align-items:stretch;text-align:center}.settings-save-actions{justify-content:center}}
</style>
@endpush
@push('scripts')<script>document.querySelectorAll('.holiday-row').forEach(row=>{const deleteForm=row.querySelector('form[action*="/settings/holidays/"]');if(!deleteForm)return;const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-primary';button.innerHTML='<i class="bi bi-pencil-square"></i> تعديل';button.addEventListener('click',()=>{const dateText=row.querySelector('small')?.textContent.trim()||'';const parts=dateText.split('/');document.getElementById('editHolidayDate').value=parts.length===3?`${parts[2]}-${parts[1]}-${parts[0]}`:'';document.getElementById('editHolidayName').value=row.querySelector('strong')?.textContent.trim()||'';document.getElementById('editHolidayForm').action=deleteForm.action;bootstrap.Modal.getOrCreateInstance(document.getElementById('editHolidayModal')).show()});deleteForm.parentElement.classList.add('d-flex','gap-2');deleteForm.parentElement.insertBefore(button,deleteForm)});const cleanNumber=value=>{const number=Number(value);return Number.isFinite(number)?String(number):''};const updateAllowanceTotal=()=>document.getElementById('allowance-total').value=cleanNumber([...document.querySelectorAll('.allowance-input')].reduce((sum,input)=>sum+(parseFloat(input.value)||0),0))+'%';document.querySelectorAll('.allowance-input').forEach(input=>input.addEventListener('input',updateAllowanceTotal));updateAllowanceTotal();const restField=document.getElementById('weekly-rest-pattern');const restToggles=[...document.querySelectorAll('.weekly-rest-toggle')];const syncRestPattern=()=>{if(!restField)return;const chars=Array(7).fill('0');restToggles.forEach(toggle=>{chars[Number(toggle.dataset.index)]=toggle.checked?'1':'0'});restField.value=chars.join('')};restToggles.forEach(toggle=>toggle.addEventListener('change',syncRestPattern));syncRestPattern();document.getElementById('payrollRules')?.addEventListener('masar:resync',updateAllowanceTotal);document.getElementById('operationalForm')?.addEventListener('masar:resync',syncRestPattern);</script>@endpush
@push('scripts')
<script>
  // Per-tab dirty tracking with save/revert bar.
  (function () {
    const snapshot = (form) => { const map = new Map(); new FormData(form).forEach((value, key) => { const list = map.get(key) || []; list.push(String(value)); map.set(key, list); }); return map; };
    const diffCount = (a, b) => { const keys = new Set([...a.keys(), ...b.keys()]); let count = 0; keys.forEach((key) => { if (JSON.stringify(a.get(key) || []) !== JSON.stringify(b.get(key) || [])) count++; }); return count; };
    document.querySelectorAll('form[data-track-dirty]').forEach((form) => {
      const pane = form.closest('.settings-tab-pane');
      const bar = form.querySelector('[data-save-bar]');
      if (!bar) return;
      const countEl = bar.querySelector('[data-dirty-count]');
      const taxWrap = form.querySelector('#taxCategories');
      const initialTaxHtml = taxWrap ? taxWrap.innerHTML : null;
      let initial = snapshot(form);
      const refresh = () => { const n = diffCount(initial, snapshot(form)); countEl.textContent = n; bar.hidden = n === 0; if (pane) pane.dataset.dirty = n > 0 ? 'true' : 'false'; };
      form.addEventListener('input', refresh);
      form.addEventListener('change', refresh);
      bar.querySelector('[data-save]')?.addEventListener('click', () => form.requestSubmit());
      bar.querySelector('[data-revert]')?.addEventListener('click', () => {
        if (taxWrap && initialTaxHtml !== null) { taxWrap.innerHTML = initialTaxHtml; window.masarReindexTax?.(); }
        form.reset();
        form.dispatchEvent(new Event('masar:resync'));
        refresh();
      });
      const settled = () => { initial = snapshot(form); bar.hidden = true; if (pane) pane.dataset.dirty = 'false'; };
      form.addEventListener('submit', settled);
      document.addEventListener('masar:success', (event) => { if (event.target === form) settled(); });
      refresh();
    });
  })();
</script>
@endpush
@push('scripts')
<script>
  // Operational settings and holidays are independent, safe AJAX updates.
  const holidayBaseUrl = @json(url('/payroll/settings/holidays'));
  const holidaysList = document.getElementById('holidaysList');
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
