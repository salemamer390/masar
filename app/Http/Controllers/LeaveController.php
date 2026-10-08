<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\HrOperationalSettingsService;
use App\Services\EmployeeLeaveStatusService;
use App\Services\PayrollPeriodGuard;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly EmployeeLeaveStatusService $leaveStatus,
    )
    {
    }

    public function index(Request $request)
    {
        $query = Leave::with(['employee.department', 'leaveType'])->orderByDesc('start_date');
        if ($request->filled('status')) $query->where('status', $request->status);
        $leaves = $query->paginate(20)->withQueryString();
        $pendingLeaves = Leave::whereIn('status', ['Pending', 'Manager Approved'])->count();
        $approvedThisMonth = Leave::where('status', 'Approved')->whereMonth('start_date', now()->month)->whereYear('start_date', now()->year)->count();
        $totalDays = Leave::where('status', 'Approved')->sum('days');
        $onLeaveToday = Leave::where('status', 'Approved')->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->count();
        $leaveTypes = LeaveType::where('is_active', 1)->get();
        $employees = Employee::orderBy('full_name')->get();

        return view('leaves.index', compact('leaves', 'pendingLeaves', 'approvedThisMonth', 'totalDays', 'onLeaveToday', 'leaveTypes', 'employees'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $periodGuard->ensureRangeOpen($start, $end);
        $overlap = Leave::where('employee_id', $data['employee_id'])->whereNotIn('status', ['Rejected', 'مرفوضة'])
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
        if ($overlap) return back()->withErrors(['leave' => 'يوجد طلب إجازة غير مرفوض يتداخل مع هذه المدة.'])->withInput();
        $days = $this->workingDays($start, $end);
        if ($days < 1) return back()->withErrors(['leave' => 'الفترة المحددة لا تحتوي على أيام عمل وفق تقويم الشركة.'])->withInput();
        $leave = Leave::create($data + ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $days, 'status' => 'Pending']);
        ActivityLog::record('leave_create', 'Leave request created.', $leave, null, $leave->toArray(), 'leaves');
        return back()->with('toast', 'Leave request created.');
    }

    public function approve(Leave $leave, Request $request, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $oldValues = $leave->only(['status', 'manager_approved_by', 'manager_approved_at', 'approved_by', 'approved_at']);
        if ($leave->status === 'Pending') {
            $leave->update(['status' => 'Manager Approved', 'manager_approved_by' => auth()->id(), 'manager_approved_at' => now()]);
            ActivityLog::record('leave_manager_approve', 'Leave request approved by manager.', $leave, $oldValues, $leave->only(['status', 'manager_approved_by', 'manager_approved_at']), 'leaves');
            return back()->with('toast', 'Leave request moved to HR review.');
        }
        if ($leave->status !== 'Manager Approved') return back()->withErrors(['leave' => 'Leave request cannot be approved in its current state.']);
        $leave->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        $this->leaveStatus->sync();
        ActivityLog::record('leave_approve', 'Leave request approved by HR.', $leave, $oldValues, $leave->only(['status', 'approved_by', 'approved_at']), 'leaves');
        return back()->with('toast', 'Leave request approved.');
    }

    public function reject(Leave $leave, Request $request, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $data = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:500']]);
        $oldValues = $leave->only(['status', 'approved_by', 'approved_at', 'rejection_reason']);
        $leave->update(['status' => 'Rejected', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejection_reason' => $data['rejection_reason'] ?? null]);
        ActivityLog::record('leave_reject', 'Leave request rejected.', $leave, $oldValues, $leave->only(['status', 'approved_by', 'approved_at', 'rejection_reason']), 'leaves');
        return back()->with('toast', 'Leave request rejected.');
    }

    public function destroy(Leave $leave, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        abort_if(in_array($leave->status, ['Approved', 'Manager Approved'], true), 422, 'Approved leave requests cannot be deleted.');
        $snapshot = $leave->toArray();
        $leave->delete();
        ActivityLog::record('leave_delete', 'Leave request deleted.', null, $snapshot, null, 'leaves');
        return back()->with('toast', 'Leave request deleted.');
    }

    private function workingDays(Carbon $start, Carbon $end): int
    {
        if (!$this->hrSettings->excludeRestAndHolidaysFromLeave()) {
            return $start->diffInDays($end) + 1;
        }

        $days = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($this->hrSettings->isWeeklyRest($date) || $this->hrSettings->isHoliday($date)) {
                continue;
            }
            $days++;
        }

        return $days;
    }
}
