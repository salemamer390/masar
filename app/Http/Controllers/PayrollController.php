<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\Salary;
use App\Services\PayrollCalculator;
use App\Services\PayrollRunService;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $records = PayrollRecord::with(['employee.department', 'employee.job'])
            ->whereDate('payroll_month', $month->toDateString())
            ->orderBy('id')
            ->get();

        $totalEmployees = Employee::count();
        $reviewedCount = $records->whereIn('status', ['معتمدة', 'تم الصرف', 'Approved', 'Paid'])->count();
        $pendingReview = $records->whereIn('status', ['قيد الإعداد', 'Draft'])->count();
        $totalNet = $records->sum('net_salary');
        $totalCost = $records->sum('employee_cost');
        $totalEmployeeInsurance = $records->sum('employee_insurance');
        $totalTax = $records->sum('income_tax');
        $totalEmployerInsurance = $records->sum('employer_insurance');
        $totalMartyrs = $records->sum('martyrs_fund');
        $runs = PayrollRun::withCount('records')->orderByDesc('payroll_month')->limit(12)->get();
        $cardDetails = [
            'employees' => Employee::orderBy('full_name')->get(['id','full_name','employee_code']),
            'reviewed' => $records->whereIn('status', ['Ù…Ø¹ØªÙ…Ø¯Ø©', 'ØªÙ… Ø§Ù„ØµØ±Ù', 'Approved', 'Paid'])->values(),
            'drafts' => $records->whereIn('status', ['Ù‚ÙŠØ¯ Ø§Ù„Ø¥Ø¹Ø¯Ø§Ø¯', 'Draft'])->values(),
            'net' => $records->sortByDesc('net_salary')->values(),
            'cost' => $records->sortByDesc('employee_cost')->values(),
        ];

        return view('payroll.index', compact('records','runs','totalEmployees','reviewedCount','pendingReview','totalNet','totalCost','totalEmployeeInsurance','totalTax','totalEmployerInsurance','totalMartyrs','month','cardDetails'));
    }

    public function generate(Request $request, PayrollRunService $service)
    {
        $targetMonth = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $run = $service->generate($targetMonth);
        return back()->with('toast', 'تم إنشاء/تحديث مسودة مسير ' . $targetMonth->translatedFormat('F Y') . ' وحفظ لقطة القواعد المستخدمة.');
    }

    public function generateRun(Request $request, PayrollRunService $service)
    {
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $service->generate($month);
        return back()->with('toast', 'تم إنشاء مسير الرواتب كمسودة.');
    }

    public function approveRun(PayrollRun $payrollRun, PayrollRunService $service)
    {
        $service->approve($payrollRun);
        return back()->with('toast', 'تم اعتماد مسير الرواتب.');
    }

    public function lockRun(PayrollRun $payrollRun, PayrollRunService $service)
    {
        $service->lock($payrollRun);
        return back()->with('toast', 'تم قفل مسير الرواتب نهائيًا.');
    }

    public function approve(PayrollRecord $payrollRecord)
    {
        if ($payrollRecord->status === 'تم الصرف') return back()->with('toast', 'هذا الراتب تم صرفه بالفعل.');
        $payrollRecord->update(['status' => 'معتمدة']);
        ActivityLog::record('payroll_approve', "تم اعتماد راتب {$payrollRecord->employee?->full_name}.", $payrollRecord);
        return back()->with('toast', 'تم اعتماد الراتب.');
    }

    /** Update variable monthly inputs without changing the employee's fixed salary structure. */
    public function updateInputs(Request $request, PayrollRecord $payrollRecord, PayrollCalculator $calculator)
    {
        abort_if($payrollRecord->is_locked || !in_array($payrollRecord->status, ['Draft', 'قيد الإعداد'], true), 422, 'لا يمكن تعديل مدخلات مسير معتمد أو مقفل.');
        $data = $request->validate([
            'ot_day_hours'=>['nullable','numeric','min:0'], 'ot_night_hours'=>['nullable','numeric','min:0'],
            'bonus'=>['nullable','numeric','min:0'], 'loans'=>['nullable','numeric','min:0'],
            'other_earnings'=>['nullable','numeric','min:0'], 'other_deductions'=>['nullable','numeric','min:0'],
        ]);
        $salary = $payrollRecord->salary ?? Salary::find($payrollRecord->salary_id);
        abort_unless($salary, 422, 'لا يوجد هيكل راتب مرتبط بهذا السجل.');
        $inputs = array_merge($data, [
            'absence_days'=>(float)$payrollRecord->absence_days, 'penalty_days'=>(float)$payrollRecord->penalty_days,
        ]);
        $result = $calculator->monthly((float)$payrollRecord->gross_salary, $inputs, $salary->insurance_wage ? (float)$salary->insurance_wage : null, (bool)$salary->no_insurance);
        $before = $payrollRecord->only(['ot_day_hours','ot_night_hours','bonus','loans','other_earnings','other_deductions','net_salary']);
        $payrollRecord->update([
            'ot_day_hours'=>$result['ot_day_hours'], 'ot_night_hours'=>$result['ot_night_hours'], 'ot_day_amount'=>$result['ot_day_amount'], 'ot_night_amount'=>$result['ot_night_amount'],
            'day_overtime_hours'=>$result['ot_day_hours'], 'night_overtime_hours'=>$result['ot_night_hours'], 'day_overtime_amount'=>$result['ot_day_amount'], 'night_overtime_amount'=>$result['ot_night_amount'],
            'overtime_amount'=>$result['overtime_amount'], 'total_overtime'=>$result['overtime_amount'], 'bonus'=>$result['bonus'], 'loans'=>$result['loans'],
            'other_earnings'=>$result['other_earnings'], 'other_deductions'=>$result['other_deductions'], 'income_tax'=>$result['income_tax'],
            'absence_deduction'=>$result['absence_deduction'], 'penalty_deduction'=>$result['penalty_deduction'], 'penalties'=>$result['penalty_deduction'],
            'deductions'=>$result['monthly_deductions'], 'net_salary'=>$result['net_monthly'],
        ]);
        ActivityLog::record('payroll_inputs_update', 'تم تحديث المدخلات المتغيرة لمسير راتب.', $payrollRecord, $before, $payrollRecord->fresh()->only(['ot_day_hours','ot_night_hours','bonus','loans','other_earnings','other_deductions','net_salary']), 'payroll');
        return back()->with('toast', 'تمت إعادة حساب راتب الموظف من المدخلات المتغيرة.');
    }

    public function pay(PayrollRecord $payrollRecord)
    {
        if ($payrollRecord->status !== 'معتمدة') return back()->withErrors(['payroll' => 'يجب اعتماد الراتب قبل تسجيل الصرف.']);
        $payrollRecord->update(['status' => 'تم الصرف', 'paid_at' => now()]);
        ActivityLog::record('payroll_pay', "تم صرف راتب الموظف ({$payrollRecord->employee?->full_name}) لشهر {$payrollRecord->payroll_month->format('m/Y')}.", $payrollRecord);
        return back()->with('toast', 'تم تسجيل صرف الراتب بنجاح.');
    }

    public function export(Request $request)
    {
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $records = PayrollRecord::with(['employee.department', 'employee.job'])
            ->whereDate('payroll_month', $month->toDateString())->orderBy('id')->get();

        if ($request->string('format')->lower() === 'csv') {
            $filename = 'tazamun-payroll-' . $month->format('Y-m') . '.csv';
            return response()->streamDownload(function () use ($records) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['الموظف','القسم','صافي المتفق عليه','بدل انتقال','بدل وجبة','بدل سكن','بدل ملابس','إجمالي البدلات','Gross','الأجر التأميني','تأمين الموظف','الضريبة','صندوق أسر الشهداء','الاستقطاعات الإضافية','الصافي','حصة صاحب العمل','تكلفة الموظف','الحالة']);
                foreach ($records as $r) {
                    fputcsv($out, [
                        $r->employee?->full_name, $r->employee?->department?->name, ($r->net_salary + ($r->fixed_deductions ?? 0) + ($r->attendance_deduction ?? 0)), $r->transportation_allowance, $r->meal_allowance, $r->housing_allowance, $r->clothing_allowance, $r->allowances, $r->gross_salary, $r->insurance_wage,
                        $r->employee_insurance, $r->income_tax, $r->martyrs_fund, $r->fixed_deductions, $r->net_salary,
                        $r->employer_insurance, $r->employee_cost, $r->status,
                    ]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('payroll.export', compact('records','month'));
    }
}
