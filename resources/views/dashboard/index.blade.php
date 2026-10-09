@extends('layouts.app')
@section('title', 'لوحة التحكم')
@push('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" referrerpolicy="no-referrer">
<link href="{{ asset('dashboard-reference.css') }}" rel="stylesheet">
@endpush

@section('content')
<div class="dashboard-command-bar dashboard-command-bar--reference" aria-label="إجراءات سريعة">
  @foreach($quickActions as $action)
    @php($actionIcons = ['موظف جديد'=>'fa-user-plus','تسجيل إجازة'=>'fa-calendar-check','تسجيل حضور'=>'fa-clock','تسجيل جزاء'=>'fa-gavel','طلب HR جديد'=>'fa-file-circle-plus','إصدار مسير الرواتب'=>'fa-circle-dollar-to-slot'])
    <button type="button" class="dashboard-existing-action" data-dashboard-page="{{ $action['page'] }}" data-dashboard-source-modal="{{ $action['source_modal'] ?? '' }}" data-dashboard-source-selector="{{ $action['source_selector'] ?? '' }}"><i class="fa-solid {{ $actionIcons[$action['label']] ?? 'fa-bolt' }}"></i><span>{{ $action['label'] }}</span></button>
  @endforeach
</div>

<div class="reference-kpi-grid reference-kpi-grid--six">
  @forelse($heroMetrics as $metric)
    <a href="{{ $metric['url'] }}" class="reference-kpi {{ $metric['tone'] }}" data-dashboard-detail="{{ $metric['key'] }}" data-detail-url="{{ route('dashboard.details', $metric['key']) }}" data-detail-icon="{{ $metric['icon'] }}">
      @php($kpiIcons = ['employees'=>'fa-users','present'=>'fa-user-check','absent'=>'fa-user-xmark','late'=>'fa-clock','requests'=>'fa-calendar-check','payroll-cost'=>'fa-sack-dollar'])
      <span class="reference-kpi-icon"><i class="fa-solid {{ $kpiIcons[$metric['key']] ?? 'fa-chart-simple' }}"></i></span>
      <span><b>{{ $metric['label'] }}</b>@if($metric['key'] === 'payroll-cost')<strong class="kpi-money-value">{{ trim(str_replace('ج.م', '', $metric['value'])) }} <span class="kpi-currency">ج.م</span></strong>@else<strong>{{ $metric['value'] }}</strong>@endif<small>{{ $metric['hint'] }}</small></span>
    </a>
  @empty<div class="panel p-4 text-muted">لا توجد مؤشرات متاحة ضمن صلاحياتك.</div>@endforelse
</div>

<div class="dashboard-reference-layout">
  <div class="dashboard-reference-column">
    @if($attendance)
      @php($attendanceTotal = $attendance['counts']['scheduled'])
      <section class="panel reference-panel attendance-panel" data-dashboard-detail="attendance" data-detail-url="{{ route('dashboard.details','attendance') }}" data-detail-icon="pie-chart-fill" tabindex="0" role="button">
        <div class="panel-head"><div><h3><i class="bi bi-pie-chart-fill"></i> حالة الحضور اليوم</h3><span>{{ $today->format('d/m/Y') }} · {{ $attendance['cutoffReached'] ? 'تم احتساب الغياب' : 'قبل وقت الاحتساب' }}</span></div><a class="btn btn-light btn-sm" href="{{ route('attendance.index',['date'=>$today->toDateString()]) }}">عرض الكل</a></div>
        <div class="attendance-overview"><div class="attendance-ring" style="--present:{{ $attendanceTotal ? (($attendance['counts']['on_time'] / $attendanceTotal) * 100) : 0 }}%;--late:{{ $attendanceTotal ? (($attendance['counts']['late'] / $attendanceTotal) * 100) : 0 }}%;--absent:{{ $attendanceTotal ? (($attendance['counts']['absent'] / $attendanceTotal) * 100) : 0 }}%;--leave:{{ $attendanceTotal ? (($attendance['counts']['leave'] / $attendanceTotal) * 100) : 0 }}%;"><span>{{ format_number($attendanceTotal) }}</span><small>مجدول اليوم</small></div><div class="attendance-breakdown"><div><i class="dot green"></i> حاضر <b>{{ format_number($attendance['counts']['on_time']) }}</b></div><div><i class="dot orange"></i> متأخر <b>{{ format_number($attendance['counts']['late']) }}</b></div><div><i class="dot red"></i> غائب <b>{{ format_number($attendance['counts']['absent']) }}</b></div><div><i class="dot purple"></i> إجازة <b>{{ format_number($attendance['counts']['leave']) }}</b></div><div><i class="dot gray"></i> لم يسجل بعد <b>{{ format_number($attendance['counts']['not_recorded']) }}</b></div></div></div>
      </section>
    @endif
    <section class="panel reference-panel recent-leaves"><div class="panel-head"><div><h3><i class="bi bi-calendar2-check"></i> آخر الإجازات المعتمدة</h3><span>المعتمدة والمرفوضة مع توضيح الحالة.</span></div><a class="btn btn-light btn-sm" href="{{ route('leaves.index') }}">عرض الكل</a></div><div class="table-responsive"><table class="table"><thead><tr><th>الموظف</th><th>نوع الإجازة</th><th>من</th><th>إلى</th><th>الحالة</th></tr></thead><tbody>@forelse($recentLeaves as $leave)<tr><td><span class="table-row-icon {{ $leave->status === 'Rejected' ? 'danger' : 'success' }}"><i class="bi bi-calendar-event"></i></span>{{ $leave->employee?->full_name }}</td><td>{{ $leave->leaveType?->name }}</td><td>{{ $leave->start_date?->format('d/m/Y') }}</td><td>{{ $leave->end_date?->format('d/m/Y') }}</td><td><span class="badge-soft {{ $leave->status === 'Rejected' ? 'danger' : 'success' }}">{{ $leave->status === 'Rejected' ? 'مرفوضة' : 'معتمدة' }}</span></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">لا توجد إجازات معتمدة أو مرفوضة حتى الآن.</td></tr>@endforelse</tbody></table></div></section>
  </div>

  <div class="dashboard-reference-column">
    <section class="panel reference-panel dates-panel"><div class="panel-head"><div><h3><i class="bi bi-calendar-week"></i> المستندات والعقود التي تنتهي قريبًا</h3><span>كل المستندات والعقود التي تنتهي خلال 30 يومًا أو أقل.</span></div><a class="btn btn-light btn-sm" href="{{ route('documents.index') }}" data-dashboard-detail="documents-expiring" data-detail-url="{{ route('dashboard.details','documents-expiring') }}" data-detail-icon="calendar-week">عرض الكل</a></div><div class="reference-list">@forelse($expiringDocuments as $item) @php($days = max(0, today()->diffInDays($item['expiry_date'], false)))<a href="{{ route('employees.show', [$item['employee'], 'tab' => $item['kind'] === 'contract' ? 'job' : 'documentsProfile']) }}"><span class="list-square {{ $days <= 7 ? 'red' : 'orange' }}"><i class="bi {{ $item['kind'] === 'contract' ? 'bi-file-earmark-ruled-fill' : 'bi-file-earmark-text-fill' }}"></i></span><span><b>{{ $item['employee']?->full_name }} · {{ $item['label'] }}</b><small>ينتهي {{ $item['expiry_date']?->format('d/m/Y') }} · {{ format_number($days) }} يومًا</small></span><time>{{ $item['expiry_date']?->format('d/m') }}</time></a>@empty<div class="text-center text-muted small py-4">لا توجد مستندات أو عقود تنتهي خلال 30 يومًا.</div>@endforelse</div></section>
    @if($payroll)<section class="panel reference-panel payroll-panel"><div class="panel-head"><div><h3><i class="bi bi-cash-stack"></i> ملخص الرواتب</h3><span>البيانات حسب الشهر والسنة المحددين.</span></div><a class="btn btn-light btn-sm" href="{{ route('payroll.index',['month'=>$month->format('Y-m-01')]) }}">فتح المسير</a></div><form method="GET" class="dashboard-payroll-filter"><select name="payroll_month_number" aria-label="شهر الرواتب">@for($m=1;$m<=12;$m++)<option value="{{ $m }}" @selected($month->month === $m)>{{ $m }}</option>@endfor</select><select name="payroll_year" aria-label="سنة الرواتب">@foreach($payrollYears as $year)<option value="{{ $year }}" @selected($month->year == $year)>{{ $year }}</option>@endforeach</select><button class="btn btn-sm btn-outline-primary">تحديث</button></form><div class="payroll-dashboard-body"><div><span><i class="bi bi-wallet2"></i> إجمالي التكلفة</span><b>{{ $payroll['run'] ? format_money($payroll['cost']).' ج.م' : '—' }}</b></div><div><span><i class="bi bi-cash-stack"></i> صافي الرواتب</span><b>{{ $payroll['run'] ? format_money($payroll['net']).' ج.م' : '—' }}</b></div><div><span><i class="bi bi-shield-check"></i> إجمالي التأمينات</span><b>{{ $payroll['run'] ? format_money($payroll['insurance']).' ج.م' : '—' }}</b></div><div><span><i class="bi bi-receipt"></i> إجمالي الضرائب</span><b>{{ $payroll['run'] ? format_money($payroll['tax']).' ج.م' : '—' }}</b></div></div></section>@endif
  </div>

  <div class="dashboard-reference-column">
    <section id="dashboard-latest-requests" data-dashboard-card="latest-requests" class="panel reference-panel requests-panel unified-requests-card">
      <div class="panel-head"><div><h3><i class="bi bi-file-earmark-text"></i> آخر الطلبات</h3><span>أحدث الطلبات الفعلية من المصادر المتاحة.</span></div><a class="btn btn-light btn-sm" href="{{ $requestsIndexUrl }}">عرض الكل</a></div>
      <div class="reference-list">
        @forelse($pendingRequests as $requestItem)
          <div class="unified-request-row dashboard-request-row">
            <a href="{{ $requestItem['details_url'] }}" class="unified-request-content">
              <span class="unified-request-icon request-type-icon" style="color:{{ $requestItem['icon_color'] }};background:{{ $requestItem['icon_background'] }}"><i class="bi {{ $requestItem['icon'] }}"></i></span>
              <span class="unified-request-copy"><b>{{ $requestItem['title'] }}</b><small>{{ $requestItem['subtitle'] }}</small></span>
              <time class="unified-request-time" datetime="{{ $requestItem['created_at']?->toDateTimeString() }}">{{ $requestItem['created_at']?->format('d/m H:i') }}</time>
            </a>
            @if($requestItem['can_approve'])
              <div class="dashboard-request-actions">
                <form method="POST" action="{{ $requestItem['approve_url'] }}">@csrf @method('PATCH')<button class="btn btn-sm btn-success" title="قبول"><i class="bi bi-check-lg"></i></button></form>
                <form method="POST" action="{{ $requestItem['reject_url'] }}">@csrf @method('PATCH')<input type="hidden" name="rejection_reason" value="تم الرفض من لوحة التحكم"><button class="btn btn-sm btn-outline-danger" title="رفض"><i class="bi bi-x-lg"></i></button></form>
              </div>
            @endif
          </div>
        @empty
          <div class="text-center text-muted small py-4">لا توجد طلبات حديثة</div>
        @endforelse
      </div>
    </section>
    @if($documents)<section class="panel reference-panel document-panel"><div class="panel-head"><div><h3><i class="bi bi-folder2-open"></i> اكتمال ملفات الموظفين</h3><span>يشترط استكمال البيانات الأساسية وأنواع المستندات المطلوبة.</span></div><a class="btn btn-light btn-sm" href="{{ route('master.index',['type'=>'documents']) }}">إدارة الأنواع</a></div><div class="document-stats"><a href="{{ route('employees.index') }}"><b>{{ format_number($documents['complete']) }}</b><span>ملفات مكتملة</span></a><a href="{{ route('employees.index') }}" class="warning" data-dashboard-detail="documents-incomplete" data-detail-url="{{ route('dashboard.details','documents-incomplete') }}" data-detail-icon="clipboard2-x-fill"><b>{{ format_number($documents['incomplete']) }}</b><span>تحتاج استكمال</span></a><a href="{{ route('documents.index') }}" class="danger" data-dashboard-detail="documents-expiring" data-detail-url="{{ route('dashboard.details','documents-expiring') }}" data-detail-icon="calendar-week"><b>{{ format_number($expiringDocuments->count()) }}</b><span>مستندات وعقود تنتهي قريبًا</span></a><a href="{{ route('documents.index') }}"><b>{{ format_number($documents['required']) }}</b><span>أنواع مستندات مطلوبة</span></a></div></section>@endif
    <section class="panel reference-panel new-employees"><div class="panel-head"><div><h3><i class="bi bi-people-fill"></i> الموظفون الجدد</h3><span>حسب تاريخ التعيين الفعلي.</span></div><a class="btn btn-light btn-sm" href="{{ route('employees.index') }}">عرض الكل</a></div><div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>الموظف</th><th>المسمى الوظيفي</th><th>التعيين</th></tr></thead><tbody>@forelse($recentEmployees as $employee)<tr><td>{{ $employee->employee_code }}</td><td><span class="table-row-icon info"><i class="bi bi-person-fill"></i></span><a href="{{ route('employees.show',$employee) }}">{{ $employee->full_name }}</a></td><td>{{ $employee->job?->title ?? '—' }}</td><td><span class="date-badge"><i class="bi bi-calendar3"></i>{{ $employee->hire_date?->format('d/m/Y') }}</span></td></tr>@empty<tr><td colspan="4" class="text-center text-muted py-4">لا توجد بيانات.</td></tr>@endforelse</tbody></table></div></section>
  </div>
</div>

@if($attentionItems)<section class="panel reference-panel mt-3"><div class="panel-head"><div><h3><i class="bi bi-exclamation-diamond-fill text-warning"></i> يحتاج إلى متابعة</h3><span>تظهر عند وجود حالة حقيقية فقط.</span></div></div><div class="attention-grid p-3">@foreach($attentionItems as $item)<a href="{{ $item['url'] }}" class="attention-card {{ $item['tone'] }}" data-dashboard-detail="{{ $item['key'] }}" data-detail-url="{{ route('dashboard.details', $item['key']) }}" data-detail-icon="{{ $item['icon'] }}"><span class="attention-icon"><i class="bi bi-{{ $item['icon'] }}"></i></span><span class="attention-content"><b>{{ $item['title'] }}</b><small>{{ $item['description'] }}</small></span><strong>{{ format_number($item['count']) }}</strong></a>@endforeach</div></section>@endif

<div class="modal fade" id="dashboardDetailModal" tabindex="-1" aria-labelledby="dashboardDetailTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="dashboardDetailTitle"><span class="dashboard-modal-title-icon"><i class="bi bi-grid-1x2-fill"></i></span><span>تفاصيل المؤشر</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body" id="dashboardDetailBody"><div class="text-center p-5"><span class="spinner-border"></span></div></div></div></div></div>
<div class="modal fade" id="documentUploadModal" tabindex="-1" aria-labelledby="documentUploadTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 id="documentUploadTitle">اختيار الموظف لرفع مستند</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="text-muted small">ابحث عن الموظف ثم افتح تبويب المستندات من ملفه.</p><input class="form-control mb-3" id="documentEmployeeSearch" placeholder="الاسم أو كود الموظف"><div id="documentEmployeeResults" class="list-group"></div></div></div></div></div>
<div class="modal fade" id="dashboardExistingActionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body p-5 text-center"><span class="spinner-border text-primary" role="status"></span><p class="small text-muted mt-3 mb-0">جاري تحميل النموذج...</p></div></div></div></div>

{{-- النماذج المخصصة السابقة معطلة: لوحة التحكم تستخرج النموذج الأصلي من صفحته. --}}
{{--
@if(false && auth()->user()?->hasPermission('employees.create'))
<div class="modal fade" id="dashboardEmployeeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('employees.store') }}">@csrf<div class="modal-header"><h5>إضافة موظف جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">الاسم الكامل</label><input name="full_name" class="form-control" required autofocus></div><div class="row g-2"><div class="col-md-6"><label class="form-label">كود الموظف <small class="text-muted">اختياري</small></label><input name="employee_code" class="form-control" placeholder="يُنشأ تلقائيًا عند تركه فارغًا"></div><div class="col-md-6"><label class="form-label">تاريخ التعيين</label><input name="hire_date" type="date" class="form-control" value="{{ today()->toDateString() }}"></div><div class="col-md-6"><label class="form-label">الراتب الإجمالي</label><input name="gross_salary" type="number" min="0.01" step="0.01" class="form-control" required></div><div class="col-md-6"><label class="form-label">الرقم القومي</label><input name="national_id" class="form-control" inputmode="numeric"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ الموظف</button></div></form></div></div></div>
@endif
@if(false && auth()->user()?->hasPermission('leaves.create'))
<div class="modal fade" id="dashboardLeaveModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('leaves.store') }}">@csrf<div class="modal-header"><h5>طلب إجازة جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($dashboardActionEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><label class="form-label">نوع الإجازة</label><select name="leave_type_id" class="form-select mb-3" required>@foreach($dashboardLeaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'بأجر' : 'بدون أجر' }})</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">من</label><input name="start_date" type="date" class="form-control" required></div><div class="col-6"><label class="form-label">إلى</label><input name="end_date" type="date" class="form-control" required></div></div><label class="form-label mt-3">السبب</label><textarea name="reason" class="form-control" rows="3"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ الطلب</button></div></form></div></div></div>
@endif
@if(false && auth()->user()?->hasPermission('attendance.create'))
<div class="modal fade" id="dashboardAttendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('attendance.store') }}">@csrf<div class="modal-header"><h5>تسجيل حضور</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($dashboardActionEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input name="attendance_date" type="date" class="form-control" value="{{ today()->toDateString() }}" required></div><div class="col-6"><label class="form-label">الحالة</label><select name="status" class="form-select"><option value="Present">حاضر</option><option value="Late">متأخر</option><option value="Absent">غائب</option><option value="Leave">إجازة</option></select></div><div class="col-6"><label class="form-label">وقت الحضور</label><input name="check_in" type="time" class="form-control" required></div><div class="col-6"><label class="form-label">وقت الانصراف</label><input name="check_out" type="time" class="form-control" required></div></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ كمسودة</button></div></form></div></div></div>
@endif
@if(false && auth()->user()?->hasPermission('penalties.create'))
<div class="modal fade" id="dashboardPenaltyModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('discipline.store') }}">@csrf<div class="modal-header"><h5>تسجيل جزاء أو مخالفة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($dashboardActionEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><label class="form-label">نوع الإجراء</label><select name="penalty_type_id" class="form-select mb-3" required>@foreach($dashboardPenaltyTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select><div class="row g-2"><div class="col-6"><label class="form-label">التاريخ</label><input name="penalty_date" type="date" value="{{ today()->toDateString() }}" class="form-control" required></div><div class="col-6"><label class="form-label">أيام الخصم</label><input name="deduction_days" type="number" min="0" step="0.5" class="form-control"></div></div><label class="form-label mt-3">سبب المخالفة</label><textarea name="reason" class="form-control" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ للمراجعة</button></div></form></div></div></div>
@endif
@if(false && auth()->user()?->hasPermission('requests.create'))
<div class="modal fade" id="dashboardRequestModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('requests.store') }}">@csrf<div class="modal-header"><h5>طلب موارد بشرية جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الموظف</label><select name="employee_id" class="form-select mb-3" required>@foreach($dashboardActionEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->full_name }} — #{{ $employee->employee_code }}</option>@endforeach</select><label class="form-label">نوع الطلب</label><select name="request_type_id" class="form-select mb-3" required><option value="">اختر النوع</option>@foreach($dashboardRequestTypes as $type)<option value="{{ $type->id }}">{{ $type->display_name }}</option>@endforeach</select><label class="form-label">عنوان الطلب</label><input name="title" class="form-control mb-3" required><label class="form-label">التفاصيل</label><textarea name="details" class="form-control" rows="3"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">إرسال للمراجعة</button></div></form></div></div></div>
@endif
@if(false && auth()->user()?->hasPermission('payroll.create'))
<div class="modal fade" id="dashboardPayrollModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" action="{{ route('payroll.generate') }}">@csrf<div class="modal-header"><h5>إصدار مسير رواتب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="text-muted small">سيتم إنشاء أو تحديث مسودة المسير للشهر المحدد وفق هياكل الرواتب السارية.</p><label class="form-label">شهر المسير</label><input name="month" type="month" class="form-control" value="{{ $month->format('Y-m') }}" required></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">إصدار المسير</button></div></form></div></div></div>
@endif
--}}
@endsection

@push('scripts')
@php($dashboardDetailUrls = [
  'employees' => route('dashboard.details', 'employees'),
  'new-employees' => route('dashboard.details', 'new-employees'),
  'attendance' => route('dashboard.details', 'attendance'),
  'present' => route('dashboard.details', 'present'),
  'absent' => route('dashboard.details', 'absent'),
  'late' => route('dashboard.details', 'late'),
  'payroll-cost' => route('dashboard.details', 'payroll-cost'),
  'payroll-net' => route('dashboard.details', 'payroll-net'),
  'payroll-summary' => route('dashboard.details', 'payroll-summary'),
  'documents-expiring' => route('dashboard.details', 'documents-expiring'),
  'documents-incomplete' => route('dashboard.details', 'documents-incomplete'),
  'requests' => route('dashboard.details', 'requests'),
  'leaves' => route('dashboard.details', 'leaves'),
])
<script>
  window.dashboardDetailUrls = @json($dashboardDetailUrls);
</script>
<script src="{{ asset('dashboard-details.js') }}?v={{ @filemtime(public_path('dashboard-details.js')) }}-3"></script>
<script>
document.querySelectorAll('.dashboard-existing-action').forEach((button) => {
  button.addEventListener('click', async () => {
    const modalEl = document.getElementById('dashboardExistingActionModal');
    const body = modalEl?.querySelector('.modal-content');
    if (!modalEl || !body) return;
    body.innerHTML = '<div class="modal-body p-5 text-center"><span class="spinner-border text-primary"></span><p class="small text-muted mt-3 mb-0">جاري تحميل النموذج...</p></div>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    try {
      const response = await fetch(button.dataset.dashboardPage, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const source = new DOMParser().parseFromString(await response.text(), 'text/html');
      const sourceModal = button.dataset.dashboardSourceModal ? source.getElementById(button.dataset.dashboardSourceModal) : source.querySelector(button.dataset.dashboardSourceSelector);
      const content = sourceModal?.querySelector('.modal-content') || sourceModal;
      if (!content) throw new Error('لم يتم العثور على نموذج الإدخال الأصلي');
      body.innerHTML = content.innerHTML;
      body.querySelectorAll('.btn-close').forEach((close) => close.addEventListener('click', () => bootstrap.Modal.getOrCreateInstance(modalEl).hide()));
      // نموذج الموظف يحتوي حساب Gross/Net واستخراج الرقم القومي في سكربت الصفحة الأصلية.
      // نشغّله هنا فقط بعد تركيب النموذج الأصلي داخل النافذة.
      if (button.dataset.dashboardSourceModal === 'addEmployeeModal') {
        source.querySelectorAll('script').forEach((script) => {
          if (!script.textContent.includes('new_employee_')) return;
          const runtimeScript = document.createElement('script');
          runtimeScript.textContent = script.textContent;
          document.body.appendChild(runtimeScript);
          runtimeScript.remove();
        });
      }
      body.querySelector('input,select,textarea')?.focus();
    } catch (error) {
      body.innerHTML = '<div class="modal-body p-4"><div class="alert alert-danger mb-0">تعذر تحميل نموذج الإدخال الأصلي. افتح الصفحة المرتبطة لإعادة المحاولة.</div></div>';
      console.error('Dashboard action form load failed', error);
    }
  });
});
</script>
@endpush
