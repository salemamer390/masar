@extends('layouts.app')
@section('title',__('استيراد الحضور'))
@push('styles')<link rel="stylesheet" href="{{ asset('css/import-wizard.css') }}?v=1">@endpush
@section('content')
@php
$controller = app(\App\Http\Controllers\AttendanceImportController::class);
$current = collect($units)->firstWhere('key',$selectedUnit);
$stats = $current['preview'];
@endphp
<div class="odir-import attendance-wizard">
  <div class="wizard-header">
    <h4><i class="bi bi-file-earmark-spreadsheet"></i> {{ __('استيراد الحضور والانصراف') }}</h4>
    <div class="import-stepper"><span class="text-success"><b class="step-number">✓</b>رفع الملف</span><span data-att-step="sheets"><b class="step-number">2</b>الأوراق وأنواعها</span><span data-att-step="review"><b class="step-number">3</b>المراجعة</span><span><b class="step-number">4</b>النتيجة</span></div>
  </div>
  <form method="POST" action="{{ route('attendance.import.preview') }}">
    @csrf
    <input type="hidden" name="import_token" value="{{ $token }}">
    <input type="hidden" name="preview_key" id="previewKey" value="{{ $previewKey }}">
    <input type="hidden" name="wizard" value="1">
    <input type="hidden" name="wizard_step" id="attendanceWizardStep" value="{{ request('wizard_step','sheets') === 'review' || $error ? 'review' : 'sheets' }}">
    <div class="wizard-body">
      <div class="file-chip mb-3">{{ $fileName }}</div>
      @if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif
      @if($skipped)<div class="import-note mb-3"><strong>أوراق تم تخطيها</strong><ul class="mb-0">@foreach($skipped as $sheet)<li>{{ $sheet['name'] }}: {{ $sheet['skip_reason'] }}</li>@endforeach</ul></div>@endif
      <section data-att-stage="sheets">
        <div class="import-section-title">الأوراق وربط الأعمدة</div>
        <p class="small text-muted">اختر الورقة أو مجموعة الأوراق المطلوب استيرادها. راجع الربط وعينة البيانات. يُستورد اختيار واحد فقط في كل مرة.</p>
        @foreach($units as $unit)
        <div class="unit-card py-3">
          <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <input class="form-check-input unit-radio" type="radio" name="unit" id="unit-{{ $unit['key'] }}" value="{{ $unit['key'] }}" @checked($selectedUnit === $unit['key'])>
            <label for="unit-{{ $unit['key'] }}" class="fw-semibold">{{ implode('، ',array_column($unit['sheets'],'name')) }}</label>
            <span class="mapping-chip">{{ $controller->layoutLabel($unit['layout']) }}</span>
            <span class="small text-muted">{{ count($unit['sheets']) }} ورقة · {{ count($unit['map']) }} حقول مرتبطة</span>
          </div>
          <details @if($selectedUnit===$unit['key']) open @endif><summary class="text-primary small mb-3">عرض / تعديل الربط وعينة البيانات</summary>
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
              <option value="">{{ __('— غير مُعيَّن —') }}</option>
              @foreach($unit['headers'] as $index => $header)
              <option value="{{ $index }}" @if($hasColumn && $unit['map'][$field] === $index) selected @endif>{{ $header }}</option>
              @endforeach
            </select>
          </div>
          @endif
          @endforeach

          @if($unit['preview']['ambiguous_dates'])
          <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small mb-1 text-danger">{{ __('تنسيق التاريخ النصي') }} <i class="bi bi-exclamation-triangle"></i></label>
            <select class="form-select form-select-sm options-select border-danger" name="options_{{ $unit['key'] }}[date_format]">
              <option value="">{{ __('— حدد التنسيق —') }}</option>
              <option value="dmy" @if(($unit['options']['date_format'] ?? '') === 'dmy') selected @endif>{{ __('يوم/شهر/سنة (31/12/2026)') }}</option>
              <option value="mdy" @if(($unit['options']['date_format'] ?? '') === 'mdy') selected @endif>{{ __('شهر/يوم/سنة (12/31/2026)') }}</option>
            </select>
            <div class="form-text text-danger">{{ __('وُجدت تواريخ نصية غامضة مثل 03/04/2026.') }}</div>
          </div>
          @endif
        </div>


          <div class="table-responsive"><table class="table table-sm small"><thead><tr><th>الموظف</th><th>الكود</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th></tr></thead><tbody>
          @foreach(array_slice($unit['preview']['sample'],0,5) as $row)<tr><td>{{ $row['employee'] }}</td><td>{{ $row['code'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['check_in'] }}</td><td>{{ $row['check_out'] }}</td></tr>@endforeach
          </tbody></table></div></details>
        </div>
        @endforeach
        <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-primary" data-att-next>متابعة للمراجعة <i class="bi bi-arrow-left"></i></button></div>
      </section>
      <section data-att-stage="review" hidden>
        <div class="import-section-title">مراجعة البيانات قبل الاستيراد</div>
        <p class="small text-muted">لا تُحفظ البيانات قبل التأكيد. البصمات الزائدة تُحفظ للمراجعة دون استبدال السجل الحالي.</p>
        <div class="import-stats">
          <div class="import-stat neutral"><span>سجلات قابلة للمعالجة</span><strong>{{ $stats['row_count'] }}</strong></div>
          <div class="import-stat good"><span>موظفون مطابقون</span><strong>{{ $stats['employee_count'] }}</strong></div>
          <div class="import-stat blue"><span>سجلات موجودة</span><strong>{{ $stats['overwrites'] }}</strong></div>
          <div class="import-stat bad"><span>رسائل تحقق / مراجعة</span><strong>{{ count($stats['errors']) }}</strong></div>
        </div>
        @foreach($units as $unit)
          @if($unit['key']===$selectedUnit)
        @if($unit['preview']['overwrites'] > 0)
        <div class="form-check mb-2">
          <input class="form-check-input options-check" type="checkbox" id="ow-{{ $unit['key'] }}" name="options_{{ $unit['key'] }}[overwrite]" value="1" @if(!empty($unit['options']['overwrite'])) checked @endif>
          <label class="form-check-label small" for="ow-{{ $unit['key'] }}">{{ __('أؤكد تحديث') }} {{ $unit['preview']['overwrites'] }} {{ __('سجلًا موجودًا ببيانات الملف.') }}</label>
        </div>
        @endif


          @endif
        @endforeach
        @if($stats['overwrites'])<div class="alert alert-info">سيُحدَّث {{ $stats['overwrites'] }} سجلًا موجودًا فقط بعد موافقتك.</div>@endif
        @if($stats['deferred_existing'])<div class="alert alert-warning">{{ $stats['deferred_existing'] }} أيام ببصمات زائدة: السجل الحالي سيبقى كما هو حتى مراجعة الاستثناءات.</div>@endif
        <div class="d-flex gap-2 mb-3 flex-wrap">
          @if($stats['errors'])<button class="btn btn-outline-danger btn-sm" type="submit" formaction="{{ route('attendance.import.rejected') }}">تحميل تقرير المرفوضات والمراجعة كاملًا (Excel)</button>@endif
        </div>
        <div class="btn-group mb-3" role="group" aria-label="تصفية نتائج التحقق">
          <button type="button" class="btn btn-light active" data-validation-tab="all">الكل</button>
          <button type="button" class="btn btn-light" data-validation-tab="ready">صالح / تحديث ({{ $stats['row_count'] }})</button>
          <button type="button" class="btn btn-light" data-validation-tab="error">أخطاء / مراجعة ({{ count($stats['errors']) }})</button>
        </div>
        <div class="table-responsive" style="max-height:380px;overflow:auto"><table class="table align-middle"><thead><tr><th>الموظف / الصف</th><th>التاريخ</th><th>الحضور</th><th>الانصراف</th><th>نتيجة التحقق</th></tr></thead><tbody>
          @foreach($stats['sample'] as $row)<tr data-validation-row="ready"><td>{{ $row['employee'] }}<div class="small text-muted">{{ $row['code'] }}</div></td><td>{{ $row['date'] }}</td><td>{{ $row['check_in'] }}</td><td>{{ $row['check_out'] }}</td><td><span class="badge {{ $row['deferred'] ? 'text-bg-warning' : ($row['exists'] ? 'text-bg-primary' : 'text-bg-success') }}">{{ $row['deferred'] ? 'مراجعة بصمات' : ($row['exists'] ? 'تحديث' : 'جديد') }}</span></td></tr>@endforeach
          @foreach($stats['errors'] as $message)<tr data-validation-row="error"><td colspan="5" class="small text-danger">{{ $message }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="d-flex gap-2 justify-content-end mt-3"><button type="button" class="btn btn-light" data-att-back>رجوع للأوراق</button><button type="submit" class="btn btn-primary" id="confirmImport" formaction="{{ route('attendance.import') }}" @disabled(!$stats['row_count'])>اعتماد واستيراد</button></div>
      </section>
      <div class="alert alert-info small py-2 d-none mt-3" id="stalePreviewNote">جارٍ تحديث المعاينة بعد تغيير الورقة أو الربط…</div>
    </div>
    <div class="wizard-footer"><a class="btn btn-light" href="{{ route('attendance.index') }}">إلغاء</a></div>
  </form>
</div>
@push('scripts')
<script>
(() => {
  const form = document.querySelector('form[action$="/import/preview"]');
  if (!form) return;
  const confirmBtn = document.getElementById('confirmImport');
  const note = document.getElementById('stalePreviewNote');
  let refreshing = false;
  const stageInput = document.getElementById('attendanceWizardStep');
  const show = (step) => {
    stageInput.value = step;
    form.querySelectorAll('[data-att-stage]').forEach(el=>el.hidden=el.dataset.attStage!==step);
    document.querySelectorAll('[data-att-step]').forEach(el=>{el.classList.toggle('text-primary',el.dataset.attStep===step);});
  };
  document.querySelectorAll('[data-att-next]').forEach(el=>el.addEventListener('click',()=>show('review')));
  document.querySelectorAll('[data-att-back]').forEach(el=>el.addEventListener('click',()=>show('sheets')));
  show(stageInput.value);
  document.querySelectorAll('[data-validation-tab]').forEach(btn=>btn.addEventListener('click',()=>{
    document.querySelectorAll('[data-validation-tab]').forEach(b=>b.classList.toggle('active',b===btn));
    document.querySelectorAll('[data-validation-row]').forEach(row=>row.hidden=btn.dataset.validationTab!=='all'&&row.dataset.validationRow!==btn.dataset.validationTab);
  }));
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
  // Interpretation changes (sheet, column mapping, date format) refresh the preview
  // automatically. The overwrite acknowledgement (.options-check) is commit-time consent
  // only: it never changes the interpretation, so it must not invalidate the preview.
  form.querySelectorAll('.map-select, .options-select, .unit-radio').forEach((el) => {
    el.addEventListener('change', () => {
      if (refreshing) return;
      refreshing = true;
      confirmBtn.disabled = true;
      note.classList.remove('d-none');
      form.requestSubmit();
    });
  });
})();
</script>
@endpush
@endsection
