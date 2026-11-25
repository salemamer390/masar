@if(auth()->user()?->hasPermission('insurance.edit') && \App\Services\InsuranceDeregistrationService::showsConfirmButton($notification))
<form method="POST" action="{{ route('insurance.deregister', $notification->data['record_id']) }}" class="notice-deregister-form" onsubmit="return confirm(@js(__('تأكيد فصل الموظف من التأمينات بعد تقديم استمارة 6؟')))">@csrf<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-dash"></i> {{ __('تم فصله') }}</button></form>
@endif
