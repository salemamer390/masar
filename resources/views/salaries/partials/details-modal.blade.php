<div class="modal fade" id="salaryDetails{{ $s->id }}" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content salary-details-modal">
    <div class="modal-header">
      <div><h5 class="modal-title mb-1">تفاصيل هيكل راتب {{ $s->employee?->full_name }}</h5><div class="small text-muted">{{ $s->employee?->job?->title ?? '—' }} · ساري من {{ $s->effective_from?->format('d/m/Y') }}</div></div>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-cash-stack"></i><span>الراتب والبدلات</span></div><div class="salary-detail-grid">
        <div><span>الراتب الصافي</span><strong>{{ format_money($s->net_salary) }} ج.م</strong></div>
        <div><span>الراتب الإجمالي</span><strong>{{ format_money($s->gross_salary) }} ج.م</strong></div>
        <div><span>الأجر الأساسي</span><strong>{{ format_money($s->basic_salary) }} ج.م</strong></div>
        <div><span>إجمالي البدلات ({{ format_number($s->allowance_limit_pct) }}%)</span><strong>{{ format_money($s->allowance_limit) }} ج.م</strong></div>
        <div><span>بدل انتقال</span><strong>{{ format_money($s->transportation_allowance) }} ج.م</strong></div>
        <div><span>بدل وجبة</span><strong>{{ format_money($s->meal_allowance) }} ج.م</strong></div>
        <div><span>بدل سكن</span><strong>{{ format_money($s->housing_allowance) }} ج.م</strong></div>
        <div><span>بدل ملابس</span><strong>{{ format_money($s->clothing_allowance) }} ج.م</strong></div>
        <div><span>المتبقي من البدلات</span><strong>{{ format_money($s->allowance_remaining) }} ج.م</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-shield-check"></i><span>التأمينات</span></div><div class="salary-detail-grid">
        <div><span>الأجر التأميني</span><strong>{{ $s->no_insurance ? 'بدون تأمينات' : format_money($s->applied_insurance_wage).' ج.م' }}</strong></div>
        <div><span>حصة العامل {{ format_number($payrollSettings['employee_insurance_rate']) }}%</span><strong>{{ format_money($s->employee_insurance) }} ج.م</strong></div>
        <div><span>حصة صاحب العمل {{ format_number($payrollSettings['employer_insurance_rate']) }}%</span><strong>{{ format_money($s->employer_insurance) }} ج.م</strong></div>
        <div><span>أساس التأمين التلقائي</span><strong>الأجر الأساسي</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-receipt"></i><span>الضرائب والاستقطاعات</span></div><div class="salary-detail-grid">
        <div><span>الإعفاء الشخصي الشهري</span><strong>{{ format_money($s->personal_exemption) }} ج.م</strong></div>
        <div><span>الوعاء الضريبي السنوي</span><strong>{{ format_money($s->taxable_annual) }} ج.م</strong></div>
        <div><span>ضريبة الدخل (شهرياً)</span><strong>{{ format_money($s->income_tax) }} ج.م</strong></div>
        <div><span>صندوق الشهداء {{ format_number($payrollSettings['martyrs_fund_rate']) }}%</span><strong>{{ format_money($s->martyrs_fund) }} ج.م</strong></div>
        <div><span>استقطاعات ثابتة</span><strong>{{ format_money($s->fixed_deductions) }} ج.م</strong></div>
        <div><span>إجمالي الاستقطاعات</span><strong>{{ format_money($s->total_deductions) }} ج.م</strong></div>
        <div><span>صافي الراتب النهائي</span><strong class="text-success">{{ format_money($s->net_salary) }} ج.م</strong></div>
      </div></div>
      <div class="salary-detail-section"><div class="salary-detail-title"><i class="bi bi-building"></i><span>تكلفة الشركة</span></div><div class="salary-cost-box"><div><span>حصة صاحب العمل</span><b>{{ format_money($s->employer_insurance) }} ج.م</b></div><div><span>إجمالي تكلفة الموظف</span><b>{{ format_money($s->employee_cost) }} ج.م</b></div></div></div>
      @if($s->notes)<div class="alert alert-light border mt-3 mb-0"><strong>ملاحظات:</strong> {{ $s->notes }}</div>@endif
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إغلاق</button>@if(auth()->user()?->hasPermission('salary.edit'))<button type="button" class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editSalary{{ $s->id }}">تعديل الهيكل</button>@endif</div>
  </div></div>
</div>
