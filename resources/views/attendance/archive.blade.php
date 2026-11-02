@extends('layouts.app')
@section('title','الشهور السابقة')
@section('content')
<div class="page-head"><div><h1>الحضور والانصراف</h1><p>سجلات محفوظة للفترات المنتهية. لا تتغير بعد حفظها.</p></div></div>
<nav class="attendance-tabs" aria-label="أقسام الحضور"><a class="attendance-tab" href="{{ route('attendance.index') }}"><i class="bi bi-calendar-check"></i> الفترة الحالية</a><a class="attendance-tab active" href="{{ route('attendance.archive.index') }}"><i class="bi bi-archive"></i> الشهور السابقة</a></nav>
<div class="panel table-panel archive-list"><div class="panel-head"><div><h3>الشهور السابقة</h3><span>كل سجل يحفظ الحضور من أول يوم لآخر يوم في الفترة وقت انتهائها.</span></div></div>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>الفترة</th><th>عدد السجلات</th><th>عدد الموظفين</th><th>تاريخ الحفظ</th><th>الإجراء</th></tr></thead><tbody>
@forelse($archives as $item)
<tr @class(['table-active' => $archive && $archive->id === $item->id])><td data-label="الفترة">{{ $item->period_start->format('d/m/Y') }} - {{ $item->period_end->format('d/m/Y') }}</td><td data-label="عدد السجلات">{{ $item->record_count }}</td><td data-label="عدد الموظفين">{{ $item->employee_count }}</td><td data-label="تاريخ الحفظ">{{ $item->captured_at?->format('d/m/Y H:i') }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('attendance.archive.show', $item) }}"><i class="bi bi-eye"></i> فتح</a> <a class="btn btn-sm btn-outline-success" href="{{ route('attendance.archive.export', $item) }}"><i class="bi bi-download"></i> تصدير</a></td></tr>
@empty
<tr><td colspan="5" class="text-center py-4">لا توجد فترات محفوظة بعد. تُحفظ الفترة تلقائيًا بعد انتهاء آخر يوم فيها.</td></tr>
@endforelse
</tbody></table></div></div>
@if($archive)
<div class="panel table-panel mt-3 archive-detail"><div class="panel-head"><div><h3>حضور الفترة {{ $archive->period_start->format('d/m/Y') }} - {{ $archive->period_end->format('d/m/Y') }}</h3><span>{{ $archive->record_count }} سجل</span></div><a class="btn btn-success" href="{{ route('attendance.archive.export', $archive) }}"><i class="bi bi-download"></i> تصدير</a></div>
<div class="table-responsive"><table class="table align-middle archive-table"><thead><tr><th>التاريخ</th><th>الموظف</th><th>القسم</th><th>الحضور</th><th>الانصراف</th><th>التأخير</th><th>الانصراف المبكر</th><th>إضافي نهاري</th><th>إضافي ليلي</th><th>الحالة</th></tr></thead><tbody>
@php($hm = fn ($m) => sprintf('%02d:%02d', intdiv((int) $m, 60), ((int) $m) % 60))
@forelse($rows as $row)
<tr><td data-label="التاريخ">{{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d/m/Y') : '' }}</td><td data-label="الموظف"><strong>{{ $row['employee_name'] }}</strong><small class="text-muted d-block">#{{ $row['employee_code'] }}</small></td><td data-label="القسم">{{ $row['department'] ?: '—' }}</td><td data-label="الحضور">{{ $row['check_in'] ? substr($row['check_in'], 0, 5) : '—' }}</td><td data-label="الانصراف">{{ $row['check_out'] ? substr($row['check_out'], 0, 5) : '—' }}</td><td data-label="التأخير">{{ $hm($row['late_minutes']) }}</td><td data-label="الانصراف المبكر">{{ $hm($row['early_leave_minutes']) }}</td><td data-label="إضافي نهاري">{{ $hm($row['day_overtime_minutes']) }}</td><td data-label="إضافي ليلي">{{ $hm($row['night_overtime_minutes']) }}</td><td data-label="الحالة">{{ arabic_label($row['status']) }}</td></tr>
@empty
<tr><td colspan="10" class="text-center py-4">لا توجد سجلات حضور في هذه الفترة.</td></tr>
@endforelse
</tbody></table></div></div>
@endif
@endsection
@push('styles')<style>
.attendance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 8px}.attendance-tab{padding:8px 16px;border-radius:12px;border:1px solid #d9e2ef;background:#fff;color:#33415c;text-decoration:none;font-weight:600}.attendance-tab.active{background:#2563eb;border-color:#2563eb;color:#fff}
.archive-table{min-width:880px}
@media(max-width:700px){.archive-list .table,.archive-table{min-width:0!important;width:100%;display:block}.archive-list .table tbody,.archive-table tbody{display:block;width:100%}.archive-list .table-responsive,.archive-detail .table-responsive{overflow-x:hidden}.archive-table thead{display:none}.archive-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;padding:12px;border:1px solid #e3eaf4;border-radius:14px;margin-bottom:10px;background:#fff}.archive-table tbody td{display:flex;flex-direction:column;border:0;padding:4px 6px;min-width:0}.archive-table tbody td::before{content:attr(data-label);font-size:11px;color:#74839c;font-weight:600}.archive-table tbody td:nth-child(2){grid-column:1/-1}.archive-table tbody td[colspan]{grid-column:1/-1;display:block}.archive-list .table thead{display:none}.archive-list .table tbody tr{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:12px;border-bottom:1px solid #e3eaf4}.archive-list .table tbody td{border:0;padding:4px 6px;display:flex;flex-direction:column}.archive-list .table tbody td[data-label]::before{content:attr(data-label);font-size:11px;color:#74839c;font-weight:600}.archive-list .table tbody tr.table-active>td{background:transparent}.archive-list .table tbody td:first-child,.archive-list .table tbody td:last-child{grid-column:1/-1}}
</style>@endpush
