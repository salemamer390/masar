@extends('layouts.app')
@section('title','تسوية ترك خدمة')
@section('content')
@php($pending = $settlement->pendingDecisions())
<div class="page-head"><div><h1>تسوية ترك خدمة — {{ $settlement->employee?->full_name }}</h1><p>{{ $settlement->reason_label }} · آخر يوم عمل {{ $settlement->last_working_day?->format('d/m/Y') }} · فترة الرواتب {{ $settlement->period_start?->format('d/m/Y') }} — {{ $settlement->period_end?->format('d/m/Y') }}</p></div><span class="badge-soft {{ $settlement->status_badge_class }}" style="align-self:center">{{ $settlement->status_label }}</span></div>

@if($settlement->status === 'Draft' && $pending)
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> لا يمكن اعتماد التسوية قبل تحديد:
  @if(in_array('leave_payout', $pending)) طريقة صرف رصيد الإجازات @endif
  @if(in_array('advance_recovery', $pending)) طريقة استرداد السلف القائمة @endif
  . التسوية لا تفترض أي قيمة من تلقاء نفسها.
</div>
@endif

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">رصيد الإجازات الملتقط</div><div class="fs-4 fw-bold">{{ format_number($settlement->leave_balance_days) }} يوم</div><div class="small text-muted">رصيد مرشح للصرف حسب قاعدة الشركة، وليس استحقاقًا تلقائيًا.</div></div></div>
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">السلف القائمة</div><div class="fs-4 fw-bold">{{ format_number($settlement->advance_outstanding) }} ج.م</div><div class="small text-muted">المتبقي بعد خصم أقساط المسير والتسويات السابقة.</div></div></div>
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">المطابقة مع الرواتب</div><div class="fs-6 fw-bold mt-1">@if($settlement->reconciliation === 'draft_run') داخل مسير الرواتب المفتوح @if($settlement->payroll_run_id)<a href="{{ route('payroll.index', ['month' => $settlement->anchor_month?->format('Y-m')]) }}" class="d-block small">فتح دورة الرواتب</a>@endif @elseif($settlement->reconciliation === 'standalone') صرف مستقل (لا يوجد مسير مفتوح للفترة) @else تُحدد عند الاعتماد @endif</div><div class="small text-muted">المسير المعتمد أو المقفل لا يُمسّ إطلاقًا.</div></div></div>
</div>

@if($settlement->status === 'Draft')
<div class="panel p-4 mb-3">
  <h5 class="mb-3">قرارات المراجعة</h5>
  <form method="POST" action="{{ route('settlements.update', $settlement) }}">@csrf @method('PUT')
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-bold">صرف رصيد الإجازات ({{ format_number($settlement->leave_balance_days) }} يوم)</label>
        <select name="leave_payout_mode" class="form-select" @disabled($settlement->leave_balance_days <= 0)>
          <option value="pending" @selected($settlement->leave_payout_mode === 'pending')>لم يُحدد — يمنع الاعتماد</option>
          <option value="skip" @selected($settlement->leave_payout_mode === 'skip')>بدون صرف</option>
          <option value="basic" @selected($settlement->leave_payout_mode === 'basic')>صرف بالأساسي ({{ format_number($leavePreview['basic']) }} ج.م)</option>
          <option value="gross" @selected($settlement->leave_payout_mode === 'gross')>صرف بالإجمالي ({{ format_number($leavePreview['gross']) }} ج.م)</option>
        </select>
        @if($settlement->leave_balance_days <= 0)<div class="small text-muted mt-1">لا يوجد رصيد إجازات للصرف.</div>@endif
      </div>
      <div class="col-md-6">
        <label class="form-label fw-bold">استرداد السلف القائمة ({{ format_number($settlement->advance_outstanding) }} ج.م)</label>
        <select name="advance_recovery_mode" class="form-select" @disabled($settlement->advance_outstanding <= 0)>
          <option value="pending" @selected($settlement->advance_recovery_mode === 'pending')>لم يُحدد — يمنع الاعتماد</option>
          <option value="scheduled" @selected($settlement->advance_recovery_mode === 'scheduled')>إبقاء الجدولة الحالية (أقساط المسير فقط)</option>
          <option value="full" @selected($settlement->advance_recovery_mode === 'full')>استرداد كامل المتبقي من التسوية</option>
        </select>
        @if($settlement->advance_outstanding <= 0)<div class="small text-muted mt-1">لا توجد سلف قائمة.</div>@endif
      </div>
      <div class="col-md-4"><label class="form-label">مكافأة نهاية الخدمة (يحددها المراجع)</label><input type="number" step="0.01" min="0" name="gratuity_amount" class="form-control" value="{{ old('gratuity_amount', $settlement->gratuity_amount) }}"></div>
      <div class="col-md-4"><label class="form-label">مستحقات إضافية</label><input type="number" step="0.01" min="0" name="manual_earnings" class="form-control" value="{{ old('manual_earnings', $settlement->manual_earnings) }}"></div>
      <div class="col-md-4"><label class="form-label">خصومات إضافية</label><input type="number" step="0.01" min="0" name="manual_deductions" class="form-control" value="{{ old('manual_deductions', $settlement->manual_deductions) }}"></div>
      <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes', $settlement->notes) }}</textarea></div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-primary">حفظ القرارات</button>
    </div>
  </form>
</div>
@else
<div class="panel p-4 mb-3">
  <h5 class="mb-3">بنود التسوية المعتمدة</h5>
  <table class="table"><tbody>
    <tr><td>صرف رصيد الإجازات</td><td>{{ $settlement->leave_payout_mode === 'skip' ? 'بدون صرف' : format_number($settlement->leave_payout_amount).' ج.م ('.($settlement->leave_payout_mode === 'basic' ? 'بالأساسي' : 'بالإجمالي').')' }}</td></tr>
    <tr><td>استرداد السلف</td><td>{{ $settlement->advance_recovery_mode === 'scheduled' ? 'إبقاء الجدولة الحالية' : format_number($settlement->advance_recovery_amount).' ج.م (استرداد كامل)' }}</td></tr>
    <tr><td>مكافأة نهاية الخدمة</td><td>{{ format_number($settlement->gratuity_amount) }} ج.م</td></tr>
    <tr><td>مستحقات إضافية</td><td>{{ format_number($settlement->manual_earnings) }} ج.م</td></tr>
    <tr><td>خصومات إضافية</td><td>{{ format_number($settlement->manual_deductions) }} ج.م</td></tr>
    <tr class="fw-bold"><td>إجمالي المستحقات</td><td>{{ format_number($settlement->total_earnings) }} ج.م</td></tr>
    <tr class="fw-bold"><td>إجمالي الخصومات</td><td>{{ format_number($settlement->total_deductions) }} ج.م</td></tr>
    <tr class="fw-bold"><td>صافي التسوية</td><td>{{ format_number($settlement->net_amount) }} ج.م</td></tr>
  </tbody></table>
  <div class="small text-muted">
    اعتمدها {{ $settlement->approver?->name ?? '—' }} في {{ $settlement->approved_at?->format('d/m/Y H:i') }}.
    @if($settlement->status === 'Paid') صرفها {{ $settlement->payer?->name ?? '—' }} في {{ $settlement->paid_at?->format('d/m/Y H:i') }}. التسوية مصروفة ومقفولة نهائيًا. @endif
  </div>
  @if($settlement->notes)<div class="mt-2">ملاحظات: {{ $settlement->notes }}</div>@endif
</div>
@endif

<div class="d-flex gap-2">
  @if($settlement->status === 'Draft' && auth()->user()?->hasPermission('settlements.approve'))
  <form method="POST" action="{{ route('settlements.approve', $settlement) }}" onsubmit="return confirm('اعتماد التسوية سينهي خدمة الموظف ويطابق المسير. متابعة؟')">@csrf<button class="btn btn-success" @disabled($pending !== [])><i class="bi bi-check-lg"></i> اعتماد التسوية</button></form>
  @endif
  @if($settlement->status === 'Approved' && auth()->user()?->hasPermission('settlements.approve'))
  <form method="POST" action="{{ route('settlements.pay', $settlement) }}" onsubmit="return confirm('الصرف نهائي ولا يمكن التراجع عنه. متابعة؟')">@csrf<button class="btn btn-success"><i class="bi bi-cash"></i> صرف التسوية وقفلها</button></form>
  @endif
  @if($settlement->status === 'Draft' && auth()->user()?->hasPermission('settlements.create'))
  <form method="POST" action="{{ route('settlements.cancel', $settlement) }}" onsubmit="return confirm('إلغاء مسودة التسوية؟')">@csrf<button class="btn btn-outline-danger">إلغاء المسودة</button></form>
  @endif
  <a class="btn btn-light" href="{{ route('settlements.index') }}">عودة للقائمة</a>
</div>
@endsection
