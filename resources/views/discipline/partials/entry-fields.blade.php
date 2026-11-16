@php($prefix = $prefix ?? 'penalty')
<label class="form-label" for="{{ $prefix }}Employee">{{ __('الموظف') }}</label>
<select name="employee_id" id="{{ $prefix }}Employee" class="form-select mb-3" required>
  @foreach($employees as $employee)
    <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
  @endforeach
</select>
<label class="form-label" for="{{ $prefix }}Type">{{ __('نوع الجزاء') }}</label>
<input name="penalty_type_name" id="{{ $prefix }}Type" list="{{ $prefix }}Types" class="form-control" maxlength="100" autocomplete="off" placeholder="{{ __('اكتب نوع الجزاء أو اختره من القائمة') }}" required>
<datalist id="{{ $prefix }}Types">
  @foreach($penaltyTypes as $type)
    <option value="{{ $type->name }}"></option>
  @endforeach
</datalist>
<p class="small text-muted mt-1 mb-3">{{ __('النوع الجديد يُضاف تلقائيًا إلى القوائم الأساسية عند الحفظ.') }}</p>
<label class="form-label" for="{{ $prefix }}Date">{{ __('التاريخ') }}</label>
<input type="date" name="penalty_date" id="{{ $prefix }}Date" class="form-control mb-3" value="{{ today()->toDateString() }}" required>
<input type="hidden" name="deduct_from_salary" value="0">
<div class="form-check mb-3">
  <input type="checkbox" name="deduct_from_salary" id="{{ $prefix }}Deduct" value="1" class="form-check-input" data-penalty-deduct>
  <label class="form-check-label" for="{{ $prefix }}Deduct">{{ __('يخصم من المرتب ؟') }}</label>
</div>
<div data-penalty-days-wrap hidden class="mb-3">
  <label class="form-label" for="{{ $prefix }}Days">{{ __('عدد أيام الخصم') }}</label>
  <input type="number" step="0.01" min="0.01" max="999.99" name="deduction_days" id="{{ $prefix }}Days" class="form-control" data-penalty-days disabled>
  <small class="text-muted">{{ __('يظهر الخصم مباشرة في المسير المفتوح وملخص الشهر، وفق إعدادات وحدود الخصم الحالية.') }}</small>
</div>
<label class="form-label" for="{{ $prefix }}Reason">{{ __('سبب المخالفة') }}</label>
<textarea name="reason" id="{{ $prefix }}Reason" class="form-control" maxlength="500" required></textarea>
