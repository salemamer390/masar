<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Penalty;
use App\Models\HrRequest;
use App\Models\MonthlySummarySnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\LeaveBalanceService;
use App\Services\OvertimeWindowMatcher;

class JobDataController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth()
            : now()->startOfMonth();
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $employeeQuery = Employee::select([
                'id', 'employee_code', 'full_name', 'department_id', 'job_id',
                'branch_id', 'employment_type_id', 'hire_date', 'birth_date', 'shift_start', 'shift_end',
                'leave_entitlement_category', 'manual_annual_balance',
            ])->with(['department:id,name', 'job:id,title', 'branch:id,name', 'employmentType:id,name', 'currentSalary'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($x) use ($request) { $x->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%'); }))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('contract_status'), function ($q) use ($request) { return $request->contract_status === 'active' ? $q->where(function ($x) { $x->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', today()); }) : $q->whereDate('contract_end_date', '<', today()); })
            ->orderByDesc('id');

        $perPage = in_array((int) $request->input('per_page', 50), [25, 50, 100, 200], true)
            ? (int) $request->input('per_page', 50)
            : 50;
        $employees = $employeeQuery->paginate($perPage)->withQueryString();

        $monthlyRows = $this->buildMonthlyRows($employees->getCollection(), $month);
        $summaryRowData = $monthlyRows->mapWithKeys(fn (array $row) => [(string) $row['employee']->id => [
            'employee_id' => $row['employee']->id,
            'employee_code' => (string) $row['employee']->employee_code,
            'early_leave_minutes' => $row['early_leave_minutes'],
            'day_overtime_minutes' => $row['day_overtime_minutes'],
            'night_overtime_minutes' => $row['night_overtime_minutes'],
            'annual_balance_remaining' => $row['annual_balance_remaining'],
            'advance_request_count' => $row['advance_request_count'],
            'permission_request_count' => $row['permission_request_count'],
            'mission_request_count' => $row['mission_request_count'],
        ]])->all();
        $summaryHistory = MonthlySummarySnapshot::orderByDesc('summary_month')->get();

        $totalJobs = Job::where('is_active', 1)->count();
        $totalDepartments = Department::where('is_active', 1)->count();
        $totalBranches = Branch::where('is_active', 1)->count();
        $activeContracts = Employee::whereNotNull('contract_start_date')->count();
        $contractsDueSoon = Employee::whereBetween('contract_end_date', [today(), today()->copy()->addDays(30)])->count();

        $jobs = Job::where('is_active', 1)->get();
        $departments = Department::where('is_active', 1)->get();
        $branches = Branch::where('is_active', 1)->get();
        $employmentTypes = EmploymentType::all();

        return view('jobs.index', compact(
            'employees', 'monthlyRows', 'summaryRowData', 'month', 'summaryHistory',
            'totalJobs',
            'totalDepartments',
            'totalBranches',
            'activeContracts',
            'contractsDueSoon',
            'jobs',
            'departments',
            'branches',
            'employmentTypes'
        ));
    }

    /** Return the next employee batch for the progressive monthly-summary table. */
    public function rows(Request $request)
    {
        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth()
            : now()->startOfMonth();
        $employees = Employee::select([
                'id', 'employee_code', 'full_name', 'department_id', 'job_id',
                'branch_id', 'employment_type_id', 'hire_date', 'birth_date', 'shift_start', 'shift_end',
                'leave_entitlement_category', 'manual_annual_balance',
            ])->with(['department:id,name', 'job:id,title', 'branch:id,name', 'employmentType:id,name', 'currentSalary'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('full_name', 'like', '%'.$request->input('search').'%')
                ->orWhere('employee_code', 'like', '%'.$request->input('search').'%')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->input('department_id')))
            ->when($request->filled('contract_status'), function ($q) use ($request) {
                return $request->input('contract_status') === 'active'
                    ? $q->where(fn ($x) => $x->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', today()))
                    : $q->whereDate('contract_end_date', '<', today());
            })
            ->orderByDesc('id')->paginate(
                in_array((int) $request->input('per_page', 50), [25, 50, 100, 200], true)
                    ? (int) $request->input('per_page', 50)
                    : 50
            )->withQueryString();

        return response()->json([
            'html' => view('jobs._summary_rows', ['monthlyRows' => $this->buildMonthlyRows($employees->getCollection(), $month)])->render(),
            'next_url' => $employees->hasMorePages()
                ? route('jobs.rows', array_merge($request->query(), ['page' => $employees->currentPage() + 1]))
                : null,
        ]);
    }


    public function captureSnapshot(Carbon $month, ?int $createdBy = null): MonthlySummarySnapshot
    {
        $month = $month->copy()->startOfMonth();
        $employees = Employee::select(['id', 'employee_code', 'full_name', 'department_id', 'job_id', 'hire_date', 'birth_date', 'shift_start', 'shift_end', 'leave_entitlement_category', 'manual_annual_balance'])
            ->with(['department:id,name', 'job:id,title', 'currentSalary'])->orderBy('id')->get();
        $rows = $this->snapshotRows($this->buildMonthlyRows($employees, $month));

        return MonthlySummarySnapshot::updateOrCreate(
            ['company_id' => auth()->user()->company_id, 'summary_month' => $month->toDateString()],
            ['rows' => $rows, 'employee_count' => count($rows), 'created_by' => $createdBy ?? auth()->id()]
        );

        return back()->with('toast', 'تم حفظ سجل ملخص الشهر.');
    }

    /** Legacy endpoint kept for cached clients; the UI no longer exposes manual saving. */
    public function saveSnapshot(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $this->captureSnapshot(Carbon::createFromFormat('Y-m', $data['month']), auth()->id());
        return back()->with('toast', 'تم حفظ سجل ملخص الشهر.');
    }

    public function export(Request $request)
    {
        $month = $request->filled('month') ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth() : now()->startOfMonth();
        $employees = Employee::select(['id', 'employee_code', 'full_name', 'department_id', 'job_id', 'hire_date', 'birth_date', 'leave_entitlement_category', 'manual_annual_balance'])
            ->with(['department:id,name', 'job:id,title', 'currentSalary'])->orderBy('id')->get();
        return $this->excelResponse($this->snapshotRows($this->buildMonthlyRows($employees, $month)), $month);
    }

    public function showSnapshot(MonthlySummarySnapshot $snapshot)
    {
        return response()->json([
            'month' => $snapshot->summary_month->format('Y-m'),
            'rows' => $snapshot->rows,
            'export_url' => route('jobs.summary-history.export', $snapshot),
        ]);
    }

    public function exportSnapshot(MonthlySummarySnapshot $snapshot)
    {
        return $this->excelResponse($snapshot->rows, $snapshot->summary_month);
    }

    private function buildMonthlyRows($employees, Carbon $month)
    {
        $period = app(\App\Services\HrOperationalSettingsService::class)->payrollPeriod($month);
        $monthStart = $period['start']->toDateString();
        $monthEnd = $period['end']->toDateString();
        $employeeIds = $employees->pluck('id');
        $attendance = Attendance::whereIn('employee_id', $employeeIds)->whereBetween('attendance_date', [$monthStart, $monthEnd])->get()->groupBy('employee_id');
        $penalties = Penalty::with('penaltyType')->whereIn('employee_id', $employeeIds)->whereBetween('penalty_date', [$monthStart, $monthEnd])->whereIn('status', ['Approved', 'معتمد'])->get()->groupBy('employee_id');
        $requests = HrRequest::with('requestType')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('created_at', '<=', $monthEnd)
            ->get()
            ->filter(fn (HrRequest $request) => $this->requestBelongsToPeriod($request, $period['start'], $period['end']))
            ->groupBy('employee_id');
        $leaves = Leave::with('leaveType')->whereIn('employee_id', $employeeIds)->whereDate('start_date', '<=', $monthEnd)->whereDate('end_date', '>=', $monthStart)->get()->groupBy('employee_id');
        $leaveBalanceService = app(LeaveBalanceService::class);
        $balances = $leaveBalanceService->balancesFor($employees, $month);

        return $employees->map(function (Employee $employee) use ($attendance, $penalties, $leaves, $requests, $month, $period, $balances): array {
            $attendanceRows = $attendance->get($employee->id, collect());
            $employeeLeaves = $leaves->get($employee->id, collect());
            $employeePenalties = $penalties->get($employee->id, collect());
            $employeeRequests = $requests->get($employee->id, collect());
            $requestSummary = $this->summarizeRequests($employeeRequests);
            $periodStart = $period['start'];
            $periodEnd = $period['end'];
            $leaveDays = $employeeLeaves->mapWithKeys(fn (Leave $leave): array => [$leave->id => $this->daysWithinPeriod($leave, $periodStart, $periodEnd)]);
            $leaveByPolicy = fn (string $policy): float => $employeeLeaves->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === $policy)->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0);
            $unpaidLeaveDays = $employeeLeaves->filter(fn (Leave $leave) => $leave->leaveType && !$leave->leaveType->is_paid)->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0);
            $balance = $balances->get($employee->id, ['annual_balance_remaining' => 0]);
            return [
                'employee' => $employee, 'salary' => $employee->currentSalary,
                'attendance_days' => $attendanceRows->whereNotIn('status', ['Absent', 'غائب'])->count(),
                'late_days' => $attendanceRows->filter(fn (Attendance $item) => in_array($item->status, ['Late', 'متأخر'], true) || (int) $item->late_minutes > 0)->count(),
                'late_minutes' => (int) $attendanceRows->sum('late_minutes'),
                'absence_days' => $attendanceRows->filter(fn (Attendance $item) => in_array($item->status, ['Absent', 'غائب'], true))->count(),
                'annual_leave_days' => $leaveByPolicy('annual'), 'casual_leave_days' => $leaveByPolicy('casual'), 'sick_leave_days' => $leaveByPolicy('sick'), 'maternity_leave_days' => $leaveByPolicy('maternity'),
                'other_leave_days' => max(0, (float) $employeeLeaves->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0) - $leaveByPolicy('annual') - $leaveByPolicy('casual') - $leaveByPolicy('sick') - $leaveByPolicy('maternity')),
                'total_leave_days' => (float) $employeeLeaves->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0), 'unpaid_leave_days' => $unpaidLeaveDays,
                'early_leave_minutes' => (int) $attendanceRows->sum('early_leave_minutes'),
                'day_overtime_minutes' => (int) $attendanceRows->sum(fn (Attendance $item) => $this->requestedOvertimeMinutes($item, $employee, $employeeRequests, 'day')),
                'night_overtime_minutes' => (int) $attendanceRows->sum(fn (Attendance $item) => $this->requestedOvertimeMinutes($item, $employee, $employeeRequests, 'night')),
                'penalty_days' => $employeePenalties->sum(fn (Penalty $penalty) => $penalty->deduction_days ?? $penalty->penaltyType?->default_deduction_days ?? 0),
                'penalty_count' => $employeePenalties->count(), 'annual_balance_remaining' => $balance['annual_balance_remaining'],
                'advance_request_count' => $this->requestTypeCount($employeeRequests, 'advance'),
                'permission_request_count' => $this->requestTypeCount($employeeRequests, 'permission'),
                'mission_request_count' => $this->requestTypeCount($employeeRequests, 'mission'),
                // Legacy keys remain available for cached row templates during rollout.
                'approved_request_count' => $requestSummary['approved_count'],
                'request_days' => $requestSummary['days'], 'request_hours' => $requestSummary['hours'],
                'request_additions' => $requestSummary['additions'], 'request_deductions' => $requestSummary['deductions'],
                'request_types' => $requestSummary['types'],
            ];
        });
    }

    private function snapshotRows($monthlyRows): array
    {
        return $monthlyRows->map(fn (array $row) => [
            'employee_code' => $row['employee']->employee_code, 'employee_name' => $row['employee']->full_name,
            'department' => $row['employee']->department?->name ?? '—',
            'attendance_days' => $row['attendance_days'], 'late_days' => $row['late_days'], 'late_minutes' => $row['late_minutes'], 'absence_days' => $row['absence_days'], 'early_leave_minutes' => $row['early_leave_minutes'], 'day_overtime_minutes' => $row['day_overtime_minutes'], 'night_overtime_minutes' => $row['night_overtime_minutes'],
            'annual_leave_days' => $row['annual_leave_days'], 'casual_leave_days' => $row['casual_leave_days'], 'sick_leave_days' => $row['sick_leave_days'], 'unpaid_leave_days' => $row['unpaid_leave_days'], 'maternity_leave_days' => $row['maternity_leave_days'], 'other_leave_days' => $row['other_leave_days'], 'total_leave_days' => $row['total_leave_days'], 'annual_balance_remaining' => $row['annual_balance_remaining'],
            'penalty_count' => $row['penalty_count'], 'penalty_days' => $row['penalty_days'],
            'advance_request_count' => $row['advance_request_count'], 'permission_request_count' => $row['permission_request_count'], 'mission_request_count' => $row['mission_request_count'],
            'approved_request_count' => $row['approved_request_count'], 'request_days' => $row['request_days'], 'request_hours' => $row['request_hours'], 'request_additions' => $row['request_additions'], 'request_deductions' => $row['request_deductions'], 'request_types' => $row['request_types'],
        ])->values()->all();
    }

    private function requestBelongsToPeriod(HrRequest $request, Carbon $periodStart, Carbon $periodEnd): bool
    {
        $payload = is_array($request->payload) ? $request->payload : [];
        $start = $payload['start_date'] ?? $payload['date'] ?? $payload['request_date'] ?? null;
        $end = $payload['end_date'] ?? $start;
        if ($start) {
            try {
                return Carbon::parse($start)->lte($periodEnd)
                    && Carbon::parse($end)->gte($periodStart);
            } catch (\Throwable) {
                // Invalid optional payload dates fall back to created_at.
            }
        }
        return $request->created_at?->betweenIncluded($periodStart, $periodEnd) ?? false;
    }

    private function summarizeRequests($requests): array
    {
        $result = ['approved_count' => 0, 'days' => 0.0, 'hours' => 0.0, 'additions' => 0.0, 'deductions' => 0.0, 'types' => []];
        foreach ($requests as $request) {
            $payload = is_array($request->payload) ? $request->payload : [];
            $result['approved_count']++;
            $result['days'] += (float) ($payload['days'] ?? $payload['duration_days'] ?? 0);
            $result['hours'] += (float) ($payload['hours'] ?? $payload['duration_hours'] ?? 0);
            $result['additions'] += (float) ($payload['addition'] ?? $payload['addition_amount'] ?? $payload['amount_added'] ?? 0);
            $result['deductions'] += (float) ($payload['deduction'] ?? $payload['deduction_amount'] ?? $payload['amount_deducted'] ?? 0);
            $label = $request->requestType?->display_name ?: $request->title;
            if ($label && !in_array($label, $result['types'], true)) $result['types'][] = $label;
        }
        $result['types'] = implode('، ', $result['types']);
        return $result;
    }

    private function excelResponse(array $rows, Carbon $month)
    {
        return response()->view('jobs.export_monthly_summary', compact('rows', 'month'), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="monthly-summary-'.$month->format('Y-m').'.xls"',
        ]);
    }


    private function requestTypeCount($requests, string $source): int
    {
        return $requests->filter(fn (HrRequest $request) => ($request->requestType?->source_key ?: $request->request_type) === $source)->count();
    }

    private function overtimeMinutes(Attendance $attendance, Employee $employee, string $period): int
    {
        if (!$attendance->check_in || !$attendance->check_out) return 0;
        $settings = app(\App\Services\HrOperationalSettingsService::class);
        $start = Carbon::parse($employee->shift_start ?: $settings->start());
        $end = Carbon::parse($employee->shift_end ?: $settings->end());
        $checkIn = Carbon::parse($attendance->check_in);
        $checkOut = Carbon::parse($attendance->check_out);
        $startMinutes = $start->hour * 60 + $start->minute;
        $endMinutes = $end->hour * 60 + $end->minute;
        $inMinutes = $checkIn->hour * 60 + $checkIn->minute;
        $outMinutes = $checkOut->hour * 60 + $checkOut->minute;
        if ($endMinutes <= $startMinutes) $endMinutes += 1440;
        if ($outMinutes <= $inMinutes) $outMinutes += 1440;
        $segments = [];
        if ($inMinutes < $startMinutes) $segments[] = [$inMinutes, min($startMinutes, $outMinutes)];
        if ($outMinutes > $endMinutes) $segments[] = [max($endMinutes, $inMinutes), $outMinutes];
        $settings = app(\App\Services\HrOperationalSettingsService::class);
        $nightStart = $this->timeToMinutes($settings->nightStart());
        $nightEnd = $this->timeToMinutes($settings->nightEnd());
        if ($nightEnd <= $nightStart) $nightEnd += 1440;
        $total = 0;
        foreach ($segments as [$from, $to]) {
            if ($to <= $from) continue;
            $night = 0;
            for ($day = -1; $day <= 1; $day++) {
                $offset = 1440 * $day;
                $night += max(0, min($to, $nightEnd + $offset) - max($from, $nightStart + $offset));
            }
            $total += $period === 'night' ? $night : max(0, ($to - $from) - $night);
        }
        return (int) round($total);
    }

    private function requestedOvertimeMinutes(Attendance $attendance, Employee $employee, $requests, string $period): int
    {
        $actual = $this->overtimeMinutes($attendance, $employee, $period);
        if ($actual <= 0 || !$attendance->check_in || !$attendance->check_out) return 0;
        $date = Carbon::parse($attendance->attendance_date)->toDateString();

        $settings = app(\App\Services\HrOperationalSettingsService::class);
        $segments = OvertimeWindowMatcher::outsideShiftSegments(
            OvertimeWindowMatcher::timeToMinutes((string) $attendance->check_in) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) $attendance->check_out) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_start ?: $settings->start())) ?? 0,
            OvertimeWindowMatcher::timeToMinutes((string) ($employee->shift_end ?: $settings->end())) ?? 0,
        );

        $authorized = 0;
        foreach ($requests as $request) {
            if (($request->requestType?->source_key ?: $request->request_type) !== 'overtime') continue;
            $payload = is_array($request->payload) ? $request->payload : [];
            $requestDate = $payload['request_date'] ?? $payload['overtime_request_date'] ?? $payload['date'] ?? null;
            if (!$requestDate) continue;
            try {
                if (Carbon::parse($requestDate)->toDateString() !== $date) continue;
            } catch (\Throwable) {
                continue;
            }
            if (($payload['overtime_period'] ?? $payload['period'] ?? null) !== $period) continue;
            $requested = (float) ($payload['hours'] ?? $payload['duration_hours'] ?? $payload['day_overtime_hours'] ?? $payload['night_overtime_hours'] ?? 0) * 60;
            if ($requested <= 0) continue;

            $from = OvertimeWindowMatcher::timeToMinutes($payload['from_time'] ?? null);
            $to = OvertimeWindowMatcher::timeToMinutes($payload['to_time'] ?? null);
            if ($from === null || $to === null) {
                // Overtime requests without a time window no longer earn
                // anything: only actual presence inside the requested window
                // counts. Amer confirmed these legacy untimed requests are
                // test data to be removed by the scoped cleanup command.
                continue;
            }
            $authorized += OvertimeWindowMatcher::authorizedMinutes($segments, $from, $to, $requested);
        }

        return (int) min($actual, max(0, $authorized));
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }

    private function daysWithinPeriod(Leave $leave, Carbon $periodStart, Carbon $periodEnd): int
    {
        $start = Carbon::parse($leave->start_date)->max($periodStart->copy()->startOfDay());
        $end = Carbon::parse($leave->end_date)->min($periodEnd->copy()->endOfDay());
        return $start->greaterThan($end) ? 0 : $start->diffInDays($end) + 1;
    }
}


