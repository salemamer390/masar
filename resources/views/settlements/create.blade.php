@extends('layouts.app')
@section('title',__('بدء تسوية ترك خدمة'))
@section('content')
<div class="page-head"><div><h1>{{ __('بدء تسوية ترك خدمة') }}</h1><p>{{ __('تُنشئ مسودة تسوية وتلتقط الفترة ورصيد الإجازات والسلف القائمة كما هي الآن. لا يتغير راتب ولا حالة الموظف قبل اعتماد التسوية.') }}</p></div></div>
<div class="panel p-4" style="max-width:640px">
<form method="POST" action="{{ route('settlements.store') }}">@csrf
  <label class="form-label">{{ __('الموظف') }}</label>
  <select name="employee_id" class="form-select mb-3" required @disabled((bool) $employee)>
    @if(!$employee)<option value="">{{ __('اختر الموظف') }}</option>@endif
    @foreach($employees as $e)<option value="{{ $e->id }}" @selected($employee?->id === $e->id)>{{ $e->full_name }} — #{{ $e->employee_code }}</option>@endforeach
  </select>
  @if($employee)<input type="hidden" name="employee_id" value="{{ $employee->id }}">@endif
  <label class="form-label">{{ __('سبب ترك الخدمة') }}</label>
  <select name="reason" class="form-select mb-3" required>
    <option value="resignation">{{ __('استقالة') }}</option>
    <option value="contract_end">{{ __('إنهاء عقد') }}</option>
    <option value="dismissal">{{ __('فصل') }}</option>
  </select>
  <label class="form-label">{{ __('آخر يوم عمل') }}</label>
  <input type="date" name="last_working_day" class="form-control mb-3" required value="{{ old('last_working_day') }}">
  <label class="form-label">{{ __('ملاحظات') }}</label>
  <textarea name="notes" class="form-control mb-3" rows="2">{{ old('notes') }}</textarea>
  <button class="btn btn-primary">{{ __('إنشاء مسودة التسوية') }}</button>
  <a class="btn btn-light" href="{{ $employee ? route('employees.show', $employee) : route('settlements.index') }}">{{ __('إلغاء') }}</a>
</form>
</div>
@endsection
