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
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /** Display the prototype employee-payroll drawer as a separate page. */
    public function employeeSalaries(Request $request)
    {
        $request->attributes->set('payroll_view', 'payroll.employee-salaries');
        return $this->index($request);
    }

    public function index(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $search = trim((string) $request->input('search', ''));
        $records = PayrollRecord::with(['employee.department', 'employee.job'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('payroll_month', $month->toDateString())
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('employee', function ($employeeQuery) use ($search) {
                    $employeeQuery->where('full_name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 10), 100))
            ->withQueryString();
        $recordCollection = $records->getCollection();
        $summaryQuery = PayrollRecord::when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('payroll_month', $month->toDateString());

        $totalEmployees = Employee::when($companyId, fn ($query) => $query->where('company_id', $companyId))->count();
        $reviewedCount = (clone $summaryQuery)->whereIn('status', ['معتمدة', 'تم الصرف', 'Approved', 'Paid'])->count();
        $pendingReview = (clone $summaryQuery)->whereIn('status', ['قيد الإعداد', 'Draft'])->count();
        $totalNet = (clone $summaryQuery)->sum('net_salary');
        $totalCost = (clone $summaryQuery)->sum('employee_cost');
        $totalEmployeeInsurance = (clone $summaryQuery)->sum('employee_insurance');
        $totalTax = (clone $summaryQuery)->sum('income_tax');
        $totalEmployerInsurance = (clone $summaryQuery)->sum('employer_insurance');
        $totalMartyrs = (clone $summaryQuery)->sum('martyrs_fund');
        $runs = PayrollRun::withCount('records')->when($companyId, fn ($query) => $query->where('company_id', $companyId))->orderByDesc('payroll_month')->limit(12)->get();
        $cardDetails = [
            'employees' => Employee::when($companyId, fn ($query) => $query->where('company_id', $companyId))->orderBy('full_name')->get(['id','full_name','employee_code']),
            'reviewed' => $recordCollection->whereIn('status', ['Ù…Ø¹ØªÙ…Ø¯Ø©', 'ØªÙ… Ø§Ù„ØµØ±Ù', 'Approved', 'Paid'])->values(),
            'drafts' => $recordCollection->whereIn('status', ['Ù‚ÙŠØ¯ Ø§Ù„Ø¥Ø¹Ø¯Ø§Ø¯', 'Draft'])->values(),
            'net' => $recordCollection->sortByDesc('net_salary')->values(),
            'cost' => $recordCollection->sortByDesc('employee_cost')->values(),
        ];
        $payrollDetails = $recordCollection->map(fn ($record) => [
            'id' => $record->id,
            'name' => $record->employee?->full_name,
            'code' => $record->employee?->employee_code,
            'department' => $record->employee?->department?->name,
            'status' => $record->status,
            'salary_id' => $record->salary_id,
            'effective_from' => $record->salary?->effective_from?->format('d/m/Y'),
            'basic_salary' => (float) $record->basic_salary,
            'allowances' => (float) $record->allowances,
            'housing_allowance' => (float) $record->housing_allowance,
            'transportation_allowance' => (float) $record->transportation_allowance,
            'meal_allowance' => (float) $record->meal_allowance,
            'clothing_allowance' => (float) $record->clothing_allowance,
            'overtime_amount' => (float) $record->overtime_amount,
            'day_overtime_amount' => max((float) ($record->day_overtime_amount ?? 0), (float) ($record->ot_day_amount ?? 0)),
            'night_overtime_amount' => max((float) ($record->night_overtime_amount ?? 0), (float) ($record->ot_night_amount ?? 0)),
            'advance_earnings' => (float) data_get($record->calculation_snapshot, 'inputs.advance_earnings', data_get($record->calculation_snapshot, 'advance_earnings', 0)),
            'bonus' => (float) $record->bonus,
            'incentives' => (float) $record->incentives,
            'commission' => (float) $record->commission,
            'profit_share' => (float) $record->profit_share,
            'other_earnings' => (float) $record->other_earnings,
            'absence_deduction' => (float) $record->absence_deduction,
            'penalties' => (float) $record->penalties,
            'loans' => (float) $record->loans,
            'employee_insurance' => (float) $record->employee_insurance,
            'income_tax' => (float) $record->income_tax,
            'martyrs_fund' => (float) $record->martyrs_fund,
            'fixed_deductions' => (float) $record->fixed_deductions,
            'other_deductions' => (float) $record->other_deductions,
            'gross_salary' => (float) $record->gross_salary,
            'net_salary' => (float) $record->net_salary,
            'employee_cost' => (float) $record->employee_cost,
            'additional_amount' => (float) $record->overtime_amount
                + (float) data_get($record->calculation_snapshot, 'inputs.advance_earnings', data_get($record->calculation_snapshot, 'advance_earnings', 0))
                + (float) $record->bonus + (float) $record->incentives + (float) $record->commission
                + (float) $record->profit_share + (float) $record->other_earnings,
        ])->values();

        // Data shape consumed by the approved payroll drawer prototype.
        // Keeping this adapter here lets us preserve the prototype UI while
        // replacing its sample JavaScript data with the real payroll records.
        $prototypePeople = $recordCollection->values()->map(function ($record) {
            $snapshot = (array) ($record->calculation_snapshot ?? []);
            $inputs = (array) ($snapshot['inputs'] ?? []);
            $employee = $record->employee;
            $items = [
                ['الراتب الأساسي', (float) $record->basic_salary],
            ];
            $allowanceItems = 0;
            foreach ([
                'housing_allowance' => 'بدل السكن',
                'transportation_allowance' => 'بدل الانتقال',
                'meal_allowance' => 'بدل الوجبة',
                'clothing_allowance' => 'بدل طبيعة العمل',
            ] as $key => $label) {
                $value = (float) ($record->{$key} ?? 0);
                if ($value > 0) {
                    $items[] = [$label, $value];
                    $allowanceItems += $value;
                }
            }
            if ($allowanceItems <= 0 && (float) $record->allowances > 0) {
                $items[] = ['البدلات', (float) $record->allowances];
            }
            foreach ([
                'overtime_amount' => 'الإضافي',
                'bonus' => 'المكافآت',
                'incentives' => 'الحوافز',
                'commission' => 'العمولات',
                'profit_share' => 'نصيب الأرباح',
                'other_earnings' => 'استحقاقات أخرى',
            ] as $key => $label) {
                if ((float) ($record->{$key} ?? 0) > 0) $items[] = [$label, (float) $record->{$key}];
            }
            $advance = (float) data_get($inputs, 'advance_earnings', data_get($snapshot, 'advance_earnings', 0));
            if ($advance > 0) $items[] = ['السلفة المصروفة', $advance];
            $deductions = [
                ['تأمين الموظف', (float) $record->employee_insurance],
                ['الضريبة', (float) $record->income_tax],
                ['صندوق الشهداء', (float) $record->martyrs_fund],
                ['خصم الغياب', (float) $record->absence_deduction],
                ['خصم الجزاءات', (float) $record->penalty_deduction],
                ['أقساط السلف', (float) $record->loans],
                ['خصومات أخرى', (float) $record->other_deductions],
            ];
            $deductions = array_values(array_filter($deductions, fn ($item) => $item[1] > 0));
            $initials = mb_substr((string) ($employee?->full_name ?? '—'), 0, 2);
            return [
                'id' => (int) $employee?->id,
                'code' => (string) ($employee?->employee_code ?? ''),
                'name' => $employee?->full_name ?? '—',
                'role' => $employee?->job?->title ?? '—',
                'dept' => $employee?->department?->name ?? '—',
                'basic' => (float) $record->basic_salary,
                'allow' => (float) $record->allowances,
                'gross' => (float) $record->gross_salary,
                'entitlements' => (float) data_get($snapshot, 'entitlements', $record->gross_salary),
                'deduct' => (float) $record->deductions,
                'totalDeductions' => (float) data_get($snapshot, 'monthly_deductions', $record->deductions),
                'net' => (float) $record->net_salary,
                'companyCost' => (float) $record->employee_cost,
                'state' => $record->status,
                'initials' => $initials,
                'structureLabel' => $record->salary_id ? 'هيكل #' . $record->salary_id : 'بدون هيكل',
                'effectiveFrom' => $record->salary?->effective_from?->format('d/m/Y'),
                'agreedNet' => (float) ($record->agreed_net_salary ?? 0),
                'salaryUrl' => $record->salary_id ? route('salaries.index', ['employee_id' => $employee?->id]) : null,
                'items' => $items,
                'deductions' => $deductions,
                'paidDays' => (int) data_get($snapshot, 'paid_days', 0),
                'absenceDays' => (float) ($record->absence_days ?? 0),
                'overtimeHours' => (float) ($record->ot_day_hours ?? 0) + (float) ($record->ot_night_hours ?? 0),
            ];
        })->values();
        $prototypeStats = [
            'cost' => (float) $totalCost,
            'net' => (float) $totalNet,
            'deductions' => (float) $recordCollection->sum('deductions'),
            'employees' => $recordCollection->count(),
        ];

        $view = $request->attributes->get('payroll_view', 'payroll.index');
        return view($view, compact('records','runs','totalEmployees','reviewedCount','pendingReview','totalNet','totalCost','totalEmployeeInsurance','totalTax','totalEmployerInsurance','totalMartyrs','month','cardDetails','payrollDetails','prototypePeople','prototypeStats'));
    }

    public function generate(Request $request, PayrollRunService $service)
    {
        $companyId = auth()->user()?->company_id;
        $targetMonth = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $existing = PayrollRun::when($companyId, fn ($query) => $query->where('company_id', $companyId))->whereDate('payroll_month', $targetMonth->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return back()->withErrors(['payroll' => 'لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا. أنشئ مسيرًا للشهر التالي أو راجع المسير الحالي.']);
        }
        $run = $service->generate($targetMonth);
        return back()->with('toast', 'تم إنشاء/تحديث مسودة مسير ' . $targetMonth->translatedFormat('F Y') . ' وحفظ لقطة القواعد المستخدمة.');
    }

    public function generateRun(Request $request, PayrollRunService $service)
    {
        $companyId = auth()->user()?->company_id;
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $existing = PayrollRun::when($companyId, fn ($query) => $query->where('company_id', $companyId))->whereDate('payroll_month', $month->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return back()->withErrors(['payroll' => 'لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا.']);
        }
        $service->generate($month);
        return back()->with('toast', 'تم إنشاء مسير الرواتب كمسودة.');
    }

    public function startGeneration(Request $request)
    {
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $companyId = auth()->user()?->company_id;
        $existing = PayrollRun::when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('payroll_month', $month->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return response()->json(['message' => 'لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا.'], 422);
        }

        $run = DB::transaction(function () use ($month, $existing) {
            $run = $existing
                ? PayrollRun::whereKey($existing->id)->lockForUpdate()->firstOrFail()
                : PayrollRun::create(['payroll_month' => $month->toDateString(), 'status' => 'Generating', 'created_by' => auth()->id()]);
            $run->update(['status' => 'Generating', 'created_by' => $run->created_by ?: auth()->id()]);
            return $run;
        });

        $total = $this->eligiblePayrollEmployeeIds($month, $companyId)->count();
        return response()->json(['success' => true, 'run_id' => $run->id, 'month' => $month->toDateString(), 'total' => $total, 'processed' => 0, 'batch_size' => 25]);
    }

    public function generateBatch(Request $request, PayrollRunService $service)
    {
        $data = $request->validate(['month' => ['required', 'date'], 'offset' => ['required', 'integer', 'min:0'], 'batch_size' => ['nullable', 'integer', 'min:10', 'max:50']]);
        $month = Carbon::parse($data['month'])->startOfMonth();
        $companyId = auth()->user()?->company_id;
        $allIds = $this->eligiblePayrollEmployeeIds($month, $companyId)->values();
        $offset = (int) $data['offset'];
        $batchSize = (int) ($data['batch_size'] ?? 25);
        $ids = $allIds->slice($offset, $batchSize)->values()->all();
        $run = PayrollRun::when($companyId, fn ($query) => $query->where('company_id', $companyId))->whereDate('payroll_month', $month->toDateString())->firstOrFail();
        abort_unless($run->status === 'Generating', 422, 'لا توجد عملية إنشاء نشطة لهذا الشهر.');
        if ($ids) $service->generate($month, $ids);
        $processed = min($offset + count($ids), $allIds->count());
        $complete = $processed >= $allIds->count();
        if ($complete) {
            $run->records()->when($allIds->isNotEmpty(), fn ($query) => $query->whereNotIn('employee_id', $allIds->all()))->delete();
            $run->update(['status' => 'Draft']);
        }
        return response()->json(['success' => true, 'processed' => $processed, 'total' => $allIds->count(), 'percent' => $allIds->count() ? (int) round(($processed / $allIds->count()) * 100) : 100, 'complete' => $complete]);
    }

    private function eligiblePayrollEmployeeIds(Carbon $month, ?int $companyId)
    {
        $excludedStatuses = ['Pending', 'Suspended', 'Terminated', 'قيد التعيين', 'موقوف', 'منتهي الخدمة'];
        return Employee::query()->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->where(function ($query) use ($excludedStatuses, $month) {
                $query->whereDoesntHave('status', fn ($status) => $status->whereIn('name', $excludedStatuses))
                    ->orWhere(function ($terminated) use ($month) {
                        $terminated->whereHas('status', fn ($status) => $status->whereIn('name', ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة']))
                            ->whereBetween('termination_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()]);
                    });
            })->orderBy('id')->pluck('id');
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
    public function updateInputs(Request $request, PayrollRecord $payrollRecord, PayrollCalculator $calculator, PayrollRunService $service)
    {
        abort_if($payrollRecord->is_locked || !in_array($payrollRecord->status, ['Draft', 'قيد الإعداد'], true), 422, 'لا يمكن تعديل مدخلات مسير معتمد أو مقفل.');
        $data = $request->validate([
            'ot_day_hours'=>['nullable','numeric','min:0'], 'ot_night_hours'=>['nullable','numeric','min:0'],
            'bonus'=>['nullable','numeric','min:0'], 'loans'=>['nullable','numeric','min:0'],
            'advance_rate'=>['nullable','numeric','min:0','max:100'],
            'other_earnings'=>['nullable','numeric','min:0'], 'other_deductions'=>['nullable','numeric','min:0'],
        ]);
        $salary = $payrollRecord->salary ?? Salary::find($payrollRecord->salary_id);
        abort_unless($salary, 422, 'لا يوجد هيكل راتب مرتبط بهذا السجل.');
        $insuranceDueSnapshot = data_get($payrollRecord->calculation_snapshot, 'insurance_due');
        $insuranceDue = $insuranceDueSnapshot === null
            ? $this->insuranceDueForExistingRecord($payrollRecord)
            : (bool) $insuranceDueSnapshot;
        $snapshot = $payrollRecord->calculation_snapshot ?? [];
        $advanceDetails = (array) ($snapshot['advance_details'] ?? []);
        $advanceAvailable = round(collect($advanceDetails)->sum(fn ($detail) => (float) ($detail['installment'] ?? 0) + (float) ($detail['remaining_after'] ?? 0)), 2);
        $advanceRate = $request->filled('advance_rate') ? (float) $data['advance_rate'] : null;
        $advanceAmount = $advanceRate !== null ? round((float) $payrollRecord->gross_salary * $advanceRate / 100, 2) : (float) ($data['loans'] ?? 0);
        if ($advanceDetails && $advanceAmount > $advanceAvailable) {
            return back()->withErrors(['advance_rate' => 'قيمة خصم السلفة تتجاوز الرصيد المتبقي منها.']);
        }
        if (!$advanceDetails && $advanceRate !== null && $advanceAmount > 0) {
            return back()->withErrors(['advance_rate' => 'لا توجد سلفة مجدولة لهذا الموظف في هذا المسير.']);
        }
        $remainingToAllocate = $advanceAmount;
        $updatedAdvanceDetails = array_map(function (array $detail) use (&$remainingToAllocate): array {
            $available = round((float) ($detail['installment'] ?? 0) + (float) ($detail['remaining_after'] ?? 0), 2);
            $installment = round(min($available, $remainingToAllocate), 2);
            $remainingToAllocate = round($remainingToAllocate - $installment, 2);
            $detail['installment'] = $installment;
            $detail['remaining_after'] = round($available - $installment, 2);
            return $detail;
        }, $advanceDetails);
        $data['loans'] = $advanceAmount;
        $inputs = array_merge($data, [
            'absence_days'=>(float)$payrollRecord->absence_days, 'penalty_days'=>(float)$payrollRecord->penalty_days,
            'incentives'=>(float) $payrollRecord->incentives, 'commission'=>(float) $payrollRecord->commission, 'profit_share'=>(float) $payrollRecord->profit_share,
            'advance_earnings'=>(float) data_get($snapshot, 'inputs.advance_earnings', 0),
        ]);
        $result = $calculator->monthly((float)$payrollRecord->gross_salary, $inputs, $salary->insurance_wage ? (float)$salary->insurance_wage : null, (bool)$salary->no_insurance || !$insuranceDue);
        $result = $service->applyStructureStatutoryValues($result, $salary, $insuranceDue);
        $result = $service->applyProration($result, (float) ($payrollRecord->calculation_snapshot['proration_factor'] ?? 1));
        $before = $payrollRecord->only(['ot_day_hours','ot_night_hours','bonus','loans','other_earnings','other_deductions','net_salary']);
        $payrollRecord->update([
            'ot_day_hours'=>$result['ot_day_hours'], 'ot_night_hours'=>$result['ot_night_hours'], 'ot_day_amount'=>$result['ot_day_amount'], 'ot_night_amount'=>$result['ot_night_amount'],
            'day_overtime_hours'=>$result['ot_day_hours'], 'night_overtime_hours'=>$result['ot_night_hours'], 'day_overtime_amount'=>$result['ot_day_amount'], 'night_overtime_amount'=>$result['ot_night_amount'],
            'overtime_amount'=>$result['overtime_amount'], 'total_overtime'=>$result['overtime_amount'], 'bonus'=>$result['bonus'], 'incentives'=>$result['incentives'], 'commission'=>$result['commission'], 'profit_share'=>$result['profit_share'], 'loans'=>$result['loans'],
            'other_earnings'=>$result['other_earnings'], 'other_deductions'=>$result['other_deductions'], 'income_tax'=>$result['income_tax'],
            'absence_deduction'=>$result['absence_deduction'], 'penalty_deduction'=>$result['penalty_deduction'], 'penalties'=>$result['penalty_deduction'],
            'deductions'=>$result['monthly_deductions'], 'net_salary'=>$result['net_monthly'],
            'calculation_snapshot'=>array_merge($snapshot, ['insurance_due'=>$insuranceDue, 'advance_earnings'=>$inputs['advance_earnings'], 'advance_details'=>$updatedAdvanceDetails, 'advance_override_rate'=>$advanceRate, 'advance_override_amount'=>$advanceRate !== null ? $advanceAmount : null]),
        ]);
        ActivityLog::record('payroll_inputs_update', 'تم تحديث المدخلات المتغيرة لمسير راتب.', $payrollRecord, $before, $payrollRecord->fresh()->only(['ot_day_hours','ot_night_hours','bonus','loans','other_earnings','other_deductions','net_salary']), 'payroll');
        if ($request->wantsJson()) {
            $updatedRecord = $payrollRecord->fresh()->load('employee');
            $updatedRecord->setAttribute('advance_earnings', (float) data_get($updatedRecord->calculation_snapshot, 'advance_earnings', 0));
            $updatedRecord->setAttribute('additional_amount', (float) $updatedRecord->overtime_amount
                + (float) $updatedRecord->advance_earnings + (float) $updatedRecord->bonus
                + (float) $updatedRecord->incentives + (float) $updatedRecord->commission
                + (float) $updatedRecord->profit_share + (float) $updatedRecord->other_earnings);
            return response()->json(['success' => true, 'message' => 'Payroll inputs recalculated.', 'record' => $updatedRecord]);
        }
        return back()->with('toast', 'تمت إعادة حساب راتب الموظف من المدخلات المتغيرة.');
    }

    private function insuranceDueForExistingRecord(PayrollRecord $record): bool
    {
        $employee = $record->employee;
        if (!$employee?->hire_date) return true;

        $month = Carbon::parse($record->payroll_month)->startOfMonth();
        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        return !($hireDate->isSameMonth($month) && !$hireDate->isSameDay($month));
    }

    public function destroyRun(PayrollRun $payrollRun, PayrollRunService $service)
    {
        abort_unless((int) $payrollRun->company_id === (int) auth()->user()?->company_id, 404);
        abort_unless($payrollRun->status === 'Locked', 422, 'يمكن حذف المسير المقفول نهائيًا فقط من هذا الإجراء.');
        $service->destroyDraft($payrollRun);
        return back()->with('toast', 'تم حذف المسير المقفول وسجلاته.');
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
        $companyId = auth()->user()?->company_id;
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $records = PayrollRecord::with(['employee.department', 'employee.job'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('payroll_month', $month->toDateString())->orderByDesc('id')->get();

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
