@extends('layouts.app')
@section('title', 'الرواتب الشهرية')
@section('content')
<div class="page-head"><div><h1>دورة الرواتب</h1><p>إنشاء، مراجعة، اعتماد وقفل مسير الرواتب مع لقطات حسابية محفوظة.</p></div><div class="d-flex gap-2">@if(auth()->user()?->hasPermission('payroll.settings'))<a class="btn btn-outline-secondary" href="{{ route('payroll.settings.index') }}"><i class="bi bi-gear-fill"></i> إعدادات الحساب</a>@endif @if(auth()->user()?->hasPermission('payroll.export'))<a class="btn btn-light" target="_blank" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01')]) }}"><i class="bi bi-printer"></i> طباعة</a><a class="btn btn-light" href="{{ route('payroll.export',['month'=>$month->format('Y-m-01'),'format'=>'csv']) }}"><i class="bi bi-file-earmark-excel"></i> تصدير CSV</a>@endif</div></div>
<div class="panel mb-3"><form method="GET" class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">شهر المسير</label><input type="month" name="month" class="form-control" value="{{ $month->format('Y-m') }}"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">عرض</button></div></form></div>
<div class="stat-grid compact">@foreach([['إجمالي الموظفين',$totalEmployees,'blue','people'],['سجلات معتمدة/مقفلة',$reviewedCount,'green','check2-circle'],['مسودات',$pendingReview,'orange','hourglass'],['صافي المسير',format_money($totalNet).' ج.م','purple','cash-stack'],['إجمالي التكلفة',format_money($totalCost).' ج.م','green','wallet2']] as $stat)<div class="stat-card"><div class="stat-icon {{ $stat[2] }}"><i class="bi bi-{{ $stat[3] }}"></i></div><div><span>{{ $stat[0] }}</span><b>{{ $stat[1] }}</b><small>{{ $month->format('m/Y') }}</small></div></div>@endforeach</div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>مسيرات الرواتب</h3><span>كل انتقال حالة يتطلب صلاحية منفصلة ويُسجّل في سجل التدقيق.</span></div>@if(auth()->user()?->hasPermission('payroll.create'))<form method="POST" action="{{ route('payroll.runs.generate') }}">@csrf<input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}"><button class="btn btn-primary"><i class="bi bi-play-fill"></i> إنشاء/تحديث مسودة الشهر</button></form>@endif</div><table class="table align-middle"><thead><tr><th>الشهر</th><th>الحالة</th><th>السجلات</th><th>تم الإنشاء</th><th>الإجراء</th></tr></thead><tbody>@forelse($runs as $run)<tr><td>{{ $run->payroll_month?->format('m/Y') }}</td><td><span class="badge {{ $run->status === 'Locked' ? 'text-bg-dark' : ($run->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $run->status }}</span></td><td>{{ $run->records_count }}</td><td>{{ $run->created_at?->format('d/m/Y H:i') }}</td><td>@if($run->status === 'Draft' && auth()->user()?->hasPermission('payroll.approve'))<form class="d-inline" method="POST" action="{{ route('payroll.runs.approve',$run) }}">@csrf<button class="btn btn-sm btn-outline-success">اعتماد</button></form>@elseif($run->status === 'Approved' && auth()->user()?->hasPermission('payroll.lock'))<form class="d-inline" method="POST" action="{{ route('payroll.runs.lock',$run) }}">@csrf<button class="btn btn-sm btn-outline-dark">قفل نهائي</button></form>@else—@endif</td></tr>@empty<tr><td colspan="5" class="text-center py-4">لا يوجد مسير بعد. يلزم وجود هيكل راتب نشط للموظفين قبل الإنشاء.</td></tr>@endforelse</tbody></table></div>
<div class="panel table-panel mt-3"><div class="panel-head"><div><h3>تفاصيل مسير {{ $month->translatedFormat('F Y') }}</h3><span>الغياب والجزاءات تأتي من السجلات المعتمدة؛ أضف البنود المتغيرة قبل اعتماد المسير.</span></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الموظف</th><th>هيكل الراتب</th><th>الراتب الإجمالي</th><th>إضافي</th><th>غياب</th><th>جزاءات</th><th>تأمين الموظف</th><th>الضريبة</th><th>الصافي</th><th>تكلفة الشركة</th><th>الحالة</th><th></th></tr></thead><tbody>@forelse($records as $record)<tr><td><a class="employee-link fw-bold" href="{{ route('employees.show',$record->employee) }}">{{ $record->employee?->full_name }}</a><small class="d-block text-muted">{{ $record->employee?->department?->name }}</small></td><td>@if($record->salary)<a href="{{ route('salaries.index',['employee_id'=>$record->employee_id]) }}" class="small fw-semibold">هيكل #{{ $record->salary_id }}</a><small class="d-block text-muted">ساري من {{ $record->salary->effective_from?->format('d/m/Y') ?? '—' }}</small><small class="d-block text-muted">المتفق عليه: {{ format_money($record->agreed_net_salary) }}</small>@else—@endif</td><td>{{ format_money($record->gross_salary) }}</td><td>{{ format_money($record->overtime_amount) }}</td><td>{{ format_money($record->absence_deduction) }} <small class="text-muted">({{ format_number($record->absence_days) }} يوم)</small></td><td>{{ format_money($record->penalties) }} <small class="text-muted">({{ format_number($record->penalty_days) }} يوم)</small></td><td>{{ format_money($record->employee_insurance) }}</td><td>{{ format_money($record->income_tax) }}</td><td class="fw-bold text-success">{{ format_money($record->net_salary) }}</td><td>{{ format_money($record->employee_cost) }}</td><td><span class="badge {{ $record->is_locked ? 'text-bg-dark' : ($record->status === 'Approved' ? 'text-bg-success' : 'text-bg-warning') }}">{{ $record->status }}</span></td><td>@if(in_array($record->status,['Draft','قيد الإعداد'],true) && !$record->is_locked && auth()->user()?->hasPermission('payroll.edit'))<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inputs{{ $record->id }}">مدخلات</button>@endif</td></tr>@empty<tr><td colspan="12" class="text-center py-5">لا توجد سجلات لهذا الشهر.</td></tr>@endforelse</tbody></table></div></div>

<div class="modal fade" id="payrollCardDetails" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="payrollCardTitle">تفاصيل البطاقة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="payrollCardBody" class="details-grid"></div></div></div></div></div>
<script>
document.querySelectorAll('.stat-grid .stat-card').forEach((card,index)=>{card.classList.add('clickable-card');card.setAttribute('role','button');card.addEventListener('click',()=>{const data=@json($cardDetails);const keys=['employees','reviewed','drafts','net','cost'];const labels=['الموظفون','السجلات المعتمدة أو المصروفة','المسودات','تفاصيل صافي المسير','تفاصيل إجمالي التكلفة'];const list=data[keys[index]]||[];document.getElementById('payrollCardTitle').textContent=labels[index]||'تفاصيل البطاقة';document.getElementById('payrollCardBody').innerHTML=list.length?list.map(item=>{const name=item.full_name||item.employee?.full_name||'—';const value=item.net_salary!==undefined?Number(item.net_salary).toLocaleString('ar-EG')+' ج.م':(item.employee_cost!==undefined?Number(item.employee_cost).toLocaleString('ar-EG')+' ج.م':(item.status||item.employee_code||''));return '<div><label>'+name+'</label><b>'+value+'</b></div>'}).join(''):'<div class="text-muted">لا توجد بيانات.</div>';bootstrap.Modal.getOrCreateInstance(document.getElementById('payrollCardDetails')).show()})});
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
@if(auth()->user()?->hasPermission('payroll.create'))
document.querySelectorAll('form[action*="/payroll/runs/"][action$="/approve"], form[action*="/payroll/runs/"][action$="/lock"]').forEach((actionForm) => {
  const runUrl = actionForm.action.replace(/\/(approve|lock)$/, '');
  const deleteForm = document.createElement('form');
  deleteForm.method = 'POST';
  deleteForm.action = runUrl;
  deleteForm.className = 'd-inline ms-1';
  deleteForm.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="DELETE"><button type="submit" class="btn btn-sm btn-outline-danger" title="حذف المسير"><i class="bi bi-trash"></i> حذف</button>`;
  deleteForm.onsubmit = () => confirm('سيتم حذف المسير وسجلات الموظفين التابعة له. لا يمكن حذف المسير المقفول أو المصروف. هل تريد المتابعة؟');
  actionForm.after(deleteForm);
});
@endif
</script>
@endpush

@push('scripts')
<script>
(() => {
  const advanceRates = @json($records->mapWithKeys(fn ($record) => [$record->id => data_get($record->calculation_snapshot, 'advance_override_rate')]));
  const advanceSchedules = @json($records->mapWithKeys(fn ($record) => [$record->id => data_get($record->calculation_snapshot, 'advance_details', [])]));
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
  const payrollDetailsData = @json($payrollDetails);
  if (payrollDetailsTable && !payrollDetailsTable.querySelector('th[data-advance-column]')) {
    const header = [...payrollDetailsTable.tHead.rows[0].cells].find((cell) => cell.textContent.trim() === 'إضافي');
    const index = header ? [...payrollDetailsTable.tHead.rows[0].cells].indexOf(header) : -1;
    if (index >= 0) {
      const advanceHeader = document.createElement('th'); advanceHeader.dataset.advanceColumn = 'true'; advanceHeader.textContent = 'السلف'; header.after(advanceHeader);
      [...payrollDetailsTable.tBodies].flatMap((body) => [...body.rows]).forEach((row, rowIndex) => { const cell = document.createElement('td'); cell.textContent = payrollDetailsData[rowIndex] ? Number(payrollDetailsData[rowIndex].loans || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2}) : '0.00'; row.cells[index]?.after(cell); });
    }
  }
  const payrollMoney = (value) => Number(value || 0).toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م';
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
  document.head.append(payrollUiStyle);
  const detailValue = (label, value) => `<div class="payroll-detail-item"><span>${label}</span><b>${value}</b></div>`;
  const openPayrollDetails = (record) => {
    if (!record || !detailBody) return;
    detailPanel.querySelector('[data-payroll-detail-subtitle]').textContent = `${record.name || '—'} · كود ${record.code || '—'}`;
    detailBody.innerHTML = `<div class="payroll-detail-section"><h6><i class="bi bi-person"></i> بيانات الموظف</h6>${detailValue('القسم', record.department || '—')}${detailValue('الحالة', record.status || '—')}</div><div class="payroll-detail-section"><h6><i class="bi bi-plus-circle"></i> الاستحقاقات</h6>${detailValue('الراتب الأساسي', payrollMoney(record.basic_salary))}${detailValue('البدلات', payrollMoney(record.allowances))}${detailValue('بدل السكن', payrollMoney(record.housing_allowance))}${detailValue('بدل الانتقال', payrollMoney(record.transportation_allowance))}${detailValue('بدل الطعام', payrollMoney(record.meal_allowance))}${detailValue('بدل الملابس', payrollMoney(record.clothing_allowance))}${detailValue('الإضافي', payrollMoney(record.overtime_amount))}${detailValue('الإجمالي', payrollMoney(record.gross_salary))}</div><div class="payroll-detail-section"><h6><i class="bi bi-dash-circle"></i> الخصومات</h6>${detailValue('السلفة', payrollMoney(record.loans))}${detailValue('خصم الغياب', payrollMoney(record.absence_deduction))}${detailValue('الجزاءات', payrollMoney(record.penalties))}${detailValue('تأمين الموظف', payrollMoney(record.employee_insurance))}${detailValue('الضريبة', payrollMoney(record.income_tax))}${detailValue('صندوق الشهداء', payrollMoney(record.martyrs_fund))}${detailValue('استقطاعات ثابتة', payrollMoney(record.fixed_deductions))}${detailValue('استقطاعات أخرى', payrollMoney(record.other_deductions))}</div><div class="payroll-detail-total"><span>الراتب الصافي</span><strong>${payrollMoney(record.net_salary)}</strong><small>تكلفة الشركة: ${payrollMoney(record.employee_cost)}</small></div>`;
    bootstrap.Offcanvas.getOrCreateInstance(detailPanel).show();
  };
  payrollDetailsRows.forEach((row, index) => {
    const record = payrollDetailsData[index];
    if (!record) return;
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
    body.innerHTML = `<div class="payroll-salary-heading"><strong>${record.name || '—'}</strong><span>هيكل #${record.salary_id || '—'} · ساري من ${record.effective_from || '—'}</span></div><div class="payroll-salary-grid">${detailValue('الراتب الأساسي', payrollMoney(record.basic_salary))}${detailValue('البدلات', payrollMoney(record.allowances))}${detailValue('بدل السكن', payrollMoney(record.housing_allowance))}${detailValue('بدل الانتقال', payrollMoney(record.transportation_allowance))}${detailValue('بدل الطعام', payrollMoney(record.meal_allowance))}${detailValue('بدل الملابس', payrollMoney(record.clothing_allowance))}${detailValue('الإجمالي المعتمد', payrollMoney(record.gross_salary))}${detailValue('الراتب الصافي', payrollMoney(record.net_salary))}</div>`;
    bootstrap.Modal.getOrCreateInstance(salaryModal).show();
  };
  if (payrollDetailsTable) {
    const employeeCodes = @json($records->map(fn ($record) => $record->employee?->employee_code)->values());
    const netSalaries = @json($records->map(fn ($record) => (float) $record->net_salary)->values());
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
    input.addEventListener('input', applyPayrollFilter);
    applyPayrollFilter();
  }
  // Payroll inputs are the only payroll action moved in this stage. Generate,
  // approve, lock, and pay remain full server transitions by design.
  const money = (value) => Number(value || 0).toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
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
    const row = form.closest('.modal')?.previousElementSibling?.closest('tr') || document.querySelector(`tr[data-payroll-record-id="${record?.id}"]`);
    if (row && record) {
      row.children[3].textContent = money(record.overtime_amount);
      row.children[4].innerHTML = `${money(record.absence_deduction)} <small class="text-muted">(${record.absence_days || 0} يوم)</small>`;
      row.children[5].innerHTML = `${money(record.penalties)} <small class="text-muted">(${record.penalty_days || 0} يوم)</small>`;
      row.children[6].textContent = money(record.employee_insurance);
      row.children[7].textContent = money(record.income_tax);
      row.children[8].textContent = money(record.net_salary);
      row.children[9].textContent = money(record.employee_cost);
    }
    bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
  });
</script>
@endpush
