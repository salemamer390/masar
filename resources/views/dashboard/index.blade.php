@extends('layouts.app')
@section('title', 'لوحة التحكم')
@push('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" referrerpolicy="no-referrer">
<link href="{{ asset('dashboard-reference.css') }}" rel="stylesheet">
@endpush

@section('content')
<div class="dashboard-command-bar dashboard-command-bar--reference" aria-label="إجراءات سريعة">
  @foreach($quickActions as $action)
    @if(!empty($action['modal']))
      <button type="button" data-dashboard-action="{{ $action['modal'] }}" title="رفع مستند لموظف محدد"><i class="fa-solid fa-cloud-arrow-up"></i><span>{{ $action['label'] }}</span></button>
    @else
      @php($actionIcons = ['موظف جديد'=>'fa-user-plus','تسجيل إجازة'=>'fa-calendar-check','تسجيل حضور'=>'fa-clock','تسجيل جزاء'=>'fa-gavel','إصدار مسير الرواتب'=>'fa-circle-dollar-to-slot','تصدير تقرير'=>'fa-chart-simple'])
      <a href="{{ $action['url'] }}"><i class="fa-solid {{ $actionIcons[$action['label']] ?? 'fa-arrow-up-right-from-square' }}"></i><span>{{ $action['label'] }}</span></a>
    @endif
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
              <span><b>{{ $requestItem['title'] }}</b><small>{{ $requestItem['subtitle'] }}</small></span>
              <time class="unified-request-time">{{ $requestItem['created_at']?->format('H:i') }}</time>
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
@endpush
