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
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PayrollController extends Controller
{
    /** Display the prototype employee-payroll drawer as a separate page. */
    public function employeeSalaries(Request $request)
    {
        $request->attributes->set('payroll_view', 'payroll.employee-salaries');
        return $this->index($request, app(PayrollRunService::class));
    }

    public function index(Request $request, PayrollRunService $service)
    {
        $companyId = auth()->user()?->company_id;
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $search = trim((string) $request->input('search', ''));
        $records = PayrollRecord::with(['employee.department', 'employee.job', 'salary'])
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

        // Truthful «آخر تحديث» for the displayed month's run: the created_at of the
        // latest successful payroll_generate log for that exact run - never the
        // page-open time and never an unrelated update timestamp.
        $currentRun = PayrollRun::when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereDate('payroll_month', $month->toDateString())->first();
        $lastPayrollRefreshAt = $currentRun ? ActivityLog::where('company_id', $currentRun->company_id)
            ->where('model_type', PayrollRun::class)->where('model_id', $currentRun->id)
            ->where('action', 'payroll_generate')->latest('created_at')->value('created_at') : null;
        $lastPayrollRefreshAt = $lastPayrollRefreshAt ? Carbon::parse($lastPayrollRefreshAt) : null;
        // Draft runs show who was skipped (and why) before the approve button; every run shows when it closes.
        $runReports = [];
        foreach ($runs as $run) {
            if ($run->status === 'Draft' && $run->company_id) {
                $runReports[$run->id] = $service->reconciliationReport($run);
            }
        }
        $hrSettings = app(\App\Services\HrOperationalSettingsService::class);
        $grace = $hrSettings->attendanceCorrectionDays();
        $runMeta = $runs->mapWithKeys(fn ($run) => [$run->id => [
            'month_ended' => $service->monthHasEnded($run->payroll_month),
            // Cross-month periods end on their own last day, not at month end.
            'period_ended' => $service->periodForRun($run)['end']->lt(\Carbon\Carbon::today()),
            // The snapshotted close date wins; older rows derive it from the period end + grace.
            'closes_on' => ($run->closes_at
                ? $run->closes_at->copy()
                : $service->closesAtFor($service->periodForRun($run)['end'], $grace))->format('d/m/Y'),
        ]])->all();
        $cardDetails = [
            'employees' => Employee::when($companyId, fn ($query) => $query->where('company_id', $companyId))->orderBy('full_name')->get(['id','full_name','employee_code']),
            'reviewed' => $recordCollection->whereIn('status', ['معتمدة', 'تم الصرف', 'Approved', 'Paid'])->values(),
            'drafts' => $recordCollection->whereIn('status', ['قيد الإعداد', 'Draft'])->values(),
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
            // Keep the salary-structure modal tied to the actual structure
            // referenced by the payroll record, with a snapshot fallback.
            'structure_basic_salary' => (float) ($record->salary?->basic_salary ?? $record->basic_salary),
            'structure_allowances' => (float) ($record->salary?->allowances ?? $record->allowances),
            'structure_housing_allowance' => (float) ($record->salary?->housing_allowance ?? $record->housing_allowance),
            'structure_transportation_allowance' => (float) ($record->salary?->transportation_allowance ?? $record->transportation_allowance),
            'structure_meal_allowance' => (float) ($record->salary?->meal_allowance ?? $record->meal_allowance),
            'structure_clothing_allowance' => (float) ($record->salary?->clothing_allowance ?? $record->clothing_allowance),
            'structure_gross_salary' => (float) ($record->salary?->gross_salary ?? $record->gross_salary),
            'structure_net_salary' => (float) ($record->salary?->net_salary ?? $record->agreed_net_salary ?? $record->net_salary),
            'structure_allowance_limit_pct' => (float) ($record->salary?->allowance_limit_pct ?? 0),
            'structure_allowance_limit' => (float) ($record->salary?->allowance_limit ?? 0),
            'structure_allowance_remaining' => (float) ($record->salary?->allowance_remaining ?? 0),
            'structure_applied_insurance_wage' => (float) ($record->salary?->applied_insurance_wage ?? $record->insurance_wage ?? 0),
            'structure_no_insurance' => (bool) ($record->salary?->no_insurance ?? false),
            'structure_employer_insurance' => (float) ($record->salary?->employer_insurance ?? $record->employer_insurance ?? 0),
            'structure_personal_exemption' => (float) ($record->salary?->personal_exemption ?? 0),
            'structure_taxable_annual' => (float) ($record->salary?->taxable_annual ?? 0),
            'structure_martyrs_fund' => (float) ($record->salary?->martyrs_fund ?? $record->martyrs_fund ?? 0),
            'structure_fixed_deductions' => (float) ($record->salary?->fixed_deductions ?? $record->fixed_deductions ?? 0),
            'structure_total_deductions' => (float) ($record->salary?->total_deductions ?? $record->deductions ?? 0),
            'structure_employee_cost' => (float) ($record->salary?->employee_cost ?? $record->employee_cost),
            'structure_notes' => $record->salary?->notes,
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
            'monthly_total' => (float) $record->monthly_total,
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
        $statusLabels = ['Draft' => 'مسودة', 'Approved' => 'معتمد', 'Locked' => 'مغلق'];
        $prototypePeople = $recordCollection->values()->map(function ($record) use ($statusLabels) {
            $snapshot = (array) ($record->calculation_snapshot ?? []);
            $inputs = (array) ($snapshot['inputs'] ?? []);
            $employee = $record->employee;
            $displayFactor = min(1, max(0, (float) data_get($snapshot, 'proration_factor', 1)));
            $hasProratedFields = array_key_exists('to_date_entitlement', $snapshot);
            $displayAllowance = static function (string $field) use ($record, $displayFactor, $hasProratedFields): float {
                $value = (float) ($record->{$field} ?? 0);
                return $hasProratedFields ? $value : round($value * $displayFactor, 2);
            };
            $displayBasic = $hasProratedFields
                ? (float) $record->basic_salary
                : round((float) $record->basic_salary * $displayFactor, 2);
            $items = [
                ['الراتب الأساسي', (float) $record->basic_salary],
            ];
            $allowanceItems = 0;
            foreach ([
                'housing_allowance' => 'بدل السكن',
                'transportation_allowance' => 'بدل الانتقال',
                'meal_allowance' => 'بدل الوجبة',
                'clothing_allowance' => 'بدل ملبس',
            ] as $key => $label) {
                $value = $displayAllowance($key);
                if ($value > 0) {
                    $items[] = [$label, $value];
                    $allowanceItems += $value;
                }
            }
            if ($allowanceItems <= 0 && (float) $record->allowances > 0) {
                $items[] = ['البدلات', (float) $record->allowances];
            }
            $dayOvertimeAmount = max((float) ($record->day_overtime_amount ?? 0), (float) ($record->ot_day_amount ?? 0));
            $nightOvertimeAmount = max((float) ($record->night_overtime_amount ?? 0), (float) ($record->ot_night_amount ?? 0));
            foreach ([
                'day_overtime_amount' => 'إضافي نهاري',
                'night_overtime_amount' => 'إضافي ليلي',
                'bonus' => 'المكافآت',
                'incentives' => 'الحوافز',
                'commission' => 'العمولات',
                'profit_share' => 'نصيب الأرباح',
                'other_earnings' => 'استحقاقات أخرى',
            ] as $key => $label) {
                $value = $key === 'day_overtime_amount' ? $dayOvertimeAmount : ($key === 'night_overtime_amount' ? $nightOvertimeAmount : (float) ($record->{$key} ?? 0));
                if ($value > 0) $items[] = [$label, $value];
            }
            $advance = (float) data_get($inputs, 'advance_earnings', data_get($snapshot, 'advance_earnings', 0));
            if ($advance > 0) $items[] = ['السلفة المصروفة', $advance];
            $advanceInstallment = (float) data_get($inputs, 'loans', data_get($snapshot, 'loans', $record->loans ?? 0));
            // Split the penalty deduction between automatic lateness/early-departure tiers and manual
            // penalties in proportion to their requested days (a monthly cap reduces both equally).
            $policyDays = (float) data_get($inputs, 'attendance_penalty_days', 0);
            $policyDetails = (array) data_get($inputs, 'attendance_penalty_details', []);
            $penaltyTotal = (float) $record->penalty_deduction;
            $requestedDays = (float) data_get($snapshot, 'penalty_days_requested', data_get($inputs, 'penalty_days', 0));
            // The calculator prices the policy share from the basic exactly; fall back to the
            // proportional estimate only for snapshots stored before that split existed.
            $policyShare = (float) data_get($snapshot, 'attendance_penalty_deduction', 0);
            if ($policyShare <= 0 && $policyDays > 0 && $requestedDays > 0) $policyShare = round($penaltyTotal * min(1, $policyDays / $requestedDays), 2);
            $penaltyLines = $policyShare > 0
                ? [['جزاءات التأخير والخروج المبكر ('.count($policyDetails).' مخالفة)', $policyShare], ['خصم الجزاءات', round($penaltyTotal - $policyShare, 2)]]
                : [['خصم الجزاءات', $penaltyTotal]];
            $deductions = [
                ['تأمين الموظف', (float) $record->employee_insurance],
                ['الضريبة', (float) $record->income_tax],
                ['صندوق الشهداء', (float) $record->martyrs_fund],
                ['خصم الغياب', (float) $record->absence_deduction],
                ...$penaltyLines,
                ['خصم وقت التأخير والخروج المبكر', (float) ($record->attendance_time_deduction ?? 0)],
                ['قسط السلفة', $advanceInstallment],
                ['خصومات أخرى', (float) $record->other_deductions],
            ];
            $deductions = array_values(array_filter($deductions, fn ($item) => $item[1] > 0));
            $initials = mb_substr((string) ($employee?->full_name ?? '—'), 0, 2);
            $toDateEntitlement = (float) data_get(
                $snapshot,
                'to_date_entitlement',
                $displayBasic
            );
            $displayAllowances = $displayAllowance('housing_allowance')
                + $displayAllowance('transportation_allowance')
                + $displayAllowance('meal_allowance')
                + $displayAllowance('clothing_allowance');
            // Mirror the «البدلات» fallback line above: when no itemized allowance
            // columns are stored, the lump allowances amount is part of the total
            // too - otherwise the drawer total is less than the sum of its lines.
            if ($displayAllowances <= 0 && (float) $record->allowances > 0) {
                $displayAllowances = (float) $record->allowances;
            }
            $displayEntitlements = round(
                $toDateEntitlement
                + $displayAllowances
                + (float) $record->overtime_amount
                + (float) $record->bonus
                + (float) $record->incentives
                + (float) $record->commission
                + (float) $record->profit_share
                + (float) $record->other_earnings
                + (float) data_get($inputs, 'advance_earnings', data_get($snapshot, 'advance_earnings', 0)),
                2
            );
            return [
                'recordId' => (int) $record->id,
                'id' => (int) $employee?->id,
                'code' => (string) ($employee?->employee_code ?? ''),
                'phone' => (string) ($employee?->phone ?? ''),
                'name' => $employee?->full_name ?? '—',
                'role' => $employee?->job?->title ?? '—',
                'dept' => $employee?->department?->name ?? '—',
                'basic' => (float) $record->basic_salary,
                'allow' => (float) $record->allowances,
                'allowanceItems' => array_values(array_filter([
                    ['بدل السكن', (float) $record->housing_allowance],
                    ['بدل الانتقال', (float) $record->transportation_allowance],
                    ['بدل الوجبة', (float) $record->meal_allowance],
                    ['بدل ملبس', (float) $record->clothing_allowance],
                ], fn ($item) => $item[1] > 0)),
                'allowanceItemsProrated' => array_values(array_filter([
                    ['بدل السكن', $displayAllowance('housing_allowance')],
                    ['بدل الانتقال', $displayAllowance('transportation_allowance')],
                    ['بدل الوجبة', $displayAllowance('meal_allowance')],
                    ['بدل ملبس', $displayAllowance('clothing_allowance')],
                ], fn ($item) => $item[1] > 0)),
                'additionalItems' => array_values(array_filter([
                    ['إضافي نهاري', $dayOvertimeAmount],
                    ['إضافي ليلي', $nightOvertimeAmount],
                    ['المكافآت', (float) $record->bonus],
                    ['الحوافز', (float) $record->incentives],
                    ['العمولات', (float) $record->commission],
                    ['نصيب الأرباح', (float) $record->profit_share],
                    ['استحقاقات أخرى', (float) $record->other_earnings],
                    ['السلفة المصروفة', (float) data_get($inputs, 'advance_earnings', data_get($snapshot, 'advance_earnings', 0))],
                ], fn ($item) => $item[1] > 0)),
                'gross' => (float) $record->gross_salary,
                'entitlements' => $displayEntitlements,
                'deduct' => (float) $record->deductions,
                'totalDeductions' => (float) data_get($snapshot, 'monthly_deductions', $record->deductions),
                'net' => (float) $record->net_salary,
                'companyCost' => (float) $record->employee_cost,
                'state' => $record->status,
                'stateLabel' => $statusLabels[$record->status] ?? $record->status,
                'insurance' => (float) $record->employee_insurance,
                'initials' => $initials,
                'structureLabel' => $record->salary_id ? 'هيكل #' . $record->salary_id : 'بدون هيكل',
                'effectiveFrom' => $record->salary?->effective_from?->format('d/m/Y'),
                'agreedNet' => (float) ($record->agreed_net_salary ?? 0),
                'salaryUrl' => $record->salary_id ? route('salaries.index', ['employee_id' => $employee?->id]) : null,
                'items' => array_merge([['المستحق حتى اليوم', $toDateEntitlement]], array_slice($items, 1)),
                'deductions' => $deductions,
                'penaltyReasons' => array_values(array_map(fn ($d) => (string) ($d['reason'] ?? ''), $policyDetails)),
                'paidDays' => (int) data_get($snapshot, 'paid_days', 0),
                'absenceDays' => (float) ($record->absence_days ?? 0),
                'overtimeHours' => (float) ($record->ot_day_hours ?? 0) + (float) ($record->ot_night_hours ?? 0),
                'overtimeAmount' => (float) ($record->overtime_amount ?? 0),
                'dayOvertimeAmount' => max((float) ($record->day_overtime_amount ?? 0), (float) ($record->ot_day_amount ?? 0)),
                'nightOvertimeAmount' => max((float) ($record->night_overtime_amount ?? 0), (float) ($record->ot_night_amount ?? 0)),
            ];
        })->values();
        $prototypeStats = [
            'cost' => (float) $totalCost,
            'net' => (float) $totalNet,
            'insurance' => (float) $totalEmployeeInsurance,
            'tax' => (float) $totalTax,
        ];

        // Every payroll month that already exists (for the month picker and the "previous payrolls" tab).
        $runStatuses = PayrollRun::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->get(['payroll_month', 'status'])
            ->mapWithKeys(fn ($run) => [Carbon::parse($run->payroll_month)->format('Y-m') => $run->status])
            ->all();
        $arabicMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $payrollHistory = PayrollRecord::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->selectRaw('payroll_month, COUNT(*) as employees, SUM(net_salary) as net, SUM(employee_cost) as cost')
            ->groupBy('payroll_month')
            ->orderByDesc('payroll_month')
            ->get()
            ->map(function ($row) use ($runStatuses, $statusLabels, $arabicMonths, $month) {
                $date = Carbon::parse($row->payroll_month);
                $status = $runStatuses[$date->format('Y-m')] ?? null;

                return [
                    'month' => $date->format('Y-m-01'),
                    'label' => $date->locale(app()->getLocale())->translatedFormat('F Y'),
                    'status' => $status,
                    'status_label' => $status ? ($statusLabels[$status] ?? $status) : '—',
                    'employees' => (int) $row->employees,
                    'net' => (float) $row->net,
                    'cost' => (float) $row->cost,
                    'current' => $date->format('Y-m') === $month->format('Y-m'),
                ];
            })
            ->values();

        $view = $request->attributes->get('payroll_view', 'payroll.index');
        return view($view, compact('payrollHistory', 'records','runs','totalEmployees','reviewedCount','pendingReview','totalNet','totalCost','totalEmployeeInsurance','totalTax','totalEmployerInsurance','totalMartyrs','month','cardDetails','payrollDetails','prototypePeople','prototypeStats','runReports','runMeta', 'lastPayrollRefreshAt'));
    }

    /** Render a standalone, print-ready payslip. The screen drawer is not used. */
    public function payslipPdf(PayrollRecord $payrollRecord)
    {
        abort_unless((int) $payrollRecord->company_id === (int) auth()->user()?->company_id, 404);

        $payrollRecord->load(['employee.department', 'employee.job', 'salary']);
        $snapshot = (array) ($payrollRecord->calculation_snapshot ?? []);
        $inputs = (array) ($snapshot['inputs'] ?? []);
        $positive = static fn ($value): bool => (float) $value > 0;
        $advance = (float) data_get($inputs, 'advance_earnings', data_get($snapshot, 'advance_earnings', 0));
        $arabic = static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $allowances = array_values(array_filter([
            ['بدل السكن', (float) $payrollRecord->housing_allowance],
            ['بدل الانتقال', (float) $payrollRecord->transportation_allowance],
            ['بدل الوجبة', (float) $payrollRecord->meal_allowance],
            ['بدل ملبس', (float) $payrollRecord->clothing_allowance],
        ], fn ($item) => $positive($item[1])));
        $allowanceLabels = [$arabic('&#x628;&#x62f;&#x644; &#x627;&#x644;&#x633;&#x643;&#x646;'), $arabic('&#x628;&#x62f;&#x644; &#x627;&#x644;&#x627;&#x646;&#x62a;&#x642;&#x627;&#x644;'), $arabic('&#x628;&#x62f;&#x644; &#x627;&#x644;&#x648;&#x62c;&#x628;&#x629;'), $arabic('&#x628;&#x62f;&#x644; &#x645;&#x644;&#x628;&#x633;')];
        foreach ($allowances as $index => &$item) { $item[0] = $allowanceLabels[$index] ?? $arabic('&#x627;&#x644;&#x628;&#x62f;&#x644;&#x627;&#x62a;'); }
        unset($item);
        if (!$allowances && (float) $payrollRecord->allowances > 0) {
            $allowances[] = ['البدلات', (float) $payrollRecord->allowances];
        }

        $overtime = array_values(array_filter([
            ['الإضافي النهاري', (float) ($payrollRecord->day_overtime_amount ?: $payrollRecord->ot_day_amount)],
            ['الإضافي الليلي', (float) ($payrollRecord->night_overtime_amount ?: $payrollRecord->ot_night_amount)],
            ['الإضافي', (float) $payrollRecord->overtime_amount],
        ], fn ($item) => $positive($item[1])));
        $overtimeLabels = [$arabic('&#x627;&#x644;&#x625;&#x636;&#x627;&#x641;&#x64a; &#x627;&#x644;&#x646;&#x647;&#x627;&#x631;&#x64a;'), $arabic('&#x627;&#x644;&#x625;&#x636;&#x627;&#x641;&#x64a; &#x627;&#x644;&#x644;&#x64a;&#x644;&#x64a;'), $arabic('&#x627;&#x644;&#x625;&#x636;&#x627;&#x641;&#x64a;')];
        foreach ($overtime as $index => &$item) { $item[0] = $overtimeLabels[$index] ?? $item[0]; }
        unset($item);
        if (count($overtime) > 1) {
            $overtime = array_values(array_filter($overtime, fn ($item) => $item[0] !== 'الإضافي'));
        }

        $additional = array_values(array_filter([
            ['المكافآت', (float) $payrollRecord->bonus],
            ['الحوافز', (float) $payrollRecord->incentives],
            ['العمولات', (float) $payrollRecord->commission],
            ['نصيب الأرباح', (float) $payrollRecord->profit_share],
            ['استحقاقات أخرى', (float) $payrollRecord->other_earnings],
            ['السلفة المصروفة', $advance],
        ], fn ($item) => $positive($item[1])));

        $additionalLabels = [$arabic('&#x627;&#x644;&#x645;&#x643;&#x627;&#x641;&#x622;&#x627;&#x62a;'), $arabic('&#x627;&#x644;&#x62d;&#x648;&#x627;&#x641;&#x632;'), $arabic('&#x627;&#x644;&#x639;&#x645;&#x648;&#x644;&#x627;&#x62a;'), $arabic('&#x646;&#x635;&#x64a;&#x628; &#x627;&#x644;&#x623;&#x631;&#x628;&#x627;&#x62d;'), $arabic('&#x627;&#x633;&#x62a;&#x62d;&#x642;&#x627;&#x642;&#x627;&#x62a; &#x623;&#x62e;&#x631;&#x649;'), $arabic('&#x627;&#x644;&#x633;&#x644;&#x641;&#x629; &#x627;&#x644;&#x645;&#x635;&#x631;&#x648;&#x641;&#x629;')];
        foreach ($additional as $index => &$item) { $item[0] = $additionalLabels[$index] ?? $item[0]; }
        unset($item);
        $earnings = array_merge(
            [['المستحق حتى اليوم', (float) data_get($snapshot, 'to_date_entitlement', $payrollRecord->basic_salary)]],
            $allowances,
            $overtime,
            $additional,
        );
        if (isset($earnings[0])) { $earnings[0][0] = $arabic('&#x627;&#x644;&#x645;&#x633;&#x62a;&#x62d;&#x642; &#x62d;&#x62a;&#x649; &#x627;&#x644;&#x64a;&#x648;&#x645;'); }
        $deductions = array_values(array_filter([
            ['تأمين الموظف', (float) $payrollRecord->employee_insurance],
            ['الضريبة', (float) $payrollRecord->income_tax],
            ['صندوق الشهداء', (float) $payrollRecord->martyrs_fund],
            ['خصم الغياب', (float) $payrollRecord->absence_deduction],
            ['خصم الجزاءات', (float) $payrollRecord->penalty_deduction],
            ['خصم وقت التأخير والخروج المبكر', (float) ($payrollRecord->attendance_time_deduction ?? 0)],
            ['قسط السلفة', (float) $payrollRecord->loans],
            ['استقطاعات أخرى', (float) $payrollRecord->other_deductions],
        ], fn ($item) => $positive($item[1])));

        $deductionLabels = [$arabic('&#x62a;&#x623;&#x645;&#x64a;&#x646; &#x627;&#x644;&#x645;&#x648;&#x638;&#x641;'), $arabic('&#x627;&#x644;&#x636;&#x631;&#x64a;&#x628;&#x629;'), $arabic('&#x635;&#x646;&#x62f;&#x648;&#x642; &#x627;&#x644;&#x634;&#x647;&#x62f;&#x627;&#x621;'), $arabic('&#x62e;&#x635;&#x645; &#x627;&#x644;&#x63a;&#x64a;&#x627;&#x628;'), $arabic('&#x62e;&#x635;&#x645; &#x627;&#x644;&#x62c;&#x632;&#x627;&#x621;&#x627;&#x62a;'), $arabic('&#x62e;&#x635;&#x645; &#x648;&#x642;&#x62a; &#x627;&#x644;&#x62a;&#x623;&#x62e;&#x64a;&#x631; &#x648;&#x627;&#x644;&#x62e;&#x631;&#x648;&#x62c; &#x627;&#x644;&#x645;&#x628;&#x643;&#x631;'), $arabic('&#x642;&#x633;&#x637; &#x627;&#x644;&#x633;&#x644;&#x641;&#x629;'), $arabic('&#x627;&#x633;&#x62a;&#x642;&#x637;&#x627;&#x639;&#x627;&#x62a; &#x623;&#x62e;&#x631;&#x649;')];
        foreach ($deductions as $index => &$item) { $item[0] = $deductionLabels[$index] ?? $item[0]; }
        unset($item);
        $viewData = [
            'record' => $payrollRecord,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'earningsTotal' => collect($earnings)->sum(fn ($item) => (float) $item[1]),
            'deductionsTotal' => collect($deductions)->sum(fn ($item) => (float) $item[1]),
            'net' => (float) $payrollRecord->net_salary,
        ];

        $tempDir = storage_path('app/mpdf');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $employeeName = trim((string) ($payrollRecord->employee?->full_name ?? 'employee'));
        // Keep the employee's name (including Arabic letters) in the downloaded filename.
        $employeeName = preg_replace('/[^\pL\pN]+/u', '-', $employeeName) ?: 'employee';
        $employeeName = trim($employeeName, '-_');
        $monthName = $payrollRecord->payroll_month?->format('Y-m') ?: 'period';
        $fileName = 'payslip-' . $employeeName . '-' . $monthName . '.pdf';
        $encodedFileName = rawurlencode($fileName);
        $title = 'Payslip - ' . ($payrollRecord->employee?->full_name ?? $payrollRecord->id);

        // Try the normal size first, then slightly smaller sizes. Keep automatic page
        // breaks enabled in every attempt so content is never clipped.
        $render = function (float $scale, bool $allowPageBreaks) use ($viewData, $tempDir, $title): Mpdf {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'tempDir' => $tempDir,
                'default_font' => 'dejavusans',
                'directionality' => 'rtl',
                'margin_left' => 14, 'margin_right' => 14,
                'margin_top' => 14, 'margin_bottom' => 14,
                'margin_header' => 0, 'margin_footer' => 0,
                'shrink_tables_to_fit' => 1,
            ]);
            if (!$allowPageBreaks) {
                $mpdf->SetAutoPageBreak(false);
            }
            $mpdf->SetTitle($title);
            $html = view('payroll.payslip-pdf', $viewData + ['scale' => $scale])->render();
            // The PDF view is server-rendered only; no scripts or buttons may affect pagination.
            $html = preg_replace('~<script\\b[^>]*>.*?</script>~is', '', $html);
            $html = preg_replace('~<button\\b[^>]*>.*?</button>~is', '', $html);
            $mpdf->WriteHTML($html);

            return $mpdf;
        };

        $mpdf = null;
        foreach ([1.0, 0.92, 0.84, 0.76] as $scale) {
            $attempt = $render($scale, true);
            if ((int) $attempt->page === 1) {
                $mpdf = $attempt;
                break;
            }
            \Illuminate\Support\Facades\Log::warning('Payslip PDF overflowed one page', ['record' => $payrollRecord->id, 'pages' => (int) $attempt->page, 'scale' => $scale]);
        }
        $mpdf ??= $render(0.76, true);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . $encodedFileName,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** Payroll always belongs to one company; accounts without a company cannot generate it. */
    private function requireCompany(): int
    {
        $companyId = auth()->user()?->company_id;
        abort_if(!$companyId, 403, 'حساب بدون شركة لا يمكنه إنشاء مسير رواتب.');

        return (int) $companyId;
    }

    private function assertSameCompany(PayrollRun $run): void
    {
        abort_unless((int) $run->company_id === (int) auth()->user()?->company_id, 404);
    }

    private function assertSameCompanyRecord(PayrollRecord $record): void
    {
        abort_unless((int) $record->company_id === (int) auth()->user()?->company_id, 404);
    }

    public function generate(Request $request, PayrollRunService $service)
    {
        $companyId = $this->requireCompany();
        $targetMonth = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $existing = PayrollRun::where('company_id', $companyId)->whereDate('payroll_month', $targetMonth->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return back()->withErrors(['payroll' => __('لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا. أنشئ مسيرًا للشهر التالي أو راجع المسير الحالي.')]);
        }
        $service->generate($targetMonth, null, $companyId);
        return back()->with('toast', __('تم إنشاء/تحديث مسودة مسير') . $targetMonth->translatedFormat('F Y') . ' وحفظ لقطة القواعد المستخدمة.');
    }

    public function generateRun(Request $request, PayrollRunService $service)
    {
        $companyId = $this->requireCompany();
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $existing = PayrollRun::where('company_id', $companyId)->whereDate('payroll_month', $month->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return back()->withErrors(['payroll' => __('لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا.')]);
        }
        $service->generate($month, null, $companyId);
        return back()->with('toast', __('تم إنشاء مسير الرواتب كمسودة.'));
    }

    public function startGeneration(Request $request, PayrollRunService $service)
    {
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $companyId = $this->requireCompany();
        $existing = PayrollRun::where('company_id', $companyId)
            ->whereDate('payroll_month', $month->toDateString())->first();
        if ($existing && in_array($existing->status, ['Approved', 'Locked'], true)) {
            return response()->json(['message' => __('لا يمكن تحديث مسير تم اعتماده أو قفله نهائيًا.')], 422);
        }

        $run = DB::transaction(function () use ($month, $existing, $companyId) {
            $run = $existing
                ? PayrollRun::whereKey($existing->id)->lockForUpdate()->firstOrFail()
                : PayrollRun::create(['company_id' => $companyId, 'payroll_month' => $month->toDateString(), 'status' => 'Generating', 'created_by' => auth()->id()]);
            $run->update(['status' => 'Generating', 'created_by' => $run->created_by ?: auth()->id()]);
            return $run;
        });

        $total = count($service->payableEmployeeIds($month, $companyId));
        return response()->json(['success' => true, 'run_id' => $run->id, 'month' => $month->toDateString(), 'total' => $total, 'processed' => 0, 'batch_size' => 25]);
    }

    public function generateBatch(Request $request, PayrollRunService $service)
    {
        $data = $request->validate(['month' => ['required', 'date'], 'offset' => ['required', 'integer', 'min:0'], 'batch_size' => ['nullable', 'integer', 'min:10', 'max:50']]);
        $month = Carbon::parse($data['month'])->startOfMonth();
        $companyId = $this->requireCompany();
        $allIds = collect($service->payableEmployeeIds($month, $companyId))->values();
        $offset = (int) $data['offset'];
        $batchSize = (int) ($data['batch_size'] ?? 25);
        $ids = $allIds->slice($offset, $batchSize)->values()->all();
        $run = PayrollRun::where('company_id', $companyId)->whereDate('payroll_month', $month->toDateString())->firstOrFail();
        abort_unless($run->status === 'Generating', 422, 'لا توجد عملية إنشاء نشطة لهذا الشهر.');
        if ($ids) $service->generate($month, $ids, $companyId);
        $processed = min($offset + count($ids), $allIds->count());
        $complete = $processed >= $allIds->count();
        if ($complete) {
            $run->records()->whereNull('paid_at')->when($allIds->isNotEmpty(), fn ($query) => $query->whereNotIn('employee_id', $allIds->all()))->delete();
            $run->update(['status' => 'Draft']);
            // Batched generation writes no per-batch activity log; record the completed
            // refresh once so «آخر تحديث» reflects refreshes started from the runs page too.
            ActivityLog::record('payroll_generate', 'Payroll run generated.', $run, null, $run->toArray(), 'payroll');
        }
        return response()->json(['success' => true, 'processed' => $processed, 'total' => $allIds->count(), 'percent' => $allIds->count() ? (int) round(($processed / $allIds->count()) * 100) : 100, 'complete' => $complete]);
    }

    public function approveRun(Request $request, PayrollRun $payrollRun, PayrollRunService $service)
    {
        $this->assertSameCompany($payrollRun);
        try {
            $service->approve($payrollRun, $request->boolean('confirm_skipped'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }
        return back()->with('toast', __('تم اعتماد مسير الرواتب.'));
    }

    public function lockRun(PayrollRun $payrollRun, PayrollRunService $service)
    {
        $this->assertSameCompany($payrollRun);
        try {
            $service->lock($payrollRun);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }
        return back()->with('toast', __('تم قفل مسير الرواتب نهائيًا.'));
    }

    /**
     * Manual final close of an ended period during its correction grace: freezes the
     * period's attendance, the saved summary, the run and its records together through
     * the same unified path the automatic daily close uses. Requires an explicit
     * confirmation; a period that has not ended is rejected; repeating is a no-op.
     */
    public function closePeriod(Request $request, PayrollRunService $service)
    {
        $data = $request->validate([
            'payroll_run_id' => ['required', 'integer', 'exists:payroll_runs,id'],
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.accepted' => 'لازم تأكد إن أرقام الفترة هتتثبت نهائيًا ومش هتقبل تعديل.',
        ]);
        $run = \App\Models\PayrollRun::withoutGlobalScopes()->findOrFail((int) $data['payroll_run_id']);
        $this->assertSameCompany($run);
        try {
            $service->closeRun($run);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->withErrors(['payroll' => $e->getMessage()]);
        }
        return back()->with('toast', __('تم قفل الفترة نهائيًا: الحضور والملخص المحفوظ والمسير وسجلاته اتثبتوا ومش هيقبلوا تعديل.'));
    }

    public function approve(PayrollRecord $payrollRecord)
    {
        $this->assertSameCompanyRecord($payrollRecord);
        if ($payrollRecord->is_locked || $payrollRecord->paid_at !== null || in_array($payrollRecord->status, ['تم الصرف', 'Paid', 'Locked', 'Approved', 'معتمدة'], true)) {
            return back()->with('toast', __('لا يمكن تغيير حالة هذا الراتب لأنه معتمد أو مقفل أو مصروف.'));
        }
        $payrollRecord->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLog::record('payroll_approve', "تم اعتماد راتب {$payrollRecord->employee?->full_name}.", $payrollRecord);
        return back()->with('toast', __('تم اعتماد الراتب.'));
    }

    /** Update variable monthly inputs without changing the employee's fixed salary structure. */
    public function updateInputs(Request $request, PayrollRecord $payrollRecord, PayrollRunService $service)
    {
        $this->assertSameCompanyRecord($payrollRecord);
        abort_if($payrollRecord->is_locked || !in_array($payrollRecord->status, ['Draft', 'قيد الإعداد'], true), 422, 'لا يمكن تعديل مدخلات مسير معتمد أو مقفل.');
        $data = $request->validate([
            'ot_day_hours'=>['nullable','numeric','min:0'], 'ot_night_hours'=>['nullable','numeric','min:0'],
            'bonus'=>['nullable','numeric','min:0'], 'loans'=>['nullable','numeric','min:0'],
            'advance_rate'=>['nullable','numeric','min:0','max:100'],
            'other_earnings'=>['nullable','numeric','min:0'], 'other_deductions'=>['nullable','numeric','min:0'],
        ]);
        $salary = $payrollRecord->salary ?? Salary::find($payrollRecord->salary_id);
        abort_unless($salary, 422, 'لا يوجد هيكل راتب مرتبط بهذا السجل.');
        $snapshot = $payrollRecord->calculation_snapshot ?? [];
        $advanceDetails = (array) ($snapshot['advance_details'] ?? []);
        $advanceAvailable = round(collect($advanceDetails)->sum(fn ($detail) => (float) ($detail['installment'] ?? 0) + (float) ($detail['remaining_after'] ?? 0)), 2);
        $advanceRate = $request->filled('advance_rate') ? (float) $data['advance_rate'] : null;
        $advanceAmount = $advanceRate !== null ? round((float) $payrollRecord->gross_salary * $advanceRate / 100, 2) : (float) ($data['loans'] ?? 0);
        if ($advanceDetails && $advanceAmount > $advanceAvailable) {
            return back()->withErrors(['advance_rate' => __('قيمة خصم السلفة تتجاوز الرصيد المتبقي منها.')]);
        }
        if (!$advanceDetails && $advanceRate !== null && $advanceAmount > 0) {
            return back()->withErrors(['advance_rate' => __('لا توجد سلفة مجدولة لهذا الموظف في هذا المسير.')]);
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
        $before = $payrollRecord->only(['ot_day_hours','ot_night_hours','bonus','loans','other_earnings','other_deductions','net_salary']);
        $changes = array_merge(
            array_intersect_key($data, array_flip(['ot_day_hours', 'ot_night_hours', 'bonus', 'loans', 'other_earnings', 'other_deductions'])),
            ['advance_details' => $updatedAdvanceDetails]
        );
        $recalculated = $service->recalculateRecord($payrollRecord, $changes);
        $recalculated->update(['calculation_snapshot' => array_merge((array) $recalculated->calculation_snapshot, [
            'advance_earnings' => (float) data_get($recalculated->calculation_snapshot, 'inputs.advance_earnings', 0),
            'advance_override_rate' => $advanceRate,
            'advance_override_amount' => $advanceRate !== null ? $advanceAmount : null,
        ])]);
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
        return back()->with('toast', __('تمت إعادة حساب راتب الموظف من المدخلات المتغيرة.'));
    }

    private function insuranceDueForExistingRecord(PayrollRecord $record): bool
    {
        $employee = $record->employee;
        if (!$employee?->hire_date) return true;

        $month = Carbon::parse($record->payroll_month)->startOfMonth();
        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        return !($hireDate->isSameMonth($month) && !$hireDate->isSameDay($month));
    }

    /** Deleting a run is for the company owner, or any role the owner granted payroll.delete to. */
    public function destroyRun(PayrollRun $payrollRun, PayrollRunService $service)
    {
        $this->assertSameCompany($payrollRun);
        abort_unless(auth()->user()?->hasPermission('payroll.delete'), 403, 'حذف المسير متاح لمدير الشركة أو لدور مُنح هذه الصلاحية.');
        $service->destroy($payrollRun);
        return back()->with('toast', __('تم حذف المسير وسجلاته.'));
    }

    public function pay(PayrollRecord $payrollRecord)
    {
        $this->assertSameCompanyRecord($payrollRecord);
        if ($payrollRecord->paid_at !== null || in_array($payrollRecord->status, ['تم الصرف', 'Paid'], true)) {
            return back()->with('toast', __('هذا الراتب تم صرفه بالفعل.'));
        }
        if (!in_array($payrollRecord->status, ['Approved', 'Locked', 'معتمدة'], true)) {
            return back()->withErrors(['payroll' => __('يجب اعتماد الراتب قبل تسجيل الصرف.')]);
        }
        // A locked record keeps its Locked status; paid_at marks the payment.
        $payrollRecord->update(['paid_at' => now(), 'status' => $payrollRecord->is_locked ? $payrollRecord->status : 'Paid']);
        ActivityLog::record('payroll_pay', "تم صرف راتب الموظف ({$payrollRecord->employee?->full_name}) لشهر {$payrollRecord->payroll_month->format('m/Y')}.", $payrollRecord);
        return back()->with('toast', __('تم تسجيل صرف الراتب بنجاح.'));
    }

    public function export(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $month = Carbon::parse($request->input('month', now()->format('Y-m-01')))->startOfMonth();
        $records = PayrollRecord::with(['employee.department', 'employee.job', 'salary'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($request->boolean('approved_only'), fn ($query) => $query->whereIn('status', ['Approved', 'Locked']))
            ->whereDate('payroll_month', $month->toDateString())->orderByDesc('id')->get();

        if (in_array((string) $request->string('format')->lower(), ['xlsx', 'excel'], true)) {
            $template = resource_path('templates/payroll-template.xlsx');
            abort_unless(is_file($template), 500, 'Excel template is missing.');
            $spreadsheet = IOFactory::load($template);
            $sheet = $spreadsheet->getActiveSheet();

            // Keep every template heading visible in Excel. Long Arabic
            // headings otherwise get clipped in the RTL sheet and appear
            // blank in some Excel versions.
            $headerStyle = $sheet->getStyle('A1:X1');
            $headerStyle->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(58);

            $row = 2;
            foreach ($records as $record) {
                if ($row > 200) {
                    $sheet->insertNewRowBefore($row, 1);
                    $sheet->duplicateStyle($sheet->getStyle('A2:X2'), "A{$row}:X{$row}");
                }
                $extras = (float) $record->bonus + (float) $record->incentives + (float) $record->commission + (float) $record->profit_share + (float) $record->other_earnings;
                $sheet->fromArray([[
                    $record->employee?->full_name,
                    $record->employee?->employee_code,
                    $record->employee?->department?->name,
                    $record->agreed_net_salary,
                    $record->basic_salary,
                    $record->allowances,
                    $record->overtime_amount,
                    round($extras, 2),
                    data_get($record->calculation_snapshot, 'inputs.advance_earnings', 0),
                    $record->gross_salary,
                    $record->insurance_wage,
                    $record->employee_insurance,
                    $record->income_tax,
                    $record->martyrs_fund,
                    $record->absence_deduction,
                    $record->penalty_deduction,
                    $record->loans,
                    $record->other_deductions,
                    $record->deductions,
                    $record->net_salary,
                    $record->employer_insurance,
                    $record->employee_cost,
                    data_get($record->calculation_snapshot, 'paid_days'),
                    $record->status,
                ]], null, "A{$row}");
                $row++;
            }
            $filename = 'payroll-' . $month->format('Y-m') . '.xlsx';
            $temporary = tempnam(storage_path('app'), 'payroll-');
            (new Xlsx($spreadsheet))->save($temporary);
            return response()->download($temporary, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        if ((string) $request->string('format')->lower() === 'csv') {
            $filename = 'payroll-' . $month->format('Y-m') . '.csv';
            return response()->streamDownload(function () use ($records) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['الموظف', 'كود الموظف', 'القسم', 'صافي المتفق عليه', 'الأساسي', 'إجمالي البدلات', 'الإضافي', 'مكافآت وحوافز وعمولات واستحقاقات أخرى', 'السلفة المصروفة', 'Gross', 'الأجر التأميني', 'تأمين الموظف', 'ضريبة الدخل', 'صندوق أسر الشهداء', 'خصم الغياب', 'خصم الجزاءات', 'قسط السلفة', 'خصومات أخرى', 'إجمالي الخصومات', 'صافي الراتب', 'حصة صاحب العمل', 'تكلفة الموظف', 'أيام مدفوعة', 'الحالة']);
                foreach ($records as $r) {
                    $extras = (float) $r->bonus + (float) $r->incentives + (float) $r->commission + (float) $r->profit_share + (float) $r->other_earnings;
                    fputcsv($out, \App\Support\CsvSafe::row([
                        $r->employee?->full_name, $r->employee?->employee_code, $r->employee?->department?->name,
                        $r->agreed_net_salary, $r->basic_salary, $r->allowances, $r->overtime_amount, round($extras, 2),
                        data_get($r->calculation_snapshot, 'inputs.advance_earnings', 0),
                        $r->gross_salary, $r->insurance_wage, $r->employee_insurance, $r->income_tax, $r->martyrs_fund,
                        $r->absence_deduction, $r->penalty_deduction, $r->loans, $r->other_deductions, $r->deductions, $r->net_salary,
                        $r->employer_insurance, $r->employee_cost, data_get($r->calculation_snapshot, 'paid_days'), $r->status,
                    ]));
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('payroll.export', compact('records', 'month'));
    }
}
