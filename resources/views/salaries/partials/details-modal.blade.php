<div class="modal fade" id="salaryDetails{{ $s->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content salary-details-modal">
    <div class="modal-header">
      <div><h5 class="modal-title mb-1">{{ __('تفاصيل هيكل راتب') }} {{ $s->employee?->full_name }}</h5><div class="small text-muted">{{ $s->employee?->job?->title ?? '—' }} {{ __('· ساري من') }} {{ $s->effective_from?->format('d/m/Y') }}</div></div>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-cash-stack"></i><span>{{ __('الراتب والبدلات') }}</span></div><div class="salary-detail-grid">
        <div><span>{{ __('الراتب الصافي') }}</span><strong>{{ format_money($s->net_salary) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('الراتب الإجمالي') }}</span><strong>{{ format_money($s->gross_salary) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('الأجر الأساسي') }}</span><strong>{{ format_money($s->basic_salary) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('إجمالي البدلات (') }}{{ format_number($s->allowance_limit_pct) }}%)</span><strong>{{ format_money($s->allowance_limit) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('بدل انتقال') }}</span><strong>{{ format_money($s->transportation_allowance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('بدل وجبة') }}</span><strong>{{ format_money($s->meal_allowance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('بدل سكن') }}</span><strong>{{ format_money($s->housing_allowance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('بدل ملابس') }}</span><strong>{{ format_money($s->clothing_allowance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('المتبقي من البدلات') }}</span><strong>{{ format_money($s->allowance_remaining) }} {{ __('ج.م') }}</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-shield-check"></i><span>{{ __('التأمينات') }}</span></div><div class="salary-detail-grid">
        <div><span>{{ __('الأجر التأميني') }}</span><strong>{{ $s->no_insurance ? __('بدون تأمينات') : format_money($s->applied_insurance_wage).' '.__('ج.م') }}</strong></div>
        <div><span>{{ __('حصة العامل') }} {{ format_number($payrollSettings['employee_insurance_rate']) }}%</span><strong>{{ format_money($s->employee_insurance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('حصة صاحب العمل') }} {{ format_number($payrollSettings['employer_insurance_rate']) }}%</span><strong>{{ format_money($s->employer_insurance) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('أساس التأمين التلقائي') }}</span><strong>{{ __('الأجر الأساسي') }}</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-receipt"></i><span>{{ __('الضرائب والاستقطاعات') }}</span></div><div class="salary-detail-grid">
        <div><span>{{ __('الإعفاء الشخصي الشهري') }}</span><strong>{{ format_money($s->personal_exemption) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('الوعاء الضريبي السنوي') }}</span><strong>{{ format_money($s->taxable_annual) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('ضريبة الدخل (شهرياً)') }}</span><strong>{{ format_money($s->income_tax) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('صندوق الشهداء') }} {{ format_number($payrollSettings['martyrs_fund_rate']) }}%</span><strong>{{ format_money($s->martyrs_fund) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('استقطاعات ثابتة') }}</span><strong>{{ format_money($s->fixed_deductions) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('إجمالي الاستقطاعات') }}</span><strong>{{ format_money($s->total_deductions) }} {{ __('ج.م') }}</strong></div>
        <div><span>{{ __('صافي الراتب النهائي') }}</span><strong class="text-success">{{ format_money($s->net_salary) }} {{ __('ج.م') }}</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-building"></i><span>{{ __('تكلفة الشركة') }}</span></div><div class="salary-cost-box"><div><span>{{ __('حصة صاحب العمل') }}</span><b>{{ format_money($s->employer_insurance) }} {{ __('ج.م') }}</b></div><div><span>{{ __('إجمالي تكلفة الموظف') }}</span><b>{{ format_money($s->employee_cost) }} {{ __('ج.م') }}</b></div></div></div>
      @if($s->notes)<div class="alert alert-light border mt-3 mb-0"><strong>{{ __('ملاحظات:') }}</strong> {{ $s->notes }}</div>@endif
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('إغلاق') }}</button>@if(auth()->user()?->hasPermission('salary.edit'))<button type="button" class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editSalary{{ $s->id }}">{{ __('تعديل الهيكل') }}</button>@endif</div>
  </div></div>
</div>
