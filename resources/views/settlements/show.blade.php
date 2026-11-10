@extends('layouts.app')
@section('title',__('تسوية ترك خدمة'))
@section('content')
@php($pending = $settlement->pendingDecisions())
<div class="page-head"><div><h1>{{ __('تسوية ترك خدمة —') }} {{ $settlement->employee?->full_name }}</h1><p>{{ $settlement->reason_label }} {{ __('· آخر يوم عمل') }} {{ $settlement->last_working_day?->format('d/m/Y') }} {{ __('· فترة الرواتب') }} {{ $settlement->period_start?->format('d/m/Y') }} — {{ $settlement->period_end?->format('d/m/Y') }}</p></div><span class="badge-soft {{ $settlement->status_badge_class }}" style="align-self:center">{{ $settlement->status_label }}</span></div>

@if($settlement->status === 'Draft' && $pending)
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> {{ __('لا يمكن اعتماد التسوية قبل تحديد:') }}
  @if(in_array('leave_payout', $pending)) {{ __('طريقة صرف رصيد الإجازات') }} @endif
  @if(in_array('advance_recovery', $pending)) {{ __('طريقة استرداد السلف القائمة') }} @endif
  {{ __('. التسوية لا تفترض أي قيمة من تلقاء نفسها.') }}
</div>
@endif

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">{{ __('رصيد الإجازات الملتقط') }}</div><div class="fs-4 fw-bold">{{ format_number($settlement->leave_balance_days) }} {{ __('يوم') }}</div><div class="small text-muted">{{ __('رصيد مرشح للصرف حسب قاعدة الشركة، وليس استحقاقًا تلقائيًا.') }}</div></div></div>
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">{{ __('السلف القائمة') }}</div><div class="fs-4 fw-bold">{{ format_number($settlement->advance_outstanding) }} {{ __('ج.م') }}</div><div class="small text-muted">{{ __('المتبقي بعد خصم أقساط المسير والتسويات السابقة.') }}</div></div></div>
  <div class="col-md-4"><div class="panel p-3 h-100"><div class="text-muted small mb-1">{{ __('المطابقة مع الرواتب') }}</div><div class="fs-6 fw-bold mt-1">@if($settlement->reconciliation === 'draft_run') {{ __('داخل مسير الرواتب المفتوح') }} @if($settlement->payroll_run_id)<a href="{{ route('payroll.index', ['month' => $settlement->anchor_month?->format('Y-m')]) }}" class="d-block small">{{ __('فتح دورة الرواتب') }}</a>@endif @elseif($settlement->reconciliation === 'standalone') {{ __('صرف مستقل (لا يوجد مسير مفتوح للفترة)') }} @else {{ __('تُحدد عند الاعتماد') }} @endif</div><div class="small text-muted">{{ __('المسير المعتمد أو المقفل لا يُمسّ إطلاقًا.') }}</div></div></div>
</div>

@if($settlement->status === 'Draft')
<div class="panel p-4 mb-3">
  <h5 class="mb-3">{{ __('قرارات المراجعة') }}</h5>
  <form method="POST" action="{{ route('settlements.update', $settlement) }}">@csrf @method('PUT')
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-bold">{{ __('صرف رصيد الإجازات (') }}{{ format_number($settlement->leave_balance_days) }} {{ __('يوم)') }}</label>
        <select name="leave_payout_mode" class="form-select" @disabled($settlement->leave_balance_days <= 0)>
          <option value="pending" @selected($settlement->leave_payout_mode === 'pending')>{{ __('لم يُحدد — يمنع الاعتماد') }}</option>
          <option value="skip" @selected($settlement->leave_payout_mode === 'skip')>{{ __('بدون صرف') }}</option>
          <option value="pay" @selected($settlement->leave_payout_mode === 'pay')>{{ __('صرف بمبلغ يحدده المراجع') }}</option>
        </select>
        <input type="number" step="0.01" min="0" name="leave_payout_amount" class="form-control mt-2" placeholder="{{ __('مبلغ الصرف (ج.م)') }}" value="{{ old('leave_payout_amount', $settlement->leave_payout_amount) }}" @disabled($settlement->leave_balance_days <= 0)>
        <div class="small text-muted mt-1">{{ __('أرقام استرشادية فقط: بالأساسي ≈') }} {{ format_number($leavePreview['basic']) }} {{ __('ج.م، بالإجمالي ≈') }} {{ format_number($leavePreview['gross']) }} {{ __('ج.م — قاعدة الصرف وقيمتها النهائية يحددها المراجع.') }}</div>
        @if($settlement->leave_balance_days <= 0)<div class="small text-muted mt-1">{{ __('لا يوجد رصيد إجازات للصرف.') }}</div>@endif
      </div>
      <div class="col-md-6">
        <label class="form-label fw-bold">{{ __('استرداد السلف القائمة (') }}{{ format_number($settlement->advance_outstanding) }} {{ __('ج.م)') }}</label>
        <select name="advance_recovery_mode" class="form-select" @disabled($settlement->advance_outstanding <= 0)>
          <option value="pending" @selected($settlement->advance_recovery_mode === 'pending')>{{ __('لم يُحدد — يمنع الاعتماد') }}</option>
          <option value="scheduled" @selected($settlement->advance_recovery_mode === 'scheduled')>{{ __('إبقاء الجدولة الحالية (أقساط المسير فقط)') }}</option>
          <option value="full" @selected($settlement->advance_recovery_mode === 'full')>{{ __('استرداد كامل المتبقي من التسوية') }}</option>
        </select>
        @if($settlement->advance_outstanding <= 0)<div class="small text-muted mt-1">{{ __('لا توجد سلف قائمة.') }}</div>@endif
      </div>
      <div class="col-md-4"><label class="form-label">{{ __('مكافأة نهاية الخدمة (يحددها المراجع)') }}</label><input type="number" step="0.01" min="0" name="gratuity_amount" class="form-control" value="{{ old('gratuity_amount', $settlement->gratuity_amount) }}"></div>
      <div class="col-md-4"><label class="form-label">{{ __('مستحقات إضافية') }}</label><input type="number" step="0.01" min="0" name="manual_earnings" class="form-control" value="{{ old('manual_earnings', $settlement->manual_earnings) }}"></div>
      <div class="col-md-4"><label class="form-label">{{ __('خصومات إضافية') }}</label><input type="number" step="0.01" min="0" name="manual_deductions" class="form-control" value="{{ old('manual_deductions', $settlement->manual_deductions) }}"></div>
      <div class="col-12"><label class="form-label">{{ __('ملاحظات') }}</label><textarea name="notes" class="form-control" rows="2">{{ old('notes', $settlement->notes) }}</textarea></div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-primary">{{ __('حفظ القرارات') }}</button>
    </div>
  </form>
</div>
@else
<div class="panel p-4 mb-3">
  <h5 class="mb-3">{{ __('بنود التسوية المعتمدة') }}</h5>
  <table class="table"><tbody>
    <tr><td>{{ __('صرف رصيد الإجازات') }}</td><td>{{ $settlement->leave_payout_mode === 'skip' ? 'بدون صرف' : format_number($settlement->leave_payout_amount).' ج.م (بتحديد المراجع)' }}</td></tr>
    <tr><td>{{ __('استرداد السلف') }}</td><td>{{ $settlement->advance_recovery_mode === 'scheduled' ? 'إبقاء الجدولة الحالية' : format_number($settlement->advance_recovery_amount).' ج.م (استرداد كامل)' }}</td></tr>
    <tr><td>{{ __('مكافأة نهاية الخدمة') }}</td><td>{{ format_number($settlement->gratuity_amount) }} {{ __('ج.م') }}</td></tr>
    <tr><td>{{ __('مستحقات إضافية') }}</td><td>{{ format_number($settlement->manual_earnings) }} {{ __('ج.م') }}</td></tr>
    <tr><td>{{ __('خصومات إضافية') }}</td><td>{{ format_number($settlement->manual_deductions) }} {{ __('ج.م') }}</td></tr>
    <tr class="fw-bold"><td>{{ __('إجمالي المستحقات') }}</td><td>{{ format_number($settlement->total_earnings) }} {{ __('ج.م') }}</td></tr>
    <tr class="fw-bold"><td>{{ __('إجمالي الخصومات') }}</td><td>{{ format_number($settlement->total_deductions) }} {{ __('ج.م') }}</td></tr>
    <tr class="fw-bold"><td>{{ __('صافي التسوية') }}</td><td>{{ format_number($settlement->net_amount) }} {{ __('ج.م') }}</td></tr>
  </tbody></table>
  <div class="small text-muted">
    {{ __('اعتمدها') }} {{ $settlement->approver?->name ?? '—' }} {{ __('في') }} {{ $settlement->approved_at?->format('d/m/Y H:i') }}.
    @if($settlement->status === 'Paid') {{ __('صرفها') }} {{ $settlement->payer?->name ?? '—' }} {{ __('في') }} {{ $settlement->paid_at?->format('d/m/Y H:i') }}{{ __('. التسوية مصروفة ومقفولة نهائيًا.') }} @endif
  </div>
  @if($settlement->notes)<div class="mt-2">{{ __('ملاحظات:') }} {{ $settlement->notes }}</div>@endif
</div>
@endif

<div class="d-flex gap-2">
  @if($settlement->status === 'Draft' && auth()->user()?->hasPermission('settlements.approve'))
  <form method="POST" action="{{ route('settlements.approve', $settlement) }}" onsubmit="return confirm('اعتماد التسوية سينهي خدمة الموظف ويطابق المسير. متابعة؟')">@csrf<button class="btn btn-success" @disabled($pending !== [])><i class="bi bi-check-lg"></i> {{ __('اعتماد التسوية') }}</button></form>
  @endif
  @if($settlement->status === 'Approved' && auth()->user()?->hasPermission('settlements.approve'))
  <form method="POST" action="{{ route('settlements.pay', $settlement) }}" onsubmit="return confirm('الصرف نهائي ولا يمكن التراجع عنه. متابعة؟')">@csrf<button class="btn btn-success"><i class="bi bi-cash"></i> {{ __('صرف التسوية وقفلها') }}</button></form>
  @endif
  @if($settlement->status === 'Draft' && auth()->user()?->hasPermission('settlements.create'))
  <form method="POST" action="{{ route('settlements.cancel', $settlement) }}" onsubmit="return confirm('إلغاء مسودة التسوية؟')">@csrf<button class="btn btn-outline-danger">{{ __('إلغاء المسودة') }}</button></form>
  @endif
  <a class="btn btn-light" href="{{ route('settlements.index') }}">{{ __('عودة للقائمة') }}</a>
</div>
@endsection
