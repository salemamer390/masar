<div class="modal fade" id="requestCreateModal" tabindex="-1" aria-labelledby="requestCreateTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" data-editing="{{ $editingRequest ? '1' : '0' }}" data-editing-id="{{ $editingRequest?->id ?? '' }}" action="{{ $editingRequest ? route('requests.update', $editingRequest) : route('requests.store') }}">
                @csrf @if($editingRequest) @method('PUT') @endif
                <div class="modal-header"><h5 class="modal-title" id="requestCreateTitle">{{ __('طلب HR جديد') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">{{ __('الموظف') }}</label><select name="employee_id" class="form-select" required><option value="">{{ __('اختر الموظف') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->full_name }} @if($employee->employee_code) · {{ $employee->employee_code }} @endif</option>@endforeach</select></div>
                    <div class="mb-3"><label class="form-label">{{ __('نوع الطلب') }}</label><select name="request_type_id" class="form-select" required><option value="">{{ __('اختر النوع') }}</option>@foreach($requestTypes as $type)<option value="{{ $type->id }}" @selected(old('request_type_id') == $type->id)>{{ __($type->display_name) }}</option>@endforeach</select></div>
                    <div class="mb-3"><label class="form-label">{{ __('عنوان الطلب') }}</label><input name="title" class="form-control" maxlength="200" value="{{ old('title') }}" placeholder="{{ __('مثال: طلب سلفة شهر أكتوبر') }}" required></div>
                    <div><label class="form-label">{{ __('التفاصيل') }}</label><textarea name="details" class="form-control" rows="4" maxlength="5000" placeholder="{{ __('أضف التفاصيل أو المبررات اللازمة...') }}">{{ old('details') }}</textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إلغاء') }}</button><button class="btn btn-primary">{{ __('إرسال الطلب') }}</button></div>
            </form>
        </div>
    </div>
</div>
