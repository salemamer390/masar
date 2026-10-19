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
use App\Models\MonthlySummarySnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\LeaveBalanceService;

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
                'branch_id', 'employment_type_id', 'hire_date', 'birth_date',
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
            'employees', 'monthlyRows', 'month', 'summaryHistory',
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
                'branch_id', 'employment_type_id', 'hire_date', 'birth_date',
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
            'html' => view('jobs._rows', ['monthlyRows' => $this->buildMonthlyRows($employees->getCollection(), $month)])->render(),
            'next_url' => $employees->hasMorePages()
                ? route('jobs.rows', array_merge($request->query(), ['page' => $employees->currentPage() + 1]))
                : null,
        ]);
    }


    public function captureSnapshot(Carbon $month, ?int $createdBy = null): MonthlySummarySnapshot
    {
        $month = $month->copy()->startOfMonth();
        $employees = Employee::select(['id', 'employee_code', 'full_name', 'department_id', 'job_id', 'hire_date', 'birth_date', 'leave_entitlement_category', 'manual_annual_balance'])
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
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();
        $employeeIds = $employees->pluck('id');
        $attendance = Attendance::whereIn('employee_id', $employeeIds)->whereBetween('attendance_date', [$monthStart, $monthEnd])->where('approval_status', 'Approved')->get()->groupBy('employee_id');
        $penalties = Penalty::with('penaltyType')->whereIn('employee_id', $employeeIds)->whereBetween('penalty_date', [$monthStart, $monthEnd])->whereIn('status', ['Approved', 'معتمد'])->get()->groupBy('employee_id');
        $leaves = Leave::with('leaveType')->whereIn('employee_id', $employeeIds)->where('status', 'Approved')->whereDate('start_date', '<=', $monthEnd)->whereDate('end_date', '>=', $monthStart)->get()->groupBy('employee_id');
        $leaveBalanceService = app(LeaveBalanceService::class);
        $balances = $leaveBalanceService->balancesFor($employees, $month->year);

        return $employees->map(function (Employee $employee) use ($attendance, $penalties, $leaves, $month, $balances): array {
            $attendanceRows = $attendance->get($employee->id, collect());
            $employeeLeaves = $leaves->get($employee->id, collect());
            $employeePenalties = $penalties->get($employee->id, collect());
            $leaveDays = $employeeLeaves->mapWithKeys(fn (Leave $leave): array => [$leave->id => $this->daysWithinMonth($leave, $month)]);
            $leaveByPolicy = fn (string $policy): float => $employeeLeaves->filter(fn (Leave $leave) => $leave->leaveType?->policy_code === $policy)->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0);
            $unpaidLeaveDays = $employeeLeaves->filter(fn (Leave $leave) => $leave->leaveType && !$leave->leaveType->is_paid)->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0);
            $balance = $balances->get($employee->id, ['remaining' => 0]);
            return [
                'employee' => $employee, 'salary' => $employee->currentSalary,
                'attendance_days' => $attendanceRows->whereNotIn('status', ['Absent', 'غائب'])->count(),
                'late_days' => $attendanceRows->filter(fn (Attendance $item) => in_array($item->status, ['Late', 'متأخر'], true) || (int) $item->late_minutes > 0)->count(),
                'late_minutes' => (int) $attendanceRows->sum('late_minutes'),
                'absence_days' => $attendanceRows->filter(fn (Attendance $item) => in_array($item->status, ['Absent', 'غائب'], true))->count(),
                'annual_leave_days' => $leaveByPolicy('annual'), 'casual_leave_days' => $leaveByPolicy('casual'), 'sick_leave_days' => $leaveByPolicy('sick'), 'maternity_leave_days' => $leaveByPolicy('maternity'),
                'other_leave_days' => max(0, (float) $employeeLeaves->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0) - $leaveByPolicy('annual') - $leaveByPolicy('casual') - $leaveByPolicy('sick') - $leaveByPolicy('maternity')),
                'total_leave_days' => (float) $employeeLeaves->sum(fn (Leave $leave) => $leaveDays[$leave->id] ?? 0), 'unpaid_leave_days' => $unpaidLeaveDays,
                'penalty_days' => $employeePenalties->sum(fn (Penalty $penalty) => $penalty->deduction_days ?? $penalty->penaltyType?->default_deduction_days ?? 0),
                'penalty_count' => $employeePenalties->count(), 'annual_balance_remaining' => $balance['remaining'],
            ];
        });
    }

    private function snapshotRows($monthlyRows): array
    {
        return $monthlyRows->map(fn (array $row) => [
            'employee_code' => $row['employee']->employee_code, 'employee_name' => $row['employee']->full_name,
            'department' => $row['employee']->department?->name ?? '—',
            'attendance_days' => $row['attendance_days'], 'late_days' => $row['late_days'], 'late_minutes' => $row['late_minutes'], 'absence_days' => $row['absence_days'],
            'annual_leave_days' => $row['annual_leave_days'], 'casual_leave_days' => $row['casual_leave_days'], 'sick_leave_days' => $row['sick_leave_days'], 'unpaid_leave_days' => $row['unpaid_leave_days'], 'maternity_leave_days' => $row['maternity_leave_days'], 'other_leave_days' => $row['other_leave_days'], 'total_leave_days' => $row['total_leave_days'],
            'penalty_count' => $row['penalty_count'], 'penalty_days' => $row['penalty_days'], 'annual_balance_remaining' => $row['annual_balance_remaining'],
        ])->values()->all();
    }

    private function excelResponse(array $rows, Carbon $month)
    {
        return response()->view('jobs.export', compact('rows', 'month'), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="monthly-summary-'.$month->format('Y-m').'.xls"',
        ]);
    }


    private function daysWithinMonth(Leave $leave, Carbon $month): int
    {
        $start = Carbon::parse($leave->start_date)->max($month->copy()->startOfMonth());
        $end = Carbon::parse($leave->end_date)->min($month->copy()->endOfMonth());
        return $start->greaterThan($end) ? 0 : $start->diffInDays($end) + 1;
    }
}


