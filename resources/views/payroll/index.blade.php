@extends('layouts.app')
@section('title', __('الرواتب الشهرية'))
@section('content')
<div class="page-head"><div><h1>{{ __('دورة الرواتب') }}</h1><p>{{ __('إنشاء، مراجعة، اعتماد وقفل مسير الرواتب مع لقطات حسابية محفوظة.') }}</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('payroll.settings'))<a class="btn btn-outline-secondary" href="{{ route('payroll.settings.index') }}"><i class="bi bi-gear-fill"></i> {{ __('إعدادات الحساب') }}</a>@endif @if(auth()->user()?->hasPermission('payroll.export'))<a class="btn btn-light" target="_blank" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01')]) }}"><i class="bi bi-printer"></i> {{ __('طباعة') }}</a><a class="btn btn-light" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01'),'format'=>'csv']) }}"><i class="bi bi-file-earmark-excel"></i> {{ __('تصدير CSV') }}</a>@endif</div></div>
<div class="panel mb-3"><form method="GET" class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">{{ __('شهر المسير') }}</label><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">{{ __('عرض') }}</button></div></form></div>
<div class="stat-grid compact">@foreach([[__('إجمالي الموظفين'),$totalEmployees,'blue','people'],[__('سجلات معتمدة/مقفلة'),$reviewedCount,'green','check2-circle'],[__('مسودات'),$pendingReview,'orange','hourglass'],[__('صافي المسير'),format_money($totalNet).' '.__('ج.م'),'purple','cash-stack'],[__('إجمالي التكلفة'),format_money($totalCost).' '.__('ج.م'),'green','wallet2']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>{{ $month->format('m/Y') }}</small></div></div>@endforeach</div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>{{ __('مسيرات الرواتب') }}</h3><span>{{ __('مسودة الشهر الحالي تُحسب حتى اليوم لتعرف مستحق كل موظف لحظيًا، ويُنشأ المسير ويُعتمد ويُقفل تلقائيًا في آخر يوم من الشهر دون تدخل.') }}</span></div>@if(auth()->user()?->hasPermission('payroll.create'))<form method="POST" action="{{ route('payroll.runs.generate') }}">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}"><button class="btn btn-primary"><i class="bi bi-play-fill"></i> {{ __('إنشاء/تحديث مسودة الشهر') }}</button></form>@endif</div>
@if($errors->has('payroll'))<div class="alert alert-danger m-3">{{ $errors->first('payroll') }}</div>@endif
<table class="table align-middle"><thead><tr><th>{{ __('الشهر') }}</th><th>{{ __('الحالة') }}</th><th>{{ __('السجلات') }}</th><th>{{ __('الإقفال التلقائي') }}</th><th>{{ __('الإجراء') }}</th></tr></thead><tbody>
@forelse($runs as $run)
@php($meta = $runMeta[$run->id] ?? ['month_ended' => false, 'period_ended' => false, 'closes_on' => ''])
@php($report = $runReports[$run->id] ?? null)
@php($statusLabel = ['Draft' => __('مسودة (حتى اليوم)'), 'Generating' => __('جارٍ الإنشاء'), 'Approved' => __('معتمد'), 'Locked' => __('مقفول')][$run->status] ?? $run->status)
<tr><td>{{ $run->payroll_month?->format('m/Y') }}@if($run->is_transition) <span class="badge text-bg-info">{{ __('انتقالي') }}</span>@endif @if($run->period_start && $run->period_end)<small class="d-block text-muted">{{ $run->period_start->format('d/m/Y') }} - {{ $run->period_end->format('d/m/Y') }}</small>@endif</td><td><span class="badge {{ $run->status === 'Locked' ? 'text-bg-dark' : ($run->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $statusLabel }}</span></td><td>{{ $run->records_count }}</td><td>@if($run->status === 'Locked'){{ __('تم') }} {{ $run->locked_at?->format('d/m/Y H:i') }}@else{{ $meta['closes_on'] }}@endif</td>
<td>
@if($run->status === 'Draft' && auth()->user()?->hasPermission('payroll.approve'))
@if($meta['period_ended'])
<form class="d-inline" method="POST" action="{{ route('payroll.runs.approve',$run) }}">@csrf
@if($report && count($report['skipped']))<label class="d-block small mb-1"><input type="checkbox" name="confirm_skipped" value="1" required> {{ __('أؤكد الاعتماد رغم استبعاد') }} {{ count($report['skipped']) }} {{ __('موظف') }}</label>@endif
<button class="btn btn-sm btn-outline-success">{{ __('اعتماد') }}</button></form>
@else<small class="text-muted">{{ __('يُعتمد ويُقفل تلقائيًا يوم') }} {{ $meta['closes_on'] }}</small>@endif
@elseif($run->status === 'Approved' && !$meta['period_ended'])
<small class="text-muted">{{ __('يُقفل تلقائيًا يوم') }} {{ $meta['closes_on'] }}</small>
@endif
@if(in_array($run->status, ['Draft', 'Approved'], true) && $meta['period_ended'] && auth()->user()?->hasPermission('payroll.lock'))
<button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#closePeriod{{ $run->id }}">{{ __('قفل نهائي') }}</button>
<div class="modal fade" id="closePeriod{{ $run->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('payroll.close-period') }}">@csrf<input type="hidden" name="payroll_run_id" value="{{ $run->id }}"><div class="modal-header"><h5>{{ __('قفل نهائي لمسير') }} {{ $run->payroll_month?->format('m/Y') }}@if($run->is_transition) {{ __('(انتقالي)') }}@endif</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border small">{{ __('القفل النهائي يثبّت حضور الفترة والملخص المحفوظ والمسير وسجلاته معًا، ومش هيقبلوا تعديل بعدها. لو المسير لسه مسودة هيُعتمد تلقائيًا قبل القفل.') }}</div><label class="d-flex gap-2 align-items-start"><input type="checkbox" name="confirm" value="1" required class="form-check-input mt-1"><span>{{ __('أؤكد إن أرقام الفترة هتتثبت نهائيًا ومش هتقبل تعديل.') }}</span></label></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-dark">{{ __('قفل نهائي') }}</button></div></form></div></div></div>
@endif
@if(auth()->user()?->hasPermission('payroll.delete'))<form class="d-inline ms-1" method="POST" action="{{ route('payroll.runs.destroy',$run) }}" onsubmit="return confirm('سيتم حذف المسير وسجلاته نهائيًا ولا يمكن التراجع. هل تريد المتابعة؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ __('حذف') }}</button></form>@endif
</td></tr>
@if($report && count($report['skipped']))
<tr class="table-warning"><td colspan="5"><strong>{{ __('موظفون مستحقون لم يُنشأ لهم سجل (') }}{{ count($report['skipped']) }} {{ __('من') }} {{ $report['eligible'] }})</strong><ul class="mb-0 small">@foreach(array_slice($report['skipped'], 0, 50) as $item)<li>{{ $item['name'] }} @if($item['code'])(#{{ $item['code'] }})@endif — {{ $item['reason'] }}</li>@endforeach</ul>@if(count($report['skipped']) > 50)<small>{{ __('… و') }}{{ count($report['skipped']) - 50 }} {{ __('آخرين') }}</small>@endif</td></tr>
@elseif($run->status !== 'Draft' && !empty($run->skipped_report['skipped']))
<tr class="table-light"><td colspan="5"><small class="text-muted">{{ __('عند الاعتماد كان') }} {{ count($run->skipped_report['skipped']) }} {{ __('موظف بدون سجل راتب:') }} @foreach(array_slice($run->skipped_report['skipped'], 0, 10) as $item){{ $item['name'] }}@if(!$loop->last){{ __('،') }} @endif @endforeach</small></td></tr>
@endif
@empty<tr><td colspan="5" class="text-center py-4">{{ __('لا يوجد مسير بعد. يلزم وجود هيكل راتب نشط للموظفين قبل الإنشاء.') }}</td></tr>@endforelse</tbody></table></div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>{{ __('تفاصيل مسير') }} {{ $month->translatedFormat('F Y') }}</h3><span>{{ __('الغياب والجزاءات تأتي من السجلات المعتمدة؛ أضف البنود المتغيرة قبل اعتماد المسير.') }}</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('الموظف') }}</th><th>{{ __('هيكل الراتب') }}</th><th>{{ __('الراتب الإجمالي') }}</th><th>{{ __('إضافي') }}</th><th>{{ __('غياب') }}</th><th>{{ __('جزاءات') }}</th><th>{{ __('تأمين الموظف') }}</th><th>{{ __('الضريبة') }}</th><th>{{ __('الصافي') }}</th><th>{{ __('تكلفة الشركة') }}</th><th>{{ __('الحالة') }}</th><th></th></tr></thead><tbody>@forelse($records as $record)<tr><td><a class="employee-link fw-bold" href="{{ route('employees.show',$record->employee) }}">{{ $record->employee?->full_name }}</a><small class="d-block text-muted">{{ $record->employee?->department?->name }}</small></td><td>@if($record->salary)<a href="{{ route('salaries.index',['employee_id'=>$record->employee_id]) }}" data-salary-details-url="{{ route('salaries.details', $record->salary) }}" class="small fw-semibold">{{ __('هيكل #') }}{{ $record->salary_id }}</a><small class="d-block text-muted">{{ __('ساري من') }} {{ $record->salary->effective_from?->format('d/m/Y') ?? '—' }}</small><small class="d-block text-muted">{{ __('المتفق عليه:') }} {{ format_money($record->agreed_net_salary) }}</small>@else—@endif</td><td>{{ format_money($record->gross_salary) }}</td><td>{{ format_money($record->overtime_amount) }}</td><td>{{ format_money($record->absence_deduction) }} <small class="text-muted">({{ format_number($record->absence_days) }} {{ __('يوم)') }}</small></td><td>{{ format_money($record->penalties) }} <small class="text-muted">({{ format_number($record->penalty_days) }} {{ __('يوم)') }}</small></td><td>{{ format_money($record->employee_insurance) }}</td><td>{{ format_money($record->income_tax) }}</td><td class="fw-bold text-success">{{ format_money($record->net_salary) }}</td><td>{{ format_money($record->employee_cost) }}</td><td><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></td><td>@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inputs{{ $record->id }}">{{ __('مدخلات') }}</button>@endif</td></tr>@empty<tr><td colspan="12" class="text-center py-5">{{ __('لا توجد سجلات لهذا الشهر.') }}</td></tr>@endforelse</tbody></table></div></div>

<div class="modal fade" id="payrollCardDetails" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="payrollCardTitle">{{ __('تفاصيل البطاقة') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="payrollCardBody" class="details-grid"></div></div></div></div></div>
<script>
document.querySelectorAll('.stat-grid .stat-card').forEach((card,index)=>{card.classList.add('clickable-card');card.setAttribute('role','button');card.addEventListener('click',()=>{const data=@json($cardDetails);const keys=['employees','reviewed','drafts','net','cost'];const labels=[window.masarT('الموظفون'),window.masarT('السجلات المعتمدة أو المصروفة'),window.masarT('المسودات'),window.masarT('تفاصيل صافي المسير'),window.masarT('تفاصيل إجمالي التكلفة')];const list=data[keys[index]]||[];document.getElementById('payrollCardTitle').textContent=labels[index]||window.masarT('تفاصيل البطاقة');document.getElementById('payrollCardBody').innerHTML=list.length?list.map(item=>{const name=item.full_name||item.employee?.full_name||'—';const value=item.net_salary!==undefined?Number(item.net_salary).toLocaleString('en-US')+window.masarT(' ج.م'):(item.employee_cost!==undefined?Number(item.employee_cost).toLocaleString('en-US')+window.masarT(' ج.م'):(item.status||item.employee_code||''));return '<div><label>'+name+'</label><b>'+value+'</b></div>'}).join(''):window.masarStaticHtml('<div class="text-muted">لا توجد بيانات.</div>');bootstrap.Modal.getOrCreateInstance(document.getElementById('payrollCardDetails')).show()})});
</script>
<script>
document.querySelectorAll('.stat-grid .stat-card').forEach(card=>card.classList.add('payroll-kpi-card'));
const payrollFilter=document.querySelector('form input[name="month"]')?.closest('.panel');const payrollPanels=document.querySelectorAll('.panel.table-panel');const payrollDetailsHead=payrollPanels[1]?.querySelector('.panel-head');if(payrollFilter&&payrollDetailsHead){payrollFilter.classList.add('payroll-inline-filter');payrollDetailsHead.append(payrollFilter);}
</script>
<div class="payroll-mobile-cards" aria-label="{{ __('تفاصيل مسير الموظفين على الهاتف') }}">
@forelse($records as $record)
  <button type="button" class="payroll-mobile-card" data-bs-toggle="modal" data-bs-target="#payrollRecordDetails{{ $record->id }}">
    <span class="payroll-mobile-card-head"><span><strong>{{ $record->employee?->full_name }}</strong><small>{{ $record->employee?->department?->name ?? '—' }}</small></span><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></span>
    <span class="payroll-mobile-card-values"><span><small>{{ __('الصافي') }}</small><b class="text-success">{{ format_money($record->net_salary) }}</b></span><span><small>{{ __('الإجمالي') }}</small><b>{{ format_money($record->gross_salary) }}</b></span><span><small>{{ __('التكلفة') }}</small><b>{{ format_money($record->employee_cost) }}</b></span></span>
    <span class="payroll-mobile-card-footer"><span>{{ __('هيكل #') }}{{ $record->salary_id ?? '—' }}</span><i class="bi bi-chevron-left"></i></span>
  </button>
@empty
  <div class="empty-page"><div class="empty-icon"><i class="bi bi-cash-stack"></i></div><p>{{ __('لا توجد سجلات لهذا الشهر.') }}</p></div>
@endforelse
</div>

@foreach($records as $record)
<div class="modal fade payroll-record-details-modal" id="payrollRecordDetails{{ $record->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title">{{ __('تفاصيل راتب') }} {{ $record->employee?->full_name }}</h5><small class="text-muted">{{ $month->translatedFormat('F Y') }}</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="payroll-record-summary"><span>{{ __('الراتب الصافي') }}</span><strong>{{ format_money($record->net_salary) }} {{ __('ج.م') }}</strong></div><div class="payroll-record-details-grid">
      <div><span>{{ __('هيكل الراتب') }}</span><b>{{ $record->salary_id ? '#'.$record->salary_id : '—' }}</b></div><div><span>{{ __('حالة المسير') }}</span><b>{{ $record->status }}</b></div><div><span>{{ __('الراتب الإجمالي') }}</span><b>{{ format_money($record->gross_salary) }} {{ __('ج.م') }}</b></div><div><span>{{ __('الإضافي') }}</span><b>{{ format_money($record->overtime_amount) }} {{ __('ج.م') }}</b></div><div><span>{{ __('خصم الغياب') }}</span><b>{{ format_money($record->absence_deduction) }} {{ __('ج.م (') }}{{ format_number($record->absence_days) }} {{ __('يوم)') }}</b></div><div><span>{{ __('الجزاءات') }}</span><b>{{ format_money($record->penalties) }} {{ __('ج.م (') }}{{ format_number($record->penalty_days) }} {{ __('يوم)') }}</b></div><div><span>{{ __('تأمين الموظف') }}</span><b>{{ format_money($record->employee_insurance) }} {{ __('ج.م') }}</b></div><div><span>{{ __('الضريبة') }}</span><b>{{ format_money($record->income_tax) }} {{ __('ج.م') }}</b></div><div><span>{{ __('تكلفة الشركة') }}</span><b>{{ format_money($record->employee_cost) }} {{ __('ج.م') }}</b></div><div><span>{{ __('تاريخ سريان الهيكل') }}</span><b>{{ $record->salary?->effective_from?->format('d/m/Y') ?? '—' }}</b></div>
    </div></div>
    <div class="modal-footer"><a class="btn btn-light" href="{{ route('employees.show', $record->employee) }}">{{ __('فتح ملف الموظف') }}</a>@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))<button class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#inputs{{ $record->id }}">{{ __('تعديل المدخلات') }}</button>@endif</div>
  </div></div>
</div>
@endforeach

@foreach($records as $record)
@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))
<div class="modal fade" id="inputs{{ $record->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('payroll.inputs.update',$record) }}">@csrf @method('PUT')<div class="modal-header"><h5>{{ __('مدخلات راتب') }} {{ $record->employee?->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border small">{{ __('الغياب:') }} {{ format_number($record->absence_days) }} {{ __('يوم · الجزاءات:') }} {{ format_number($record->penalty_days) }} {{ __('يوم. هذه القيم مصدرها السجلات المعتمدة ولا تعدل من هنا.') }}</div><div class="row g-3"><div class="col-6"><label class="form-label">{{ __('ساعات إضافي نهاري') }}</label><input type="number" min="0" step="0.01" name="ot_day_hours" value="{{ format_number($record->ot_day_hours) }}" class="form-control"></div><div class="col-6"><label class="form-label">{{ __('ساعات إضافي ليلي') }}</label><input type="number" min="0" step="0.01" name="ot_night_hours" value="{{ format_number($record->ot_night_hours) }}" class="form-control"></div><div class="col-6"><label class="form-label">{{ __('مكافأة') }}</label><input type="number" min="0" step="0.01" name="bonus" value="{{ format_number($record->bonus) }}" class="form-control"></div><div class="col-6"><label class="form-label">{{ __('سلف') }}</label><input type="number" min="0" step="0.01" name="loans" value="{{ format_number($record->loans) }}" class="form-control"></div><div class="col-6"><label class="form-label">{{ __('استحقاقات أخرى') }}</label><input type="number" min="0" step="0.01" name="other_earnings" value="{{ format_number($record->other_earnings) }}" class="form-control"></div><div class="col-6"><label class="form-label">{{ __('استقطاعات أخرى') }}</label><input type="number" min="0" step="0.01" name="other_deductions" value="{{ format_number($record->other_deductions) }}" class="form-control"></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('حفظ وإعادة الحساب') }}</button></div></form></div></div></div>
@endif
@endforeach
@push('scripts')
<script>
document.querySelectorAll('form[action*="/payroll/"]').forEach((form) => {
  if (!form.action.includes('/inputs')) return;
  const modal = form.closest('.modal');
  if (!modal) return;
  const note = modal.querySelector('.alert');
  if (note) note.innerHTML += window.masarStaticHtml(' <br><strong>وحدة القياس:</strong> الإضافي بالساعات، والغياب والجزاءات بالأيام. القيم المعروضة مأخوذة من السجلات المعتمدة ولا تُكتب يدويًا من هنا.');
});
</script>
@endpush

@push('scripts')
<script>
(() => {
  const form = document.querySelector('form[action$="/payroll/runs/generate"]');
  const modalElement = document.getElementById('payrollGenerationModal');
  if (!form || !modalElement || typeof bootstrap === 'undefined') return;
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || form.querySelector('[name="_token"]')?.value;
  const percent = document.getElementById('payrollGenerationPercent');
  const progress = document.getElementById('payrollGenerationProgress');
  const message = document.getElementById('payrollGenerationMessage');
  const icon = document.getElementById('payrollGenerationIcon');
  const setProgress = (value, text) => {
    const safe = Math.max(0, Math.min(100, Number(value) || 0));
    percent.textContent = `${safe}%`;
    progress.style.width = `${safe}%`;
    if (text) message.textContent = text;
  };
  const requestJson = async (url, body) => {
    const response = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || window.masarT('تعذر إنشاء المسير.'));
    return data;
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    modalElement.querySelector('.modal-content').classList.remove('border-danger');
    icon.className = 'bi bi-arrow-repeat fs-1 text-primary';
    setProgress(0, window.masarT('جاري تجهيز قائمة الموظفين...'));
    modal.show();
    try {
      const month = form.querySelector('[name="month"]')?.value;
      const started = await requestJson('{{ route('payroll.runs.generate.start') }}', { month });
      const total = Number(started.total || 0);
      let offset = 0;
      if (!total) {
        setProgress(100, window.masarT('لا توجد سجلات موظفين لإنشائها.'));
      }
      while (offset < total || total === 0) {
        const result = await requestJson('{{ route('payroll.runs.generate.batch') }}', { month: started.month, offset, batch_size: started.batch_size || 25 });
        offset = Number(result.processed || total);
        setProgress(result.percent, `${window.masarT("تمت معالجة")} ${offset} ${window.masarT("من")} ${total} ${window.masarT("موظف")}`);
        if (result.complete || total === 0) break;
      }
      setProgress(100, window.masarT('اكتمل إنشاء مسودة المسير بنجاح.'));
      icon.className = 'bi bi-check-circle-fill fs-1 text-success';
      setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      icon.className = 'bi bi-exclamation-triangle-fill fs-1 text-danger';
      modalElement.querySelector('.modal-content').classList.add('border-danger');
      message.textContent = error.message;
      if (button) button.disabled = false;
    }
  });
})();
</script>
@endpush

@push('scripts')
<script>
(() => {
  const advanceRates = @json($records->getCollection()->mapWithKeys(fn ($record) => [$record->id => data_get($record->calculation_snapshot, 'advance_override_rate')]));
  const advanceSchedules = @json($records->getCollection()->mapWithKeys(fn ($record) => [$record->id => data_get($record->calculation_snapshot, 'advance_details', [])]));
  document.querySelectorAll('form[action*="/payroll/"][action$="/inputs"]').forEach((form) => {
    if (form.querySelector('[name="advance_rate"]')) return;
    const loans = form.querySelector('[name="loans"]');
    if (!loans) return;
    const recordId = form.action.match(/\/payroll\/(\d+)\/?$/)?.[1];
    if ((advanceSchedules[recordId] || []).length) { loans.readOnly = true; loans.title = window.masarT('يتم تحديد السلفة من نسبة الخصم اليدوية أو الحساب التلقائي'); }
    const wrapper = document.createElement('div');
    wrapper.className = 'col-6';
    wrapper.innerHTML = window.masarStaticHtml('<label class="form-label">نسبة خصم السلفة (%)</label><input type="number" name="advance_rate" min="0" max="100" step="0.01" class="form-control" placeholder="تلقائي حسب 10%"><small class="text-muted">يُحسب المبلغ من الراتب الإجمالي ولا يتجاوز الرصيد.</small>');
    wrapper.querySelector('input').value = advanceRates[recordId] ?? '';
    loans.closest('.col-6')?.after(wrapper);
  });
})();
</script>
@endpush

@push('scripts')
<script>
(() => {
  const advanceByRecord = @json($records->getCollection()->mapWithKeys(fn ($record) => [$record->id => (float) data_get($record->calculation_snapshot, 'advance_earnings', 0)]));
  document.querySelectorAll('.payroll-record-details-modal').forEach((modal) => {
    modal.addEventListener('show.bs.modal', () => {
      const id = modal.id.replace('payrollRecordDetails', '');
      const amount = Number(advanceByRecord[id] || 0);
      const grid = modal.querySelector('.payroll-record-details-grid');
      if (!grid || amount <= 0 || grid.querySelector('[data-advance-earned]')) return;
      const item = document.createElement('div');
      item.dataset.advanceEarned = 'true';
      item.innerHTML = `<span>${window.masarT("السلفة المصروفة")}</span><b>${amount.toLocaleString('en-US', {maximumFractionDigits: 0})} ${window.masarT("ج.م")}</b>`;
      grid.insertBefore(item, grid.children[4] || null);
    });
  });
})();
</script>
@endpush
<script>
(() => {
  const hideZeroItems = (root) => {
    root.querySelectorAll('.payroll-record-details-grid > div, .payroll-detail-section .payroll-detail-item').forEach((item) => {
      const value = item.querySelector('b')?.textContent || item.textContent || '';
      const match = value.match(/-?\d[\d,]*(?:\.\d+)?/);
      const number = match ? Number(match[0].replace(/,/g, '')) : NaN;
      if (Number.isFinite(number) && number === 0) item.remove();
    });
  };
  document.querySelectorAll('.payroll-record-details-modal').forEach((modal) => {
    hideZeroItems(modal);
    modal.addEventListener('show.bs.modal', () => hideZeroItems(modal));
  });
})();
</script>
<div class="payroll-pagination-bar mt-3">
  <div class="text-muted small">{{ __('عرض') }} {{ $records->firstItem() ?? 0 }} {{ __('إلى') }} {{ $records->lastItem() ?? 0 }} {{ __('من') }} {{ $records->total() }} {{ __('موظف') }}</div>
  <form method="GET" class="payroll-page-size d-flex align-items-center gap-2">
    @foreach(request()->except(['page', 'per_page']) as $key => $value)
      @if(is_scalar($value))
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
      @endif
    @endforeach
    <label for="payrollPerPage" class="small mb-0">&#1593;&#1583;&#1583; &#1575;&#1604;&#1589;&#1601;&#1608;&#1601;</label>
    <select id="payrollPerPage" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
      @foreach([10, 25, 50, 100] as $size)
        <option value="{{ $size }}" @selected((int) $records->perPage() === $size)>{{ $size }}</option>
      @endforeach
    </select>
  </form>
  <div class="d-flex justify-content-center">{{ $records->onEachSide(1)->links() }}</div>
</div>
<style>
  .payroll-pagination-bar { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .75rem 1.25rem; }
  .payroll-pagination-bar nav { display: flex; justify-content: center; }
  .payroll-pagination-bar .pagination { margin-bottom: 0; }
  .payroll-page-size .form-select { width: auto; min-width: 4.5rem; }
</style>
<div class="modal fade" id="payrollGenerationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content text-center p-4">
      <div class="mb-3"><i class="bi bi-arrow-repeat fs-1 text-primary" id="payrollGenerationIcon"></i></div>
      <h5 class="mb-2">{{ __('جاري تحميل المسير') }}</h5>
      <p class="text-muted small mb-3" id="payrollGenerationMessage">{{ __('جاري تجهيز بيانات الموظفين...') }}</p>
      <div class="d-flex justify-content-between small mb-1"><span>{{ __('التقدم') }}</span><strong id="payrollGenerationPercent">0%</strong></div>
      <div class="progress" style="height:10px"><div id="payrollGenerationProgress" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div></div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
  const payrollDetailsTable = document.querySelectorAll('.panel.table-panel')[1]?.querySelector('table');
  if (payrollDetailsTable && !payrollDetailsTable.querySelector('th[data-serial-column]')) {
    const serialHeader = document.createElement('th');
    serialHeader.dataset.serialColumn = 'true';
    serialHeader.textContent = window.masarT('م');
    payrollDetailsTable.tHead?.rows[0]?.prepend(serialHeader);
    [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, index) => {
      const serialCell = document.createElement('td');
      serialCell.textContent = String(index + 1);
      serialCell.className = 'text-muted fw-semibold';
      row.prepend(serialCell);
    });
  }
  window.payrollDetailsData = @json($payrollDetails);
  const payrollDetailsData = window.payrollDetailsData;
  if (payrollDetailsTable && !payrollDetailsTable.querySelector('th[data-monthly-total-column]')) {
    const headers = [...payrollDetailsTable.tHead.rows[0].cells];
    const grossIndex = headers.findIndex((cell) => cell.textContent.trim() === window.masarT('الراتب الإجمالي'));
    if (grossIndex >= 0) {
      const monthlyHeader = document.createElement('th');
      monthlyHeader.dataset.monthlyTotalColumn = 'true';
      monthlyHeader.textContent = window.masarT('الإجمالي الشهري');
      headers[grossIndex].after(monthlyHeader);
      [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, rowIndex) => {
        const cell = document.createElement('td');
        cell.textContent = Number(payrollDetailsData[rowIndex]?.monthly_total || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
        row.cells[grossIndex]?.after(cell);
      });
    }
  }
  if (false && payrollDetailsTable && !payrollDetailsTable.querySelector('th[data-advance-column]')) {
    const header = [...payrollDetailsTable.tHead.rows[0].cells].find((cell) => cell.textContent.trim() === window.masarT('إضافي'));
    const targetHeader = header || payrollDetailsTable.tHead.rows[0].cells[4];
    const index = targetHeader ? [...payrollDetailsTable.tHead.rows[0].cells].indexOf(targetHeader) : -1;
    if (index >= 0) {
      const advanceHeader = document.createElement('th'); advanceHeader.dataset.advanceColumn = 'true'; advanceHeader.textContent = window.masarT('السلف'); targetHeader.after(advanceHeader);
      [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, rowIndex) => { const cell = document.createElement('td'); cell.textContent = payrollDetailsData[rowIndex] ? Number(payrollDetailsData[rowIndex].loans || 0).toLocaleString('en-US', {maximumFractionDigits: 0}) : '0'; row.cells[index]?.after(cell); });
    }
  }
  const payrollMoney = (value) => Number(value || 0).toLocaleString('en-US', {maximumFractionDigits: 0}) + window.masarT(' ج.م');
  const payrollMoneyDecimal = (value) => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + window.masarT(' ج.م');
  const payrollDetailsRows = payrollDetailsTable ? [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]) : [];
  const detailPanel = document.createElement('div');
  detailPanel.className = 'offcanvas offcanvas-end payroll-detail-panel';
  detailPanel.tabIndex = -1;
  detailPanel.id = 'payrollDetailPanel';
  detailPanel.innerHTML = window.masarStaticHtml('<div class="offcanvas-header"><div><h5 class="offcanvas-title">تفاصيل مسير الموظف</h5><small class="text-muted" data-payroll-detail-subtitle></small></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button></div><div class="offcanvas-body" data-payroll-detail-body></div>');
  document.body.append(detailPanel);
  const detailBody = detailPanel.querySelector('[data-payroll-detail-body]');
  const payrollUiStyle = document.createElement('style');
  payrollUiStyle.textContent = '.payroll-clickable-row{cursor:pointer;transition:background .15s ease}.payroll-clickable-row:hover{background:#f1f6ff!important}.payroll-detail-panel{width:min(440px,96vw)}.payroll-detail-section{border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:12px;background:#fff}.payroll-detail-section h6{display:flex;gap:7px;align-items:center;margin:0 0 10px;color:#173d72;font-weight:800}.payroll-detail-item{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-bottom:1px solid #f1f5f9}.payroll-detail-item:last-child{border-bottom:0}.payroll-detail-item span{color:#64748b}.payroll-detail-item b{color:#1e293b}.payroll-detail-total{padding:15px;border-radius:12px;background:#eafaf1;display:grid;gap:4px}.payroll-detail-total span{color:#166534}.payroll-detail-total strong{font-size:22px;color:#15803d}.payroll-detail-total small{color:#64748b}.payroll-salary-heading{display:flex;justify-content:space-between;gap:10px;padding:12px;border-radius:12px;background:#f8fafc;margin-bottom:12px}.payroll-salary-heading span{color:#64748b}.payroll-salary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.payroll-salary-grid .payroll-detail-item{border:1px solid #e2e8f0;border-radius:10px;padding:10px;display:grid}.payroll-salary-grid .payroll-detail-item b{font-size:15px}@media(max-width:600px){.payroll-salary-grid{grid-template-columns:1fr}.payroll-detail-panel{width:100%}}';
  payrollUiStyle.textContent += '.payroll-collapse-toggle{cursor:pointer;user-select:none;background:#f8fafc;padding-inline:8px;border-radius:8px}.payroll-collapse-toggle::after{content:"⌃";color:#1769e0;font-weight:800;margin-inline-start:auto}.payroll-collapse-toggle[aria-expanded="false"]::after{content:"⌄"}';
  payrollUiStyle.textContent += '.payroll-salary-section{border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin-bottom:12px;background:#fff}.payroll-salary-section h6{display:flex;align-items:center;gap:8px;margin:0 0 10px;color:#173d72;font-weight:800}.payroll-salary-section h6 i{color:#1769e0}.payroll-salary-section .payroll-detail-item{background:#fff}';
  document.head.append(payrollUiStyle);
  const detailValue = (label, value) => `<div class="payroll-detail-item"><span>${label}</span><b>${value}</b></div>`;
  const openPayrollDetails = (record) => {
    if (!record || !detailBody) return;
    detailPanel.querySelector('[data-payroll-detail-subtitle]').textContent = `${record.name || '—'} · ${window.masarT("كود")} ${record.code || '—'}`;
    detailBody.innerHTML = `<div class="payroll-detail-section"><h6><i class="bi bi-person"></i> ${window.masarT("بيانات الموظف")}</h6>${detailValue(window.masarT('القسم'), record.department || '—')}${detailValue(window.masarT('الحالة'), record.status || '—')}</div><div class="payroll-detail-section"><h6><i class="bi bi-plus-circle"></i> ${window.masarT("الاستحقاقات")}</h6>${detailValue(window.masarT('الراتب الأساسي'), payrollMoney(record.basic_salary))}${detailValue(window.masarT('البدلات'), payrollMoney(record.allowances))}${detailValue(window.masarT('بدل السكن'), payrollMoney(record.housing_allowance))}${detailValue(window.masarT('بدل الانتقال'), payrollMoney(record.transportation_allowance))}${detailValue(window.masarT('بدل الطعام'), payrollMoney(record.meal_allowance))}${detailValue(window.masarT('بدل الملابس'), payrollMoney(record.clothing_allowance))}${detailValue(window.masarT('الإضافي'), payrollMoney(record.overtime_amount))}${detailValue(window.masarT('الإجمالي'), payrollMoney(record.gross_salary))}</div><div class="payroll-detail-section"><h6><i class="bi bi-dash-circle"></i> ${window.masarT("الخصومات")}</h6>${detailValue(window.masarT('السلفة'), payrollMoney(record.loans))}${detailValue(window.masarT('خصم الغياب'), payrollMoney(record.absence_deduction))}${detailValue(window.masarT('الجزاءات'), payrollMoney(record.penalties))}${detailValue(window.masarT('تأمين الموظف'), payrollMoney(record.employee_insurance))}${detailValue(window.masarT('الضريبة'), payrollMoney(record.income_tax))}${detailValue(window.masarT('صندوق الشهداء'), payrollMoneyDecimal(record.martyrs_fund))}${detailValue(window.masarT('استقطاعات ثابتة'), payrollMoney(record.fixed_deductions))}${detailValue(window.masarT('استقطاعات أخرى'), payrollMoney(record.other_deductions))}</div><div class="payroll-detail-total"><span>${window.masarT("الراتب الصافي")}</span><strong>${payrollMoney(record.net_salary)}</strong><small>${window.masarT("تكلفة الشركة:")} ${payrollMoney(record.employee_cost)}</small></div>`;
    const deductionsHeading = [...detailBody.querySelectorAll('h6')].find((heading) => heading.textContent.trim() === window.masarT('الخصومات'));
    if (deductionsHeading) deductionsHeading.childNodes[deductionsHeading.childNodes.length - 1].textContent = window.masarT(' الاستقطاعات');
    const earningsSection = detailBody.querySelectorAll('.payroll-detail-section')[1];
    const grossTotalItem = earningsSection ? [...earningsSection.querySelectorAll('.payroll-detail-item')].reverse().find((item) => item.querySelector('b')?.textContent?.trim() === payrollMoney(record.gross_salary)) : null;
    grossTotalItem?.remove();
    let additionalItems = [];
    if (earningsSection) {
      const allowanceItems = [...earningsSection.querySelectorAll('.payroll-detail-item')].slice(2, 6);
      const allowanceTotal = earningsSection.querySelectorAll('.payroll-detail-item')[1];
      if (allowanceTotal && allowanceItems.length) {
        allowanceTotal.classList.add('payroll-collapse-toggle');
        allowanceTotal.setAttribute('role', 'button');
        allowanceTotal.setAttribute('tabindex', '0');
        allowanceTotal.setAttribute('aria-expanded', 'false');
        allowanceItems.forEach((item) => { item.hidden = true; });
        const toggleAllowances = () => {
          const expanded = allowanceTotal.getAttribute('aria-expanded') === 'true';
          allowanceTotal.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          allowanceItems.forEach((item) => { item.hidden = expanded; });
        };
        allowanceTotal.addEventListener('click', toggleAllowances);
        allowanceTotal.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleAllowances(); }
        });
      }
      earningsSection.querySelectorAll('.payroll-detail-item').forEach((item) => {
        if (item.querySelector('span')?.textContent?.includes(window.masarT('الإضافي'))) item.remove();
        const amount = item.querySelector('b')?.textContent?.trim() || '';
        if (/^0(?:[.,]0+)?(?:\s|$)/.test(amount)) item.remove();
      });
      if (Number(record.day_overtime_amount || 0) > 0) earningsSection.insertAdjacentHTML('beforeend', detailValue(window.masarT('الإضافي النهاري'), payrollMoney(record.day_overtime_amount)));
      if (Number(record.night_overtime_amount || 0) > 0) earningsSection.insertAdjacentHTML('beforeend', detailValue(window.masarT('الإضافي الليلي'), payrollMoney(record.night_overtime_amount)));
      const dayOvertime = Number(record.day_overtime_amount || 0);
      const nightOvertime = Number(record.night_overtime_amount || 0);
      const totalOvertime = Number(record.overtime_amount || 0);
      if (dayOvertime <= 0 && nightOvertime <= 0 && totalOvertime > 0) {
        earningsSection.insertAdjacentHTML('beforeend', detailValue(window.masarT('الإضافي'), payrollMoney(totalOvertime)));
      }
      detailBody.querySelectorAll('.payroll-detail-section:nth-of-type(3) .payroll-detail-item').forEach((item) => {
        const amount = item.querySelector('b')?.textContent?.trim() || '';
        if (/^0(?:[.,]0+)?(?:\s|$)/.test(amount)) item.remove();
      });
      const addAdditional = (label, value) => { if (Number(value || 0) > 0) additionalItems.push([label, Number(value)]); };
      addAdditional(window.masarT('المكافأة'), record.bonus);
      addAdditional(window.masarT('الحوافز'), record.incentives);
      addAdditional(window.masarT('العمولة'), record.commission);
      addAdditional(window.masarT('نصيب الأرباح'), record.profit_share);
      addAdditional(window.masarT('استحقاقات أخرى'), record.other_earnings);
    }
    if (earningsSection) {
      if (Number(record.advance_earnings || 0) > 0) additionalItems.push([window.masarT('السلفة المصروفة'), Number(record.advance_earnings)]);
      if (additionalItems.length) {
        const additionalTotal = additionalItems.reduce((sum, [, value]) => sum + value, 0);
        const additionalGroup = document.createElement('div');
        additionalGroup.className = 'payroll-additional-group';
        additionalGroup.innerHTML = `${detailValue(window.masarT('إضافي'), payrollMoney(additionalTotal))}<div class="payroll-additional-items" hidden>${additionalItems.map(([label, value]) => detailValue(label, payrollMoney(value))).join('')}</div>`;
        const toggle = additionalGroup.querySelector('.payroll-detail-item');
        const items = additionalGroup.querySelector('.payroll-additional-items');
        toggle.classList.add('payroll-collapse-toggle');
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('tabindex', '0');
        toggle.setAttribute('aria-expanded', 'false');
        const toggleAdditional = () => { const expanded = toggle.getAttribute('aria-expanded') === 'true'; toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true'); items.hidden = expanded; };
        toggle.addEventListener('click', toggleAdditional);
        toggle.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleAdditional(); } });
        earningsSection.append(additionalGroup);
      }
    }
    if (earningsSection) earningsSection.insertAdjacentHTML('beforeend', detailValue(window.masarT('الإجمالي الشهري'), payrollMoney(record.monthly_total ?? record.gross_salary)));
    bootstrap.Offcanvas.getOrCreateInstance(detailPanel).show();
  };
  payrollDetailsRows.forEach((row, index) => {
    const record = payrollDetailsData[index];
    if (!record) return;
    const overtimeIndex = [...(payrollDetailsTable?.tHead?.rows[0]?.cells || [])]
      .findIndex((cell) => cell.textContent.trim() === window.masarT('إضافي'));
    const overtimeCell = overtimeIndex >= 0 ? row.cells[overtimeIndex] : null;
    if (overtimeCell) {
      const additional = Number(record.additional_amount || 0);
      overtimeCell.textContent = additional.toLocaleString('en-US', {maximumFractionDigits: 0});
    }
    row.dataset.payrollRecordIndex = String(index);
    row.classList.add('payroll-clickable-row');
    row.addEventListener('click', (event) => {
      if (event.target.closest('a,button,form,input,select,textarea')) return;
      openPayrollDetails(record);
    });
    row.querySelectorAll('a[href*="/salaries"]').forEach((link) => {
      link.addEventListener('click', (event) => { event.preventDefault(); openSalaryDetails(record, link.dataset.salaryDetailsUrl); });
    });
  });
  // Reuse the actual salaries-page modal, rendered by the shared Blade partial.
  // No financial values or modal markup are reconstructed in JavaScript.
  const openSalaryDetails = async (record, sourceUrl) => {
    const modalId = 'salaryDetails' + record.salary_id;
    const existing = document.getElementById(modalId);
    if (existing) { bootstrap.Modal.getOrCreateInstance(existing).show(); return; }
    try {
      const response = await fetch(sourceUrl, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const source = new DOMParser().parseFromString(await response.text(), 'text/html');
      const modal = source.getElementById(modalId);
      if (!modal) throw new Error('Salary structure unavailable');
      const imported = document.importNode(modal, true);
      document.body.appendChild(imported);
      bootstrap.Modal.getOrCreateInstance(imported).show();
    } catch (error) {
      window.showToast?.(window.masarT('تعذر تحميل هيكل الراتب. أعد المحاولة أو افتح صفحة هياكل الرواتب.'));
      console.error('Salary structure modal load failed', error);
    }
  };
  if (payrollDetailsTable) {
    const employeeCodes = @json($records->getCollection()->map(fn ($record) => $record->employee?->employee_code)->values());
    const netSalaries = @json($records->getCollection()->map(fn ($record) => (float) $record->net_salary)->values());
    // Locate columns by their header text so the net salary label always reads the net column.
    const headerTexts = [...payrollDetailsTable.tHead.rows[0].cells].map((cell) => cell.textContent.trim());
    const colIndex = (label) => headerTexts.indexOf(label);
    [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, index) => {
      const employeeCell = row.cells[colIndex(window.masarT('الموظف'))];
      if (employeeCell && employeeCodes[index] && !employeeCell.querySelector('[data-employee-code]')) {
        const code = document.createElement('small');
        code.dataset.employeeCode = 'true';
        code.className = 'd-block text-muted';
        code.textContent = `${window.masarT("كود الموظف:")} ${employeeCodes[index]}`;
        employeeCell.append(code);
      }
      const salaryCell = row.cells[colIndex(window.masarT('الراتب الإجمالي'))];
      const netCell = row.cells[colIndex(window.masarT('الصافي'))];
      if (salaryCell && netCell) {
        const net = salaryCell.querySelector('[data-net-salary]') || document.createElement('small');
        net.dataset.netSalary = 'true';
        net.className = 'd-block text-muted';
        net.textContent = `${window.masarT("الراتب الصافي:")} ${netCell.textContent.trim()}`;
        const oldAgreed = [...salaryCell.querySelectorAll('small')].find((item) => item.textContent.includes(window.masarT('المتفق عليه')));
        if (oldAgreed) oldAgreed.replaceWith(net); else if (!salaryCell.querySelector('[data-net-salary]')) salaryCell.append(net);
      }
    });
    document.querySelectorAll('.payroll-mobile-card').forEach((card, index) => {
      const title = card.querySelector('strong');
      if (title && employeeCodes[index] && !card.querySelector('[data-employee-code]')) {
        const code = document.createElement('small');
        code.dataset.employeeCode = 'true';
        code.textContent = `${window.masarT("كود الموظف:")} ${employeeCodes[index]}`;
        title.parentElement.append(code);
      }
    });
  }
  const payrollDetailsWrap = payrollDetailsTable?.closest('.table-responsive');
  if (payrollDetailsTable && payrollDetailsWrap && !payrollDetailsWrap.querySelector('.payroll-record-filter')) {
    const filter = document.createElement('div');
    filter.className = 'payroll-record-filter d-flex align-items-center gap-2 mb-3';
    filter.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;';
    filter.innerHTML = window.masarStaticHtml('<label class="form-label mb-0" for="payrollRecordSearch"><i class="bi bi-search"></i> بحث في الموظفين</label><input id="payrollRecordSearch" type="search" class="form-control" placeholder="اكتب اسم الموظف أو الكود أو القسم أو الحالة" autocomplete="off"><span class="text-muted small" data-payroll-filter-count></span>');
    payrollDetailsWrap.prepend(filter);
    const input = filter.querySelector('input');
    input.value = @json(request('search', ''));
    input.style.cssText = 'max-width:360px;min-width:220px;border-radius:10px;border:1px solid #cbd5e1;padding:.55rem .75rem;';
    const count = filter.querySelector('[data-payroll-filter-count]');
    const rows = [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]);
    const empty = document.createElement('div');
    empty.className = 'text-center text-muted py-4 payroll-filter-empty';
    empty.textContent = window.masarT('لا توجد نتائج مطابقة.');
    empty.hidden = true;
    payrollDetailsWrap.append(empty);
    const applyPayrollFilter = () => {
      const query = input.value.trim().toLocaleLowerCase('ar-EG');
      let visible = 0;
      rows.forEach((row) => {
        const match = !query || row.textContent.toLocaleLowerCase('ar-EG').includes(query);
        row.hidden = !match;
        if (match) visible++;
      });
      count.textContent = query ? `${visible} ${window.masarT("من")} ${rows.length}` : `${rows.length} ${window.masarT("موظف")}`;
      empty.hidden = visible !== 0 || rows.length === 0;
    };
    let searchTimer;
    input.addEventListener('input', () => {
      applyPayrollFilter();
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        const url = new URL(window.location.href);
        const value = input.value.trim();
        if (value) url.searchParams.set('search', value);
        else url.searchParams.delete('search');
        url.searchParams.delete('page');
        window.location.assign(url.toString());
      }, 350);
    });
    applyPayrollFilter();
  }
  // Payroll inputs are the only payroll action moved in this stage. Generate,
  // approve, lock, and pay remain full server transitions by design.
  const money = (value) => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + window.masarT(' ج.م');
  document.querySelectorAll('[id^="inputs"]').forEach((modal) => {
    const trigger = document.querySelector(`[data-bs-target="#${modal.id}"]`);
    const match = modal.id.match(/^inputs(\d+)$/);
    if (trigger && match) trigger.closest('tr')?.setAttribute('data-payroll-record-id', match[1]);
  });
  document.querySelectorAll('form[action*="/payroll/"][action$="/inputs"]').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = window.masarT('جارٍ إعادة الحساب'); });
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.action.endsWith('/inputs')) return;
    const record = event.detail?.record;
    // Keep the already-rendered Payslip drawer in sync with the AJAX update.
    // The table row is updated below, but its drawer reads payrollDetailsData.
    if (record?.id && Array.isArray(window.payrollDetailsData)) {
      const detail = window.payrollDetailsData.find((item) => String(item.id) === String(record.id));
      if (detail) Object.assign(detail, record, {
        day_overtime_amount: Math.max(Number(record.day_overtime_amount || 0), Number(record.ot_day_amount || 0)),
        night_overtime_amount: Math.max(Number(record.night_overtime_amount || 0), Number(record.ot_night_amount || 0)),
        bonus: Number(record.bonus || 0),
        loans: Number(record.loans || 0),
        other_earnings: Number(record.other_earnings || 0),
        advance_earnings: Number(record.advance_earnings || 0),
      });
    }
    const row = form.closest('.modal')?.previousElementSibling?.closest('tr') || document.querySelector(`tr[data-payroll-record-id="${record?.id}"]`);
    if (row && record) {
      const additional = Number(record.additional_amount ?? (Number(record.overtime_amount || 0) + Number(record.advance_earnings || 0) + Number(record.bonus || 0) + Number(record.other_earnings || 0)));
      // Columns: 0 employee, 1 structure, 2 gross, 3 additional, 4 absence, 5 penalties, 6 insurance, 7 tax, 8 net, 9 company cost.
      row.children[3].textContent = additional.toLocaleString('en-US', {maximumFractionDigits: 0});
      row.children[4].innerHTML = `${money(record.absence_deduction)} <small class="text-muted">(${record.absence_days || 0} ${window.masarT("يوم)")}</small>`;
      row.children[5].innerHTML = `${money(record.penalties)} <small class="text-muted">(${record.penalty_days || 0} ${window.masarT("يوم)")}</small>`;
      row.children[6].textContent = money(record.employee_insurance);
      row.children[7].textContent = money(record.income_tax);
      row.children[8].textContent = money(record.net_salary);
      row.children[9].textContent = money(record.employee_cost);
    }
    bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
  });
</script>
@endpush
