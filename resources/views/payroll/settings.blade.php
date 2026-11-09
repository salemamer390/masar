@extends('layouts.app')
@section('title', 'الإعدادات')
@section('content')
<div class="page-head"><div><h1>الإعدادات</h1><p>إدارة قواعد الحساب وهياكل الرواتب من مكان واحد مع سجل تاريخي لكل تعديل.</p></div><a class="btn btn-outline-primary" href="{{ route('salaries.index') }}"><i class="bi bi-cash-stack"></i> هياكل الرواتب</a></div>
<div class="alert alert-warning mb-3"><i class="bi bi-shield-check ms-1"></i> التعديلات تطبق على الحسابات الجديدة فقط. المسيرات المعتمدة أو المقفلة تحتفظ بلقطة مستقلة من قواعدها.</div>
<nav class="settings-tabs" aria-label="أقسام الإعدادات"><button type="button" class="settings-tab active" data-settings-tab="payrollRules"><i class="bi bi-cash-stack"></i> قواعد الرواتب</button><button type="button" class="settings-tab" data-settings-tab="operational"><i class="bi bi-clock-history"></i> أوقات الدوام</button><button type="button" class="settings-tab" data-settings-tab="attendancePenalties"><i class="bi bi-alarm"></i> لائحة الجزاءات</button><button type="button" class="settings-tab" data-settings-tab="holidays"><i class="bi bi-calendar-event"></i> العطلات الرسمية</button><button type="button" class="settings-tab" data-settings-tab="history"><i class="bi bi-clock-history"></i> سجل التعديلات</button><button type="button" class="settings-tab" data-settings-tab="leavePolicy"><i class="bi bi-calendar2-heart"></i> إعدادات الإجازات</button><button type="button" class="settings-tab" data-settings-tab="calcRules"><i class="bi bi-sliders"></i> قواعد أساس الحساب</button><button type="button" class="settings-tab" data-settings-tab="taxBrackets"><i class="bi bi-percent"></i> شرائح الضرائب</button></nav>

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
<div class="settings-card wide"><div class="settings-card-head"><h3>توزيع البدلات</h3><span>البدل الأخير يأخذ الباقي تلقائيًا ليكتمل المجموع 100%. فعّل «حساب جزئي» ليُحتسب البدل بنسبة أيام العمل عند التعيين أو ترك الخدمة خلال الشهر، أو أوقفه ليُصرف كاملًا.</span></div>
@if(!($allowanceProrationReady ?? true))<div class="alert alert-warning py-2 mb-3" role="alert"><i class="bi bi-exclamation-triangle ms-1"></i> اختيار «الحساب الجزئي» للبدلات يحتاج تحديث قاعدة البيانات أولًا: شغّل <code dir="ltr">php artisan migrate</code> ثم أعد تحميل الصفحة. النسب تحفظ كالمعتاد.</div>@endif
<div class="allowance-row">@foreach($allowances as $allowance)<div class="allowance-item"><label class="form-label">{{ $allowance->allowance_name }}</label><div class="input-group settings-unit"><input type="number" step="0.0001" min="0" max="100" name="allowances[{{ $allowance->allowance_key }}]" value="{{ format_number(old('allowances.'.$allowance->allowance_key,$allowance->percentage)) }}" class="form-control allowance-input {{ $loop->last ? 'allowance-auto' : '' }}" @if($loop->last) readonly tabindex="-1" @endif required><span class="input-group-text">٪</span></div>@if($allowanceProrationReady ?? true)<label class="allowance-prorate"><input type="hidden" name="allowance_prorated[{{ $allowance->allowance_key }}]" value="0"><input type="checkbox" name="allowance_prorated[{{ $allowance->allowance_key }}]" value="1" @checked(old('allowance_prorated.'.$allowance->allowance_key, $allowance->is_prorated ?? 1))> حساب جزئي مع أيام العمل</label>@endif @if($loop->last)<small class="settings-hint">يأخذ الباقي تلقائيًا من إجمالي البدلات.</small>@endif</div>@endforeach</div>
<div class="allowance-bar" aria-label="توزيع نسب البدلات">@foreach($allowances as $allowance)<div class="allowance-seg" data-allowance-seg="{{ $allowance->allowance_key }}"><span></span></div>@endforeach</div>
<div class="allowance-legend">@foreach($allowances as $allowance)<span class="allowance-chip"><i></i>{{ $allowance->allowance_name }} <b data-allowance-chip="{{ $allowance->allowance_key }}"></b></span>@endforeach</div>
</div>
</div>
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ التعديلات</button></span></div>
</form>

<form id="calcRules" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.rules') }}" data-track-dirty="true" hidden>@csrf @method('PUT')
<div class="settings-effective"><div class="settings-effective-text"><strong><i class="bi bi-calendar2-check ms-1"></i> التعديلات الجديدة تسري من التاريخ المحدد</strong><span>حدد التاريخ الذي تبدأ منه القواعد الجديدة.</span></div><div class="settings-effective-field"><label class="form-label">تاريخ السريان</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" class="form-control" required></div></div>
<div class="settings-cards"><div class="settings-card wide"><div class="settings-card-head"><h3>قواعد أساس الحساب</h3><span>اختر الأساس الذي يُحسب عليه كل بند: الأساسي (Basic) أو الإجمالي (Gross)، وحدد البدلات التي تُخصم عن يوم الغياب. تسري على موظفي شركتك فقط، وتُعاد حسابات هياكل الرواتب فور الحفظ.</span></div><div class="settings-card-grid two">
@foreach(['insurance_basis'=>['insurance.automatic_calculation_basis','أساس الأجر التأميني التلقائي'],'overtime_basis'=>['overtime.calculation_basis','أساس أجر ساعة الإضافي وأجر يوم الراحة والعطلات'],'penalty_basis'=>['penalty.calculation_basis','أساس خصم الجزاءات']] as $ruleField=>$ruleMeta)
<div class="settings-field"><label class="form-label">{{ $ruleMeta[1] }}</label><select name="rules[{{ $ruleField }}]" class="form-select" required>@foreach(['Basic'=>'الأساسي (Basic)','Gross'=>'الإجمالي (Gross)'] as $ruleValue=>$ruleText)<option value="{{ $ruleValue }}" @selected(($rules[$ruleMeta[0]]->rule_value ?? null) === $ruleValue)>{{ $ruleText }}</option>@endforeach</select><small class="settings-hint">{{ $rules[$ruleMeta[0]]->description ?? '' }}</small></div>
@endforeach
@php($absenceDeducted = array_filter(explode(',', (string) ($rules['absence.deducted_allowances']->rule_value ?? 'meal,transportation'))))
<div class="settings-field"><label class="form-label">أساس خصم الغياب</label><select name="rules[absence_basis]" class="form-select" required>@foreach(['Basic'=>'الأساسي (Basic)','Gross'=>'الإجمالي (Gross)'] as $ruleValue=>$ruleText)<option value="{{ $ruleValue }}" @selected(($rules['absence.calculation_basis']->rule_value ?? null) === $ruleValue)>{{ $ruleText }}</option>@endforeach</select><small class="settings-hint">{{ $rules['absence.calculation_basis']->description ?? '' }}</small></div>
<div class="settings-field"><label class="form-label">البدلات المخصومة عن يوم الغياب</label><div class="operational-week">@foreach(['housing'=>'بدل المسكن','transportation'=>'بدل الانتقال','meal'=>'بدل الوجبة','clothing'=>'بدل الملبس','other'=>'بدلات أخرى'] as $allowanceKey=>$allowanceLabel)<label><input type="checkbox" name="absence_deducted_allowances[]" value="{{ $allowanceKey }}" @checked(in_array($allowanceKey, $absenceDeducted, true))>{{ $allowanceLabel }}</label>@endforeach</div><small class="settings-hint">مع أساس «الأساسي» يُضاف نصيب اليوم من كل بدل محدد إلى خصم يوم الغياب: خصم اليوم = (الأساسي + البدلات المحددة) ÷ مقام الغياب، والبدلات غير المحددة لا تُخصم بسبب الغياب. مع أساس «الإجمالي» كل البدلات داخلة أصلًا في الإجمالي فلا تُخصم مرة ثانية.</small></div>
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


<form id="attendancePenalties" class="settings-tab-pane" method="POST" action="{{ route('payroll.settings.attendance-penalties.update') }}" data-track-dirty="true" hidden>@csrf @method('PUT')
@php($apTiers = old('tiers', $attendancePenaltyPolicy['tiers']))
@php([$apGroups, $apMatrixFit, $apByOcc, $apOccLabels, $apKindLabels, $apKindIcons] = call_user_func(function (array $apTiers): array {
    $apGroups = ['late' => [], 'early_leave' => []];
    foreach ($apTiers as $i => $tier) {
        $kind = ($tier['kind'] ?? 'late') === 'early_leave' ? 'early_leave' : 'late';
        $key = (int) ($tier['from_minutes'] ?? 0).'|'.($tier['to_minutes'] ?? '');
        $tier['_i'] = $i;
        $apGroups[$kind][$key][] = $tier;
    }
    $apMatrixFit = function (array $tiers): bool {
        if (count($tiers) !== 4) return false;
        $want = ['1|1' => 1, '2|2' => 1, '3|3' => 1, '4|' => 1];
        foreach ($tiers as $t) {
            $k = (int) ($t['occurrence_from'] ?? 1).'|'.($t['occurrence_to'] ?? '');
            if (!isset($want[$k])) return false;
            unset($want[$k]);
        }
        return count($want) === 0;
    };
    $apByOcc = fn (array $tiers) => collect($tiers)->sortBy(fn ($t) => (int) ($t['occurrence_from'] ?? 1))->values()->all();
    return [$apGroups, $apMatrixFit, $apByOcc,
        ['المرة الأولى', 'المرة الثانية', 'المرة الثالثة', 'الرابعة فأكثر'],
        ['late' => 'التأخير', 'early_leave' => 'الخروج المبكر'],
        ['late' => 'bi-clock-history', 'early_leave' => 'bi-box-arrow-left']];
}, $apTiers))
<div class="settings-cards">
<div class="settings-card wide">
<div class="settings-card-head"><h3>لائحة الجزاءات</h3><span>جزاءات التأخير والخروج المبكر. تُطبق على الحسابات الجديدة فقط، والمسيرات المعتمدة أو المقفلة لا تتغير.</span></div>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="ap-status">
<div class="settings-field">
<label class="form-label">تفعيل اللائحة</label>
<div class="d-flex align-items-center gap-2 flex-wrap">
<div class="form-check form-switch mb-0"><input type="checkbox" class="form-check-input" role="switch" id="apEnabled" name="is_enabled" value="1" @checked(old('is_enabled', $attendancePenaltyPolicy['enabled']))><label class="form-check-label" for="apEnabled">تطبيق اللائحة على المرتبات</label></div>
<span class="ap-state {{ old('is_enabled', $attendancePenaltyPolicy['enabled']) ? 'on' : 'off' }}" id="apStatePill">{{ old('is_enabled', $attendancePenaltyPolicy['enabled']) ? 'مفعّلة' : 'معطّلة' }}</span>
</div>
</div>
<div class="settings-field"><label class="form-label">تبدأ من تاريخ</label><input type="date" name="effective_from" class="form-control" value="{{ old('effective_from', $attendancePenaltyPolicy['effective_from']) }}"><small class="settings-hint">المخالفات قبل هذا التاريخ لا تُحسب ولا تُعد تكرارًا.</small></div>
</div>
</div>
<div class="settings-card wide">
<div class="settings-card-head"><h3>شرائح الجزاء</h3><span>الجزاء حسب دقائق المخالفة وتكرارها طوال مدة عمل الموظف بالشركة. عدّاد مستقل لكل شريحة، وبعد آخر مرة معرّفة يتكرر نفس الجزاء.</span></div>
<div class="alert alert-warning mt-1 mb-2">الدقائق خارج الشرائح المعرّفة لا يُخصم عنها جزاء تلقائي (ويظل أجر الوقت الضائع محسوبًا دائمًا). أضف شريحة لتغطيتها.</div>
@foreach(['late', 'early_leave'] as $apKind)
@php($apKindCount = array_sum(array_map('count', $apGroups[$apKind])))
<div class="ap-section" data-ap-section="{{ $apKind }}">
<div class="ap-section-title"><i class="bi {{ $apKindIcons[$apKind] }}"></i> {{ $apKindLabels[$apKind] }} <span class="ap-count" data-ap-count>{{ $apKindCount }} {{ $apKindCount === 1 ? 'شريحة' : 'شرائح' }}</span></div>
<div class="ap-rows" data-ap-rows>
@foreach($apGroups[$apKind] as $apBandTiers)
@if($apMatrixFit($apBandTiers))
@php($apCells = $apByOcc($apBandTiers))
@php($apFirst = $apCells[0])
<div class="ap-row" data-ap-row>
<div class="ap-band"><span class="ap-band-range">من <input type="number" min="1" class="ap-band-from" value="{{ $apFirst['from_minutes'] ?? '' }}" aria-label="من دقيقة"> إلى <input type="number" min="1" class="ap-band-to" value="{{ $apFirst['to_minutes'] ?? '' }}" placeholder="بلا حد" aria-label="إلى دقيقة"> دقيقة</span></div>
@foreach($apCells as $apPos => $apCell)
@php($apI = $apCell['_i'])
<div class="ap-cell">
<label>{{ $apOccLabels[$apPos] }}</label>
<div class="ap-cell-inputs {{ (float) ($apCell['deduction_days'] ?? 0) == 0.0 ? 'is-warning' : '' }}">
<input type="number" min="0" step="0.01" name="tiers[{{ $apI }}][deduction_days]" value="{{ $apCell['deduction_days'] ?? '' }}" required aria-label="قيمة الجزاء">
<select name="tiers[{{ $apI }}][unit]" aria-label="الوحدة"><option value="days" @selected(($apCell['unit'] ?? 'days') === 'days')>أيام</option><option value="minutes" @selected(($apCell['unit'] ?? '') === 'minutes')>دقائق</option></select>
<span class="ap-warn-tag">إنذار</span>
</div>
<input type="hidden" name="tiers[{{ $apI }}][kind]" value="{{ $apKind }}">
<input type="hidden" class="ap-h-from" name="tiers[{{ $apI }}][from_minutes]" value="{{ $apFirst['from_minutes'] ?? '' }}">
<input type="hidden" class="ap-h-to" name="tiers[{{ $apI }}][to_minutes]" value="{{ $apFirst['to_minutes'] ?? '' }}">
<input type="hidden" name="tiers[{{ $apI }}][occurrence_from]" value="{{ $apCell['occurrence_from'] ?? ($apPos + 1) }}">
<input type="hidden" name="tiers[{{ $apI }}][occurrence_to]" value="{{ $apCell['occurrence_to'] ?? ($apPos === 3 ? '' : $apPos + 1) }}">
</div>
@endforeach
<button type="button" class="ap-del" data-ap-remove aria-label="حذف الشريحة"><i class="bi bi-trash"></i></button>
</div>
@else
@foreach($apBandTiers as $apCell)
@php($apI = $apCell['_i'])
<div class="ap-row ap-row-custom" data-ap-row>
<div class="ap-custom-grid">
<div class="ap-f"><label>النوع</label><select name="tiers[{{ $apI }}][kind]" class="form-select form-select-sm" data-ap-kind><option value="late" @selected($apKind === 'late')>تأخير</option><option value="early_leave" @selected($apKind === 'early_leave')>خروج مبكر</option></select></div>
<div class="ap-f"><label>من دقيقة</label><input type="number" min="1" name="tiers[{{ $apI }}][from_minutes]" class="form-control form-control-sm" value="{{ $apCell['from_minutes'] ?? '' }}" required></div>
<div class="ap-f"><label>إلى دقيقة <small>(فارغ = بلا حد)</small></label><input type="number" min="1" name="tiers[{{ $apI }}][to_minutes]" class="form-control form-control-sm" value="{{ $apCell['to_minutes'] ?? '' }}"></div>
<div class="ap-f"><label>من المرة</label><input type="number" min="1" name="tiers[{{ $apI }}][occurrence_from]" class="form-control form-control-sm" value="{{ $apCell['occurrence_from'] ?? 1 }}"></div>
<div class="ap-f"><label>إلى المرة <small>(فارغ = بلا حد)</small></label><input type="number" min="1" name="tiers[{{ $apI }}][occurrence_to]" class="form-control form-control-sm" value="{{ $apCell['occurrence_to'] ?? '' }}"></div>
<div class="ap-f ap-f-amount"><label>الجزاء (0 = إنذار)</label><div class="ap-cell-inputs {{ (float) ($apCell['deduction_days'] ?? 0) == 0.0 ? 'is-warning' : '' }}"><input type="number" min="0" step="0.01" name="tiers[{{ $apI }}][deduction_days]" class="form-control form-control-sm" value="{{ $apCell['deduction_days'] ?? '' }}" required><select name="tiers[{{ $apI }}][unit]" aria-label="الوحدة"><option value="days" @selected(($apCell['unit'] ?? 'days') === 'days')>أيام</option><option value="minutes" @selected(($apCell['unit'] ?? '') === 'minutes')>دقائق</option></select><span class="ap-warn-tag">إنذار</span></div></div>
</div>
<button type="button" class="ap-del" data-ap-remove aria-label="حذف الشريحة"><i class="bi bi-trash"></i></button>
</div>
@endforeach
@endif
@endforeach
</div>
<div class="ap-empty" data-ap-empty @if($apKindCount > 0) hidden @endif>لا توجد شرائح معرّفة. أضف شريحة لتغطية هذه المخالفة.</div>
</div>
@endforeach
<ul class="ap-legend">
<li>القيمة 0 تعني إنذارًا مسجلًا بدون خصم.</li>
<li>اليوم يُحسب من الأساسي، والدقيقة من الأساسي بنفس أساس الساعة: 480 دقيقة جزاء = يوم جزاء.</li>
<li>أيام الجزاء خاضعة لسقف أيام الجزاءات الشهري.</li>
<li>في اليوم الواحد يُخصم جزاء التأخير وجزاء الخروج المبكر معًا.</li>
<li>يُخصم أجر الوقت الضائع دائمًا من الإجمالي في سطر مستقل، ولا يدخل في سقف الجزاءات.</li>
<li>الجزاء اليدوي في صفحة الجزاءات مستقل ولا يُلغي الجزاء التلقائي.</li>
</ul>
<div class="d-flex gap-2 flex-wrap mt-2"><button type="button" class="btn btn-outline-primary" data-ap-add="late"><i class="bi bi-plus-lg"></i> إضافة شريحة تأخير</button><button type="button" class="btn btn-outline-primary" data-ap-add="early_leave"><i class="bi bi-plus-lg"></i> إضافة شريحة انصراف مبكر</button><button type="button" class="btn btn-outline-secondary" id="apRestoreSuggested"><i class="bi bi-arrow-counterclockwise"></i> استعادة الاقتراح المبدئي</button></div>
<small class="settings-hint d-block mt-1">«استعادة الاقتراح المبدئي» تملأ الجدول بالقيم المقترحة فقط، ولا تُحفظ التغييرات إلا بضغط «حفظ اللائحة».</small>
</div>
</div>
<div class="settings-save-bar" data-save-bar hidden><span class="settings-save-text">تعديلات غير محفوظة: <b data-dirty-count>0</b></span><span class="settings-save-actions"><button type="button" class="btn btn-outline-light" data-revert>تراجع</button><button type="button" class="btn btn-primary" data-save><i class="bi bi-save"></i> حفظ اللائحة</button></span></div>
</form>
@push('scripts')<script>(function(){
const form=document.getElementById('attendancePenalties');if(!form)return;
let n=1000;
const occLabels=['المرة الأولى','المرة الثانية','المرة الثالثة','الرابعة فأكثر'];
const esc=v=>String(v??'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
const warnTag='<span class="ap-warn-tag">إنذار</span>';
const unitSel=(i,u)=>`<select name="tiers[${i}][unit]" aria-label="الوحدة"><option value="days"${u==='minutes'?'':' selected'}>أيام</option><option value="minutes"${u==='minutes'?' selected':''}>دقائق</option></select>`;
const cellHtml=(i,kind,from,to,occF,occT,amount,unit,label)=>`<div class="ap-cell"><label>${label}</label><div class="ap-cell-inputs${Number(amount)===0?' is-warning':''}"><input type="number" min="0" step="0.01" name="tiers[${i}][deduction_days]" value="${esc(amount)}" required aria-label="قيمة الجزاء">${unitSel(i,unit)}${warnTag}</div><input type="hidden" name="tiers[${i}][kind]" value="${kind}"><input type="hidden" class="ap-h-from" name="tiers[${i}][from_minutes]" value="${esc(from)}"><input type="hidden" class="ap-h-to" name="tiers[${i}][to_minutes]" value="${esc(to)}"><input type="hidden" name="tiers[${i}][occurrence_from]" value="${occF}"><input type="hidden" name="tiers[${i}][occurrence_to]" value="${esc(occT)}"></div>`;
const matrixRow=(kind,from,to,cells)=>`<div class="ap-row" data-ap-row><div class="ap-band"><span class="ap-band-range">من <input type="number" min="1" class="ap-band-from" value="${esc(from)}" aria-label="من دقيقة"> إلى <input type="number" min="1" class="ap-band-to" value="${esc(to)}" placeholder="بلا حد" aria-label="إلى دقيقة"> دقيقة</span></div>${cells.map((c,p)=>cellHtml(c.i,kind,from,to,c.occF,c.occT,c.amount,c.unit,occLabels[p])).join('')}<button type="button" class="ap-del" data-ap-remove aria-label="حذف الشريحة"><i class="bi bi-trash"></i></button></div>`;
const customRow=(i,t)=>{const kind=t&&t.kind==='early_leave'?'early_leave':'late';t=t||{};return `<div class="ap-row ap-row-custom" data-ap-row><div class="ap-custom-grid"><div class="ap-f"><label>النوع</label><select name="tiers[${i}][kind]" class="form-select form-select-sm" data-ap-kind><option value="late"${kind==='late'?' selected':''}>تأخير</option><option value="early_leave"${kind==='early_leave'?' selected':''}>خروج مبكر</option></select></div><div class="ap-f"><label>من دقيقة</label><input type="number" min="1" name="tiers[${i}][from_minutes]" class="form-control form-control-sm" value="${esc(t.from_minutes)}" required></div><div class="ap-f"><label>إلى دقيقة <small>(فارغ = بلا حد)</small></label><input type="number" min="1" name="tiers[${i}][to_minutes]" class="form-control form-control-sm" value="${esc(t.to_minutes)}"></div><div class="ap-f"><label>من المرة</label><input type="number" min="1" name="tiers[${i}][occurrence_from]" class="form-control form-control-sm" value="${esc(t.occurrence_from??1)}"></div><div class="ap-f"><label>إلى المرة <small>(فارغ = بلا حد)</small></label><input type="number" min="1" name="tiers[${i}][occurrence_to]" class="form-control form-control-sm" value="${esc(t.occurrence_to)}"></div><div class="ap-f ap-f-amount"><label>الجزاء (0 = إنذار)</label><div class="ap-cell-inputs${Number(t.deduction_days)===0?' is-warning':''}"><input type="number" min="0" step="0.01" name="tiers[${i}][deduction_days]" class="form-control form-control-sm" value="${esc(t.deduction_days)}" required>${unitSel(i,t.unit)}${warnTag}</div></div></div><button type="button" class="ap-del" data-ap-remove aria-label="حذف الشريحة"><i class="bi bi-trash"></i></button></div>`;};
const refreshCounts=()=>{form.querySelectorAll('[data-ap-section]').forEach(sec=>{const c=sec.querySelectorAll('[data-ap-row]').length;const el=sec.querySelector('[data-ap-count]');if(el)el.textContent=c+' '+(c===1?'شريحة':'شرائح');const empty=sec.querySelector('[data-ap-empty]');if(empty)empty.hidden=c>0;});};
const markDirty=()=>form.dispatchEvent(new Event('input',{bubbles:true}));
form.querySelectorAll('[data-ap-add]').forEach(btn=>btn.addEventListener('click',()=>{const kind=btn.dataset.apAdd==='early_leave'?'early_leave':'late';form.querySelector(`[data-ap-section="${kind}"] [data-ap-rows]`).insertAdjacentHTML('beforeend',customRow(n++,{kind}));refreshCounts();markDirty();}));
document.getElementById('apRestoreSuggested').addEventListener('click',()=>{
const suggested=@json($suggestedPenaltyTiers ?? []);
const seen={late:{},early_leave:{}},order={late:[],early_leave:[]};
suggested.forEach(t=>{const k=t.kind==='early_leave'?'early_leave':'late';const key=(t.from_minutes??'')+'|'+(t.to_minutes??'');if(!seen[k][key]){seen[k][key]=[];order[k].push(key);}seen[k][key].push(t);});
['late','early_leave'].forEach(k=>{
const rows=form.querySelector(`[data-ap-section="${k}"] [data-ap-rows]`);rows.innerHTML='';
order[k].forEach(key=>{const list=seen[k][key].slice().sort((a,b)=>(a.occurrence_from||1)-(b.occurrence_from||1));
const fit=list.length===4&&list.every((t,p)=>Number(t.occurrence_from??1)===p+1&&(p<3?Number(t.occurrence_to??'')===p+1:(t.occurrence_to===null||t.occurrence_to===''||t.occurrence_to===undefined)));
if(fit){const cells=list.map((t,p)=>({i:n++,amount:t.deduction_days,unit:t.unit||'days',occF:t.occurrence_from??(p+1),occT:p===3?'':(t.occurrence_to??(p+1))}));rows.insertAdjacentHTML('beforeend',matrixRow(k,list[0].from_minutes,list[0].to_minutes??'',cells));}
else list.forEach(t=>rows.insertAdjacentHTML('beforeend',customRow(n++,t)));
});});
refreshCounts();markDirty();});
form.addEventListener('click',e=>{const b=e.target.closest('[data-ap-remove]');if(!b)return;b.closest('[data-ap-row]').remove();refreshCounts();markDirty();});
form.addEventListener('change',e=>{const s=e.target.closest('[data-ap-kind]');if(!s)return;const row=s.closest('[data-ap-row]');const target=form.querySelector(`[data-ap-section="${s.value}"] [data-ap-rows]`);if(target&&row.parentElement!==target)target.appendChild(row);refreshCounts();});
form.addEventListener('input',e=>{const wrap=e.target.closest?e.target.closest('.ap-cell-inputs'):null;if(!wrap||!e.target.name||!e.target.name.includes('deduction_days'))return;wrap.classList.toggle('is-warning',Number(e.target.value)===0);});
document.addEventListener('input',e=>{const band=e.target.closest?e.target.closest('.ap-band'):null;if(!band)return;const row=band.closest('[data-ap-row]');const from=row.querySelector('.ap-band-from').value,to=row.querySelector('.ap-band-to').value;row.querySelectorAll('.ap-h-from').forEach(h=>h.value=from);row.querySelectorAll('.ap-h-to').forEach(h=>h.value=to);},true);
const pill=document.getElementById('apStatePill'),sw=document.getElementById('apEnabled');
if(pill&&sw)sw.addEventListener('change',()=>{pill.textContent=sw.checked?'مفعّلة':'معطّلة';pill.classList.toggle('on',sw.checked);pill.classList.toggle('off',!sw.checked);});
})();</script>@endpush
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
<link rel="stylesheet" href="{{ asset('css/pages/payroll-settings-01.css') }}?v={{ filemtime(public_path('css/pages/payroll-settings-01.css')) }}">
<link rel="stylesheet" href="{{ asset('css/pages/payroll-settings-02.css') }}?v={{ filemtime(public_path('css/pages/payroll-settings-02.css')) }}">
<link rel="stylesheet" href="{{ asset('css/pages/payroll-settings-03.css') }}?v={{ filemtime(public_path('css/pages/payroll-settings-03.css')) }}">
@endpush
@push('scripts')<script>document.querySelectorAll('.holiday-row').forEach(row=>{const deleteForm=row.querySelector('form[action*="/settings/holidays/"]');if(!deleteForm)return;const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-primary';button.innerHTML='<i class="bi bi-pencil-square"></i> تعديل';button.addEventListener('click',()=>{const dateText=row.querySelector('small')?.textContent.trim()||'';const parts=dateText.split('/');document.getElementById('editHolidayDate').value=parts.length===3?`${parts[2]}-${parts[1]}-${parts[0]}`:'';document.getElementById('editHolidayName').value=row.querySelector('strong')?.textContent.trim()||'';document.getElementById('editHolidayForm').action=deleteForm.action;bootstrap.Modal.getOrCreateInstance(document.getElementById('editHolidayModal')).show()});deleteForm.parentElement.classList.add('d-flex','gap-2');deleteForm.parentElement.insertBefore(button,deleteForm)});const cleanNumber=value=>{const number=Number(value);return Number.isFinite(number)?String(number):''};const allowanceInputs=[...document.querySelectorAll('.allowance-input')];const autoAllowanceInput=document.querySelector('.allowance-input.allowance-auto');const allowanceKeyOf=input=>(input.name.match(/allowances\[(.+?)\]/)||[])[1]||'';const updateAllowanceTotal=()=>{const others=allowanceInputs.filter(input=>input!==autoAllowanceInput).reduce((sum,input)=>sum+(parseFloat(input.value)||0),0);if(autoAllowanceInput)autoAllowanceInput.value=cleanNumber(Math.max(0,Math.round((100-others)*10000)/10000));allowanceInputs.forEach(input=>{const key=allowanceKeyOf(input);if(!key)return;const pct=Math.max(0,parseFloat(input.value)||0);const text=cleanNumber(Math.round(pct*100)/100)+'٪';const seg=document.querySelector(`[data-allowance-seg="${key}"]`);if(seg){seg.style.width=pct+'%';const label=seg.querySelector('span');if(label)label.textContent=pct>=7?text:'';}const chip=document.querySelector(`[data-allowance-chip="${key}"]`);if(chip)chip.textContent=text;})};allowanceInputs.forEach(input=>input.addEventListener('input',updateAllowanceTotal));updateAllowanceTotal();const restField=document.getElementById('weekly-rest-pattern');const restToggles=[...document.querySelectorAll('.weekly-rest-toggle')];const syncRestPattern=()=>{if(!restField)return;const chars=Array(7).fill('0');restToggles.forEach(toggle=>{chars[Number(toggle.dataset.index)]=toggle.checked?'1':'0'});restField.value=chars.join('')};restToggles.forEach(toggle=>toggle.addEventListener('change',syncRestPattern));syncRestPattern();document.getElementById('payrollRules')?.addEventListener('masar:resync',updateAllowanceTotal);document.getElementById('operationalForm')?.addEventListener('masar:resync',syncRestPattern);</script>@endpush
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
