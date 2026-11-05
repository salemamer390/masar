@extends('layouts.app')
@section('title', 'الرواتب الشهرية')
@section('content')
<div class="page-head"><div><h1>دورة الرواتب</h1><p>إنشاء، مراجعة، اعتماد وقفل مسير الرواتب مع لقطات حسابية محفوظة.</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('payroll.settings'))<a class="btn btn-outline-secondary" href="{{ route('payroll.settings.index') }}"><i class="bi bi-gear-fill"></i> إعدادات الحساب</a>@endif @if(auth()->user()?->hasPermission('payroll.export'))<a class="btn btn-light" target="_blank" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01')]) }}"><i class="bi bi-printer"></i> طباعة</a><a class="btn btn-light" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01'),'format'=>'csv']) }}"><i class="bi bi-file-earmark-excel"></i> تصدير CSV</a>@endif</div></div>
<div class="panel mb-3"><form method="GET" class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">شهر المسير</label><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">عرض</button></div></form></div>
<div class="stat-grid compact">@foreach([['إجمالي الموظفين',$totalEmployees,'blue','people'],['سجلات معتمدة/مقفلة',$reviewedCount,'green','check2-circle'],['مسودات',$pendingReview,'orange','hourglass'],['صافي المسير',format_money($totalNet).' ج.م','purple','cash-stack'],['إجمالي التكلفة',format_money($totalCost).' ج.م','green','wallet2']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>{{ $month->format('m/Y') }}</small></div></div>@endforeach</div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>مسيرات الرواتب</h3><span>مسودة الشهر الحالي تُحسب حتى اليوم لتعرف مستحق كل موظف لحظيًا، ويُنشأ المسير ويُعتمد ويُقفل تلقائيًا في آخر يوم من الشهر دون تدخل.</span></div>@if(auth()->user()?->hasPermission('payroll.create'))<form method="POST" action="{{ route('payroll.runs.generate') }}">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}"><button class="btn btn-primary"><i class="bi bi-play-fill"></i> إنشاء/تحديث مسودة الشهر</button></form>@endif</div>
@if($errors->has('payroll'))<div class="alert alert-danger m-3">{{ $errors->first('payroll') }}</div>@endif
<table class="table align-middle"><thead><tr><th>الشهر</th><th>الحالة</th><th>السجلات</th><th>الإقفال التلقائي</th><th>الإجراء</th></tr></thead><tbody>
@forelse($runs as $run)
@php($meta = $runMeta[$run->id] ?? ['month_ended' => false, 'period_ended' => false, 'closes_on' => ''])
@php($report = $runReports[$run->id] ?? null)
@php($statusLabel = ['Draft' => 'مسودة (حتى اليوم)', 'Generating' => 'جارٍ الإنشاء', 'Approved' => 'معتمد', 'Locked' => 'مقفول'][$run->status] ?? $run->status)
<tr><td>{{ $run->payroll_month?->format('m/Y') }}@if($run->is_transition) <span class="badge text-bg-info">انتقالي</span>@endif @if($run->period_start && $run->period_end)<small class="d-block text-muted">{{ $run->period_start->format('d/m/Y') }} - {{ $run->period_end->format('d/m/Y') }}</small>@endif</td><td><span class="badge {{ $run->status === 'Locked' ? 'text-bg-dark' : ($run->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $statusLabel }}</span></td><td>{{ $run->records_count }}</td><td>@if($run->status === 'Locked')تم {{ $run->locked_at?->format('d/m/Y H:i') }}@else{{ $meta['closes_on'] }}@endif</td>
<td>
@if($run->status === 'Draft' && auth()->user()?->hasPermission('payroll.approve'))
@if($meta['period_ended'])
<form class="d-inline" method="POST" action="{{ route('payroll.runs.approve',$run) }}">@csrf
@if($report && count($report['skipped']))<label class="d-block small mb-1"><input type="checkbox" name="confirm_skipped" value="1" required> أؤكد الاعتماد رغم استبعاد {{ count($report['skipped']) }} موظف</label>@endif
<button class="btn btn-sm btn-outline-success">اعتماد</button></form>
@else<small class="text-muted">يُعتمد ويُقفل تلقائيًا يوم {{ $meta['closes_on'] }}</small>@endif
@elseif($run->status === 'Approved' && !$meta['period_ended'])
<small class="text-muted">يُقفل تلقائيًا يوم {{ $meta['closes_on'] }}</small>
@endif
@if(in_array($run->status, ['Draft', 'Approved'], true) && $meta['period_ended'] && auth()->user()?->hasPermission('payroll.lock'))
<button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#closePeriod{{ $run->id }}">قفل نهائي</button>
<div class="modal fade" id="closePeriod{{ $run->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('payroll.close-period') }}">@csrf<input type="hidden" name="payroll_run_id" value="{{ $run->id }}"><div class="modal-header"><h5>قفل نهائي لمسير {{ $run->payroll_month?->format('m/Y') }}@if($run->is_transition) (انتقالي)@endif</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border small">القفل النهائي يثبّت حضور الفترة والملخص المحفوظ والمسير وسجلاته معًا، ومش هيقبلوا تعديل بعدها. لو المسير لسه مسودة هيُعتمد تلقائيًا قبل القفل.</div><label class="d-flex gap-2 align-items-start"><input type="checkbox" name="confirm" value="1" required class="form-check-input mt-1"><span>أؤكد إن أرقام الفترة هتتثبت نهائيًا ومش هتقبل تعديل.</span></label></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-dark">قفل نهائي</button></div></form></div></div></div>
@endif
@if(auth()->user()?->hasPermission('payroll.delete'))<form class="d-inline ms-1" method="POST" action="{{ route('payroll.runs.destroy',$run) }}" onsubmit="return confirm('سيتم حذف المسير وسجلاته نهائيًا ولا يمكن التراجع. هل تريد المتابعة؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">حذف</button></form>@endif
</td></tr>
@if($report && count($report['skipped']))
<tr class="table-warning"><td colspan="5"><strong>موظفون مستحقون لم يُنشأ لهم سجل ({{ count($report['skipped']) }} من {{ $report['eligible'] }})</strong><ul class="mb-0 small">@foreach(array_slice($report['skipped'], 0, 50) as $item)<li>{{ $item['name'] }} @if($item['code'])(#{{ $item['code'] }})@endif — {{ $item['reason'] }}</li>@endforeach</ul>@if(count($report['skipped']) > 50)<small>… و{{ count($report['skipped']) - 50 }} آخرين</small>@endif</td></tr>
@elseif($run->status !== 'Draft' && !empty($run->skipped_report['skipped']))
<tr class="table-light"><td colspan="5"><small class="text-muted">عند الاعتماد كان {{ count($run->skipped_report['skipped']) }} موظف بدون سجل راتب: @foreach(array_slice($run->skipped_report['skipped'], 0, 10) as $item){{ $item['name'] }}@if(!$loop->last)، @endif @endforeach</small></td></tr>
@endif
@empty<tr><td colspan="5" class="text-center py-4">لا يوجد مسير بعد. يلزم وجود هيكل راتب نشط للموظفين قبل الإنشاء.</td></tr>@endforelse</tbody></table></div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>تفاصيل مسير {{ $month->translatedFormat('F Y') }}</h3><span>الغياب والجزاءات تأتي من السجلات المعتمدة؛ أضف البنود المتغيرة قبل اعتماد المسير.</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الموظف</th><th>هيكل الراتب</th><th>الراتب الإجمالي</th><th>إضافي</th><th>غياب</th><th>جزاءات</th><th>تأمين الموظف</th><th>الضريبة</th><th>الصافي</th><th>تكلفة الشركة</th><th>الحالة</th><th></th></tr></thead><tbody>@forelse($records as $record)<tr><td><a class="employee-link fw-bold" href="{{ route('employees.show',$record->employee) }}">{{ $record->employee?->full_name }}</a><small class="d-block text-muted">{{ $record->employee?->department?->name }}</small></td><td>@if($record->salary)<a href="{{ route('salaries.index',['employee_id'=>$record->employee_id]) }}" class="small fw-semibold">هيكل #{{ $record->salary_id }}</a><small class="d-block text-muted">ساري من {{ $record->salary->effective_from?->format('d/m/Y') ?? '—' }}</small><small class="d-block text-muted">المتفق عليه: {{ format_money($record->agreed_net_salary) }}</small>@else—@endif</td><td>{{ format_money($record->gross_salary) }}</td><td>{{ format_money($record->overtime_amount) }}</td><td>{{ format_money($record->absence_deduction) }} <small class="text-muted">({{ format_number($record->absence_days) }} يوم)</small></td><td>{{ format_money($record->penalties) }} <small class="text-muted">({{ format_number($record->penalty_days) }} يوم)</small></td><td>{{ format_money($record->employee_insurance) }}</td><td>{{ format_money($record->income_tax) }}</td><td class="fw-bold text-success">{{ format_money($record->net_salary) }}</td><td>{{ format_money($record->employee_cost) }}</td><td><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></td><td>@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inputs{{ $record->id }}">مدخلات</button>@endif</td></tr>@empty<tr><td colspan="12" class="text-center py-5">لا توجد سجلات لهذا الشهر.</td></tr>@endforelse</tbody></table></div></div>

<div class="modal fade" id="payrollCardDetails" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="payrollCardTitle">تفاصيل البطاقة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="payrollCardBody" class="details-grid"></div></div></div></div></div>
<script>
document.querySelectorAll('.stat-grid .stat-card').forEach((card,index)=>{card.classList.add('clickable-card');card.setAttribute('role','button');card.addEventListener('click',()=>{const data=@json($cardDetails);const keys=['employees','reviewed','drafts','net','cost'];const labels=['الموظفون','السجلات المعتمدة أو المصروفة','المسودات','تفاصيل صافي المسير','تفاصيل إجمالي التكلفة'];const list=data[keys[index]]||[];document.getElementById('payrollCardTitle').textContent=labels[index]||'تفاصيل البطاقة';document.getElementById('payrollCardBody').innerHTML=list.length?list.map(item=>{const name=item.full_name||item.employee?.full_name||'—';const value=item.net_salary!==undefined?Number(item.net_salary).toLocaleString('en-US')+' ج.م':(item.employee_cost!==undefined?Number(item.employee_cost).toLocaleString('en-US')+' ج.م':(item.status||item.employee_code||''));return '<div><label>'+name+'</label><b>'+value+'</b></div>'}).join(''):'<div class="text-muted">لا توجد بيانات.</div>';bootstrap.Modal.getOrCreateInstance(document.getElementById('payrollCardDetails')).show()})});
</script>
<script>
document.querySelectorAll('.stat-grid .stat-card').forEach(card=>card.classList.add('payroll-kpi-card'));
const payrollFilter=document.querySelector('form input[name="month"]')?.closest('.panel');const payrollPanels=document.querySelectorAll('.panel.table-panel');const payrollDetailsHead=payrollPanels[1]?.querySelector('.panel-head');if(payrollFilter&&payrollDetailsHead){payrollFilter.classList.add('payroll-inline-filter');payrollDetailsHead.append(payrollFilter);}
</script>
<div class="payroll-mobile-cards" aria-label="تفاصيل مسير الموظفين على الهاتف">
@forelse($records as $record)
  <button type="button" class="payroll-mobile-card" data-bs-toggle="modal" data-bs-target="#payrollRecordDetails{{ $record->id }}">
    <span class="payroll-mobile-card-head"><span><strong>{{ $record->employee?->full_name }}</strong><small>{{ $record->employee?->department?->name ?? '—' }}</small></span><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></span>
    <span class="payroll-mobile-card-values"><span><small>الصافي</small><b class="text-success">{{ format_money($record->net_salary) }}</b></span><span><small>الإجمالي</small><b>{{ format_money($record->gross_salary) }}</b></span><span><small>التكلفة</small><b>{{ format_money($record->employee_cost) }}</b></span></span>
    <span class="payroll-mobile-card-footer"><span>هيكل #{{ $record->salary_id ?? '—' }}</span><i class="bi bi-chevron-left"></i></span>
  </button>
@empty
  <div class="empty-page"><div class="empty-icon"><i class="bi bi-cash-stack"></i></div><p>لا توجد سجلات لهذا الشهر.</p></div>
@endforelse
</div>

@foreach($records as $record)
<div class="modal fade payroll-record-details-modal" id="payrollRecordDetails{{ $record->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title">تفاصيل راتب {{ $record->employee?->full_name }}</h5><small class="text-muted">{{ $month->translatedFormat('F Y') }}</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="payroll-record-summary"><span>الراتب الصافي</span><strong>{{ format_money($record->net_salary) }} ج.م</strong></div><div class="payroll-record-details-grid">
      <div><span>هيكل الراتب</span><b>{{ $record->salary_id ? '#'.$record->salary_id : '—' }}</b></div><div><span>حالة المسير</span><b>{{ $record->status }}</b></div><div><span>الراتب الإجمالي</span><b>{{ format_money($record->gross_salary) }} ج.م</b></div><div><span>الإضافي</span><b>{{ format_money($record->overtime_amount) }} ج.م</b></div><div><span>خصم الغياب</span><b>{{ format_money($record->absence_deduction) }} ج.م ({{ format_number($record->absence_days) }} يوم)</b></div><div><span>الجزاءات</span><b>{{ format_money($record->penalties) }} ج.م ({{ format_number($record->penalty_days) }} يوم)</b></div><div><span>تأمين الموظف</span><b>{{ format_money($record->employee_insurance) }} ج.م</b></div><div><span>الضريبة</span><b>{{ format_money($record->income_tax) }} ج.م</b></div><div><span>تكلفة الشركة</span><b>{{ format_money($record->employee_cost) }} ج.م</b></div><div><span>تاريخ سريان الهيكل</span><b>{{ $record->salary?->effective_from?->format('d/m/Y') ?? '—' }}</b></div>
    </div></div>
    <div class="modal-footer"><a class="btn btn-light" href="{{ route('employees.show', $record->employee) }}">فتح ملف الموظف</a>@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))<button class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#inputs{{ $record->id }}">تعديل المدخلات</button>@endif</div>
  </div></div>
</div>
@endforeach

@foreach($records as $record)
@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))
<div class="modal fade" id="inputs{{ $record->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{ route('payroll.inputs.update',$record) }}">@csrf @method('PUT')<div class="modal-header"><h5>مدخلات راتب {{ $record->employee?->full_name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border small">الغياب: {{ format_number($record->absence_days) }} يوم · الجزاءات: {{ format_number($record->penalty_days) }} يوم. هذه القيم مصدرها السجلات المعتمدة ولا تعدل من هنا.</div><div class="row g-3"><div class="col-6"><label class="form-label">ساعات إضافي نهاري</label><input type="number" min="0" step="0.01" name="ot_day_hours" value="{{ format_number($record->ot_day_hours) }}" class="form-control"></div><div class="col-6"><label class="form-label">ساعات إضافي ليلي</label><input type="number" min="0" step="0.01" name="ot_night_hours" value="{{ format_number($record->ot_night_hours) }}" class="form-control"></div><div class="col-6"><label class="form-label">مكافأة</label><input type="number" min="0" step="0.01" name="bonus" value="{{ format_number($record->bonus) }}" class="form-control"></div><div class="col-6"><label class="form-label">سلف</label><input type="number" min="0" step="0.01" name="loans" value="{{ format_number($record->loans) }}" class="form-control"></div><div class="col-6"><label class="form-label">استحقاقات أخرى</label><input type="number" min="0" step="0.01" name="other_earnings" value="{{ format_number($record->other_earnings) }}" class="form-control"></div><div class="col-6"><label class="form-label">استقطاعات أخرى</label><input type="number" min="0" step="0.01" name="other_deductions" value="{{ format_number($record->other_deductions) }}" class="form-control"></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ وإعادة الحساب</button></div></form></div></div></div>
@endif
@endforeach
@push('scripts')
<script>
document.querySelectorAll('form[action*="/payroll/"]').forEach((form) => {
  if (!form.action.includes('/inputs')) return;
  const modal = form.closest('.modal');
  if (!modal) return;
  const note = modal.querySelector('.alert');
  if (note) note.innerHTML += ' <br><strong>وحدة القياس:</strong> الإضافي بالساعات، والغياب والجزاءات بالأيام. القيم المعروضة مأخوذة من السجلات المعتمدة ولا تُكتب يدويًا من هنا.';
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
    if (!response.ok) throw new Error(data.message || 'تعذر إنشاء المسير.');
    return data;
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    modalElement.querySelector('.modal-content').classList.remove('border-danger');
    icon.className = 'bi bi-arrow-repeat fs-1 text-primary';
    setProgress(0, 'جاري تجهيز قائمة الموظفين...');
    modal.show();
    try {
      const month = form.querySelector('[name="month"]')?.value;
      const started = await requestJson('{{ route('payroll.runs.generate.start') }}', { month });
      const total = Number(started.total || 0);
      let offset = 0;
      if (!total) {
        setProgress(100, 'لا توجد سجلات موظفين لإنشائها.');
      }
      while (offset < total || total === 0) {
        const result = await requestJson('{{ route('payroll.runs.generate.batch') }}', { month: started.month, offset, batch_size: started.batch_size || 25 });
        offset = Number(result.processed || total);
        setProgress(result.percent, `تمت معالجة ${offset} من ${total} موظف`);
        if (result.complete || total === 0) break;
      }
      setProgress(100, 'اكتمل إنشاء مسودة المسير بنجاح.');
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
    if ((advanceSchedules[recordId] || []).length) { loans.readOnly = true; loans.title = 'يتم تحديد السلفة من نسبة الخصم اليدوية أو الحساب التلقائي'; }
    const wrapper = document.createElement('div');
    wrapper.className = 'col-6';
    wrapper.innerHTML = '<label class="form-label">نسبة خصم السلفة (%)</label><input type="number" name="advance_rate" min="0" max="100" step="0.01" class="form-control" placeholder="تلقائي حسب 10%"><small class="text-muted">يُحسب المبلغ من الراتب الإجمالي ولا يتجاوز الرصيد.</small>';
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
      item.innerHTML = `<span>السلفة المصروفة</span><b>${amount.toLocaleString('en-US', {maximumFractionDigits: 0})} ج.م</b>`;
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
  <div class="text-muted small">عرض {{ $records->firstItem() ?? 0 }} إلى {{ $records->lastItem() ?? 0 }} من {{ $records->total() }} موظف</div>
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
      <h5 class="mb-2">جاري تحميل المسير</h5>
      <p class="text-muted small mb-3" id="payrollGenerationMessage">جاري تجهيز بيانات الموظفين...</p>
      <div class="d-flex justify-content-between small mb-1"><span>التقدم</span><strong id="payrollGenerationPercent">0%</strong></div>
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
    serialHeader.textContent = 'م';
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
    const grossIndex = headers.findIndex((cell) => cell.textContent.trim() === 'الراتب الإجمالي');
    if (grossIndex >= 0) {
      const monthlyHeader = document.createElement('th');
      monthlyHeader.dataset.monthlyTotalColumn = 'true';
      monthlyHeader.textContent = 'الإجمالي الشهري';
      headers[grossIndex].after(monthlyHeader);
      [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, rowIndex) => {
        const cell = document.createElement('td');
        cell.textContent = Number(payrollDetailsData[rowIndex]?.monthly_total || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
        row.cells[grossIndex]?.after(cell);
      });
    }
  }
  if (false && payrollDetailsTable && !payrollDetailsTable.querySelector('th[data-advance-column]')) {
    const header = [...payrollDetailsTable.tHead.rows[0].cells].find((cell) => cell.textContent.trim() === 'إضافي');
    const targetHeader = header || payrollDetailsTable.tHead.rows[0].cells[4];
    const index = targetHeader ? [...payrollDetailsTable.tHead.rows[0].cells].indexOf(targetHeader) : -1;
    if (index >= 0) {
      const advanceHeader = document.createElement('th'); advanceHeader.dataset.advanceColumn = 'true'; advanceHeader.textContent = 'السلف'; targetHeader.after(advanceHeader);
      [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, rowIndex) => { const cell = document.createElement('td'); cell.textContent = payrollDetailsData[rowIndex] ? Number(payrollDetailsData[rowIndex].loans || 0).toLocaleString('en-US', {maximumFractionDigits: 0}) : '0'; row.cells[index]?.after(cell); });
    }
  }
  const payrollMoney = (value) => Number(value || 0).toLocaleString('en-US', {maximumFractionDigits: 0}) + ' ج.م';
  const payrollMoneyDecimal = (value) => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
  const payrollDetailsRows = payrollDetailsTable ? [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]) : [];
  const detailPanel = document.createElement('div');
  detailPanel.className = 'offcanvas offcanvas-end payroll-detail-panel';
  detailPanel.tabIndex = -1;
  detailPanel.id = 'payrollDetailPanel';
  detailPanel.innerHTML = '<div class="offcanvas-header"><div><h5 class="offcanvas-title">تفاصيل مسير الموظف</h5><small class="text-muted" data-payroll-detail-subtitle></small></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="إغلاق"></button></div><div class="offcanvas-body" data-payroll-detail-body></div>';
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
    detailPanel.querySelector('[data-payroll-detail-subtitle]').textContent = `${record.name || '—'} · كود ${record.code || '—'}`;
    detailBody.innerHTML = `<div class="payroll-detail-section"><h6><i class="bi bi-person"></i> بيانات الموظف</h6>${detailValue('القسم', record.department || '—')}${detailValue('الحالة', record.status || '—')}</div><div class="payroll-detail-section"><h6><i class="bi bi-plus-circle"></i> الاستحقاقات</h6>${detailValue('الراتب الأساسي', payrollMoney(record.basic_salary))}${detailValue('البدلات', payrollMoney(record.allowances))}${detailValue('بدل السكن', payrollMoney(record.housing_allowance))}${detailValue('بدل الانتقال', payrollMoney(record.transportation_allowance))}${detailValue('بدل الطعام', payrollMoney(record.meal_allowance))}${detailValue('بدل الملابس', payrollMoney(record.clothing_allowance))}${detailValue('الإضافي', payrollMoney(record.overtime_amount))}${detailValue('الإجمالي', payrollMoney(record.gross_salary))}</div><div class="payroll-detail-section"><h6><i class="bi bi-dash-circle"></i> الخصومات</h6>${detailValue('السلفة', payrollMoney(record.loans))}${detailValue('خصم الغياب', payrollMoney(record.absence_deduction))}${detailValue('الجزاءات', payrollMoney(record.penalties))}${detailValue('تأمين الموظف', payrollMoney(record.employee_insurance))}${detailValue('الضريبة', payrollMoney(record.income_tax))}${detailValue('صندوق الشهداء', payrollMoneyDecimal(record.martyrs_fund))}${detailValue('استقطاعات ثابتة', payrollMoney(record.fixed_deductions))}${detailValue('استقطاعات أخرى', payrollMoney(record.other_deductions))}</div><div class="payroll-detail-total"><span>الراتب الصافي</span><strong>${payrollMoney(record.net_salary)}</strong><small>تكلفة الشركة: ${payrollMoney(record.employee_cost)}</small></div>`;
    const deductionsHeading = [...detailBody.querySelectorAll('h6')].find((heading) => heading.textContent.trim() === 'الخصومات');
    if (deductionsHeading) deductionsHeading.childNodes[deductionsHeading.childNodes.length - 1].textContent = ' الاستقطاعات';
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
        if (item.querySelector('span')?.textContent?.includes('الإضافي')) item.remove();
        const amount = item.querySelector('b')?.textContent?.trim() || '';
        if (/^0(?:[.,]0+)?(?:\s|$)/.test(amount)) item.remove();
      });
      if (Number(record.day_overtime_amount || 0) > 0) earningsSection.insertAdjacentHTML('beforeend', detailValue('الإضافي النهاري', payrollMoney(record.day_overtime_amount)));
      if (Number(record.night_overtime_amount || 0) > 0) earningsSection.insertAdjacentHTML('beforeend', detailValue('الإضافي الليلي', payrollMoney(record.night_overtime_amount)));
      const dayOvertime = Number(record.day_overtime_amount || 0);
      const nightOvertime = Number(record.night_overtime_amount || 0);
      const totalOvertime = Number(record.overtime_amount || 0);
      if (dayOvertime <= 0 && nightOvertime <= 0 && totalOvertime > 0) {
        earningsSection.insertAdjacentHTML('beforeend', detailValue('الإضافي', payrollMoney(totalOvertime)));
      }
      detailBody.querySelectorAll('.payroll-detail-section:nth-of-type(3) .payroll-detail-item').forEach((item) => {
        const amount = item.querySelector('b')?.textContent?.trim() || '';
        if (/^0(?:[.,]0+)?(?:\s|$)/.test(amount)) item.remove();
      });
      const addAdditional = (label, value) => { if (Number(value || 0) > 0) additionalItems.push([label, Number(value)]); };
      addAdditional('المكافأة', record.bonus);
      addAdditional('الحوافز', record.incentives);
      addAdditional('العمولة', record.commission);
      addAdditional('نصيب الأرباح', record.profit_share);
      addAdditional('استحقاقات أخرى', record.other_earnings);
    }
    if (earningsSection) {
      if (Number(record.advance_earnings || 0) > 0) additionalItems.push(['السلفة المصروفة', Number(record.advance_earnings)]);
      if (additionalItems.length) {
        const additionalTotal = additionalItems.reduce((sum, [, value]) => sum + value, 0);
        const additionalGroup = document.createElement('div');
        additionalGroup.className = 'payroll-additional-group';
        additionalGroup.innerHTML = `${detailValue('إضافي', payrollMoney(additionalTotal))}<div class="payroll-additional-items" hidden>${additionalItems.map(([label, value]) => detailValue(label, payrollMoney(value))).join('')}</div>`;
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
    if (earningsSection) earningsSection.insertAdjacentHTML('beforeend', detailValue('الإجمالي الشهري', payrollMoney(record.monthly_total ?? record.gross_salary)));
    bootstrap.Offcanvas.getOrCreateInstance(detailPanel).show();
  };
  payrollDetailsRows.forEach((row, index) => {
    const record = payrollDetailsData[index];
    if (!record) return;
    const overtimeIndex = [...(payrollDetailsTable?.tHead?.rows[0]?.cells || [])]
      .findIndex((cell) => cell.textContent.trim() === 'إضافي');
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
      link.addEventListener('click', (event) => { event.preventDefault(); openSalaryDetails(record); });
    });
  });
  const salaryModal = document.createElement('div');
  salaryModal.className = 'modal fade payroll-salary-modal';
  salaryModal.id = 'payrollSalaryDetails';
  salaryModal.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">تفاصيل هيكل الراتب</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" data-salary-body></div></div></div>';
  document.body.append(salaryModal);
  const openSalaryDetails = (record) => {
    const body = salaryModal.querySelector('[data-salary-body]');
    const value = (label, amount) => detailValue(label, payrollMoney(amount || 0));
    const decimalValue = (label, amount) => detailValue(label, payrollMoneyDecimal(amount || 0));
    const section = (title, icon, content) => `<div class="payroll-salary-section"><h6><i class="bi bi-${icon}"></i>${title}</h6><div class="payroll-salary-grid">${content}</div></div>`;
    body.innerHTML = `<div class="payroll-salary-heading"><strong>${record.name || '—'}</strong><span>هيكل #${record.salary_id || '—'} · ساري من ${record.effective_from || '—'}</span></div>
      ${section('الراتب والبدلات','cash-stack', value('الراتب الصافي',record.structure_net_salary ?? record.net_salary)+value('الراتب الإجمالي',record.structure_gross_salary ?? record.gross_salary)+value('الأجر الأساسي',record.structure_basic_salary ?? record.basic_salary)+value(`إجمالي البدلات (${record.structure_allowance_limit_pct || 0}%)`,record.structure_allowance_limit)+value('بدل الانتقال',record.structure_transportation_allowance)+value('بدل الوجبة',record.structure_meal_allowance)+value('بدل السكن',record.structure_housing_allowance)+value('بدل الملابس',record.structure_clothing_allowance)+value('المتبقي من البدلات',record.structure_allowance_remaining))}
      ${section('التأمينات','shield-check', value('الأجر التأميني',record.structure_no_insurance ? 0 : record.structure_applied_insurance_wage)+value('حصة الموظف',record.employee_insurance)+value('حصة صاحب العمل',record.structure_employer_insurance)+detailValue('حالة التأمين',record.structure_no_insurance ? 'بدون تأمينات' : 'مؤمّن'))}
      ${section('الضرائب والاستقطاعات','receipt', value('الإعفاء الشخصي الشهري',record.structure_personal_exemption)+value('الوعاء الضريبي السنوي',record.structure_taxable_annual)+value('ضريبة الدخل الشهرية',record.income_tax)+decimalValue('صندوق الشهداء',record.structure_martyrs_fund)+value('استقطاعات ثابتة',record.structure_fixed_deductions)+value('إجمالي الاستقطاعات',record.structure_total_deductions)+value('صافي الراتب النهائي',record.structure_net_salary ?? record.net_salary))}
      <div class="payroll-salary-section"><h6><i class="bi bi-building"></i>تكلفة الشركة</h6><div class="payroll-salary-grid">${value('حصة صاحب العمل',record.structure_employer_insurance)}${value('إجمالي تكلفة الموظف',record.structure_employee_cost ?? record.employee_cost)}</div></div>
      ${record.structure_notes ? `<div class="alert alert-light border mt-3 mb-0"><strong>ملاحظات:</strong> ${record.structure_notes}</div>` : ''}`;
    bootstrap.Modal.getOrCreateInstance(salaryModal).show();
  };
  if (payrollDetailsTable) {
    const employeeCodes = @json($records->getCollection()->map(fn ($record) => $record->employee?->employee_code)->values());
    const netSalaries = @json($records->getCollection()->map(fn ($record) => (float) $record->net_salary)->values());
    [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, index) => {
      const employeeCell = row.cells[1];
      if (employeeCell && employeeCodes[index] && !employeeCell.querySelector('[data-employee-code]')) {
        const code = document.createElement('small');
        code.dataset.employeeCode = 'true';
        code.className = 'd-block text-muted';
        code.textContent = `كود الموظف: ${employeeCodes[index]}`;
        employeeCell.append(code);
      }
      const salaryCell = row.cells[2];
      const netCell = row.cells[9];
      if (salaryCell && netCell) {
        const net = salaryCell.querySelector('[data-net-salary]') || document.createElement('small');
        net.dataset.netSalary = 'true';
        net.className = 'd-block text-muted';
        net.textContent = `الراتب الصافي: ${netCell.textContent.trim()}`;
        const oldAgreed = [...salaryCell.querySelectorAll('small')].find((item) => item.textContent.includes('المتفق عليه'));
        if (oldAgreed) oldAgreed.replaceWith(net); else if (!salaryCell.querySelector('[data-net-salary]')) salaryCell.append(net);
      }
    });
    document.querySelectorAll('.payroll-mobile-card').forEach((card, index) => {
      const title = card.querySelector('strong');
      if (title && employeeCodes[index] && !card.querySelector('[data-employee-code]')) {
        const code = document.createElement('small');
        code.dataset.employeeCode = 'true';
        code.textContent = `كود الموظف: ${employeeCodes[index]}`;
        title.parentElement.append(code);
      }
    });
  }
  const payrollDetailsWrap = payrollDetailsTable?.closest('.table-responsive');
  if (payrollDetailsTable && payrollDetailsWrap && !payrollDetailsWrap.querySelector('.payroll-record-filter')) {
    const filter = document.createElement('div');
    filter.className = 'payroll-record-filter d-flex align-items-center gap-2 mb-3';
    filter.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;';
    filter.innerHTML = '<label class="form-label mb-0" for="payrollRecordSearch"><i class="bi bi-search"></i> بحث في الموظفين</label><input id="payrollRecordSearch" type="search" class="form-control" placeholder="اكتب اسم الموظف أو الكود أو القسم أو الحالة" autocomplete="off"><span class="text-muted small" data-payroll-filter-count></span>';
    payrollDetailsWrap.prepend(filter);
    const input = filter.querySelector('input');
    input.value = @json(request('search', ''));
    input.style.cssText = 'max-width:360px;min-width:220px;border-radius:10px;border:1px solid #cbd5e1;padding:.55rem .75rem;';
    const count = filter.querySelector('[data-payroll-filter-count]');
    const rows = [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]);
    const empty = document.createElement('div');
    empty.className = 'text-center text-muted py-4 payroll-filter-empty';
    empty.textContent = 'لا توجد نتائج مطابقة.';
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
      count.textContent = query ? `${visible} من ${rows.length}` : `${rows.length} موظف`;
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
  const money = (value) => Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' ج.م';
  document.querySelectorAll('[id^="inputs"]').forEach((modal) => {
    const trigger = document.querySelector(`[data-bs-target="#${modal.id}"]`);
    const match = modal.id.match(/^inputs(\d+)$/);
    if (trigger && match) trigger.closest('tr')?.setAttribute('data-payroll-record-id', match[1]);
  });
  document.querySelectorAll('form[action*="/payroll/"][action$="/inputs"]').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ إعادة الحساب'; });
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
      row.children[4].textContent = Number(record.monthly_total || 0).toLocaleString('en-US', {maximumFractionDigits: 0});
      row.children[5].textContent = additional.toLocaleString('en-US', {maximumFractionDigits: 0});
      row.children[5].innerHTML = `${money(record.absence_deduction)} <small class="text-muted">(${record.absence_days || 0} يوم)</small>`;
      row.children[6].innerHTML = `${money(record.penalties)} <small class="text-muted">(${record.penalty_days || 0} يوم)</small>`;
      row.children[7].textContent = money(record.employee_insurance);
      row.children[8].textContent = money(record.income_tax);
      row.children[9].textContent = money(record.net_salary);
      row.children[10].textContent = money(record.employee_cost);
      // Column order includes the new monthly total after gross salary.
      row.children[5].textContent = additional.toLocaleString('en-US', {maximumFractionDigits: 0});
      row.children[6].innerHTML = `${money(record.absence_deduction)} <small class="text-muted">(${record.absence_days || 0} يوم)</small>`;
      row.children[7].innerHTML = `${money(record.penalties)} <small class="text-muted">(${record.penalty_days || 0} يوم)</small>`;
      row.children[8].textContent = money(record.employee_insurance);
      row.children[9].textContent = money(record.income_tax);
      row.children[10].textContent = money(record.net_salary);
      row.children[11].textContent = money(record.employee_cost);
    }
    bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
  });
</script>
@endpush
