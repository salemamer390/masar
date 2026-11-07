<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

/** Employee profile projections: stored history only, never payroll recalculation. */
class EmployeeProfileController extends Controller
{
    public function show(Employee $employee)
    {
        $employee->load(['department', 'job', 'branch', 'employmentType', 'status', 'manager', 'profileDetail']);
        $profile = $employee->profileDetail;
        $edit = $employee->only(['full_name', 'employee_code', 'national_id', 'phone', 'email', 'gender', 'birth_date', 'address', 'marital_status', 'qualification', 'department_id', 'job_id', 'branch_id', 'employment_type_id', 'status_id', 'leave_entitlement_category', 'manager_employee_id', 'contract_start_date', 'contract_end_date', 'contract_duration', 'termination_date', 'termination_type', 'notes']);
        foreach (['birth_date', 'contract_start_date', 'contract_end_date', 'termination_date'] as $key) $edit[$key] = $employee->{$key}?->toDateString();
        return response()->json(['data' => [
            'id' => $employee->id, 'title' => $employee->full_name, 'status' => $employee->status?->name, 'edit' => $edit,
            'fields' => [
                'كود الموظف' => $employee->employee_code, 'الرقم القومي' => $employee->national_id, 'الهاتف' => $employee->phone, 'البريد الإلكتروني' => $employee->email,
                'تاريخ الميلاد' => $employee->birth_date?->toDateString(), 'العنوان' => $employee->address, 'النوع' => $employee->gender, 'الحالة الاجتماعية' => $employee->marital_status, 'المؤهل' => $employee->qualification,
                'القسم' => $employee->department?->name, 'الوظيفة' => $employee->job?->title, 'الفرع' => $employee->branch?->name, 'نوع التوظيف' => $employee->employmentType?->name,
                'المدير المباشر' => $employee->manager?->full_name, 'تاريخ بداية العقد' => $employee->contract_start_date?->toDateString(), 'نهاية العقد' => $employee->contract_end_date?->toDateString(), 'مدة العقد' => $employee->contract_duration,
                'إجمالي الراتب المتفق عليه' => $employee->gross_salary, 'فئة استحقاق الإجازة' => $employee->leave_entitlement_category, 'رصيد الإجازة اليدوي' => $employee->manual_annual_balance,
                'نوع إنهاء الخدمة' => $employee->termination_type, 'تاريخ إنهاء الخدمة' => $employee->termination_date?->toDateString(), 'ملاحظات' => $employee->notes,
                'الجنسية' => $profile?->nationality, 'البريد الشخصي' => $profile?->personal_email, 'نمط العمل' => $profile?->work_pattern, 'أيام العمل الأسبوعية' => $profile?->weekly_work_days,
                'ساعات العمل اليومية' => $profile?->daily_work_hours, 'نوع العقد' => $profile?->contract_type, 'فترة الاختبار' => $profile?->probation_period,
            ],
        ]]);
    }

    public function history(Request $request, Employee $employee)
    {
        $section = $request->validate(['section' => 'required|in:movements,salaries,payroll,leaves,attendance,penalties,requests,documents,contacts,banks'])['section'];
        $query = match ($section) {
            'movements' => $employee->movements()->with(['movementType', 'oldDepartment', 'newDepartment', 'oldJob', 'newJob', 'creator']),
            'salaries' => $employee->salaries()->with('paymentMethod'),
            'payroll' => $employee->payrollRecords(),
            'leaves' => $employee->leaves()->with('leaveType'),
            'attendance' => $employee->attendances(),
            'penalties' => $employee->penalties()->with('penaltyType'),
            'requests' => $employee->hrRequests()->with('requestType'),
            'documents' => $employee->documents()->with('type'),
            'contacts' => $employee->emergencyContacts(),
            'banks' => $employee->bankAccounts()->with(['bank', 'paymentMethod']),
        };
        $page = $query->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($r) => $this->row($section, $r)));
        return response()->json($page);
    }

    private function row(string $section, $r): array
    {
        $fields = match ($section) {
            'movements' => ['التاريخ' => $r->movement_date?->toDateString(), 'النوع' => $r->movementType?->name, 'السبب' => $r->reason, 'القسم السابق' => $r->oldDepartment?->name, 'القسم الجديد' => $r->newDepartment?->name, 'الوظيفة السابقة' => $r->oldJob?->title, 'الوظيفة الجديدة' => $r->newJob?->title, 'الراتب السابق' => $r->old_salary, 'الراتب الجديد' => $r->new_salary, 'سجله' => $r->creator?->name, 'وقت التسجيل' => $r->created_at?->toDateTimeString(), 'قبل التعديل' => $r->old_values, 'بعد التعديل' => $r->new_values, 'ملاحظات' => $r->notes],
            'salaries' => ['من' => $r->effective_from?->toDateString(), 'إلى' => $r->effective_to?->toDateString(), 'الأساسي' => $r->basic_salary, 'الإجمالي' => $r->gross_salary, 'الصافي المحفوظ' => $r->net_salary, 'وسيلة الدفع' => $r->paymentMethod?->name, 'الحالة' => $r->status],
            'payroll' => ['الشهر' => $r->payroll_month?->format('Y-m'), 'الأساسي' => $r->basic_salary, 'البدلات' => $r->allowances, 'الصافي' => $r->net_salary, 'الحالة' => $r->status, 'مقفل' => (bool) $r->is_locked, 'وقت الصرف المسجل' => $r->paid_at],
            'leaves' => ['النوع' => $r->leaveType?->name, 'من' => $r->start_date?->toDateString(), 'إلى' => $r->end_date?->toDateString(), 'الأيام' => $r->days, 'الحالة' => $r->status, 'السبب' => $r->reason, 'سبب الرفض' => $r->rejection_reason],
            'attendance' => ['التاريخ' => $r->attendance_date?->toDateString(), 'الحضور' => $r->check_in, 'الانصراف' => $r->check_out, 'الحالة' => $r->status, 'المصدر' => $r->source, 'دقائق التأخير' => $r->late_minutes],
            'penalties' => ['النوع' => $r->penaltyType?->name, 'التاريخ' => $r->penalty_date?->toDateString(), 'السبب' => $r->reason, 'القيمة' => $r->amount, 'أيام الخصم' => $r->deduction_days, 'الحالة' => $r->status],
            'requests' => ['النوع' => $r->requestType?->display_name, 'التفاصيل' => $r->details, 'القيمة' => $r->display_value, 'الحالة' => $r->status, 'سبب الرفض' => $r->rejection_reason],
            'documents' => ['الاسم' => $r->document_name, 'النوع' => $r->type?->name ?: $r->document_type, 'الإصدار' => $r->issue_date?->toDateString(), 'الانتهاء' => $r->expiry_date?->toDateString(), 'الحالة' => $r->status, 'ملاحظات' => $r->notes],
            'contacts' => ['الاسم' => $r->name, 'الهاتف' => $r->phone, 'صلة القرابة' => $r->relationship, 'رئيسي' => (bool) $r->is_primary],
            'banks' => ['البنك' => $r->bank?->name, 'اسم صاحب الحساب' => $r->account_holder_name, 'رقم الحساب' => $r->account_number, 'IBAN' => $r->iban, 'وسيلة الدفع' => $r->paymentMethod?->name, 'رئيسي' => (bool) $r->is_primary, 'من' => $r->effective_from?->toDateString(), 'إلى' => $r->effective_to?->toDateString(), 'الحالة' => $r->status],
        };
        return ['id' => $r->id, 'title' => $r->title ?? $r->document_name ?? $r->name ?? $r->movementType?->name ?? '#'.$r->id, 'fields' => $fields];
    }
}
