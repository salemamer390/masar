@extends('layouts.app')

@section('title', 'ملف الموظف - '.$employee->full_name)

@section('content')
@php($profile = $employee->profileDetail)
<div class="profile-head">
    <a href="{{ route('employees.index') }}" class="back-btn">
        <i class="bi bi-arrow-right"></i> العودة للموظفين
    </a>

    <div class="profile-person">
        <div class="profile-avatar">{{ $employee->initials }}</div>
        <div>
            <h1>{{ $employee->full_name }}</h1>
            <p>{{ $employee->job?->title ?? '—' }} · {{ $employee->department?->name ?? '—' }}</p>
            <div class="profile-tags">
                @php($employeeStatus = $employee->status?->name ?? 'Active')
                <span class="badge-soft {{ match($employeeStatus) { 'Leave' => 'warning', 'Suspended', 'Terminated' => 'danger', default => 'success' } }}">{{ match($employeeStatus) { 'Active' => 'نشط', 'Leave' => 'إجازة', 'Suspended' => 'موقوف', 'Terminated' => 'منتهي الخدمة', 'Pending' => 'قيد التعيين', default => $employeeStatus } }}</span>
                <span>كود الموظف #{{ $employee->employee_code }}</span>
            </div>
        </div>
    </div>

    <div class="profile-actions">
        @if(auth()->user()?->hasPermission('employees.edit'))
            <a href="{{ route('employees.show', [$employee, 'tab' => 'job']) }}" class="btn btn-light">
                <i class="bi bi-pencil"></i> البيانات الوظيفية
            </a>
        @endif
        @if(auth()->user()?->hasPermission('employees.edit'))
        <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#employeeMasterModal"><i class="bi bi-pencil-square"></i> تعديل الملف</button>
        @endif
        <a href="{{ route('employees.print', $employee) }}" target="_blank" class="btn btn-primary">
            <i class="bi bi-printer"></i> طباعة الملف
        </a>
    </div>
</div>

<div class="profile-tabs">
    @foreach([
        ['overview','نظرة عامة','person'],
        ['job','البيانات الوظيفية','briefcase'],
        ['salary','هيكل الراتب','cash-stack'],
        ['payroll','مسيرات الرواتب','receipt'],
        ['attendance','الحضور','clock'],
        ['leaves','الإجازات','calendar-check'],
        ['documentsProfile','المسوغات','folder'],
        ['insurance','التأمينات','shield-check'],
        ['discipline','الجزاءات','exclamation-octagon'],
        ['movements','الحركات','arrow-left-right']
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
        <div class="panel profile-panel">
            <div class="panel-head">
                <div>
                    <h3>البيانات الشخصية</h3>
                    <span>المعلومات الأساسية للموظف</span>
                </div>
            </div>
            <div class="details-grid">
                <div><label>الاسم الكامل</label><b>{{ $employee->full_name }}</b></div>
                <div><label>الرقم القومي</label><b>{{ $employee->national_id ?? '—' }}</b></div>
                <div><label>تاريخ الميلاد</label><b>{{ $employee->birth_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>الحالة الاجتماعية</label><b>{{ $employee->marital_status ?? '—' }}</b></div>
                <div><label>الهاتف</label><b>{{ $employee->phone ?? '—' }}</b></div>
                <div><label>البريد الإلكتروني</label><b>{{ $employee->email ?? '—' }}</b></div>
                <div><label>العنوان</label><b>{{ $employee->address ?? '—' }}</b></div>
                <div><label>المؤهل</label><b>{{ $employee->qualification ?? '—' }}</b></div>
            </div>
        </div>

        <div class="panel profile-panel">
            <div class="panel-head">
                <div>
                    <h3>ملخص الموظف</h3>
                    <span>بيانات مرتبطة بوحدات النظام</span>
                </div>
            </div>
            <div class="profile-summary">
                <div><i class="bi bi-briefcase"></i><span>الوظيفة</span><b>{{ $employee->job?->title ?? '—' }}</b></div>
                <div><i class="bi bi-diagram-3"></i><span>القسم</span><b>{{ $employee->department?->name ?? '—' }}</b></div>
                <div><i class="bi bi-calendar-event"></i><span>تاريخ التعيين</span><b>{{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><i class="bi bi-cash-stack"></i><span>هيكل الراتب الحالي</span><b>{{ number_format($currentSalary?->net_salary ?? $currentSalary?->salary_input ?? 0) }} ج.م</b></div>
            </div>
        </div>
    </div>

    <div class="profile-grid mt-3">
        <div class="panel profile-panel">
            <div class="panel-head"><h3>جهات اتصال الطوارئ</h3></div>
            @forelse($employee->emergencyContacts as $contact)
                <div class="details-grid mb-2"><div><label>الاسم</label><b>{{ $contact->name }}</b></div><div><label>الهاتف</label><b>{{ $contact->phone }}</b></div><div><label>الصلة</label><b>{{ $contact->relationship }}</b></div></div>
            @empty <p class="text-muted">لا توجد جهة اتصال مسجلة.</p> @endforelse
            @if(auth()->user()?->hasPermission('employees.edit'))
            <form method="POST" action="{{ route('employees.emergency-contacts.store', $employee) }}" class="row g-2 mt-2">@csrf
                <div class="col-md-4"><input name="name" class="form-control" placeholder="الاسم" required></div><div class="col-md-3"><input name="phone" class="form-control" placeholder="الهاتف" required></div><div class="col-md-3"><input name="relationship" class="form-control" placeholder="صلة القرابة" required></div><div class="col-md-2"><button class="btn btn-light w-100">إضافة</button></div>
            </form>
            @endif
        </div>
        <div class="panel profile-panel">
            <div class="panel-head"><h3>الحسابات البنكية</h3></div>
            @forelse($employee->bankAccounts as $account)
                <div class="details-grid mb-2"><div><label>البنك</label><b>{{ $account->bank?->name ?? '—' }}</b></div><div><label>IBAN</label><b>{{ $account->iban ?? '—' }}</b></div><div><label>طريقة الصرف</label><b>{{ $account->paymentMethod?->name ?? '—' }}</b></div></div>
            @empty <p class="text-muted">لا توجد حسابات بنكية مسجلة.</p> @endforelse
            @if(auth()->user()?->hasPermission('employees.edit'))
            <form method="POST" action="{{ route('employees.bank-accounts.store', $employee) }}" class="row g-2 mt-2">@csrf
                <div class="col-md-4"><select name="bank_id" class="form-select"><option value="">البنك</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><input name="iban" class="form-control" placeholder="IBAN"></div>
                <div class="col-md-2"><select name="payment_method_id" class="form-select"><option value="">طريقة الصرف</option>@foreach($paymentMethods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><input type="date" name="effective_from" class="form-control" value="{{ now()->toDateString() }}" required></div>
                <div class="col-12"><button class="btn btn-light">إضافة حساب</button></div>
            </form>
            @endif
        </div>
    </div>

@elseif($activeTab === 'job')
    <div class="profile-grid">
        <div class="panel profile-panel">
            <div class="panel-head"><h3>البيانات الوظيفية والعقد</h3></div>
            <div class="details-grid">
                <div><label>الكود الوظيفي</label><b>#{{ $employee->employee_code }}</b></div>
                <div><label>المسمى الوظيفي</label><b>{{ $employee->job?->title ?? '—' }}</b></div>
                <div><label>القسم</label><b>{{ $employee->department?->name ?? '—' }}</b></div>
                <div><label>الفرع</label><b>{{ $employee->branch?->name ?? '—' }}</b></div>
                <div><label>نوع التوظيف</label><b>{{ $employee->employmentType?->name ?? '—' }}</b></div>
                <div><label>تاريخ التعيين</label><b>{{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>بداية العقد</label><b>{{ $employee->contract_start_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>نهاية العقد</label><b>{{ $employee->contract_end_date?->format('d/m/Y') ?? '—' }}</b></div>
                <div><label>المدير المباشر</label><b>{{ $employee->manager?->full_name ?? '—' }}</b></div>
            </div>
        </div>

        <div class="panel profile-panel">
            <div class="panel-head"><h3>التسلسل الوظيفي</h3></div>
            <div class="timeline">
                @forelse($employee->movements->sortByDesc('movement_date') as $movement)
                    <div>
                        <b>{{ $movement->movement_date?->format('d/m/Y') }}</b>
                        <span>{{ $movement->movementType?->name ?? 'حركة وظيفية' }}</span>
                    </div>
                @empty
                    <div><span>لا توجد حركات وظيفية مسجلة.</span></div>
                @endforelse
            </div>
        </div>
    </div>

    @if(auth()->user()?->hasPermission('employees.edit'))
    <section class="panel profile-panel mt-3" id="employment-status">
        <div class="panel-head"><div><h3>حالة الموظف</h3><span>اختر الحالة من القوائم الأساسية ثم احفظ التغيير.</span></div></div>
        <form method="POST" action="{{ route('employees.update', $employee) }}" class="p-3 row g-3 align-items-end">
            @csrf @method('PUT')
            <input type="hidden" name="full_name" value="{{ $employee->full_name }}">
            <div class="col-md-6"><label class="form-label" for="employee_status_id">الحالة</label><select id="employee_status_id" name="status_id" class="form-select" required>@foreach($statuses as $item)<option value="{{ $item->id }}" @selected($employee->status_id == $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> حفظ الحالة</button></div>
        </form>
    </section>
    @endif

@elseif($activeTab === 'salary')
    <div class="panel profile-panel">
        <div class="panel-head">
            <div><h3>هيكل الرواتب</h3><span>هذا السجل يعرض نفس هيكل الراتب المعتمد من شاشة هيكل الرواتب.</span></div>
        </div>
        @forelse($employee->salaries as $s)
            <div class="employee-salary-row" data-bs-toggle="modal" data-bs-target="#employeeSalaryDetails{{ $s->id }}" role="button">
                <div><small>السريان</small><b>{{ $s->effective_from?->format('d/m/Y') ?? '—' }}</b></div>
                <div><small>الأجر الصافي (Net)</small><b>{{ format_money($s->salary_input) }} ج.م</b></div>
                <div><small>Gross</small><b>{{ number_format($s->gross_salary,2) }} ج.م</b></div>
                <div><small>إجمالي البدلات</small><b>{{ number_format($s->allowance_limit,2) }} ج.م</b></div>
                <div><small>حصة العامل</small><b>{{ number_format($s->employee_insurance,2) }} ج.م</b></div>
                <div><small>الضريبة</small><b>{{ number_format($s->income_tax,2) }} ج.م</b></div>
                <div><span class="badge-soft {{ $s->status === 'Active' ? 'success' : 'secondary' }}">{{ $s->status === 'Active' ? 'ساري' : 'سابق' }}</span></div>
            </div>
            <div class="modal fade" id="employeeSalaryDetails{{ $s->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content salary-details-modal">
                    <div class="modal-header"><div><h5 class="modal-title mb-1">تفاصيل هيكل الراتب</h5><div class="small text-muted">{{ $employee->full_name }} · ساري من {{ $s->effective_from?->format('d/m/Y') }}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-cash-stack"></i><span>الراتب والبدلات</span></div><div class="salary-detail-grid">
                            <div><span>الأجر الصافي (Net)</span><strong>{{ number_format($s->salary_input,2) }} ج.م</strong></div><div><span>Gross</span><strong>{{ number_format($s->gross_salary,2) }} ج.م</strong></div><div><span>الأجر الأساسي</span><strong>{{ number_format($s->basic_salary,2) }} ج.م</strong></div><div><span>إجمالي البدلات ({{ format_number($s->allowance_limit_pct) }}%)</span><strong>{{ number_format($s->allowance_limit,2) }} ج.م</strong></div><div><span>بدل انتقال</span><strong>{{ number_format($s->transportation_allowance,2) }} ج.م</strong></div><div><span>بدل وجبة</span><strong>{{ number_format($s->meal_allowance,2) }} ج.م</strong></div><div><span>بدل سكن</span><strong>{{ number_format($s->housing_allowance,2) }} ج.م</strong></div><div><span>بدل ملابس</span><strong>{{ number_format($s->clothing_allowance ?? 0,2) }} ج.م</strong></div><div><span>المتبقي</span><strong>{{ number_format($s->allowance_remaining,2) }} ج.م</strong></div>
                        </div></div>
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-shield-check"></i><span>التأمينات</span></div><div class="salary-detail-grid"><div><span>الأجر التأميني</span><strong>{{ $s->no_insurance ? 'بدون تأمينات' : format_money($s->insurance_wage).' ج.م' }}</strong></div><div><span>حصة العامل {{ format_number($payrollRates['employee']) }}%</span><strong>{{ format_money($s->employee_insurance) }} ج.م</strong></div><div><span>حصة صاحب العمل {{ format_number($payrollRates['employer']) }}%</span><strong>{{ format_money($s->employer_insurance) }} ج.م</strong></div></div></div>
                        <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-receipt"></i><span>الضريبة والاستقطاعات</span></div><div class="salary-detail-grid"><div><span>الدخل الخاضع سنويًا</span><strong>{{ format_money($s->taxable_annual) }} ج.م</strong></div><div><span>ضريبة الدخل</span><strong>{{ format_money($s->income_tax) }} ج.م</strong></div><div><span>صندوق الشهداء</span><strong>{{ format_money($s->martyrs_fund) }} ج.م</strong></div><div><span>إجمالي الاستقطاعات</span><strong>{{ format_money($s->total_deductions) }} ج.م</strong></div><div><span>Net النهائي</span><strong class="text-success">{{ format_money($s->net_salary) }} ج.م</strong></div><div><span>تكلفة الموظف</span><strong>{{ format_money($s->employee_cost) }} ج.م</strong></div></div></div>
                    </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إغلاق</button></div>
                </div></div>
            </div>
        @empty
            <div class="text-center py-5 text-muted">لا يوجد هيكل راتب لهذا الموظف.</div>
        @endforelse
    </div>

@elseif($activeTab === 'payroll')
    <div class="panel profile-panel">
        <div class="panel-head"><div><h3>مسيرات الرواتب</h3><span>كل سجل شهري مرتبط مباشرة بهيكل الراتب الذي كان سارياً في تاريخ المسير.</span></div><a class="btn btn-sm btn-outline-primary" href="{{ route('salaries.index',['employee_id'=>$employee->id]) }}">عرض هيكل الراتب</a></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>الشهر</th><th>هيكل الراتب</th><th>المتفق عليه</th><th>Gross</th><th>البدلات</th><th>الإضافي</th><th>الخصومات</th><th>Net</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse($employee->payrollRecords as $record)
                    <tr>
                        <td>{{ $record->payroll_month?->format('m/Y') ?? '—' }}</td>
                        <td>@if($record->salary)<a href="{{ route('salaries.index',['employee_id'=>$employee->id]) }}">هيكل #{{ $record->salary_id }}</a><small class="d-block text-muted">ساري من {{ $record->salary->effective_from?->format('d/m/Y') ?? '—' }}</small>@else—@endif</td>
                        <td>{{ format_money($record->agreed_net_salary) }}</td>
                        <td>{{ format_money($record->gross_salary) }}</td>
                        <td>{{ format_money($record->allowances) }}</td>
                        <td>{{ format_money($record->overtime_amount) }}</td>
                        <td>{{ format_money($record->deductions) }}</td>
                        <td class="fw-bold text-success">{{ format_money($record->net_salary) }}</td>
                        <td><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center py-5">لا توجد مسيرات مرتبطة بهذا الموظف.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'attendance')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>سجل الحضور والانصراف</h3></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>التأخير</th><th>الحالة</th></tr></thead>
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
                    <tr><td colspan="5" class="text-center py-5">لا توجد سجلات حضور.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'leaves')
    <div class="panel profile-panel">
        <div class="panel-head">
            <div><h3>سجل الإجازات</h3><span>الرصيد المستخدم: {{ $leaveUsed }} يوم · المتاح: {{ $leaveBalance === null ? 'غير محدد' : max(0, $leaveBalance - $leaveUsed).' يوم' }}</span></div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>النوع</th><th>من</th><th>إلى</th><th>الأيام</th><th>الحالة</th></tr></thead>
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
                    <tr><td colspan="5" class="text-center py-5">لا توجد إجازات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'documentsProfile')
    <div class="profile-section-head">
        <div><h2>مسوغات التعيين</h2><p>إدارة المستندات الخاصة بالموظف.</p></div>
        @if(auth()->user()?->hasPermission('documents.upload'))
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#documentModal"><i class="bi bi-upload"></i> رفع مسوغ</button>
        @endif
    </div>

    <div class="documents-card-grid">
        @forelse($employee->documents as $d)
            <div class="doc-card {{ in_array($d->status,['مطلوب','Required']) ? 'missing' : '' }}">
                <div class="doc-card-head">
                    <span class="doc-number">#{{ $d->id }}</span>
                    <div class="doc-card-title"><button type="button" data-doc-name="{{ $d->id }}" @if($d->file_path) onclick="window.open('{{ route('documents.preview',$d) }}','_blank')" @elseif(auth()->user()?->hasPermission('documents.upload')) onclick="openDocument({{ $d->id }})" @endif>{{ $d->document_name }}</button></div>
                </div>
                <div class="doc-status"><span class="badge-soft {{ $d->status_badge_class }}">{{ $d->status }}</span></div>
                <div class="doc-thumb">
                    @if($d->file_path && $d->isImage())
                        <img src="{{ $d->file_url }}" alt="{{ $d->document_name }}">
                    @elseif($d->file_path)
                        <i class="bi {{ str_contains($d->mime_type ?? '', 'pdf') ? 'bi-file-earmark-pdf' : 'bi-file-earmark-text' }} fs-1 text-secondary"></i>
                    @else
                        <div class="doc-thumb-placeholder"><i class="bi bi-cloud-arrow-up"></i><span>لم يتم رفع الملف</span></div>
                    @endif
                </div>
                <div class="doc-file">{{ $d->file_name ?? 'لم يتم رفع ملف' }}</div>
                <div class="doc-meta">{{ $d->formatted_size }}</div>
                <div class="doc-card-actions">
                    @if($d->file_path)
                        <a class="btn btn-sm btn-light" target="_blank" href="{{ route('documents.preview',$d) }}">عرض</a>
                        @if(auth()->user()?->hasPermission('documents.replace'))
                            <form method="POST" action="{{ route('documents.replace',$d) }}" enctype="multipart/form-data">
                                @csrf
                                <input class="d-none" id="replace{{ $d->id }}" type="file" name="document_file" onchange="this.form.submit()">
                                <button type="button" class="btn btn-sm btn-light" onclick="document.getElementById('replace{{ $d->id }}').click()">استبدال</button>
                            </form>
                        @endif
                        @if(auth()->user()?->hasPermission('documents.delete'))
                            <form method="POST" action="{{ route('documents.destroy',$d) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">حذف</button>
                            </form>
                        @endif
                    @elseif(auth()->user()?->hasPermission('documents.upload'))
                        <button class="btn btn-sm btn-primary" type="button" onclick="openDocument({{ $d->id }})">رفع</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="empty-page w-100"><div class="empty-icon"><i class="bi bi-folder-plus"></i></div><h2>لا توجد مسوغات</h2><p>أضف أول مسوغ لهذا الموظف.</p></div>
        @endforelse
    </div>

    @if(auth()->user()?->hasPermission('documents.upload'))
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('employees.documents.store',$employee) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">رفع مسوغ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <label class="form-label">اسم المسوغ</label>
                        <input id="documentName" name="document_name" class="form-control mb-3" required>
                        <input type="hidden" id="documentId" name="document_id">
                        <label class="form-label">نوع المستند</label>
                        <select name="document_type_id" class="form-select mb-3"><option value="">غير محدد</option>@foreach($documentTypes as $documentType)<option value="{{ $documentType->id }}">{{ $documentType->name }} @if($documentType->is_required) (مطلوب) @endif</option>@endforeach</select>
                        <label class="form-label">تاريخ انتهاء الصلاحية</label>
                        <input type="date" name="expiry_date" class="form-control mb-3">
                        <label class="form-label">الملف</label>
                        <input name="document_file" type="file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx">
                        <small class="text-muted">الحد الأقصى 10 ميجابايت.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                        <button class="btn btn-primary">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

@elseif($activeTab === 'insurance')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>بيانات التأمينات الاجتماعية</h3></div>
        @php($ins = $employee->insuranceRecord)
        <div class="details-grid">
            <div><label>الحالة</label><b>{{ $ins?->status ?? 'غير مسجل' }}</b></div>
            <div><label>الرقم التأميني</label><b>{{ $ins?->insurance_number ?? '—' }}</b></div>
            <div><label>كود المهنة</label><b>{{ $ins?->job_code ?? '—' }}</b></div>
            <div><label>تاريخ بدء التأمين</label><b>{{ $ins?->insurance_start_date?->format('d/m/Y') ?? '—' }}</b></div>
        </div>
    </div>

@elseif($activeTab === 'discipline')
    <div class="panel profile-panel">
        <div class="panel-head"><div><h3>سجل الجزاءات والمخالفات</h3><span>الإجراءات: {{ $penaltyTotal }} · المفتوح: {{ $penaltyOpen }}</span></div></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>التاريخ</th><th>الإجراء</th><th>السبب</th><th>المبلغ</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse($employee->penalties as $p)
                    <tr>
                        <td>{{ $p->penalty_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $p->penaltyType?->name ?? '—' }}</td>
                        <td>{{ $p->reason }}</td>
                        <td>{{ format_money($p->amount ?? 0) }}</td>
                        <td><span class="badge-soft {{ $p->status_badge_class }}">{{ $p->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5">لا توجد جزاءات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

@elseif($activeTab === 'movements')
    <div class="panel profile-panel">
        <div class="panel-head"><h3>الحركات الوظيفية</h3></div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>التاريخ</th><th>الحركة</th><th>القسم السابق</th><th>القسم الجديد</th><th>الوظيفة السابقة</th><th>الوظيفة الجديدة</th><th>الراتب الجديد</th></tr></thead>
                <tbody>
                @forelse($employee->movements as $m)
                    <tr>
                        <td>{{ $m->movement_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $m->movementType?->name ?? '—' }}</td>
                        <td>{{ $m->oldDepartment?->name ?? '—' }}</td>
                        <td>{{ $m->newDepartment?->name ?? '—' }}</td>
                        <td>{{ $m->oldJob?->title ?? '—' }}</td>
                        <td>{{ $m->newJob?->title ?? '—' }}</td>
                        <td>{{ format_money($m->new_salary ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-5">لا توجد حركات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
</div>

@if(auth()->user()?->hasPermission('employees.edit'))
<div class="modal fade" id="employeeMasterModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl"><div class="modal-content"><form method="POST" action="{{ route('employees.update', $employee) }}" class="employee-master-form">@csrf @method('PUT')
<div class="modal-header"><h5 class="modal-title">تعديل ملف الموظف: {{ $employee->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<input type="hidden" name="salary_source" id="employee_edit_salary_source" value=""><input type="hidden" name="salary_value" id="employee_edit_salary_value" value="">
<div class="modal-body">
<div class="panel mb-3"><div class="panel-head"><div><h3>البيانات الشخصية</h3><span>مصدرها جدول الموظفين وتفاصيل الملف.</span></div></div><div class="p-3 row g-3">
<div class="col-md-4"><label class="form-label">الاسم الكامل</label><input name="full_name" class="form-control" value="{{ old('full_name',$employee->full_name) }}" required></div><div class="col-md-3"><label class="form-label">الاسم الأول</label><input name="first_name" class="form-control" value="{{ old('first_name',$employee->first_name) }}"></div><div class="col-md-3"><label class="form-label">الاسم الأوسط</label><input name="middle_name" class="form-control" value="{{ old('middle_name',$employee->middle_name) }}"></div><div class="col-md-2"><label class="form-label">اسم العائلة</label><input name="last_name" class="form-control" value="{{ old('last_name',$employee->last_name) }}"></div>
<div class="col-md-3"><label class="form-label">الرقم القومي</label><input name="national_id" class="form-control" value="{{ old('national_id',$employee->national_id) }}"></div><div class="col-md-3"><label class="form-label">الجنسية</label><input name="profile[nationality]" class="form-control" value="{{ old('profile.nationality',$profile?->nationality) }}"></div><div class="col-md-3"><label class="form-label">النوع</label><input name="gender" class="form-control" value="{{ old('gender',$employee->gender) }}"></div><div class="col-md-3"><label class="form-label">تاريخ الميلاد</label><input name="birth_date" type="date" class="form-control" value="{{ old('birth_date',$employee->birth_date?->format('Y-m-d')) }}"></div>
<div class="col-md-3"><label class="form-label">الهاتف</label><input name="phone" class="form-control" value="{{ old('phone',$employee->phone) }}"></div><div class="col-md-3"><label class="form-label">بريد العمل</label><input name="email" type="email" class="form-control" value="{{ old('email',$employee->email) }}"></div>
<div class="col-md-3"><label class="form-label">الراتب الإجمالي (Gross)</label><input id="employee_edit_gross" type="number" step="0.01" min="0.01" class="form-control employee-salary-input" value="{{ old('gross_salary',$employee->gross_salary) }}"></div><div class="col-md-3"><label class="form-label">الراتب الصافي (Net)</label><input id="employee_edit_net" type="number" step="0.01" min="0.01" class="form-control employee-salary-input" value="{{ old('net_salary',$currentSalary?->net_salary) }}"></div><div class="col-md-3"><label class="form-label">البريد الشخصي</label><input name="profile[personal_email]" type="email" class="form-control" value="{{ old('profile.personal_email',$profile?->personal_email) }}"></div><div class="col-md-3"><label class="form-label">الحالة الاجتماعية</label><input name="marital_status" class="form-control" value="{{ old('marital_status',$employee->marital_status) }}"></div>
<div class="col-md-6"><label class="form-label">العنوان</label><input name="address" class="form-control" value="{{ old('address',$employee->address) }}"></div><div class="col-md-3"><label class="form-label">المؤهل</label><input name="qualification" class="form-control" value="{{ old('qualification',$employee->qualification) }}"></div><div class="col-md-3"><label class="form-label">الموقف العسكري</label><input name="profile[military_status]" class="form-control" value="{{ old('profile.military_status',$profile?->military_status) }}"></div>
</div></div>
<div class="panel mb-3"><div class="panel-head"><div><h3>البيانات الوظيفية والعقد</h3><span>تُحفظ العلاقات بالمعرّفات وتظهر بالاسم.</span></div></div><div class="p-3 row g-3">
<div class="col-md-3"><label class="form-label">القسم</label><select name="department_id" class="form-select"><option value="">—</option>@foreach($departments as $item)<option value="{{ $item->id }}" @selected(old('department_id',$employee->department_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">الوظيفة</label><select name="job_id" class="form-select"><option value="">—</option>@foreach($jobs as $item)<option value="{{ $item->id }}" @selected(old('job_id',$employee->job_id)==$item->id)>{{ $item->title }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">الفرع / مقر العمل</label><select name="branch_id" class="form-select"><option value="">—</option>@foreach($branches as $item)<option value="{{ $item->id }}" @selected(old('branch_id',$employee->branch_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">نوع التوظيف</label><select name="employment_type_id" class="form-select"><option value="">—</option>@foreach($employmentTypes as $item)<option value="{{ $item->id }}" @selected(old('employment_type_id',$employee->employment_type_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">الحالة</label><select name="status_id" class="form-select"><option value="">—</option>@foreach($statuses as $item)<option value="{{ $item->id }}" @selected(old('status_id',$employee->status_id)==$item->id)>{{ $item->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">المدير المباشر</label><select name="manager_employee_id" class="form-select"><option value="">—</option>@foreach($managers as $item)<option value="{{ $item->id }}" @selected(old('manager_employee_id',$employee->manager_employee_id)==$item->id)>{{ $item->full_name }} (#{{ $item->employee_code }})</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">تاريخ التعيين</label><input id="employee_hire_date" type="date" name="hire_date" class="form-control" value="{{ old('hire_date',$employee->hire_date?->format('Y-m-d')) }}"></div><div class="col-md-3"><label class="form-label">نوع العقد</label><input name="profile[contract_type]" class="form-control" value="{{ old('profile.contract_type',$profile?->contract_type) }}"></div>
<div class="col-md-3"><label class="form-label">بداية العقد</label><input id="employee_contract_start_date" type="date" name="contract_start_date" class="form-control" value="{{ old('contract_start_date',$employee->contract_start_date?->format('Y-m-d')) }}" readonly></div><div class="col-md-3"><label class="form-label">نهاية العقد</label><input id="employee_contract_end_date" type="date" name="contract_end_date" class="form-control" value="{{ old('contract_end_date',$employee->contract_end_date?->format('Y-m-d')) }}"></div><div class="col-md-3"><label class="form-label">مدة العقد</label><input name="contract_duration" class="form-control" value="{{ old('contract_duration',$employee->contract_duration) }}" placeholder="سنة"></div><div class="col-md-3"><label class="form-label">فترة التجربة</label><input name="profile[probation_period]" class="form-control" value="{{ old('profile.probation_period',$profile?->probation_period) }}"></div>
<div class="col-md-3"><label class="form-label">نمط العمل</label><input name="profile[work_pattern]" class="form-control" value="{{ old('profile.work_pattern',$profile?->work_pattern) }}"></div><div class="col-md-3"><label class="form-label">أيام العمل</label><input name="profile[weekly_work_days]" class="form-control" value="{{ old('profile.weekly_work_days',$profile?->weekly_work_days) }}"></div><div class="col-md-3"><label class="form-label">ساعات العمل يوميًا</label><input name="profile[daily_work_hours]" type="number" step="0.01" min="0" max="24" class="form-control" value="{{ old('profile.daily_work_hours',format_number($profile?->daily_work_hours)) }}"></div><div class="col-md-3"><label class="form-label">مواعيد العمل</label><input name="profile[working_hours_text]" class="form-control" value="{{ old('profile.working_hours_text',$profile?->working_hours_text) }}"></div>
<div class="col-md-3"><label class="form-label">الإجازة السنوية</label><input name="profile[annual_leave_days]" type="number" step="0.01" min="0" class="form-control" value="{{ old('profile.annual_leave_days',format_number($profile?->annual_leave_days)) }}"></div><div class="col-md-3"><label class="form-label">حالة إرفاق العقد</label><input name="profile[contract_attachment_status]" class="form-control" value="{{ old('profile.contract_attachment_status',$profile?->contract_attachment_status) }}"></div><div class="col-md-3"><label class="form-label">العملة</label><input name="profile[currency]" class="form-control" value="{{ old('profile.currency',$profile?->currency) }}"></div>
</div></div>
<div class="panel"><div class="panel-head"><div><h3>بيانات الهوية والملاحظات</h3></div></div><div class="p-3 row g-3"><div class="col-md-4"><label class="form-label">تاريخ إصدار الهوية</label><input type="date" name="profile[national_id_issue_date]" class="form-control" value="{{ old('profile.national_id_issue_date',$profile?->national_id_issue_date?->format('Y-m-d')) }}"></div><div class="col-md-4"><label class="form-label">تاريخ انتهاء الهوية</label><input type="date" name="profile[national_id_expiry_date]" class="form-control" value="{{ old('profile.national_id_expiry_date',$profile?->national_id_expiry_date?->format('Y-m-d')) }}"></div><div class="col-md-4"><label class="form-label">مكان إصدار الهوية</label><input name="profile[national_id_place]" class="form-control" value="{{ old('profile.national_id_place',$profile?->national_id_place) }}"></div><div class="col-md-6"><label class="form-label">شروط إضافية</label><textarea name="profile[additional_conditions]" class="form-control" rows="2">{{ old('profile.additional_conditions',$profile?->additional_conditions) }}</textarea></div><div class="col-md-6"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes',$employee->notes) }}</textarea></div></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ بيانات الموظف</button></div></form></div></div></div>
@endif

@push('scripts')
<script>
(function(){
    const hireDate=document.getElementById('employee_hire_date');
    const contractStart=document.getElementById('employee_contract_start_date');
    const contractEnd=document.getElementById('employee_contract_end_date');
    let contractEndEdited=false;
    function oneYearAfter(value){
        if(!value) return '';
        const date=new Date(value+'T00:00:00');
        date.setFullYear(date.getFullYear()+1);
        return date.toISOString().slice(0,10);
    }
    if(hireDate?.value){
        if(!contractStart.value) contractStart.value=hireDate.value;
        if(!contractEnd.value) contractEnd.value=oneYearAfter(hireDate.value);
    }
    contractEnd?.addEventListener('input',()=>{ contractEndEdited=true; });
    hireDate?.addEventListener('change',()=>{
        contractStart.value=hireDate.value;
        if(!contractEndEdited) contractEnd.value=oneYearAfter(hireDate.value);
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
</script>
@endpush
@endsection
