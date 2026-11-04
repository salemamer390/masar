<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Department;
use App\Services\HrOperationalSettingsService;
use App\Services\EmployeeLeaveStatusService;
use App\Services\PayrollPeriodGuard;
use App\Services\LeaveBalanceService;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly EmployeeLeaveStatusService $leaveStatus,
        private readonly LeaveBalanceService $leaveBalances,
    )
    {
    }

    public function index(Request $request)
    {
        $query = Leave::with(['employee.department', 'leaveType'])->orderByDesc('start_date')->orderByDesc('id');
        if ($request->filled('status')) {
            $request->status === 'Approved' ? $query->effective() : $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) $query->where('employee_id', $request->employee_id);
        if ($request->filled('department_id')) $query->whereHas('employee', fn ($q) => $q->where('department_id', $request->department_id));
        if ($request->filled('leave_type_id')) $query->where('leave_type_id', $request->leave_type_id);
        if ($request->filled('from_date')) $query->whereDate('end_date', '>=', $request->from_date);
        if ($request->filled('to_date')) $query->whereDate('start_date', '<=', $request->to_date);
        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $leaves = $query->paginate($perPage)->withQueryString();
        $pendingLeaves = 0;
        $approvedThisMonth = Leave::effective()->whereMonth('start_date', now()->month)->whereYear('start_date', now()->year)->count();
        $totalDays = Leave::effective()->sum('days');
        $onLeaveToday = Leave::effective()->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->count();
        $rejectedThisMonth = Leave::where('status', 'Rejected')->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count();
        $leaveTypes = LeaveType::where('is_active', 1)->get();
        $employees = Employee::orderBy('full_name')->get();
        $departments = Department::where('is_active', 1)->orderBy('name')->get();

        return view('leaves.index', compact('leaves', 'pendingLeaves', 'approvedThisMonth', 'totalDays', 'onLeaveToday', 'rejectedThisMonth', 'leaveTypes', 'employees', 'departments'));
    }

    public function update(Request $request, Leave $leave, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:Pending,Manager Approved,Approved,Rejected'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
            'is_early_grant' => ['nullable', 'boolean'],
            'early_grant_reason' => ['required_if:is_early_grant,1', 'nullable', 'string', 'max:500'],
        ]);
        $data['status'] = ($data['status'] ?? null) === 'Rejected' ? 'Rejected' : 'Approved';
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $this->ensureOwnedReferences($data);
        if (!empty($data['is_early_grant']) && !auth()->user()?->hasPermission('leaves.approve')) {
            abort(403, 'لا يملك المستخدم صلاحية منح إجازة استثنائية.');
        }
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $periodGuard->ensureRangeOpen($start, $end);
        $overlap = Leave::where('id', '!=', $leave->id)->where('employee_id', $data['employee_id'])
            ->effective()
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
        if ($overlap) {
            return $request->wantsJson()
                ? response()->json(['message' => 'يوجد طلب إجازة متداخل مع هذه المدة.'], 422)
                : back()->withErrors(['leave' => 'يوجد طلب إجازة متداخل مع هذه المدة.'])->withInput();
        }
        $days = $this->workingDays($start, $end);
        if ($days < 1) {
            return $request->wantsJson()
                ? response()->json(['message' => 'الفترة المحددة لا تحتوي على أيام عمل.'], 422)
                : back()->withErrors(['leave' => 'الفترة المحددة لا تحتوي على أيام عمل.'])->withInput();
        }
        $previousEmployee = $leave->employee;
        $previousStart = $leave->start_date->copy();
        $oldValues = $leave->only(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'status']);
        $proposed = clone $leave;
        $proposed->fill(['employee_id' => $data['employee_id'], 'leave_type_id' => $data['leave_type_id'], 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'is_early_grant' => (bool) ($data['is_early_grant'] ?? false), 'early_grant_reason' => $data['early_grant_reason'] ?? null]);
        $proposed->unsetRelation('employee')->unsetRelation('leaveType');
        if ($data['status'] === 'Approved') $this->leaveBalances->validateForApproval($proposed, $leave->id);
        $leave->update([
            'employee_id' => $data['employee_id'], 'leave_type_id' => $data['leave_type_id'],
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $days,
            'reason' => $data['reason'] ?? null, 'status' => $data['status'],
            'rejection_reason' => $data['status'] === 'Rejected' ? ($data['rejection_reason'] ?? null) : null,
            'approved_by' => in_array($data['status'], ['Approved', 'Rejected'], true) ? auth()->id() : null,
            'approved_at' => in_array($data['status'], ['Approved', 'Rejected'], true) ? now() : null,
            'manager_approved_by' => in_array($data['status'], ['Manager Approved', 'Approved'], true) ? auth()->id() : null,
            'manager_approved_at' => in_array($data['status'], ['Manager Approved', 'Approved'], true) ? now() : null,
            'is_early_grant' => (bool) ($data['is_early_grant'] ?? false),
            'early_grant_reason' => $data['early_grant_reason'] ?? null,
            'early_grant_approved_by' => ($data['status'] === 'Approved' && !empty($data['is_early_grant'])) ? auth()->id() : null,
            'early_grant_approved_at' => ($data['status'] === 'Approved' && !empty($data['is_early_grant'])) ? now() : null,
        ]);
        $leave->unsetRelation('employee')->unsetRelation('leaveType');
        $this->leaveStatus->sync();
        $this->leaveBalances->sync($leave->employee, $start);
        $this->leaveBalances->sync($previousEmployee, $previousStart);
        if ($previousEmployee->id !== $leave->employee_id) $payrollRuns->refreshEmployeeInOpenDrafts((int) $previousEmployee->id);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $leave->employee_id);
        ActivityLog::record('leave_update', 'Leave request updated.', $leave, $oldValues, $leave->only(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'status']), 'leaves');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Leave request updated.', 'leave' => $leave->fresh()->load(['employee.department', 'leaveType'])])
            : back()->with('toast', 'تم تحديث طلب الإجازة بنجاح.');
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'is_early_grant' => ['nullable', 'boolean'],
            'early_grant_reason' => ['required_if:is_early_grant,1', 'nullable', 'string', 'max:500'],
        ]);
        $data['status'] = ($data['status'] ?? null) === 'Rejected' ? 'Rejected' : 'Approved';
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $this->ensureOwnedReferences($data);
        if (!empty($data['is_early_grant']) && !auth()->user()?->hasPermission('leaves.approve')) {
            abort(403, 'لا يملك المستخدم صلاحية منح إجازة استثنائية.');
        }
        $periodGuard->ensureRangeOpen($start, $end);
        $overlap = Leave::where('employee_id', $data['employee_id'])->effective()
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
        if ($overlap) {
            return $request->wantsJson()
                ? response()->json(['message' => 'يوجد طلب إجازة غير مرفوض يتداخل مع هذه المدة.'], 422)
                : back()->withErrors(['leave' => 'يوجد طلب إجازة غير مرفوض يتداخل مع هذه المدة.'])->withInput();
        }
        $days = $this->workingDays($start, $end);
        if ($days < 1) {
            return $request->wantsJson()
                ? response()->json(['message' => 'الفترة المحددة لا تحتوي على أيام عمل وفق تقويم الشركة.'], 422)
                : back()->withErrors(['leave' => 'الفترة المحددة لا تحتوي على أيام عمل وفق تقويم الشركة.'])->withInput();
        }
        $leave = new Leave(array_merge($data, [
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'days' => $days, 'status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now(),
            'is_early_grant' => (bool) ($data['is_early_grant'] ?? false),
            'early_grant_reason' => $data['early_grant_reason'] ?? null,
            'early_grant_approved_by' => !empty($data['is_early_grant']) ? auth()->id() : null,
            'early_grant_approved_at' => !empty($data['is_early_grant']) ? now() : null,
        ]));
        // Validate before saving, so this record never consumes its own balance twice.
        $this->leaveBalances->validateForApproval($leave);
        $leave->save();
        $this->leaveBalances->sync($leave->employee, $start);
        $this->leaveStatus->sync();
        ActivityLog::record('leave_create', 'Leave request created.', $leave, null, $leave->toArray(), 'leaves');
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $leave->employee_id);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Leave request created.', 'leave' => $leave->fresh()->load(['employee.department', 'leaveType'])])
            : back()->with('toast', 'تم إنشاء طلب الإجازة بنجاح.');
    }

    public function approve(Leave $leave, Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $oldValues = $leave->only(['status', 'manager_approved_by', 'manager_approved_at', 'approved_by', 'approved_at']);
        abort_if(in_array($leave->status, Leave::inactiveStatuses(), true), 422, 'لا يمكن إعادة تفعيل إجازة ملغاة من رابط اعتماد قديم.');
        $this->leaveBalances->validateForApproval($leave, $leave->id);
        $leave->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'early_grant_approved_by' => $leave->is_early_grant ? auth()->id() : null, 'early_grant_approved_at' => $leave->is_early_grant ? now() : null]);
        $this->leaveStatus->sync();
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $leave->employee_id);
        ActivityLog::record('leave_approve', 'Leave request approved by HR.', $leave, $oldValues, $leave->only(['status', 'approved_by', 'approved_at']), 'leaves');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Leave request approved.', 'leave' => $leave->fresh()])
            : back()->with('toast', 'تم اعتماد طلب الإجازة بنجاح.');
    }

    public function reject(Leave $leave, Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $data = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:500']]);
        $oldValues = $leave->only(['status', 'approved_by', 'approved_at', 'rejection_reason']);
        $leave->update(['status' => 'Rejected', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejection_reason' => $data['rejection_reason'] ?? null]);
        $this->leaveBalances->sync($leave->employee, $leave->start_date);
        $this->leaveStatus->sync();
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $leave->employee_id);
        ActivityLog::record('leave_reject', 'Leave request rejected.', $leave, $oldValues, $leave->only(['status', 'approved_by', 'approved_at', 'rejection_reason']), 'leaves');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Leave request rejected.', 'leave' => $leave->fresh()])
            : back()->with('toast', 'تم رفض طلب الإجازة.');
    }

    public function destroy(Leave $leave, Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureRangeOpen($leave->start_date, $leave->end_date);
        $snapshot = $leave->toArray();
        $employee = $leave->employee;
        $start = $leave->start_date->copy();
        $leave->delete();
        $this->leaveBalances->sync($employee, $start);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $snapshot['employee_id']);
        $this->leaveStatus->sync();
        ActivityLog::record('leave_delete', 'Leave request deleted.', null, $snapshot, null, 'leaves');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Leave request deleted.', 'leave_id' => $snapshot['id']])
            : back()->with('toast', 'تم حذف طلب الإجازة.');
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

    private function ensureOwnedReferences(array $data): void
    {
        Employee::findOrFail($data['employee_id']);
        LeaveType::findOrFail($data['leave_type_id']);
    }
}
