@extends('layouts.app')
@section('title','معاينة استيراد الحضور')
@section('content')
@php
    $controller = app(\App\Http\Controllers\AttendanceImportController::class);
@endphp
<div class="container py-3" style="max-width:980px">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h4 class="mb-1"><i class="bi bi-file-earmark-spreadsheet me-2"></i>معاينة استيراد الحضور</h4>
      <div class="text-muted small">الملف: {{ $fileName }} — لم يُحفظ أي سجل بعد. راجع الورقة وتعيين الأعمدة ثم اعتمد الاستيراد.</div>
    </div>
    <a href="{{ route('attendance.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع</a>
  </div>

  @if($error)
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ $error }}</div>
  @endif

  @if($skipped)
  <div class="alert alert-warning">
    <strong>أوراق تم تخطيها ولن تُستورد:</strong>
    <ul class="mb-0 mt-2">
      @foreach($skipped as $sheet)
      <li>«{{ $sheet['name'] }}»: {{ $sheet['skip_reason'] }}</li>
      @endforeach
    </ul>
  </div>
  @endif

  <form method="POST" action="{{ route('attendance.import.preview') }}">
    @csrf
    <input type="hidden" name="import_token" value="{{ $token }}">
    <input type="hidden" name="preview_key" id="previewKey" value="{{ $previewKey }}">

    @foreach($units as $unit)
    <div class="card shadow-sm mb-3 unit-card">
      <div class="card-body">
        <div class="form-check mb-2">
          <input class="form-check-input unit-radio" type="radio" name="unit" id="unit-{{ $unit['key'] }}" value="{{ $unit['key'] }}" @if($selectedUnit === $unit['key']) checked @endif>
          <label class="form-check-label" for="unit-{{ $unit['key'] }}">
            <strong>
            @if(count($unit['sheets']) > 1)
              مجموعة «أوراق الموظفين» ({{ count($unit['sheets']) }} ورقة)
            @else
              ورقة «{{ $unit['sheets'][0]['name'] }}»
            @endif
            </strong>
            <span class="text-muted small">{{ $controller->layoutLabel($unit['layout']) }}</span>
          </label>
        </div>

        @if(count($unit['sheets']) > 1)
        <div class="text-muted small mb-2">الأوراق: {{ implode('، ', array_map(fn ($s) => $s['name'], $unit['sheets'])) }}</div>
        @endif

        <div class="row g-3 mb-3 map-fields" data-unit="{{ $unit['key'] }}">
          @foreach($fields as $field)
          @php
            $hasColumn = isset($unit['map'][$field]);
            $relevant = true;
            if ($unit['layout'] === \App\Services\Imports\AttendanceSheetAnalyzer::LAYOUT_PUNCHES) {
                $relevant = in_array($field, ['employee_code','employee_name','punch_at'], true);
            } elseif ($unit['layout'] === \App\Services\Imports\AttendanceSheetAnalyzer::LAYOUT_DAILY) {
                $relevant = in_array($field, ['employee_code','employee_name','date','check_in','check_out','status'], true);
            } else {
                $relevant = in_array($field, ['date','check_in','check_out','status'], true);
            }
          @endphp
          @if($relevant)
          <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small mb-1">{{ $fieldLabels[$field] ?? $field }}</label>
            <select class="form-select form-select-sm map-select" name="map_{{ $unit['key'] }}[{{ $field }}]">
              <option value="">— غير مُعيَّن —</option>
              @foreach($unit['headers'] as $index => $header)
              <option value="{{ $index }}" @if($hasColumn && $unit['map'][$field] === $index) selected @endif>{{ $header }}</option>
              @endforeach
            </select>
          </div>
          @endif
          @endforeach

          @if($unit['preview']['ambiguous_dates'])
          <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small mb-1 text-danger">تنسيق التاريخ النصي <i class="bi bi-exclamation-triangle"></i></label>
            <select class="form-select form-select-sm options-select border-danger" name="options_{{ $unit['key'] }}[date_format]">
              <option value="">— حدد التنسيق —</option>
              <option value="dmy" @if(($unit['options']['date_format'] ?? '') === 'dmy') selected @endif>يوم/شهر/سنة (31/12/2026)</option>
              <option value="mdy" @if(($unit['options']['date_format'] ?? '') === 'mdy') selected @endif>شهر/يوم/سنة (12/31/2026)</option>
            </select>
            <div class="form-text text-danger">وُجدت تواريخ نصية غامضة مثل 03/04/2026.</div>
          </div>
          @endif
        </div>

        <div class="d-flex gap-3 flex-wrap small mb-2">
          <span class="badge bg-success-subtle text-success-emphasis border"><i class="bi bi-check2"></i> {{ $unit['preview']['row_count'] }} سجلًا جاهزًا</span>
          <span class="badge bg-primary-subtle text-primary-emphasis border"><i class="bi bi-people"></i> {{ $unit['preview']['employee_count'] }} موظفًا مُطابَقًا</span>
          @if($unit['preview']['overwrites'] > 0)
          <span class="badge bg-warning-subtle text-warning-emphasis border"><i class="bi bi-arrow-repeat"></i> سيُحدَّث {{ $unit['preview']['overwrites'] }} سجلًا موجودًا@php
            if ($unit['preview']['overwrites_manual'] > 0) { echo ' (منها ' . $unit['preview']['overwrites_manual'] . ' يدوي/معتمد)'; }
          @endphp</span>
          @endif
          @if($unit['preview']['errors'])
          <span class="badge bg-danger-subtle text-danger-emphasis border"><i class="bi bi-exclamation-triangle"></i> {{ count($unit['preview']['errors']) }} تنبيه</span>
          @endif
        </div>

        @if($unit['preview']['deferred_existing'] > 0)
        <div class="alert alert-warning py-2">{{ $unit['preview']['deferred_existing'] }} يومًا ببصمات زائدة له سجل حضور موجود: السجل الحالي سيبقى كما هو لحين المراجعة في الاستثناءات، ولن يتغير بهذا الاستيراد.</div>
        @endif
        @if($unit['preview']['sample'])
        <div class="table-responsive mb-2">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>الموظف</th><th>الكود</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th></tr></thead>
            <tbody>
              @foreach($unit['preview']['sample'] as $row)
              <tr>
                <td>{{ $row['employee'] }}</td><td>{{ $row['code'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['check_in'] }}</td><td>{{ $row['check_out'] }}</td>
                <td>@if($row['exists'])<span class="badge bg-warning-subtle text-warning-emphasis border">موجود — سيُحدَّث</span>@endif</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @endif

        @if($unit['preview']['overwrites'] > 0)
        <div class="form-check mb-2">
          <input class="form-check-input options-check" type="checkbox" id="ow-{{ $unit['key'] }}" name="options_{{ $unit['key'] }}[overwrite]" value="1" @if(!empty($unit['options']['overwrite'])) checked @endif>
          <label class="form-check-label small" for="ow-{{ $unit['key'] }}">أؤكد تحديث {{ $unit['preview']['overwrites'] }} سجلًا موجودًا ببيانات الملف.</label>
        </div>
        @endif

        @if($unit['preview']['errors'])
        <details class="small">
          <summary class="text-danger">صفوف تحتاج مراجعة (لن تُستورد):</summary>
          <ul class="mt-2 mb-0">
            @foreach($unit['preview']['errors'] as $rowError)
            <li>{{ $rowError }}</li>
            @endforeach
          </ul>
        </details>
        @endif
      </div>
    </div>
    @endforeach

    <div class="alert alert-info small py-2 d-none" id="stalePreviewNote"><i class="bi bi-info-circle me-1"></i>غيّرت الورقة أو التعيين — اضغط «تحديث المعاينة» ثم راجع النتيجة قبل الاعتماد.</div>
    <div class="d-flex gap-2 justify-content-end">
      <a href="{{ route('attendance.index') }}" class="btn btn-light">إلغاء</a>
      <button type="submit" class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> تحديث المعاينة</button>
      <button type="submit" class="btn btn-primary" id="confirmImport" formaction="{{ route('attendance.import') }}"><i class="bi bi-check2-circle"></i> اعتماد واستيراد</button>
    </div>
  </form>
</div>
@push('scripts')
<script>
(() => {
  const form = document.querySelector('form[action$="/import/preview"]');
  if (!form) return;
  const keyInput = document.getElementById('previewKey');
  const confirmBtn = document.getElementById('confirmImport');
  const note = document.getElementById('stalePreviewNote');
  const markStale = () => {
    keyInput.value = '';
    confirmBtn.disabled = true;
    note.classList.remove('d-none');
  };
  form.querySelectorAll('.map-select, .options-select, .options-check, .unit-radio').forEach((el) => {
    el.addEventListener('change', markStale);
  });
  form.addEventListener('submit', () => {
    // Only the checked unit's fields are submitted, renamed to map[field]/options[field].
    form.querySelectorAll('.map-select, .options-select, .options-check').forEach((el) => { el.disabled = true; });
    const checked = form.querySelector('.unit-radio:checked');
    if (!checked) return;
    form.querySelectorAll('.map-fields[data-unit="' + checked.value + '"] .map-select, .map-fields[data-unit="' + checked.value + '"] .options-select').forEach((el) => {
      el.disabled = false;
      el.name = el.name.replace(/^(map|options)_[^[]+/, '$1');
    });
    const overwrite = document.getElementById('ow-' + checked.value);
    if (overwrite && overwrite.checked) {
      overwrite.disabled = false;
      overwrite.name = 'options[overwrite]';
    }
  });
})();
</script>
@endpush
@endsection
