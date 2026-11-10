@extends('layouts.app')
@section('title',__('هيكل الرواتب'))
@section('content')
<div class="page-head salary-page-head">
  <div><h1>{{ __('هيكل الرواتب') }}</h1><p>{{ __('هيكل الراتب هو المصدر الثابت للراتب؛ المسير الشهري يأخذ منه القيم الثابتة ثم يضيف المدخلات الشهرية المعتمدة. المدخل الأساسي هو الراتب الإجمالي. النظام يوزع البدلات ويحسِب التأمين والضريبة والصافي وفق المواصفة المعتمدة.') }}</p></div>
  <div class="d-flex gap-2 flex-wrap">
    @if(auth()->user()?->hasPermission('roles.view'))
    <a class="btn btn-light" href="{{ route('master.index',['type'=>'payment']) }}"><i class="bi bi-gear"></i> {{ __('إعدادات طرق الصرف') }}</a>
    @endif
    @if(auth()->user()?->hasPermission('salary.edit'))
    <form method="POST" action="{{ route('salaries.recalculate') }}" onsubmit="return confirm('إعادة حساب وحفظ كل هياكل الرواتب السارية بمنطق الضريبة الحالي؟ لن تتأثر المسيرات المعتمدة أو المصروفة.')">@csrf<button class="btn btn-light"><i class="bi bi-arrow-repeat"></i> {{ __('إعادة حساب الهياكل') }}</button></form>
    @endif
    @if(auth()->user()?->hasPermission('salary.create'))
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#salaryModal"><i class="bi bi-plus-lg"></i> {{ __('إضافة هيكل راتب') }}</button>
    @endif
  </div>
</div>

<form method="GET" action="{{ route('salaries.index') }}" class="filter-bar salary-filter-bar">
  <div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو كود الموظف') }}"></div>
  <select name="department_id" class="form-select"><option value="">{{ __('كل الأقسام') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>
  <button class="btn btn-primary"><i class="bi bi-funnel"></i> {{ __('تصفية') }}</button><a href="{{ route('salaries.index') }}" class="btn btn-light">{{ __('مسح') }}</a>
</form>

{{-- منهج الحساب مخفي مؤقتًا من الواجهة، مع الإبقاء على المنطق الحسابي فعالًا. --}}
<div class="panel mb-3 salary-calculation-method" hidden>
  <div class="panel-head"><div><h3>{{ __('منهج الحساب') }}</h3><span>{{ __('القيم التالية ثابتة طبقاً لمنطق حاسبة الرواتب المعتمدة.') }}</span></div></div>
  <div class="salary-detail-grid">
    <div><span>{{ __('الراتب المدخل') }}</span><strong>{{ __('الراتب الصافي') }}</strong></div>
    <div><span>{{ __('نسبة البدلات من الأساسي') }}</span><strong>{{ format_number($allowanceRate) }}%</strong></div>
    <div><span>{{ __('حصة العامل') }}</span><strong>{{ format_number($payrollSettings['employee_insurance_rate']) }}%</strong></div>
    <div><span>{{ __('حصة صاحب العمل') }}</span><strong>{{ format_number($payrollSettings['employer_insurance_rate']) }}%</strong></div>
    <div><span>{{ __('صندوق الشهداء') }}</span><strong>{{ format_number($payrollSettings['martyrs_fund_rate']) }}%</strong></div>
    <div><span>{{ __('الأجر التأميني') }}</span><strong>{{ format_number($payrollSettings['insurance_minimum']) }} — {{ format_number($payrollSettings['insurance_maximum']) }}</strong></div>
    <div><span>{{ __('الإعفاء الشخصي الشهري') }}</span><strong>{{ format_number($payrollSettings['personal_exemption']) }}</strong></div>
  </div>
</div>

<form method="GET" action="{{ route('salaries.index') }}" class="filter-bar salary-filter-bar">
  <div class="search-table"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="{{ __('بحث بالاسم أو كود الموظف') }}"></div>
  <select name="department_id" class="form-select"><option value="">{{ __('كل الأقسام') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>@endforeach</select>
  <button class="btn btn-primary"><i class="bi bi-funnel"></i> {{ __('تصفية') }}</button><a href="{{ route('salaries.index') }}" class="btn btn-light">{{ __('مسح') }}</a>
</form>

@if($selectedEmployee)
  <div class="alert alert-info py-2 mb-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
    <span>{{ __('عرض هيكل الراتب الخاص بالموظف:') }} <strong>{{ $selectedEmployee->full_name }}</strong></span>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('employees.show', ['employee' => $selectedEmployee->id, 'tab' => 'salary']) }}">{{ __('فتح ملف الموظف') }}</a>
  </div>
@endif

<div class="salary-layout">
@forelse($salaries as $s)
  <div class="salary-card salary-card-compact" data-salary-id="{{ $s->id }}" data-bs-toggle="modal" data-bs-target="#salaryDetails{{ $s->id }}" role="button" tabindex="0" aria-label="{{ __('عرض تفاصيل راتب') }} {{ $s->employee?->full_name }}">
    <div class="salary-card-head">
      <div class="salary-employee">
        <div class="salary-avatar">@if($s->employee?->profile_photo_url)<img src="{{ $s->employee->profile_photo_url }}" alt="{{ $s->employee->full_name }}">@else{{ $s->employee?->initials }}@endif</div>
        <div>
          <strong data-employee-id="{{ $s->employee_id }}">{{ $s->employee?->full_name }}</strong>
          <div class="small text-muted">{{ $s->employee?->job?->title ?? '—' }} · {{ $s->employee?->department?->name ?? '—' }}</div>
        </div>
      </div>
      <span class="badge-soft success">{{ __('ساري') }}</span>
    </div>

    <div class="salary-main-number">
      {{ format_money($s->net_salary) }} <small>{{ __('ج.م') }}</small>
      <span>{{ __('الراتب الصافي') }}</span>
    </div>

    <div class="salary-compact-grid salary-card-key-metrics">
      <div><span>{{ __('الراتب الإجمالي') }}</span><b>{{ format_money($s->gross_salary) }}</b></div>
      <div><span>{{ __('إجمالي البدلات') }}</span><b>{{ format_money($s->allowances) }}</b></div>
      <div><span>{{ __('حصة العامل') }}</span><b>{{ format_money($s->employee_insurance) }}</b></div>
      <div><span>{{ __('الضريبة') }}</span><b>{{ format_money($s->income_tax) }}</b></div>
    </div>

    <div class="salary-card-cost">
      <span>{{ __('إجمالي تكلفة الموظف') }}</span>
      <strong>{{ format_money($s->employee_cost) }} {{ __('ج.م') }}</strong>
    </div>

    <div class="salary-card-footer">
      <span>{{ __('ساري من') }} {{ $s->effective_from?->format('d/m/Y') }}</span>
      <span><i class="bi bi-link-45deg"></i> {{ __('مصدر مسير الرواتب') }}</span>
    </div>

    <div class="d-flex gap-2 mt-3" onclick="event.stopPropagation()">
      @if(auth()->user()?->hasPermission('salary.edit'))<button class="btn btn-sm btn-outline-primary flex-fill" data-bs-toggle="modal" data-bs-target="#editSalary{{ $s->id }}">{{ __('تعديل') }}</button>@endif
      @if(auth()->user()?->hasPermission('salary.delete'))<form method="POST" action="{{ route('salaries.destroy',$s) }}" onsubmit="return confirm('إيقاف هيكل الراتب الحالي؟')" class="flex-fill">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger w-100">{{ __('إيقاف') }}</button></form>@endif
    </div>
  </div>
@empty
  <div class="empty-page" style="grid-column:1/-1"><div class="empty-icon"><i class="bi bi-cash-stack"></i></div><h2>{{ __('لا توجد هياكل رواتب') }}</h2><p>{{ __('ابدأ بإضافة هيكل راتب لأول موظف.') }}</p></div>
@endforelse
</div>

@foreach($salaries as $s)
@include('salaries.partials.details-modal', ['s' => $s])
@endforeach

<div class="modal fade" id="salaryModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
<form method="POST" action="{{ route('salaries.store') }}" id="salaryForm">@csrf
<input type="hidden" name="salary_type" value="gross">
<div class="modal-header"><h5 class="modal-title">{{ __('إضافة هيكل راتب') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
  <div class="alert alert-primary mb-3"><b>{{ __('الراتب الإجمالي هو المدخل الأساسي.') }}</b> {{ __('الأساسي والبدلات الأربعة تُوزع تلقائياً من الإجمالي حسب إعدادات النظام.') }}</div>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" id="salary_employee_id" class="form-select" required><option value="">{{ __('اختر الموظف') }}</option>@foreach($employees as $e)<option value="{{ $e->id }}" data-gross="{{ $e->gross_salary ?? '' }}">{{ $e->full_name }} — {{ $e->job?->title ?? __('بدون وظيفة') }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label">{{ __('تاريخ السريان') }}</label><input name="effective_from" type="date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
    <div class="col-md-3"><label class="form-label">{{ __('طريقة الصرف') }}</label><select name="payment_method_id" class="form-select"><option value="">{{ __('اختر') }}</option>@foreach($paymentMethods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label">{{ __('الراتب الإجمالي الشهري — من ملف الموظف') }}</label><input id="salary_input" name="salary_input" type="number" step="0.01" min="0.01" class="form-control calc-input" readonly required><div class="form-text">{{ __('يُقرأ تلقائيًا من الراتب الإجمالي في ملف الموظف.') }}</div></div>
    <div class="col-md-4"><label class="form-label">{{ __('الراتب الإجمالي المحسوب') }}</label><input id="gross_display" type="text" class="form-control" readonly value="—"></div>
    <div class="col-md-4"><label class="form-label">{{ __('الأجر الأساسي') }}</label><input id="basic_display" type="text" class="form-control" readonly value="—"></div>
  </div>

  <div class="panel mt-3" style="background:#f8fafc">
    <div class="panel-head"><div><h3>{{ __('البدلات التلقائية') }}</h3><span>{{ __('تُحسب من الإجمالي: انتقال') }} {{ format_number($allowanceDistribution['transport']['percentage'] ?? $allowanceDistribution['transportation']['percentage'] ?? 35) }}{{ __('%، سكن') }} {{ format_number($allowanceDistribution['housing']['percentage'] ?? 30) }}{{ __('%، وجبة') }} {{ format_number($allowanceDistribution['meal']['percentage'] ?? 20) }}{{ __('%، والملابس هي الباقي.') }}</span></div></div>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label">{{ __('إجمالي البدلات المسموح') }}</label><input id="allowance_limit_display" class="form-control" readonly value="0.00 ج.م"></div>
      <div class="col-md-3"><label class="form-label">{{ __('بدل انتقال') }}</label><input name="transportation_allowance" class="form-control allowance-input" type="number" min="0" step="0.01" value="0"></div>
      <div class="col-md-3"><label class="form-label">{{ __('بدل وجبة') }}</label><input name="meal_allowance" class="form-control allowance-input" type="number" min="0" step="0.01" value="0"></div>
      <div class="col-md-3"><label class="form-label">{{ __('بدل سكن') }}</label><input name="housing_allowance" class="form-control allowance-input" type="number" min="0" step="0.01" value=""></div>
      <div class="col-md-3"><label class="form-label">{{ __('بدل ملابس') }}</label><input name="clothing_allowance" class="form-control allowance-input" type="number" min="0" step="0.01" value=""></div>
      <div class="col-md-3"><label class="form-label">{{ __('إجمالي البدلات المدخلة') }}</label><input id="allowances_display" class="form-control" readonly value="0.00 ج.م"></div>
      <div class="col-md-3"><label class="form-label">{{ __('المتبقي') }}</label><input id="allowance_remaining_display" class="form-control" readonly value="0.00 ج.م"></div>
      <div class="col-md-6 d-flex align-items-end"><div id="allowanceMessage" class="small text-muted pb-2">{{ __('أدخل الـNet ثم وزّع البدلات.') }}</div></div>
    </div>
  </div>

  <div class="panel mt-3" style="background:#fff">
    <div class="panel-head"><div><h3>{{ __('التأمينات والضرائب') }}</h3><span>{{ __('الحساب وفق معادلات حاسبة الرواتب المعتمدة.') }}</span></div></div>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">{{ __('الأجر التأميني اليدوي') }} <small class="text-muted">{{ __('اختياري') }}</small></label><input name="insurance_wage" type="number" min="0" step="0.01" class="form-control" placeholder="{{ __('فارغ = تلقائي') }}"></div>
      <div class="col-md-4"><label class="form-label">{{ __('أساس الأجر التأميني') }}</label><input class="form-control" value="الأجر الأساسي تلقائياً" readonly></div>
      <div class="col-md-4"><label class="form-label">{{ __('الإعفاء الشخصي الشهري') }}</label><input name="personal_exemption" type="number" step="0.01" class="form-control" value="{{ format_number($payrollSettings['personal_exemption']) }}" readonly></div>
      <div class="col-md-4"><label class="form-label">{{ __('المعاملة الضريبية') }}</label><select name="tax_treatment" class="form-select"><option value="egyptian">{{ __('المصريين وما في حكمهم') }}</option><option value="other">{{ __('معاملة أخرى') }}</option></select></div>
      <div class="col-md-4"><label class="form-label">{{ __('استقطاعات ثابتة إضافية') }}</label><input name="fixed_deductions" type="number" min="0" step="0.01" class="form-control" value="0"></div>
      <div class="col-md-4 d-flex align-items-end"><label class="form-check mb-2"><input name="no_insurance" value="1" type="checkbox" class="form-check-input"> {{ __('بدون تأمينات') }}</label></div>
    </div>
  </div>

  <div class="panel mt-3" style="background:#f8fafc"><div class="panel-head"><div><h3>{{ __('ملخص الحساب') }}</h3><span>{{ __('سيتم تحديث القيم تلقائياً أثناء الإدخال.') }}</span></div></div><div class="salary-detail-grid" id="previewGrid">
    <div><span>Gross</span><strong data-result="gross">—</strong></div><div><span>{{ __('الأجر التأميني') }}</span><strong data-result="insurance_wage">—</strong></div><div><span>{{ __('حصة العامل') }} {{ format_number($payrollSettings['employee_insurance_rate']) }}%</span><strong data-result="employee_insurance">—</strong></div><div><span>{{ __('ضريبة الدخل') }}</span><strong data-result="income_tax">—</strong></div><div><span>{{ __('صندوق الشهداء') }} {{ format_number($payrollSettings['martyrs_fund_rate']) }}%</span><strong data-result="martyrs_fund">—</strong></div><div><span>{{ __('إجمالي الاستقطاعات') }}</span><strong data-result="total_deductions">—</strong></div><div><span>{{ __('Net النهائي') }}</span><strong data-result="net_after_fixed">—</strong></div><div><span>{{ __('حصة صاحب العمل') }} {{ format_number($payrollSettings['employer_insurance_rate']) }}%</span><strong data-result="employer_insurance">—</strong></div><div><span>{{ __('تكلفة الموظف') }}</span><strong data-result="employee_cost">—</strong></div><div><span>{{ __('الوعاء الضريبي السنوي') }}</span><strong data-result="taxable_annual">—</strong></div><div><span>{{ __('الضريبة السنوية') }}</span><strong data-result="annual_tax">—</strong></div><div><span>{{ __('Net قبل الاستقطاعات الثابتة') }}</span><strong data-result="net">—</strong></div>
  </div>
  <div class="small text-muted px-3 pb-3">{{ __('الضريبة تُحسب على أساس سنوي: الإجمالي × 12 − تأمين الموظف − الإعفاء الشخصي، ثم تُحدَّد فئة الدخل وتُطبَّق شرائحها، والضريبة الشهرية = السنوية ÷ 12.') }}</div></div>
  <div id="salaryError" class="alert alert-danger d-none mt-3"></div>
  <div class="mt-3"><label class="form-label">{{ __('ملاحظات') }}</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button id="saveSalaryBtn" class="btn btn-primary">{{ __('حفظ واعتماد الهيكل') }}</button></div>
</form></div></div></div>

@foreach($salaries as $s)
<div class="modal fade" id="editSalary{{ $s->id }}" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form method="POST" action="{{ route('salaries.update',$s) }}">@csrf @method('PUT')
<input type="hidden" name="salary_type" value="gross">
<div class="modal-header"><h5>{{ __('تعديل هيكل') }} {{ $s->employee?->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<div class="alert alert-primary">{{ __('سيعاد اشتقاق الأساسي والبدلات والتأمين والضريبة من الإجمالي وفق إعدادات النظام.') }}</div>
<div class="row g-3">
<div class="col-md-4"><label class="form-label">{{ __('الإجمالي الشهري (Gross) — مصدره ملف الموظف') }}</label><input name="salary_input" value="{{ $s->gross_salary }}" type="number" step="0.01" min="0.01" class="form-control edit-salary-input" readonly required><div class="form-text">{{ __('لتغيير الراتب عدّل ملف الموظف؛ هيكل الرواتب يقرأ الإجمالي منه.') }}</div></div>
<div class="col-md-4"><label class="form-label">{{ __('تاريخ السريان') }}</label><input name="effective_from" value="{{ $s->effective_from?->format('Y-m-d') }}" type="date" class="form-control" required></div>
<div class="col-md-4"><label class="form-label">{{ __('Gross المحسوب') }}</label><input value="{{ format_money($s->gross_salary) }} ج.م" type="text" class="form-control" readonly></div>
<div class="col-md-4"><label class="form-label">{{ __('بدل انتقال') }}</label><input name="transportation_allowance" value="{{ $s->transportation_allowance }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('بدل وجبة') }}</label><input name="meal_allowance" value="{{ $s->meal_allowance }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('بدل سكن') }}</label><input name="housing_allowance" value="{{ $s->housing_allowance }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('بدل ملابس') }}</label><input name="clothing_allowance" value="{{ $s->clothing_allowance }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('الأجر التأميني اليدوي') }}</label><input name="insurance_wage" value="{{ $s->insurance_wage }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('استبعاد البدلات من التأمين') }}</label><select name="excluded_allowance_pct" class="form-select"><option value="30" @selected((float)$s->excluded_allowance_pct===30.0)>30%</option><option value="25" @selected((float)$s->excluded_allowance_pct===25.0)>25%</option><option value="20" @selected((float)$s->excluded_allowance_pct===20.0)>20%</option><option value="0" @selected((float)$s->excluded_allowance_pct===0.0)>0%</option></select></div>
<div class="col-md-4"><label class="form-label">{{ __('الإعفاء السنوي') }}</label><input name="personal_exemption" value="{{ $s->personal_exemption }}" type="number" step="0.01" class="form-control" readonly></div>
<div class="col-md-4"><label class="form-label">{{ __('استقطاعات ثابتة') }}</label><input name="fixed_deductions" value="{{ $s->fixed_deductions }}" type="number" step="0.01" min="0" class="form-control"></div>
<div class="col-md-4"><label class="form-label">{{ __('المعاملة الضريبية') }}</label><select name="tax_treatment" class="form-select"><option value="egyptian" @selected($s->tax_treatment==='egyptian')>{{ __('المصريين وما في حكمهم') }}</option><option value="other" @selected($s->tax_treatment==='other')>{{ __('معاملة أخرى') }}</option></select></div>
<div class="col-md-4 d-flex align-items-end"><label class="form-check mb-2"><input name="no_insurance" value="1" type="checkbox" class="form-check-input" @checked($s->no_insurance)> {{ __('بدون تأمينات') }}</label></div>
</div>
<div class="panel mt-3" style="background:#f8fafc"><div class="panel-head"><div><h3>{{ __('تفاصيل الهيكل الحالية') }}</h3><span>{{ __('البدلات الأربعة تُحسب تلقائياً وفق نسبة البدلات') }} {{ format_number($allowanceRate) }}%.</span></div></div><div class="salary-detail-grid">
<div><span>{{ __('إجمالي البدلات') }}</span><strong>{{ format_money($s->allowance_limit) }}</strong></div><div><span>{{ __('بدلات مدخلة') }}</span><strong>{{ format_money($s->allowances) }}</strong></div><div><span>{{ __('المتبقي') }}</span><strong>{{ format_money($s->allowance_remaining) }}</strong></div><div><span>{{ __('الأجر الأساسي') }}</span><strong>{{ format_money($s->basic_salary) }}</strong></div><div><span>{{ __('حصة العامل') }}</span><strong>{{ format_money($s->employee_insurance) }}</strong></div><div><span>{{ __('الضريبة') }}</span><strong>{{ format_money($s->income_tax) }}</strong></div><div><span>{{ __('حصة صاحب العمل') }}</span><strong>{{ format_money($s->employer_insurance) }}</strong></div><div><span>{{ __('تكلفة الموظف') }}</span><strong>{{ format_money($s->employee_cost) }}</strong></div><div><span>{{ __('Net النهائي') }}</span><strong>{{ format_money($s->net_salary) }}</strong></div>
</div></div>
<div class="mt-3"><label class="form-label">{{ __('ملاحظات') }}</label><textarea name="notes" class="form-control">{{ $s->notes }}</textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ التعديل وإعادة الحساب') }}</button></div></form></div></div></div>
@endforeach

@push('scripts')
<script>
(function(){
  const modal=document.getElementById('salaryModal'); if(!modal)return;
  const allowanceRate={{ json_encode((float)$allowanceRate) }};
  const fmt=n=>Math.round(Number(n||0)).toLocaleString('en-US')+window.masarT(' ج.م');
  const form=modal.querySelector('form');
  const input=form.querySelector('[name=salary_input]');
  const employeeSelect=form.querySelector('[name=employee_id]');
  const allowanceInputs=[...form.querySelectorAll('.allowance-input')];
  function syncEmployeeGross(){
    const option=employeeSelect?.selectedOptions?.[0];
    const gross=option?.dataset?.gross || '';
    input.value=gross;
    document.getElementById('gross_display').value=gross ? fmt(gross) : '—';
    document.getElementById('salaryError').classList.add('d-none');
    if(!gross && option?.value){ document.getElementById('salaryError').textContent=window.masarT('لا يوجد راتب إجمالي محفوظ لهذا الموظف. أدخل الراتب من ملف الموظف أولًا.'); document.getElementById('salaryError').classList.remove('d-none'); }
  }
  const error=document.getElementById('salaryError');
  function updateAllowanceUI(){
    const limit=Number(input.value||0)*(allowanceRate/100);
    const total=allowanceInputs.reduce((s,e)=>s+Math.max(0,Number(e.value||0)),0);
    const remaining=limit-total;
    document.getElementById('allowance_limit_display').value=fmt(limit);
    document.getElementById('allowances_display').value=fmt(total);
    document.getElementById('allowance_remaining_display').value=fmt(remaining);
    const msg=document.getElementById('allowanceMessage');
    if(remaining<-.001){msg.className='small text-danger pb-2';msg.textContent=window.masarT('إجمالي البدلات تجاوز نسبة ')+allowanceRate+window.masarT('% — لا يمكن الحفظ.');}
    else {msg.className='small text-success pb-2';msg.textContent=window.masarT('المتبقي من إجمالي البدلات: ')+fmt(remaining);}
    return remaining;
  }
  function setResult(k,v){const el=form.querySelector('[data-result="'+k+'"]');if(el)el.textContent=fmt(v);}
  async function preview(){
    error.classList.add('d-none');
    const remaining=updateAllowanceUI();
    if(!input.value){return;}
    if(remaining<-.001){return;}
    const data=new FormData(form); data.set('salary_type','gross'); data.set('no_insurance',form.querySelector('[name=no_insurance]').checked?'1':'0');
    try{
      const r=await fetch('{{ route('salaries.preview') }}',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:data});
      const x=await r.json();
      if(!r.ok){throw new Error(x.message||window.masarT('تعذر حساب الراتب'));}
      Object.keys(x).forEach(k=>setResult(k,x[k]));
      document.getElementById('gross_display').value=fmt(x.gross);
      document.getElementById('basic_display').value=fmt(Math.max(0,Number(x.gross)-Number(x.allowances||0)));
    }catch(e){error.textContent=e.message;error.classList.remove('d-none');}
  }
  form.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',preview));
  employeeSelect?.addEventListener('change',()=>{syncEmployeeGross(); preview();});
  syncEmployeeGross();
  form.querySelectorAll('input,select').forEach(el=>el.addEventListener('change',preview));
  form.addEventListener('masar:before-submit',function(e){if(updateAllowanceUI()<-.001){e.preventDefault();error.textContent=window.masarT('إجمالي البدلات لا يمكن أن يتجاوز نسبة ')+allowanceRate+'%.';error.classList.remove('d-none');}});
  form.addEventListener('submit',function(e){if(updateAllowanceUI()<-.001){e.preventDefault();error.textContent=window.masarT('إجمالي البدلات لا يمكن أن يتجاوز نسبة ')+allowanceRate+'%.';error.classList.remove('d-none');}});
  modal.addEventListener('shown.bs.modal',preview);
})();
</script>
@endpush
@push('scripts')
<script>
(() => {
  const bindSalaryForms = (root = document) => {
    root.querySelectorAll('#salaryForm, [id^="editSalary"] form, .salary-layout form[action*="/salaries/"]').forEach(form => {
      form.dataset.ajax = 'true';
      form.dataset.ajaxLabel = form.querySelector('input[name="_method"][value="DELETE"]') ? window.masarT('جارٍ الإيقاف') : window.masarT('جارٍ الحفظ');
      if (form.querySelector('input[name="_method"][value="DELETE"]')) {
        form.dataset.ajaxConfirm = window.masarT('إيقاف هيكل الراتب الحالي؟');
        form.removeAttribute('onsubmit');
      }
    });
  };
  const refreshSalaryStructures = async (removedId = null) => {
    const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    const source = new DOMParser().parseFromString(await response.text(), 'text/html');
    const layout = document.querySelector('.salary-layout');
    const freshLayout = source.querySelector('.salary-layout');
    if (layout && freshLayout) layout.replaceWith(freshLayout);
    if (removedId) document.querySelectorAll('#salaryDetails' + removedId + ', #editSalary' + removedId).forEach(modal => modal.remove());
    source.querySelectorAll('[id^="salaryDetails"], [id^="editSalary"]').forEach(freshModal => {
      const currentModal = document.getElementById(freshModal.id);
      if (currentModal) currentModal.replaceWith(freshModal.cloneNode(true));
      else document.body.appendChild(freshModal.cloneNode(true));
    });
    bindSalaryForms();
  };
  bindSalaryForms();
  document.addEventListener('masar:success', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.ajax !== 'true') return;
    const action = new URL(form.action, window.location.origin).pathname;
    const isStore = action.endsWith('/salaries');
    const isUpdateOrDelete = action.includes('/salaries/');
    if (!isStore && !isUpdateOrDelete) return;
    const isDelete = Boolean(form.querySelector('input[name="_method"][value="DELETE"]'));
    const removedId = isDelete ? event.detail?.salary_id : null;
    bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
    if (form.id === 'salaryForm') form.reset();
    try {
      await refreshSalaryStructures(removedId);
    } catch (error) {
      console.error('Salary structures refresh failed', error);
    }
  });
})();
</script>
@endpush
@endsection
