@extends('layouts.app')

@section('title', __('ملف الموظف -').$employee->full_name)

@section('content')
@php($profile = $employee->profileDetail)
@php($ageYears = $employee->birth_date ? $employee->birth_date->age : null)
@php($servicePeriod = null)
@php($resignationMovement = $employee->movements->first(fn ($movement) => str_contains((string) $movement->reason, 'استقالة')))
@php($dismissalMovement = $employee->movements->first(fn ($movement) => str_contains((string) $movement->reason, 'فصل')))
@php($hasPreviousResignation = $employee->termination_type === 'resignation' || (bool) $resignationMovement)
@php($hasPreviousDismissal = $employee->termination_type === 'dismissal' || (bool) $dismissalMovement)
@if($employee->contract_start_date)
    @php($serviceDiff = $employee->contract_start_date->diff(today()))
    @php($servicePeriod = ['years' => $serviceDiff->y, 'months' => $serviceDiff->m, 'days' => $serviceDiff->d])
@endif
<div class="profile-head">
    <a href="{{ route('employees.index') }}" class="back-btn profile-back-btn">
        <i class="bi bi-arrow-right"></i> {{ __('العودة للموظفين') }}
    </a>

    <div class="profile-person">
        <button type="button" class="profile-avatar profile-avatar-button" data-bs-toggle="modal" data-bs-target="#profilePhotoModal" title="{{ __('إدارة الصورة') }}">@if($employee->profile_photo_url)<img src="{{ $employee->profile_photo_url }}" alt="{{ $employee->full_name }}">@else<i class="bi bi-person-fill" aria-hidden="true"></i>@endif</button>
        <div>
            <h1>{{ $employee->full_name }}</h1>
            <p>{{ $employee->job?->title ?? '—' }} · {{ $employee->department?->name ?? '—' }}</p>
            <div class="profile-tags">
                @php($employeeStatus = $employee->status?->name ?? 'Active')
                <button type="button" class="badge-soft border-0 {{ match($employeeStatus) { 'Leave' => 'warning', 'Suspended', 'Terminated' => 'danger', default => 'success' } }}" data-bs-toggle="modal" data-bs-target="#employeeStatusModal">{{ match($employeeStatus) { 'Active' => __('نشط'), 'Leave' => __('إجازة'), 'Suspended' => __('موقوف'), 'Terminated' => __('منتهي الخدمة'), 'Pending' => __('قيد التعيين'), default => $employeeStatus } }} <i class="bi bi-chevron-down"></i></button>
                @if($hasPreviousResignation)
                    <span class="badge-soft warning d-block" title="{{ __('استقالة سابقة') }}" aria-label="{{ __('استقالة سابقة') }}"><i class="bi bi-file-earmark-text"></i></span>
                @endif
                @if($hasPreviousDismissal)
                    <span class="badge-soft danger d-block">{{ __('فصل سابق') }}{{ ($employee->termination_type === 'dismissal' && $employee->termination_date) ? ' · '.$employee->termination_date->format('d/m/Y') : ($dismissalMovement?->movement_date ? ' · '.$dismissalMovement->movement_date->format('d/m/Y') : '') }}</span>
                @endif
            </div>
        </div>
        <div class="profile-metrics" aria-label="{{ __('ملخص بيانات الموظف') }}">
            <div class="profile-metric"><i class="bi bi-hash"></i><span>{{ __('كود الموظف') }}</span><b>{{ $employee->employee_code ?? '—' }}</b></div>
            <div class="profile-metric"><i class="bi bi-person"></i><span>{{ __('العمر') }}</span><b>{{ $ageYears !== null ? $ageYears.' '.__('سنة') : '—' }}</b></div>
            <div class="profile-metric"><i class="bi bi-briefcase"></i><span>{{ __('مدة الخدمة') }}</span><b>
                @if(!$servicePeriod) —
                @elseif($servicePeriod['years'] > 0)
                    {{ $servicePeriod['years'] }} {{ __('سنة') }}{{ $servicePeriod['months'] > 0 ? ' '.__('و').$servicePeriod['months'].' '.__('شهر') : '' }}
                @elseif($servicePeriod['months'] > 0)
                    {{ $servicePeriod['months'] }} {{ __('شهر') }}{{ $servicePeriod['days'] > 0 ? ' '.__('و').$servicePeriod['days'].' '.__('يوم') : '' }}
                @else
                    {{ $servicePeriod['days'] }} {{ __('يوم') }}
                @endif
            </b></div>
            <div class="profile-metric"><i class="bi bi-diagram-3"></i><span>{{ __('القسم') }}</span><b>{{ $employee->department?->name ?? '—' }}</b></div>
        </div>
    </div>

    <div class="profile-actions">
        @if(auth()->user()?->hasPermission('employees.edit'))
            <a href="{{ route('employees.show', [$employee, 'tab' => 'job']) }}" class="btn btn-light">
                <i class="bi bi-pencil"></i> {{ __('البيانات الوظيفية') }}
            </a>
        @endif
        @if(auth()->user()?->hasPermission('employees.edit'))
        <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#employeeMasterModal"><i class="bi bi-pencil-square"></i> {{ __('تعديل الملف') }}</button>
        @endif
        <a href="{{ route('employees.print', $employee) }}" target="_blank" class="btn btn-primary">
            <i class="bi bi-printer"></i> {{ __('طباعة الملف') }}
        </a>
    </div>
</div>

<div class="profile-tabs">
    @foreach([
        ['overview',__('نظرة عامة'),'person'],
        ['salary',__('هيكل الراتب'),'cash-stack'],
        ['payroll',__('مسيرات الرواتب'),'receipt'],
        ['attendance',__('الحضور'),'clock'],
        ['leaves',__('الإجازات'),'calendar-check'],
        ['documentsProfile',__('المسوغات'),'folder'],
        ['insurance',__('التأمينات'),'shield-check'],
        ['discipline',__('الجزاءات'),'exclamation-octagon'],
        ['movements',__('الحركات'),'arrow-left-right']
    ] as $tab)
        <a class="profile-tab {{ $activeTab === $tab[0] ? 'active' : '' }}"
           href="{{ route('employees.show', [$employee, 'tab' => $tab[0]]) }}">
            <i class="bi bi-{{ $tab[2] }}"></i> {{ $tab[1] }}
        </a>
    @endforeach
</div>

<div class="profile-content">
@if($activeTab === 'overview')
    <div class="profile-grid">
        <div class="panel profile-panel" id="employee-emergency-contacts">
            <div class="panel-head">
                <div>
                    <h3>{{ __('البيانات الشخصية') }}</h3>
                    <span>{{ __('المعلومات الأساسية للموظف') }}</span>
                </div>
            </div>
            <div class="details-grid">
                <div><label>{{ __('الاسم الكامل') }}</label><b>{{ $employee->full_name }}</b></div>
                <div><label>{{ __('الرقم القومي') }}</label><b>{{ $employee->national_id ?? '—' }}</b></div>
                <div><label>{{ __('تاريخ الميلاد') }}</label><b>{{ $employee->birth_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>{{ __('الحالة الاجتماعية') }}</label><b>{{ $employee->marital_status ?? '—' }}</b></div>
                <div><label>{{ __('الهاتف') }}</label><b>{{ $employee->phone ?? '—' }}</b></div>
                <div><label>{{ __('البريد الإلكتروني') }}</label><b>{{ $employee->email ?? '—' }}</b></div>
                <div><label>{{ __('العنوان') }}</label><b>{{ $employee->address ?? '—' }}</b></div>
                <div><label>{{ __('المؤهل') }}</label><b>{{ $employee->qualification ?? '—' }}</b></div>
            </div>
        </div>

        <div class="panel profile-panel">
            <div class="panel-head">
                <div>
                    <h3>{{ __('ملخص الموظف') }}</h3>
                    <span>{{ __('بيانات مرتبطة بوحدات النظام') }}</span>
                </div>
            </div>
            <div class="profile-summary">
                <div><i class="bi bi-briefcase"></i><span>{{ __('الوظيفة') }}</span><b>{{ $employee->job?->title ?? '—' }}</b></div>
                <div><i class="bi bi-diagram-3"></i><span>{{ __('القسم') }}</span><b>{{ $employee->department?->name ?? '—' }}</b></div>
                <div><i class="bi bi-calendar-event"></i><span>{{ __('تاريخ بداية العقد') }}</span><b>{{ $employee->contract_start_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><i class="bi bi-cash-stack"></i><span>{{ __('هيكل الراتب الحالي') }}</span><b>{{ number_format($currentSalary?->net_salary ?? $currentSalary?->salary_input ?? 0) }} {{ __('ج.م') }}</b></div>
            </div>
        </div>
    </div>

    <section class="panel profile-panel mt-3 profile-completion {{ $profileCompletion['complete'] ? 'profile-complete' : 'profile-incomplete' }}">
        <div class="panel-head"><div><h3><i class="bi {{ $profileCompletion['complete'] ? 'bi-patch-check-fill' : 'bi-clipboard2-x-fill' }}"></i> {{ __('اكتمال ملف الموظف') }}</h3><span>{{ $profileCompletion['complete'] ? __('البيانات الأساسية والمستندات المطلوبة مكتملة.') : __('لا يُعد الملف مكتملًا قبل استيفاء العناصر التالية.') }}</span></div>@if(! $profileCompletion['complete'] && auth()->user()?->hasPermission('employees.edit'))<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#employeeMasterModal">{{ __('استكمال البيانات') }}</button>@endif</div>
        @if($profileCompletion['complete'])
            <div class="p-3 text-success small"><i class="bi bi-check-circle-fill"></i> {{ __('الملف جاهز للتشغيل والمتابعة.') }}</div>
        @else
            <div class="p-3 row g-3"><div class="col-md-6"><b class="d-block mb-2">{{ __('بيانات ناقصة') }}</b><div class="completion-list">@forelse($profileCompletion['missing_fields'] as $field)<span><i class="bi bi-dot"></i>{{ $field }}</span>@empty<span class="text-success"><i class="bi bi-check-lg"></i> {{ __('البيانات الأساسية مكتملة') }}</span>@endforelse</div></div><div class="col-md-6"><b class="d-block mb-2">{{ __('مستندات مطلوبة ناقصة أو غير صالحة') }}</b><div class="completion-list">@forelse($profileCompletion['missing_documents'] as $document)<span><i class="bi bi-dot"></i>{{ $document }}</span>@empty<span class="text-success"><i class="bi bi-check-lg"></i> {{ __('المستندات المطلوبة مكتملة') }}</span>@endforelse</div></div></div>
        @endif
    </section>

    <div class="profile-grid mt-3">
        <div class="panel profile-panel">
            <div class="panel-head"><h3>{{ __('جهات اتصال الطوارئ') }}</h3></div>
            @forelse($employee->emergencyContacts as $contact)
                <div class="details-grid mb-2"><div><label>{{ __('الاسم') }}</label><b>{{ $contact->name }}</b></div><div><label>{{ __('الهاتف') }}</label><b>{{ $contact->phone }}</b></div><div><label>{{ __('الصلة') }}</label><b>{{ $contact->relationship }}</b></div></div>
            @empty <p class="text-muted">{{ __('لا توجد جهة اتصال مسجلة.') }}</p> @endforelse
            @if(auth()->user()?->hasPermission('employees.edit'))
            <form method="POST" action="{{ route('employees.emergency-contacts.store', $employee) }}" class="row g-2 mt-2 employee-contact-form">@csrf
                <div class="col-md-4"><input name="name" class="form-control" placeholder="{{ __('الاسم') }}" required></div><div class="col-md-3"><input name="phone" class="form-control" placeholder="{{ __('الهاتف') }}" required></div><div class="col-md-3"><input name="relationship" class="form-control" placeholder="{{ __('صلة القرابة') }}" required></div><div class="col-md-2"><button class="btn btn-light w-100">{{ __('إضافة') }}</button></div>
            </form>
            @endif
        </div>
        <div class="panel profile-panel" id="employee-bank-accounts">
            <div class="panel-head"><h3>{{ __('الحسابات البنكية') }}</h3></div>
            @forelse($employee->bankAccounts as $account)
                <div class="details-grid mb-2"><div><label>{{ __('البنك') }}</label><b>{{ $account->bank?->name ?? '—' }}</b></div><div><label>IBAN</label><b>{{ $account->iban ?? '—' }}</b></div><div><label>{{ __('طريقة الصرف') }}</label><b>{{ $account->paymentMethod?->name ?? '—' }}</b></div></div>
            @empty <p class="text-muted">{{ __('لا توجد حسابات بنكية مسجلة.') }}</p> @endforelse
            @if(auth()->user()?->hasPermission('employees.edit'))
            <form method="POST" action="{{ route('employees.bank-accounts.store', $employee) }}" class="row g-2 mt-2 employee-contact-form">@csrf
                <div class="col-md-4"><select name="bank_id" class="form-select"><option value="">{{ __('البنك') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><input name="iban" class="form-control" placeholder="IBAN"></div>
                <div class="col-md-2"><select name="payment_method_id" class="form-select"><option value="">{{ __('طريقة الصرف') }}</option>@foreach($paymentMethods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><input type="date" name="effective_from" class="form-control" value="{{ now()->toDateString() }}" required></div>
                <div class="col-12"><button class="btn btn-light">{{ __('إضافة حساب') }}</button></div>
            </form>
            @endif
        </div>
    </div>

@elseif($activeTab === 'job')
    <div class="profile-grid">
        <div class="panel profile-panel">
            <div class="panel-head"><h3>{{ __('البيانات الوظيفية والعقد') }}</h3></div>
            <div class="details-grid">
                <div><label>{{ __('الكود الوظيفي') }}</label><b>#{{ $employee->employee_code }}</b></div>
                <div><label>{{ __('المسمى الوظيفي') }}</label><b>{{ $employee->job?->title ?? '—' }}</b></div>
                <div><label>{{ __('القسم') }}</label><b>{{ $employee->department?->name ?? '—' }}</b></div>
                <div><label>{{ __('الفرع') }}</label><b>{{ $employee->branch?->name ?? '—' }}</b></div>
                <div><label>{{ __('نوع التوظيف') }}</label><b>{{ $employee->employmentType?->name ?? '—' }}</b></div>
                <div><label>{{ __('بداية العقد') }}</label><b>{{ $employee->contract_start_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>{{ __('نهاية العقد') }}</label><b>{{ $employee->contract_end_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>{{ __('المدير المباشر') }}</label><b>{{ $employee->manager?->full_name ?? '—' }}</b></div>
            </div>
        </div>

        <div class="panel profile-panel">
            <div class="panel-head"><h3>{{ __('التسلسل الوظيفي') }}</h3></div>
            <div class="timeline">
                @forelse($employee->movements->sortByDesc('movement_date') as $movement)
                    <div>
                        <b>{{ $movement->movement_date?->format('d/m/Y') }}</b>
                        <span>{{ $movement->movementType?->name ?? __('حركة وظيفية') }}</span>
                    </div>
                @empty
                    <div><span>{{ __('لا توجد حركات وظيفية مسجلة.') }}</span></div>
                @endforelse
            </div>
        </div>
    </div>

    @if(auth()->user()?->hasPermission('employees.edit'))
    <section class="panel profile-panel mt-3" id="employment-status-panel">
        <div class="panel-head"><div><h3>{{ __('حالة الموظف') }}</h3><span>{{ __('اختر الحالة من القوائم الأساسية ثم احفظ التغيير.') }}</span></div></div>
        <form method="POST" action="{{ route('employees.update', $employee) }}" class="p-3 row g-3 align-items-end">
            @csrf @method('PUT')
            <input type="hidden" name="full_name" value="{{ $employee->full_name }}">
            <div class="col-md-4"><label class="form-label" for="employee_status_id">{{ __('الحالة') }}</label><select id="employee_status_id" name="status_id" class="form-select" required>@foreach($statuses as $item)<option value="{{ $item->id }}" @selected($employee->status_id == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('نوع انتهاء الخدمة') }}</label><select name="termination_type" class="form-select"><option value="">—</option><option value="resignation" @selected($employee->termination_type === 'resignation')>{{ __('استقالة') }}</option><option value="contract_end" @selected($employee->termination_type === 'contract_end')>{{ __('إنهاء عقد') }}</option><option value="dismissal" @selected($employee->termination_type === 'dismissal')>{{ __('فصل') }}</option></select></div>
            <div class="col-md-3"><label class="form-label">{{ __('تاريخ انتهاء الخدمة') }}</label><input type="date" name="termination_date" class="form-control" value="{{ $employee->termination_date?->format('Y-m-d') }}"></div>
            @php($returnDefault = $employee->return_date?->format('Y-m-d') ?? (in_array($employee->status?->name, ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة', 'Resigned', 'Resignation', 'مستقيل', 'استقال', 'استقالة'], true) ? today()->format('Y-m-d') : ''))
            <div class="col-md-3"><label class="form-label">{{ __('تاريخ الرجوع الفعلي (مطلوب عند إعادة الموظف لنشط)') }}</label><input type="date" name="return_date" class="form-control" value="{{ old('return_date', $returnDefault) }}">@error('return_date')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> {{ __('حفظ الحالة') }}</button></div>
        </form>
        @if(auth()->user()?->hasPermission('settlements.create'))
        @php($activeSettlement = \App\Models\Settlement::withoutGlobalScopes()->where('employee_id', $employee->id)->where('company_id', $employee->company_id)->whereIn('status', ['Draft', 'Approved'])->latest('id')->first())
        <div class="px-3 pb-3">
            @if($activeSettlement)
                <a class="btn btn-light btn-sm" href="{{ route('settlements.show', $activeSettlement) }}"><i class="bi bi-door-open"></i> {{ __('توجد تسوية نشطة — عرض التسوية') }}</a>
            @else
                <a class="btn btn-light btn-sm" href="{{ route('settlements.create', ['employee_id' => $employee->id]) }}"><i class="bi bi-door-closed"></i> {{ __('بدء تسوية ترك خدمة') }}</a>
            @endif
        </div>
        @endif
    </section>
    @endif

@elseif($activeTab === 'salary')
    <div class="panel profile-panel">
        <div class="panel-head">
            <div><h3>{{ __('هيكل الرواتب') }}</h3><span>{{ __('هذا السجل يعرض نفس هيكل الراتب المعتمد من شاشة هيكل الرواتب.') }}</span></div>
        </div>
        @forelse($employee->salaries as $s)
            <div class="employee-salary-row" data-bs-toggle="modal" data-bs-target="#employeeSalaryDetails{{ $s->id }}" role="button">
                <div><small>{{ __('السريان') }}</small><b>{{ $s->effective_from?->format('d/m/Y') ?? '—' }}</b></div>
                <div><small>{{ $s->salary_type === 'net' ? __('الأجر الصافي') : __('الإجمالي المعتمد') }}</small><b>{{ format_money($s->salary_input) }} {{ __('ج.م') }}</b></div>
                <div><small>Gross</small><b>{{ number_format($s->gross_salary,2) }} {{ __('ج.م') }}</b></div>
                <div><small>{{ __('إجمالي البدلات') }}</small><b>{{ number_format($s->allowances,2) }} {{ __('ج.م') }}</b></div>
                <div><small>{{ __('حصة العامل') }}</small><b>{{ number_format($s->employee_insurance,2) }} {{ __('ج.م') }}</b></div>
                <div><small>{{ __('الضريبة') }}</small><b>{{ number_format($s->income_tax,2) }} {{ __('ج.م') }}</b></div>
                <div><span class="badge-soft {{ $s->status === 'Active' ? 'success' : 'secondary' }}">{{ $s->status === 'Active' ? __('ساري') : __('سابق') }}</span></div>
            </div>
            <div class="modal fade" id="employeeSalaryDetails{{ $s->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content salary-details-modal">
                    <div class="modal-header"><div><h5 class="modal-title mb-1">{{ __('تفاصيل هيكل الراتب') }}</h5><div class="small text-muted">{{ $employee->full_name }} {{ __('· ساري من') }} {{ $s->effective_from?->format('d/m/Y') }}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-cash-stack"></i><span>{{ __('الراتب والبدلات') }}</span></div><div class="salary-detail-grid">
                            <div><span>{{ $s->salary_type === 'net' ? __('الأجر الصافي') : __('الإجمالي المعتمد') }}</span><strong>{{ format_money($s->salary_input) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('الراتب الإجمالي') }}</span><strong>{{ format_money($s->gross_salary) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('الأجر الأساسي') }}</span><strong>{{ format_money($s->basic_salary) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('إجمالي البدلات (') }}{{ format_number($s->allowance_limit_pct) }}%)</span><strong>{{ format_money($s->allowance_limit) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('بدل انتقال') }}</span><strong>{{ format_money($s->transportation_allowance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('بدل وجبة') }}</span><strong>{{ format_money($s->meal_allowance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('بدل سكن') }}</span><strong>{{ format_money($s->housing_allowance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('بدل ملابس') }}</span><strong>{{ format_money($s->clothing_allowance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('المتبقي من البدلات') }}</span><strong>{{ format_money($s->allowance_remaining) }} {{ __('ج.م') }}</strong></div>
                        </div></div>
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-shield-check"></i><span>{{ __('التأمينات') }}</span></div><div class="salary-detail-grid"><div><span>{{ __('الأجر التأميني') }}</span><strong>{{ $s->no_insurance ? __('بدون تأمينات') : format_money($s->applied_insurance_wage).' '.__('ج.م') }}</strong></div><div><span>{{ __('حصة العامل') }} {{ format_number($payrollRates['employee']) }}%</span><strong>{{ format_money($s->employee_insurance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('حصة صاحب العمل') }} {{ format_number($payrollRates['employer']) }}%</span><strong>{{ format_money($s->employer_insurance) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('أساس التأمين التلقائي') }}</span><strong>{{ __('الأجر الأساسي') }}</strong></div></div></div>
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-receipt"></i><span>{{ __('الضرائب والاستقطاعات') }}</span></div><div class="salary-detail-grid"><div><span>{{ __('الإعفاء الشخصي الشهري') }}</span><strong>{{ format_money($s->personal_exemption) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('الوعاء الضريبي السنوي') }}</span><strong>{{ format_money($s->taxable_annual) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('ضريبة الدخل (شهرياً)') }}</span><strong>{{ format_money($s->income_tax) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('صندوق الشهداء') }}</span><strong>{{ format_money($s->martyrs_fund) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('استقطاعات ثابتة') }}</span><strong>{{ format_money($s->fixed_deductions) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('إجمالي الاستقطاعات') }}</span><strong>{{ format_money($s->total_deductions) }} {{ __('ج.م') }}</strong></div><div><span>{{ __('صافي الراتب النهائي') }}</span><strong class="text-success">{{ format_money($s->net_salary) }} {{ __('ج.م') }}</strong></div></div></div>
                    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إغلاق') }}</button></div>
                </div></div>
            </div>
        @empty
            <div class="text-center py-5 text-muted">{{ __('لا يوجد هيكل راتب لهذا الموظف.') }}</div>
        @endforelse
    </div>

@elseif($activeTab === 'payroll')
    <div class="panel profile-panel">
        <div class="panel-head"><div><h3>{{ __('مسيرات الرواتب') }}</h3><span>{{ __('كل سجل شهري مرتبط مباشرة بهيكل الراتب الذي كان سارياً في تاريخ المسير.') }}</span></div><a class="btn btn-sm btn-outline-primary" href="{{ route('salaries.index',['employee_id'=>$employee->id]) }}">{{ __('عرض هيكل الراتب') }}</a></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>{{ __('الشهر') }}</th><th>{{ __('هيكل الراتب') }}</th><th>{{ __('المتفق عليه') }}</th><th>Gross</th><th>{{ __('البدلات') }}</th><th>{{ __('الإضافي') }}</th><th>{{ __('الخصومات') }}</th><th>Net</th><th>{{ __('الحالة') }}</th></tr></thead>
                <tbody>
                @forelse($employee->payrollRecords as $record)
                    <tr>
                        <td>{{ $record->payroll_month?->format('m/Y') ?? '—' }}</td>
                        <td>@if($record->salary)<a href="{{ route('salaries.index',['employee_id'=>$employee->id]) }}">{{ __('هيكل #') }}{{ $record->salary_id }}</a><small class="d-block text-muted">{{ __('ساري من') }} {{ $record->salary->effective_from?->format('d/m/Y') ?? '—' }}</small>@else—@endif</td>
                        <td>{{ format_money($record->agreed_net_salary) }}</td>
                        <td>{{ format_money($record->gross_salary) }}</td>
                        <td>{{ format_money($record->allowances) }}</td>
                        <td>{{ format_money($record->overtime_amount) }}</td>
                        <td>{{ format_money($record->deductions) }}</td>
                        <td class="fw-bold text-success">{{ format_money($record->net_salary) }}</td>
                        <td><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center py-5">{{ __('لا توجد مسيرات مرتبطة بهذا الموظف.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'attendance')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>{{ __('سجل الحضور والانصراف') }}</h3></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('الحضور') }}</th><th>{{ __('الانصراف') }}</th><th>{{ __('التأخير') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                <tbody>
                @forelse($employee->attendances as $a)
                    <tr>
                        <td>{{ $a->attendance_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $a->check_in ?? '—' }}</td>
                        <td>{{ $a->check_out ?? '—' }}</td>
                        <td>{{ $a->formatted_late }}</td>
                        <td><span class="badge-soft {{ $a->status_badge_class }}">{{ $a->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5">{{ __('لا توجد سجلات حضور.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'leaves')
    <div class="panel profile-panel">
        <div class="panel-head">
            <div><h3>{{ __('سجل الإجازات') }}</h3><span>{{ __('الرصيد المستخدم:') }} {{ $leaveUsed }} {{ __('يوم · المتاح:') }} {{ $leaveBalance === null ? __('غير محدد') : max(0, $leaveBalance - $leaveUsed).' '.__('يوم') }}</span></div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>{{ __('النوع') }}</th><th>{{ __('من') }}</th><th>{{ __('إلى') }}</th><th>{{ __('الأيام') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                <tbody>
                @forelse($employee->leaves as $l)
                    <tr>
                        <td>{{ $l->leaveType?->name ?? '—' }}</td>
                        <td>{{ $l->start_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $l->end_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $l->days }}</td>
                        <td><span class="badge-soft {{ $l->status_badge_class }}">{{ $l->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5">{{ __('لا توجد إجازات.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'documentsProfile')
    <div class="profile-section-head">
        <div><h2>{{ __('مسوغات التعيين') }}</h2><p>{{ __('إدارة المستندات الخاصة بالموظف.') }}</p></div>
        <div class="d-flex gap-2 flex-wrap">
            @if(auth()->user()?->hasPermission('roles.view'))<a class="btn btn-light" href="{{ route('master.index',['type'=>'documents']) }}"><i class="bi bi-gear"></i> {{ __('إعدادات أنواع المستندات') }}</a>@endif
            @if(auth()->user()?->hasPermission('documents.upload'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#documentModal"><i class="bi bi-upload"></i> {{ __('رفع مسوغ') }}</button>@endif
        </div>
    </div>

    @php($documentsByType = $employee->documents->filter(fn ($document) => $document->document_type_id)->keyBy('document_type_id'))
    @php($requiredDocumentTypeIds = $requiredDocumentTypes->pluck('id'))
    @php($pendingRequiredTypes = $requiredDocumentTypes->filter(fn ($type) => ! $documentsByType->get($type->id)?->file_path))
    <div class="documents-card-grid">
        @foreach($pendingRequiredTypes as $documentType)
            @php($pendingDocument = $documentsByType->get($documentType->id))
            <div class="doc-card missing required-document-card">
                <div class="doc-card-head">
                    <span class="doc-number"><i class="bi bi-exclamation-circle"></i></span>
                    <div class="doc-card-title"><button type="button" @if(auth()->user()?->hasPermission('documents.upload')) onclick='@if($pendingDocument)openDocument({{ $pendingDocument->id }}, @json($pendingDocument->document_name), {{ $documentType->id }}, @json($pendingDocument->expiry_date?->format('Y-m-d')))@else openRequiredDocument({{ $documentType->id }}, @json($documentType->name)) @endif' @endif>{{ $documentType->name }}</button></div>
                </div>
                <div class="doc-status"><span class="badge-soft danger">{{ __('مطلوب لكل موظف') }}</span></div>
                <div class="doc-thumb"><div class="doc-thumb-placeholder"><i class="bi bi-cloud-arrow-up"></i><span>{{ __('لم يتم رفع المستند') }}</span></div></div>
                <div class="doc-file">{{ ['basic'=>__('مستند أساسي'),'job'=>__('مستند خاص بالوظيفة'),'workplace'=>__('مستند خاص بمكتب العمل')][$documentType->category] ?? __('مستند مطلوب') }}</div>
                <div class="doc-meta">{{ __('ارفعه لإكمال ملف الموظف') }}</div>
                <div class="doc-card-actions">
                    @if(auth()->user()?->hasPermission('documents.upload'))
                        <button class="btn btn-sm btn-primary" type="button" onclick='@if($pendingDocument)openDocument({{ $pendingDocument->id }}, @json($pendingDocument->document_name), {{ $documentType->id }}, @json($pendingDocument->expiry_date?->format('Y-m-d')))@else openRequiredDocument({{ $documentType->id }}, @json($documentType->name)) @endif'><i class="bi bi-upload"></i> {{ __('رفع المستند') }}</button>
                    @endif
                </div>
            </div>
        @endforeach
        @forelse($employee->documents->reject(fn ($document) => $requiredDocumentTypeIds->contains($document->document_type_id) && ! $document->file_path) as $d)
            <div class="doc-card {{ in_array($d->status,['مطلوب','Required']) ? 'missing' : '' }}">
                <div class="doc-card-head">
                    <span class="doc-number">#{{ $d->id }}</span>
                    <div class="doc-card-title"><button type="button" data-doc-name="{{ $d->id }}" @if($d->file_path) onclick="window.open('{{ route('documents.preview',$d) }}','_blank')" @elseif(auth()->user()?->hasPermission('documents.upload')) onclick='openDocument({{ $d->id }}, @json($d->document_name), @json($d->document_type_id), @json($d->expiry_date?->format('Y-m-d')))' @endif>{{ $d->document_name }}</button></div>
                </div>
                <div class="doc-status"><span class="badge-soft {{ $d->status_badge_class }}">{{ $d->status }}</span></div>
                <div class="doc-thumb">
                    @if($d->file_path && $d->isImage())
                        <img src="{{ route('documents.preview',$d) }}" alt="{{ $d->document_name }}">
                    @elseif($d->file_path)
                        <i class="bi {{ str_contains($d->mime_type ?? '', 'pdf') ? 'bi-file-earmark-pdf' : 'bi-file-earmark-text' }} fs-1 text-secondary"></i>
                    @else
                        <div class="doc-thumb-placeholder"><i class="bi bi-cloud-arrow-up"></i><span>{{ __('لم يتم رفع الملف') }}</span></div>
                    @endif
                </div>
                <div class="doc-file">{{ $d->file_name ?? __('لم يتم رفع ملف') }}</div>
                <div class="doc-meta">{{ $d->formatted_size }}</div>
                <div class="doc-card-actions">
                    @if($d->file_path)
                        <a class="btn btn-sm btn-light" target="_blank" href="{{ route('documents.preview',$d) }}">{{ __('عرض') }}</a>
                        @if(auth()->user()?->hasPermission('documents.replace'))
                            <form method="POST" action="{{ route('documents.replace',$d) }}" enctype="multipart/form-data">
                                @csrf
                                <input class="d-none" id="replace{{ $d->id }}" type="file" name="document_file" onchange="this.form.submit()">
                                <button type="button" class="btn btn-sm btn-light" onclick="document.getElementById('replace{{ $d->id }}').click()">{{ __('استبدال') }}</button>
                            </form>
                        @endif
                        @if(auth()->user()?->hasPermission('documents.delete'))
                            <form method="POST" action="{{ route('documents.destroy',$d) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">{{ __('حذف') }}</button>
                            </form>
                        @endif
                    @elseif(auth()->user()?->hasPermission('documents.upload'))
                        <button class="btn btn-sm btn-primary" type="button" onclick='openDocument({{ $d->id }}, @json($d->document_name), @json($d->document_type_id), @json($d->expiry_date?->format('Y-m-d')))'>{{ __('رفع') }}</button>
                    @endif
                </div>
            </div>
        @empty
            @if($pendingRequiredTypes->isEmpty())
                <div class="empty-page w-100"><div class="empty-icon"><i class="bi bi-folder-plus"></i></div><h2>{{ __('لا توجد مسوغات') }}</h2><p>{{ __('أضف أول مسوغ لهذا الموظف.') }}</p></div>
            @endif
        @endforelse
    </div>

    @if(auth()->user()?->hasPermission('documents.upload'))
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('employees.documents.store',$employee) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">{{ __('رفع مسوغ') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">{{ __('اسم المسوغ') }}</label>
                        <input id="documentName" name="document_name" class="form-control mb-3" required>
                        <input type="hidden" id="documentId" name="document_id">
                        <label class="form-label">{{ __('نوع المستند') }}</label>
                        <select id="documentTypeId" name="document_type_id" class="form-select mb-3"><option value="">{{ __('غير محدد') }}</option>@foreach(['basic'=>__('مستندات أساسية'),'job'=>__('مستندات خاصة بالوظيفة'),'workplace'=>__('مستندات خاصة بمكتب العمل')] as $categoryKey=>$categoryLabel)<optgroup label="{{ $categoryLabel }}">@foreach($documentTypes->where('category',$categoryKey) as $documentType)<option value="{{ $documentType->id }}">{{ $documentType->name }} @if($documentType->is_required) {{ __('(مطلوب)') }} @endif</option>@endforeach</optgroup>@endforeach</select>
                        <label class="form-label">{{ __('تاريخ انتهاء الصلاحية') }}</label>
                        <input id="documentExpiryDate" type="date" name="expiry_date" class="form-control mb-3">
                        <label class="form-label">{{ __('الملف') }}</label>
                        <input name="document_file" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx">
                        <small class="text-muted">{{ __('الحد الأقصى 10 ميجابايت.') }}</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button>
                        <button class="btn btn-primary">{{ __('حفظ') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

@elseif($activeTab === 'insurance')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>{{ __('بيانات التأمينات الاجتماعية') }}</h3></div>
        @php($ins = $employee->insuranceRecord)
        @if($ins && $ins->status !== 'registered')
            <form method="POST" action="{{ route('insurance.update', $ins) }}" class="d-inline">@csrf @method('PUT')<input type="hidden" name="insurance_number" value="{{ $employee->insurance_number ?? $ins->insurance_number }}"><input type="hidden" name="insurance_start_date" value="{{ $ins->insurance_start_date?->format('Y-m-d') ?? now()->toDateString() }}"><input type="hidden" name="insurance_category" value="workers"><input type="hidden" name="status" value="registered"><button class="btn btn-sm btn-primary"><i class="bi bi-shield-check"></i> {{ __('إعادة التسجيل بالتأمينات') }}</button></form>
        @elseif(!$ins)
            <a class="btn btn-sm btn-primary" href="{{ route('insurance.index', ['tab' => 'unregistered', 'search' => $employee->employee_code]) }}"><i class="bi bi-shield-plus"></i> {{ __('تسجيل بالتأمينات') }}</a>
        @endif
        <div class="mb-3">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('insurance.index', ['search' => $employee->employee_code]) }}"><i class="bi bi-shield-check"></i> {{ __('إدارة التأمينات') }}</a>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('employees.forms.s1.editable', $employee) }}"><i class="bi bi-printer"></i> {{ __('طباعة س1 الرسمي') }}</a>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('employees.forms.s6.editable', $employee) }}"><i class="bi bi-printer"></i> {{ __('طباعة س6') }}</a>
        </div>
        <div class="details-grid">
            <div><label>{{ __('الحالة') }}</label><b>{{ $ins?->status ?? __('غير مسجل') }}</b></div>
            <div><label>{{ __('الرقم التأميني') }}</label><b>{{ $ins?->insurance_number ?? '—' }}</b></div>
            <div><label>{{ __('كود المهنة') }}</label><b>{{ $ins?->job_code ?? '—' }}</b></div>
            <div><label>{{ __('تاريخ بدء التأمين') }}</label><b>{{ $ins?->insurance_start_date?->format('d/m/Y') ?? '—' }}</b></div>
            <div><label>{{ __('مكتب التأمينات') }}</label><b>{{ $ins?->insurance_office ?? '—' }}</b></div>
            <div><label>{{ __('فئة التأمين') }}</label><b>{{ $ins?->insurance_category ?? '—' }}</b></div>
            <div><label>{{ __('مهلة التسجيل') }}</label><b>{{ $ins?->registration_deadline?->format('d/m/Y') ?? '—' }}</b></div>
            <div><label>{{ __('الأجر التأميني') }}</label><b>{{ $employee->currentSalary && !$employee->currentSalary->no_insurance ? format_money($employee->currentSalary->applied_insurance_wage) . ' '.__('ج.م') : '—' }}</b></div>
        </div>
    </div>

@elseif($activeTab === 'requests')
    <div class="panel profile-panel"><div class="panel-head"><div><h3>{{ __('الطلبات') }}</h3><span>{{ __('طلبات الموظف المسجلة في النظام') }}</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('الطلب') }}</th><th>{{ __('النوع') }}</th><th>{{ __('التاريخ') }}</th><th>{{ __('الحالة') }}</th></tr></thead><tbody>@forelse($employee->hrRequests as $request)<tr><td>{{ $request->title }}</td><td>{{ $request->requestType?->name ?? '—' }}</td><td>{{ $request->created_at?->format('d/m/Y') }}</td><td><span class="badge-soft {{ $request->status_badge_class }}">{{ arabic_label($request->status) }}</span></td></tr>@empty<tr><td colspan="4" class="text-center py-5">{{ __('لا توجد طلبات.') }}</td></tr>@endforelse</tbody></table></div></div>

@elseif($activeTab === 'discipline')
    <div class="panel profile-panel">
        <div class="panel-head"><div><h3>{{ __('سجل الجزاءات والمخالفات') }}</h3><span>{{ __('الإجراءات:') }} {{ $penaltyTotal }} {{ __('· المفتوح:') }} {{ $penaltyOpen }}</span></div></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('الإجراء') }}</th><th>{{ __('السبب') }}</th><th>{{ __('المبلغ') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                <tbody>
                @forelse($employee->penalties as $p)
                    <tr>
                        <td>{{ $p->penalty_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $p->penaltyType?->name ?? '—' }}</td>
                        <td>{{ $p->reason }}</td>
                        <td>{{ format_number($p->deduction_days ?? $p->penaltyType?->default_deduction_days ?? 0) }}</td>
                        <td>{{ format_money($p->amount ?? 0) }}</td>
                        <td><span class="badge-soft {{ $p->status_badge_class }}">{{ $p->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5">{{ __('لا توجد جزاءات.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'movements')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>{{ __('الحركات الوظيفية') }}</h3></div>
        <div class="table-responsive">
            <table class="table align-middle employee-movements-table">
                <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('الحركة') }}</th><th>{{ __('التفاصيل') }}</th><th>{{ __('القسم السابق') }}</th><th>{{ __('القسم الجديد') }}</th><th>{{ __('الوظيفة السابقة') }}</th><th>{{ __('الوظيفة الجديدة') }}</th><th>{{ __('الراتب الجديد') }}</th></tr></thead>
                <tbody>
                @forelse($employee->movements->sortByDesc(function ($movement) {
                    return sprintf('%s %s',
                        $movement->movement_date?->format('Y-m-d') ?? '0000-00-00',
                        $movement->created_at?->format('Y-m-d H:i:s') ?? '0000-00-00 00:00:00'
                    );
                }) as $m)
                    <tr data-movement-row>
                        <td data-sort-value="{{ $m->movement_date?->format('Y-m-d') ?? '' }}">{{ $m->movement_date?->format('d/m/Y') ?? '—' }}</td>
                        <td data-sort-value="{{ $m->movementType?->name ?? '' }}">{{ match($m->movementType?->name) { 'Status Change' => __('تغيير الحالة'), 'Profile Update' => __('تحديث بيانات وظيفية'), default => $m->movementType?->name ?? '—' } }}</td>
                        <td data-sort-value="{{ data_get($m->new_values, 'reactivated') ? __('عودة إلى العمل') : ($m->reason ?? $m->notes ?? '') }}">{{ data_get($m->new_values, 'reactivated') ? __('عودة إلى العمل') : ($m->reason ?? $m->notes ?? '—') }}</td>
                        <td data-sort-value="{{ $m->oldDepartment?->name ?? '' }}">{{ $m->oldDepartment?->name ?? '—' }}</td>
                        <td data-sort-value="{{ $m->newDepartment?->name ?? '' }}">{{ $m->newDepartment?->name ?? '—' }}</td>
                        <td data-sort-value="{{ $m->oldJob?->title ?? '' }}">{{ $m->oldJob?->title ?? '—' }}</td>
                        <td data-sort-value="{{ $m->newJob?->title ?? '' }}">{{ $m->newJob?->title ?? '—' }}</td>
                        <td data-sort-value="{{ $m->new_salary ?? 0 }}">{{ format_money($m->new_salary ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-5">{{ __('لا توجد حركات.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
</div>

@if(auth()->user()?->hasPermission('employees.edit'))
<div class="modal fade" id="employeeMasterModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content"><form method="POST" action="{{ route('employees.update', $employee) }}" class="employee-master-form" data-termination-type="{{ $employee->termination_type }}" data-termination-date="{{ $employee->termination_date?->format('Y-m-d') }}" enctype="multipart/form-data">@csrf @method('PUT')
<div class="modal-header"><h5 class="modal-title">{{ __('تعديل ملف الموظف:') }} {{ $employee->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<input type="hidden" name="salary_source" id="employee_edit_salary_source" value=""><input type="hidden" name="salary_value" id="employee_edit_salary_value" value="">
<div class="modal-body">
<section class="panel employee-termination-panel mb-3" id="employeeMasterTerminationPanel">
  <div class="panel-head"><div><h3><i class="bi bi-person-x"></i> {{ __('بيانات انتهاء الخدمة') }}</h3><span>{{ __('تُستخدم فقط عند الاستقالة أو الفصل، وتُلغى تلقائيًا عند إعادة الحالة إلى نشط.') }}</span></div></div>
  <div class="employee-termination-fields">
    <div class="employee-termination-field"><label class="form-label" for="employee_master_termination_type">{{ __('نوع انتهاء الخدمة') }}</label><select id="employee_master_termination_type" name="termination_type" class="form-select"><option value="">—</option><option value="resignation" @selected(old('termination_type',$employee->termination_type)==='resignation')>{{ __('استقالة') }}</option><option value="contract_end" @selected(old('termination_type',$employee->termination_type)==='contract_end')>{{ __('إنهاء عقد') }}</option><option value="dismissal" @selected(old('termination_type',$employee->termination_type)==='dismissal')>{{ __('فصل') }}</option></select></div>
    <div class="employee-termination-field"><label class="form-label" for="employee_master_termination_date">{{ __('تاريخ انتهاء الخدمة') }}</label><input id="employee_master_termination_date" type="date" name="termination_date" class="form-control" value="{{ old('termination_date',$employee->termination_date?->format('Y-m-d')) }}"></div>
    <div class="employee-termination-alert"><div class="alert alert-warning py-2 px-3 mb-0 d-none" id="employeeMasterActiveWarning"><i class="bi bi-exclamation-triangle-fill"></i> {{ __('اختيار «نشط» يلغي بيانات انتهاء الخدمة السابقة عند الحفظ.') }}</div></div>
  </div>
</section>
<div class="panel mb-3"><div class="panel-head"><div><h3>{{ __('البيانات الشخصية') }}</h3><span>{{ __('مصدرها جدول الموظفين وتفاصيل الملف.') }}</span></div></div><div class="p-3 row g-3">
<div class="col-md-3"><label class="form-label">{{ __('الصورة الشخصية') }}</label><input name="profile_photo" type="file" accept=".jpg,.jpeg,.png,.webp" class="form-control"><small class="text-muted">{{ __('صورة JPG أو PNG أو WEBP حتى 5 ميجابايت.') }}</small></div>
<div class="col-md-4"><label class="form-label">{{ __('الاسم الكامل') }}</label><input name="full_name" class="form-control" value="{{ old('full_name',$employee->full_name) }}" required></div><div class="col-md-3"><label class="form-label">{{ __('الاسم الأول') }}</label><input name="first_name" class="form-control" value="{{ old('first_name',$employee->first_name) }}"></div><div class="col-md-3"><label class="form-label">{{ __('الاسم الأوسط') }}</label><input name="middle_name" class="form-control" value="{{ old('middle_name',$employee->middle_name) }}"></div><div class="col-md-2"><label class="form-label">{{ __('اسم العائلة') }}</label><input name="last_name" class="form-control" value="{{ old('last_name',$employee->last_name) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('الرقم القومي') }}</label><input name="national_id" class="form-control" value="{{ old('national_id',$employee->national_id) }}"></div><div class="col-md-3"><label class="form-label">{{ __('الجنسية') }}</label><input name="profile[nationality]" class="form-control" value="{{ old('profile.nationality',$profile?->nationality) }}"></div><div class="col-md-3"><label class="form-label">{{ __('النوع') }}</label><input name="gender" class="form-control" value="{{ old('gender',$employee->gender) }}"></div><div class="col-md-3"><label class="form-label">{{ __('تاريخ الميلاد') }}</label><input name="birth_date" type="date" class="form-control" value="{{ old('birth_date',$employee->birth_date?->format('Y-m-d')) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('الهاتف') }}</label><input name="phone" class="form-control" value="{{ old('phone',$employee->phone) }}"></div><div class="col-md-3"><label class="form-label">{{ __('بريد العمل') }}</label><input name="email" type="email" class="form-control" value="{{ old('email',$employee->email) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('الراتب الإجمالي (Gross)') }}</label><input id="employee_edit_gross" type="number" step="0.01" min="0.01" class="form-control employee-salary-input" value="{{ old('gross_salary',$employee->gross_salary) }}"></div><div class="col-md-3"><label class="form-label">{{ __('الراتب الصافي (Net)') }}</label><input id="employee_edit_net" type="number" step="0.01" min="0.01" class="form-control employee-salary-input" value="{{ old('net_salary',$currentSalary?->net_salary) }}"></div><div class="col-md-3"><label class="form-label">{{ __('البريد الشخصي') }}</label><input name="profile[personal_email]" type="email" class="form-control" value="{{ old('profile.personal_email',$profile?->personal_email) }}"></div><div class="col-md-3"><label class="form-label">{{ __('الحالة الاجتماعية') }}</label><input name="marital_status" class="form-control" value="{{ old('marital_status',$employee->marital_status) }}"></div>
<div class="col-md-6"><label class="form-label">{{ __('العنوان') }}</label><input name="address" class="form-control" value="{{ old('address',$employee->address) }}"></div><div class="col-md-3"><label class="form-label">{{ __('المؤهل') }}</label><input name="qualification" class="form-control" value="{{ old('qualification',$employee->qualification) }}"></div><div class="col-md-3"><label class="form-label">{{ __('الموقف العسكري') }}</label><input name="profile[military_status]" class="form-control" value="{{ old('profile.military_status',$profile?->military_status) }}"></div>
</div></div>
<div class="panel mb-3"><div class="panel-head"><div><h3><i class="bi bi-heart-pulse"></i> {{ __('بيانات الحمل (من الشهادة الطبية)') }}</h3><span>{{ __('تُسجَّل للموظفة الحامل وهي تعمل: تُطبَّق حقوق الحمل (تخفيض الساعات بتاريخ البداية الموثق، ومنع الإضافي حتى التاريخ المتوقع) دون إنشاء إجازة أو تغيير حالة. عند تسجيل «اجازة وضع» تُنقل هذه البيانات إلى الإجازة وتُمسح من هنا.') }}</span></div></div><div class="p-3 row g-3">
<div class="col-md-4"><label class="form-label">{{ __('التاريخ المتوقع للولادة') }}</label><input type="date" name="expected_delivery_date" class="form-control" value="{{ old('expected_delivery_date',$employee->expected_delivery_date?->format('Y-m-d')) }}"></div>
<div class="col-md-4"><label class="form-label">{{ __('بداية تخفيض ساعات الحمل (من الشهادة)') }}</label><input type="date" name="pregnancy_reduction_start" class="form-control" value="{{ old('pregnancy_reduction_start',$employee->pregnancy_reduction_start?->format('Y-m-d')) }}"><small class="text-muted">{{ __('بدونها لا يُطبَّق تخفيض الساعات؛ التاريخ المتوقع وحده لا يكفي.') }}</small></div>
</div></div>
<div class="panel mb-3"><div class="panel-head"><div><h3>{{ __('البيانات الوظيفية والعقد') }}</h3><span>{{ __('تُحفظ العلاقات بالمعرّفات وتظهر بالاسم.') }}</span></div></div><div class="p-3 row g-3">
<div class="col-md-3"><label class="form-label">{{ __('القسم') }}</label><select name="department_id" class="form-select"><option value="">—</option>@foreach($departments as $item)<option value="{{ $item->id }}" @selected(old('department_id',$employee->department_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('الوظيفة') }}</label><select name="job_id" class="form-select"><option value="">—</option>@foreach($jobs as $item)<option value="{{ $item->id }}" @selected(old('job_id',$employee->job_id)==$item->id)>{{ $item->title }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('الفرع / مقر العمل') }}</label><select name="branch_id" class="form-select"><option value="">—</option>@foreach($branches as $item)<option value="{{ $item->id }}" @selected(old('branch_id',$employee->branch_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('نوع التوظيف') }}</label><select name="employment_type_id" class="form-select"><option value="">—</option>@foreach($employmentTypes as $item)<option value="{{ $item->id }}" @selected(old('employment_type_id',$employee->employment_type_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">{{ __('الحالة') }}</label><select name="status_id" class="form-select"><option value="">—</option>@foreach($statuses as $item)<option value="{{ $item->id }}" @selected(old('status_id',$employee->status_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('نوع انتهاء الخدمة') }}</label><select name="termination_type" class="form-select"><option value="">—</option><option value="resignation" @selected(old('termination_type',$employee->termination_type)==='resignation')>{{ __('استقالة') }}</option><option value="contract_end" @selected(old('termination_type',$employee->termination_type)==='contract_end')>{{ __('إنهاء عقد') }}</option><option value="dismissal" @selected(old('termination_type',$employee->termination_type)==='dismissal')>{{ __('فصل') }}</option></select></div><div class="col-md-3"><label class="form-label">{{ __('تاريخ انتهاء الخدمة') }}</label><input type="date" name="termination_date" class="form-control" value="{{ old('termination_date',$employee->termination_date?->format('Y-m-d')) }}"></div><div class="col-md-3"><label class="form-label">{{ __('المدير المباشر') }}</label><select name="manager_employee_id" class="form-select"><option value="">—</option>@foreach($managers as $item)<option value="{{ $item->id }}" @selected(old('manager_employee_id',$employee->manager_employee_id)==$item->id)>{{ $item->full_name }} (#{{ $item->employee_code }})</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">{{ __('نوع العقد') }}</label><input name="profile[contract_type]" class="form-control" value="{{ old('profile.contract_type',$profile?->contract_type) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('تاريخ بداية العقد') }}</label><input id="employee_contract_start_date" type="date" name="contract_start_date" class="form-control" value="{{ old('contract_start_date',$employee->contract_start_date?->format('Y-m-d')) }}"></div><div class="col-md-3"><label class="form-label">{{ __('نهاية العقد') }}</label><input id="employee_contract_end_date" type="date" name="contract_end_date" class="form-control" value="{{ old('contract_end_date',$employee->contract_end_date?->format('Y-m-d')) }}"></div><div class="col-md-3"><label class="form-label">{{ __('مدة العقد') }}</label><input name="contract_duration" class="form-control" value="{{ old('contract_duration',$employee->contract_duration) }}" placeholder="{{ __('سنة') }}"></div><div class="col-md-3"><label class="form-label">{{ __('فترة التجربة') }}</label><input name="profile[probation_period]" class="form-control" value="{{ old('profile.probation_period',$profile?->probation_period) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('نمط العمل') }}</label><input name="profile[work_pattern]" class="form-control" value="{{ old('profile.work_pattern',$profile?->work_pattern) }}"></div><div class="col-md-3"><label class="form-label">{{ __('أيام العمل') }}</label><input name="profile[weekly_work_days]" class="form-control" value="{{ old('profile.weekly_work_days',$profile?->weekly_work_days) }}"></div><div class="col-md-3"><label class="form-label">{{ __('ساعات العمل يوميًا') }}</label><input name="profile[daily_work_hours]" type="number" step="0.01" min="0" max="24" class="form-control" value="{{ old('profile.daily_work_hours',format_number($profile?->daily_work_hours)) }}"></div><div class="col-md-3"><label class="form-label">{{ __('مواعيد العمل') }}</label><input name="profile[working_hours_text]" class="form-control" value="{{ old('profile.working_hours_text',$profile?->working_hours_text) }}"></div>
<div class="col-md-3"><label class="form-label">{{ __('الإجازة السنوية') }}</label><input name="profile[annual_leave_days]" type="number" step="0.01" min="0" class="form-control" value="{{ old('profile.annual_leave_days',format_number($profile?->annual_leave_days)) }}"></div><div class="col-md-3"><label class="form-label">{{ __('حالة إرفاق العقد') }}</label><input name="profile[contract_attachment_status]" class="form-control" value="{{ old('profile.contract_attachment_status',$profile?->contract_attachment_status) }}"></div><div class="col-md-3"><label class="form-label">{{ __('العملة') }}</label><input name="profile[currency]" class="form-control" value="{{ old('profile.currency',$profile?->currency) }}"></div>
</div></div>
<div class="panel"><div class="panel-head"><div><h3>{{ __('بيانات الهوية والملاحظات') }}</h3></div></div><div class="p-3 row g-3"><div class="col-md-4"><label class="form-label">{{ __('تاريخ إصدار الهوية') }}</label><input type="date" name="profile[national_id_issue_date]" class="form-control" value="{{ old('profile.national_id_issue_date',$profile?->national_id_issue_date?->format('Y-m-d')) }}"></div><div class="col-md-4"><label class="form-label">{{ __('تاريخ انتهاء الهوية') }}</label><input type="date" name="profile[national_id_expiry_date]" class="form-control" value="{{ old('profile.national_id_expiry_date',$profile?->national_id_expiry_date?->format('Y-m-d')) }}"></div><div class="col-md-4"><label class="form-label">{{ __('مكان إصدار الهوية') }}</label><input name="profile[national_id_place]" class="form-control" value="{{ old('profile.national_id_place',$profile?->national_id_place) }}"></div><div class="col-md-6"><label class="form-label">{{ __('شروط إضافية') }}</label><textarea name="profile[additional_conditions]" class="form-control" rows="2">{{ old('profile.additional_conditions',$profile?->additional_conditions) }}</textarea></div><div class="col-md-6"><label class="form-label">{{ __('ملاحظات') }}</label><textarea name="notes" class="form-control" rows="2">{{ old('notes',$employee->notes) }}</textarea></div></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ بيانات الموظف') }}</button></div></form></div></div></div>
@endif

@if(auth()->user()?->hasPermission('employees.edit'))
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('#employeeMasterModal form.employee-master-form');
  const status = form?.querySelector('select[name="status_id"]');
  if (!form || !status) return;
  let type = form.querySelector('select[name="termination_type"]');
  let date = form.querySelector('input[name="termination_date"]');
  // Fallback for cached/older markup: create the fields directly inside the
  // edit-file modal so the feature cannot disappear from the UI.
  if (!type || !date) {
    const row = form.querySelector('.modal-body .panel:nth-of-type(2) .p-3.row') || form.querySelector('.modal-body .p-3.row');
    if (!row) return;
    const block = document.createElement('div');
    block.className = 'row g-3 employee-termination-fallback';
    block.innerHTML = window.masarStaticHtml('<div class="col-md-3"><label class="form-label">نوع انتهاء الخدمة</label><select name="termination_type" class="form-select"><option value="">—</option><option value="resignation">استقالة</option><option value="contract_end">إنهاء عقد</option><option value="dismissal">فصل</option></select></div><div class="col-md-3"><label class="form-label">تاريخ انتهاء الخدمة</label><input type="date" name="termination_date" class="form-control"></div>');
    row.appendChild(block);
    type = block.querySelector('[name="termination_type"]');
    date = block.querySelector('[name="termination_date"]');
    type.value = form.dataset.terminationType || '';
    date.value = form.dataset.terminationDate || '';
  }

  const warning = document.createElement('div');
  warning.className = 'alert alert-warning d-none mt-2 mb-0 py-2';
  warning.innerHTML = window.masarStaticHtml('<i class="bi bi-exclamation-triangle-fill"></i> عند حفظ الحالة «نشط»، سيُلغي النظام نوع وتاريخ انتهاء الخدمة السابقين.');
  const fieldsRow = type.closest('.col-md-3')?.parentElement;
  if (fieldsRow) {
    const heading = document.createElement('div');
    heading.className = 'col-12 employee-termination-heading';
    heading.innerHTML = window.masarStaticHtml('<strong><i class="bi bi-person-x"></i> بيانات انتهاء الخدمة</strong><small>تُستخدم فقط عند الاستقالة أو الفصل، وتُلغى تلقائيًا عند إعادة الحالة إلى نشط.</small>');
    fieldsRow.insertBefore(heading, type.closest('.col-md-3'));
    fieldsRow.appendChild(warning);
  }

  const isActive = () => ['active', 'نشط'].includes((status.selectedOptions[0]?.textContent || '').trim().toLowerCase());
  const sync = () => {
    const active = isActive();
    warning.classList.toggle('d-none', !active || (!type.value && !date.value));
    if (active) {
      type.value = '';
      date.value = '';
    }
  };
  status.addEventListener('change', sync);
  sync();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('#employeeMasterModal form.employee-master-form');
  if (!form) return;
  // The dedicated panel at the top is the canonical edit-file control. Hide
  // the legacy duplicates further down in the long employment row.
  ['termination_type', 'termination_date'].forEach(function (name) {
    [...form.querySelectorAll('[name="' + name + '"]')].slice(1).forEach(function (field) {
      field.closest('.col-md-3, .col-6')?.classList.add('d-none');
    });
  });
  const status = form.querySelector('select[name="status_id"]');
  const warning = document.getElementById('employeeMasterActiveWarning');
  const type = form.querySelector('select[name="termination_type"]');
  const date = form.querySelector('input[name="termination_date"]');
  const syncWarning = function () {
    const active = ['active', 'نشط'].includes((status?.selectedOptions[0]?.textContent || '').trim().toLowerCase());
    warning?.classList.toggle('d-none', !active || (!form.dataset.terminationType && !form.dataset.terminationDate));
    if (active) { if (type) type.value = ''; if (date) date.value = ''; }
  };
  status?.addEventListener('change', syncWarning);
  syncWarning();
});
</script>
<div class="modal fade" id="employeeStatusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ route('employees.update', $employee) }}" id="employeeStatusForm">
      @csrf @method('PUT')
      <div class="modal-header"><h5 class="modal-title">{{ __('تغيير حالة الموظف') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><input type="hidden" name="full_name" value="{{ $employee->full_name }}"><input type="hidden" name="status_action" value="1">
        <label class="form-label">{{ __('الحالة') }}</label>
        <select name="status_id" id="profile_status_id" class="form-select mb-3" required>@foreach($statuses as $item)<option value="{{ $item->id }}" data-status-name="{{ $item->name }}" @selected($employee->status_id == $item->id)>{{ match($item->name) { 'Active' => __('نشط'), 'Leave' => __('إجازة'), 'Suspended' => __('موقوف'), 'Terminated' => __('منتهي الخدمة'), 'Pending' => __('قيد التعيين'), default => $item->name } }}</option>@endforeach</select>
        <div id="profileTerminationFields" class="row g-3"><div class="col-6"><label class="form-label">{{ __('نوع انتهاء الخدمة') }}</label><select name="termination_type" class="form-select"><option value="">—</option><option value="resignation" @selected($employee->termination_type === 'resignation')>{{ __('استقالة') }}</option><option value="contract_end" @selected($employee->termination_type === 'contract_end')>{{ __('إنهاء عقد') }}</option><option value="dismissal" @selected($employee->termination_type === 'dismissal')>{{ __('فصل') }}</option></select></div><div class="col-6"><label class="form-label">{{ __('تاريخ الحالة') }}</label><input type="date" name="termination_date" class="form-control" value="{{ $employee->termination_date?->format('Y-m-d') ?? now()->toDateString() }}"></div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ الحالة') }}</button></div>
    </form>
  </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () { const select=document.getElementById('profile_status_id'), fields=document.getElementById('profileTerminationFields'), type=fields?.querySelector('[name="termination_type"]'), date=fields?.querySelector('[name="termination_date"]'); const sync=()=>{const name=select?.selectedOptions[0]?.dataset.statusName||'', suspended=['Suspended','موقوف'].includes(name), resigned=['Resigned','Resignation','مستقيل','استقالة'].includes(name), show=['Terminated','منتهي الخدمة','منتهى الخدمة','Suspended','موقوف','Resigned','Resignation','مستقيل','استقالة'].includes(name); fields?.classList.toggle('d-none',!show); type?.closest('.col-6')?.classList.toggle('d-none',suspended||resigned); if(show&&date)date.value=new Date().toISOString().slice(0,10); if(type)type.value=suspended?'':(resigned?'resignation':type.value);}; select?.addEventListener('change',sync); sync(); });
</script>
@endif

@push('scripts')
<div class="modal fade" id="profilePhotoModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content profile-photo-modal">@if($employee->profile_photo_url)<div class="modal-body text-center"><img class="profile-photo-full" src="{{ $employee->profile_photo_url }}" alt="{{ $employee->full_name }}"></div>@endif @if(auth()->user()?->hasPermission('employees.edit'))<form method="POST" action="{{ route('employees.photo',$employee) }}" enctype="multipart/form-data">@csrf @method('PATCH')<div class="modal-body pt-0"><label class="form-label">{{ $employee->profile_photo_url ? __('تغيير الصورة') : __('إضافة صورة شخصية') }}</label><input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/webp"><small class="text-muted">{{ __('JPG أو PNG أو WebP، حتى 5 ميجابايت.') }}</small></div><div class="modal-footer justify-content-center gap-2"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button>@if($employee->profile_photo_url)<button type="submit" form="deleteProfilePhoto" class="btn btn-outline-danger">{{ __('حذف الصورة') }}</button>@endif<button class="btn btn-primary">{{ __('حفظ الصورة') }}</button></div></form>@if($employee->profile_photo_url)<form id="deleteProfilePhoto" method="POST" action="{{ route('employees.photo.delete',$employee) }}" class="d-none">@csrf @method('DELETE')</form>@endif @endif</div></div></div>
<script>
(function(){
    const contractStart=document.getElementById('employee_contract_start_date');
    const contractEnd=document.getElementById('employee_contract_end_date');
    let contractEndEdited=false;
    function oneYearAfter(value){
        if(!value) return '';
        const date=new Date(value+'T00:00:00');
        date.setFullYear(date.getFullYear()+1);
        return date.toISOString().slice(0,10);
    }
    if(contractStart?.value){
        if(!contractEnd.value) contractEnd.value=oneYearAfter(contractStart.value);
    }
    contractEnd?.addEventListener('input',()=>{ contractEndEdited=true; });
    contractStart?.addEventListener('change',()=>{
        if(!contractEndEdited) contractEnd.value=oneYearAfter(contractStart.value);
    });

    const gross=document.getElementById('employee_edit_gross');
    const net=document.getElementById('employee_edit_net');
    const mode=document.getElementById('employee_edit_salary_source');
    const salaryValue=document.getElementById('employee_edit_salary_value');
    const form=gross?.closest('form');
    if(!gross || !net || !mode || !salaryValue || !form) return;

    const endpoint='{{ route('employees.salary-preview.get') }}';
    let sequence=0;
    let lastSource=null;
    let requestController=null;

    async function sync(source){
        const input=source==='gross'?gross:net;
        const otherInput=source==='gross'?net:gross;
        const value=input.value.trim();
        mode.value=source;
        salaryValue.value=value;
        if(requestController) requestController.abort();
        if(!value || Number(value)<=0){
            otherInput.value='';
            lastSource=null;
            return;
        }
        lastSource=source;
        const current=++sequence;
        requestController=new AbortController();
        try{
            const url=endpoint+'?salary_source='+encodeURIComponent(source)+'&salary_value='+encodeURIComponent(value);
            const r=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},signal:requestController.signal});
            if(!r.ok || current!==sequence) return;
            const x=await r.json();
            otherInput.value=Number(source==='gross'?x.net:x.gross).toFixed(2);
        }catch(e){ if(e.name!=='AbortError') console.error('Salary preview failed',e); }
    }

    gross.addEventListener('input',()=>sync('gross'));
    net.addEventListener('input',()=>sync('net'));
    gross.addEventListener('change',()=>sync('gross'));
    net.addEventListener('change',()=>sync('net'));
    form.addEventListener('submit',()=>{
        if(lastSource){
            mode.value=lastSource;
            salaryValue.value=lastSource==='gross'?gross.value:net.value;
        }
    });
})();

window.openDocument = function (documentId, documentName = '', documentTypeId = '', expiryDate = '') {
    const nameInput = document.getElementById('documentName');
    const idInput = document.getElementById('documentId');
    const typeInput = document.getElementById('documentTypeId');
    const expiryInput = document.getElementById('documentExpiryDate');
    const modalElement = document.getElementById('documentModal');
    if (!modalElement) return;

    nameInput.value = documentName || '';
    idInput.value = documentId || '';
    typeInput.value = documentTypeId || '';
    expiryInput.value = expiryDate || '';
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
};

window.openRequiredDocument = function (documentTypeId, documentName) {
    window.openDocument('', documentName, documentTypeId, '');
};

// Keep document operations smooth without attempting to rebuild file cards in
// the browser.  The server remains the single source of truth for previews,
// permissions and required-document placeholders; only this small section is
// refreshed after a successful upload, replacement or deletion.
const documentModalForm = document.querySelector('#documentModal form');
const bindDocumentAjax = (root = document) => {
    root.querySelectorAll('#documentModal form, form[action*="/documents/"]').forEach((form) => {
        const path = new URL(form.action, window.location.origin).pathname;
        const isUpload = path.includes('/employees/') && path.endsWith('/documents');
        const isReplace = path.includes('/documents/') && path.endsWith('/replace');
        const isDelete = path.includes('/documents/') && !isReplace;
        if (!isUpload && !isReplace && !isDelete) return;
        form.dataset.ajax = 'true';
        form.dataset.ajaxLabel = isDelete ? window.masarT('جارٍ الحذف') : window.masarT('جارٍ الحفظ');
        if (isDelete) form.dataset.ajaxConfirm = window.masarT('هل تريد حذف هذا المستند؟');
    });
};
const refreshDocumentsSection = async () => {
    const response = await fetch(window.location.href, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const source = new DOMParser().parseFromString(await response.text(), 'text/html');
    const freshGrid = source.querySelector('.documents-card-grid');
    const currentGrid = document.querySelector('.documents-card-grid');
    if (!freshGrid || !currentGrid) return;
    currentGrid.replaceWith(freshGrid);
    bindDocumentAjax(freshGrid);
};
bindDocumentAjax();
document.addEventListener('masar:success', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.ajax !== 'true') return;
    const path = new URL(form.action, window.location.origin).pathname;
    const isEmployeeDocument = path.includes('/employees/') && path.endsWith('/documents');
    const isDocumentAction = path.includes('/documents/');
    if (!isEmployeeDocument && !isDocumentAction) return;
    if (form === documentModalForm) {
        bootstrap.Modal.getInstance(document.getElementById('documentModal'))?.hide();
        form.reset();
    }
    try {
        await refreshDocumentsSection();
    } catch (error) {
        console.error('Document section refresh failed', error);
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const annual = document.querySelector('input[name="profile[annual_leave_days]"]');
  if (!annual || document.querySelector('[name="leave_entitlement_category"]')) return;
  annual.closest('.col-md-3')?.insertAdjacentHTML('afterend', window.masarStaticHtml('<div class="col-md-3"><label class="form-label">فئة استحقاق الإجازة</label><select name="leave_entitlement_category" class="form-select"><option value="normal">عادي</option><option value="disabled">ذوو الإعاقة والأقزام (45 يومًا)</option><option value="hazardous">عمل خطر / منطقة نائية (+7 أيام)</option><option value="disabled_hazardous">ذوو الإعاقة والأقزام + عمل خطر / نائي (45 + 7 أيام)</option></select></div>'));
  const category = document.querySelector('[name="leave_entitlement_category"]'); if (category) category.value = @json(old('leave_entitlement_category', $employee->leave_entitlement_category ?? 'normal'));
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('.employee-movements-table');
    if (!table) return;
    const headers = [...table.querySelectorAll('thead th')];
    const body = table.querySelector('tbody');
    if (!body || !headers.length) return;
    const rows = () => [...body.querySelectorAll('tr[data-movement-row]')];
    const normalize = value => (value || '').trim().toLocaleLowerCase('ar');
    const valueFor = (row, index) => {
        const value = normalize(row.cells[index]?.dataset.sortValue || row.cells[index]?.textContent);
        return /^[-+]?\d+(?:[.,]\d+)?$/.test(value)
            ? Number(value.replace(',', '.'))
            : value;
    };
    let activeIndex = 0;
    let direction = 'desc';
    const renderIcons = () => headers.forEach((header, index) => {
        header.classList.add('movement-sortable');
        header.setAttribute('role', 'button');
        header.setAttribute('tabindex', '0');
        let icon = header.querySelector('.movement-sort-icon');
        if (!icon) {
            icon = document.createElement('i');
            icon.className = 'bi bi-arrow-down-up movement-sort-icon';
            icon.setAttribute('aria-hidden', 'true');
            header.appendChild(icon);
        }
        icon.className = 'bi movement-sort-icon ' + (index === activeIndex
            ? (direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down')
            : 'bi-arrow-down-up');
        header.classList.toggle('is-sorted', index === activeIndex);
        header.setAttribute('aria-sort', index === activeIndex
            ? (direction === 'asc' ? 'ascending' : 'descending')
            : 'none');
    });
    const sortBy = (index, nextDirection) => {
        activeIndex = index;
        direction = nextDirection;
        rows().sort((a, b) => {
            const left = valueFor(a, index);
            const right = valueFor(b, index);
            const comparison = typeof left === 'number' && typeof right === 'number'
                ? left - right
                : String(left).localeCompare(String(right), 'ar', { numeric: true, sensitivity: 'base' });
            return direction === 'asc' ? comparison : -comparison;
        }).forEach(row => body.appendChild(row));
        renderIcons();
    };
    headers.forEach((header, index) => {
        const activate = () => sortBy(index, activeIndex === index && direction === 'desc' ? 'asc' : 'desc');
        header.addEventListener('click', activate);
        header.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); }
        });
    });
    sortBy(0, 'desc');
});
</script>
@endpush
<script>
(function(){const cards=document.querySelectorAll('.profile-summary>div');if(cards[3]){const label=cards[3].querySelector('span');if(label)label.textContent=window.masarT('الراتب الصافي');}})();
(function(){document.querySelectorAll('.table thead tr').forEach(tr=>{const text=tr.innerText;if(text.includes(window.masarT('المبلغ'))&&!text.includes(window.masarT('الأيام'))){const th=document.createElement('th');th.textContent=window.masarT('الأيام');const amount=[...tr.children].find(x=>x.innerText.includes('المبلغ'));tr.insertBefore(th,amount||tr.lastElementChild);}})})();
(function(){document.querySelectorAll('.table tbody tr').forEach(row=>{const cells=row.children;if(cells.length===5&&row.closest('.profile-panel')?.innerText.includes('الإجازات')){const paid=document.createElement('td');paid.textContent='{{ $employee->leaves->first()?->leaveType?->is_paid ? __('بأجر') : __('بدون أجر') }}';row.insertBefore(paid,cells[1]);const deduction=document.createElement('td');deduction.textContent='0';row.insertBefore(deduction,row.lastElementChild);}})})();
(function(){document.querySelectorAll('.profile-tabs a[href*="tab=job"]').forEach(el=>el.remove());const tabs=document.querySelector('.profile-tabs');if(tabs&&!tabs.querySelector('[href*="tab=requests"]')){const a=document.createElement('a');a.className='profile-tab';a.href='{{ route('employees.show',[$employee,'tab'=>'requests']) }}';a.innerHTML=window.masarStaticHtml('<i class="bi bi-inbox"></i> الطلبات');tabs.appendChild(a);}})();
(function(){const form=document.querySelector('#employeeMasterModal form');if(!form)return;const id=form.querySelector('[name="national_id"]'),birth=form.querySelector('[name="birth_date"]'),gender=form.querySelector('[name="gender"]');if(!id)return;id.addEventListener('input',()=>{const n=id.value.replace(/\D/g,'');if(n.length!==14||!birth||!gender)return;const year=n[0]==='3'?2000+Number(n.slice(1,3)):1900+Number(n.slice(1,3));const month=n.slice(3,5),day=n.slice(5,7);const d=new Date(year,Number(month)-1,Number(day));if(d.getFullYear()!==year||d.getMonth()+1!==Number(month)||d.getDate()!==Number(day))return;birth.value=year+'-'+month+'-'+day;gender.value=Number(n[12])%2?'ذكر':'أنثى';});})();
</script>
<script>
(function(){
 const leavesTable=document.querySelector('.profile-tabs .active[href*="tab=leaves"]')?document.querySelector('.profile-content .table'):null;
 if(leavesTable){const rows=leavesTable.querySelectorAll('tbody tr');const leaveData=@json($employee->leaves->map(fn($leave)=>['paid'=>(bool)$leave->leaveType?->is_paid]));const head=leavesTable.querySelector('thead tr');if(head&&head.children.length===5){const paidHead=document.createElement('th');paidHead.textContent=window.masarT('الأجر');head.insertBefore(paidHead,head.children[1]);const deductionHead=document.createElement('th');deductionHead.textContent=window.masarT('قيمة الخصم');head.insertBefore(deductionHead,head.lastElementChild);rows.forEach((row,i)=>{if(row.children.length!==5)return;const paid=document.createElement('td');paid.textContent=leaveData[i]?.paid?window.masarT('بأجر'):window.masarT('بدون أجر');row.insertBefore(paid,row.children[1]);const deduction=document.createElement('td');deduction.textContent=leaveData[i]?.paid?'0':window.masarT('يحسب في المسير');row.insertBefore(deduction,row.lastElementChild);});}}
})();
</script>
@push('scripts')
<script>
(function () {
    const forms = document.querySelectorAll('.employee-contact-form');
    if (!forms.length) return;

    forms.forEach(form => {
        form.dataset.ajax = 'true';
        form.dataset.ajaxLabel = window.masarT('جارٍ الحفظ');
    });

    const refreshPanel = async (id) => {
        const response = await fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const source = new DOMParser().parseFromString(await response.text(), 'text/html');
        const current = document.getElementById(id);
        const fresh = source.getElementById(id);
        if (current && fresh) current.replaceWith(fresh);
    };

    document.addEventListener('masar:success', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('employee-contact-form')) return;
        const panelId = form.action.includes('/emergency-contacts')
            ? 'employee-emergency-contacts'
            : 'employee-bank-accounts';
        form.reset();
        try {
            await refreshPanel(panelId);
            const replacement = document.getElementById(panelId)?.querySelector('.employee-contact-form');
            if (replacement) {
                replacement.dataset.ajax = 'true';
                replacement.dataset.ajaxLabel = window.masarT('جارٍ الحفظ');
            }
        } catch (error) {
            console.error('Employee contact section refresh failed', error);
        }
    });
})();
</script>
@endpush
@endsection
