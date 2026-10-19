<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Department;
use App\Services\PayrollPeriodGuard;
use App\Services\HrOperationalSettingsService;
use App\Services\DashboardDataService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly DashboardDataService $dashboard,
    )
    {
    }
    public function index(Request $request)
    {
        $today = today();
        $query = Attendance::with(['employee.department', 'employee.job'])->orderByDesc('attendance_date')->orderByDesc('id');
        if ($request->filled('from_date')) { $query->whereDate('attendance_date', '>=', $request->from_date); }
        if ($request->filled('to_date')) { $query->whereDate('attendance_date', '<=', $request->to_date); }
        if ($request->filled('employee_id')) { $query->where('employee_id', $request->employee_id); }
        if ($request->filled('department_id')) { $query->whereHas('employee', fn ($q) => $q->where('department_id', $request->department_id)); }

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $attendances = $query->paginate($perPage)->withQueryString();
        $attendances->getCollection()->each(fn (Attendance $attendance) => $this->decorateOvertime($attendance));
        $todaySummary = $this->dashboard->attendance($request->user(), $today);
        $presentToday = $todaySummary['counts']['present'];
        $absentToday = $todaySummary['counts']['absent'];
        $lateToday = $todaySummary['counts']['late'];
        $todayRecords = Attendance::whereDate('attendance_date', $today)->get();
        $minutes = $todayRecords->filter(fn (Attendance $record) => filled($record->check_in))->map(fn (Attendance $record) => Carbon::parse($record->check_in)->hour * 60 + Carbon::parse($record->check_in)->minute);
        $average = (int) round($minutes->avg() ?? 0);
        $avgCheckIn = $minutes->isEmpty() ? '—' : sprintf('%02d:%02d', intdiv($average, 60), $average % 60);
        $employees = Employee::orderBy('full_name')->get();
        $departments = Department::where('is_active', 1)->orderBy('name')->get();

        $defaultShiftStart = $this->hrSettings->start();
        $defaultShiftEnd = $this->hrSettings->end();
        return view('attendance.index', compact('attendances', 'presentToday', 'absentToday', 'lateToday', 'avgCheckIn', 'employees', 'departments', 'defaultShiftStart', 'defaultShiftEnd'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'],
            'check_in' => ['required', 'date_format:H:i'], 'check_out' => ['required', 'date_format:H:i'], 'status' => ['required', 'string', 'max:50'],
            'late_minutes' => ['nullable', 'integer', 'min:0'], 'source' => ['nullable', 'string', 'max:50'],
        ]);
        $periodGuard->ensureOpen($validated['attendance_date']);
        $employee = Employee::findOrFail($validated['employee_id']);
        $checkIn = $validated['check_in'] ?? null;
        $checkOut = $validated['check_out'] ?? null;
        $lateMinutes = $this->lateMinutes($checkIn, $employee->shift_start ?: $this->hrSettings->start());
        $earlyLeaveMinutes = $this->earlyLeaveMinutes($checkOut, $employee->shift_end ?: $this->hrSettings->end());
        $workedHours = $this->workedHours($checkIn, $checkOut);
        $status = ($checkIn || $checkOut) ? ($lateMinutes > 0 ? 'Late' : 'Present') : $validated['status'];
        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'attendance_date' => $validated['attendance_date']],
            ['check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $status, 'late_minutes' => $lateMinutes, 'early_leave_minutes' => $earlyLeaveMinutes, 'worked_hours' => $workedHours, 'source' => $validated['source'] ?? 'manual', 'approval_status' => 'Draft', 'approved_by' => null, 'approved_at' => null]
        );
        $this->decorateOvertime($attendance->load('employee.department'));
        ActivityLog::record('attendance_record', 'Attendance record created or updated.', $attendance, null, $attendance->toArray(), 'attendance');
        $fresh = $attendance->fresh()->load('employee.department');
        $this->decorateOvertime($fresh);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance saved.', 'attendance' => $fresh])
            : back()->with('toast', 'تم حفظ سجل الحضور كمسودة.');
    }

    public function approve(Attendance $attendance, Request $request, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureOpen($attendance->attendance_date);
        $oldValues = $attendance->only(['approval_status', 'approved_by', 'approved_at']);
        $attendance->update(['approval_status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLog::record('attendance_approve', 'Attendance record approved.', $attendance, $oldValues, $attendance->only(['approval_status', 'approved_by', 'approved_at']), 'attendance');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance record approved.', 'attendance' => $attendance->fresh()])
            : back()->with('toast', 'تم اعتماد سجل الحضور.');
    }

    public function update(Request $request, Attendance $attendance, PayrollPeriodGuard $periodGuard)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'],
            'check_in' => ['required', 'date_format:H:i'], 'check_out' => ['required', 'date_format:H:i'],
            'status' => ['required', 'string', 'max:50'], 'source' => ['nullable', 'string', 'max:50'],
        ]);

        $periodGuard->ensureOpen($attendance->attendance_date);
        $periodGuard->ensureOpen($validated['attendance_date']);
        $duplicate = Attendance::query()->where('employee_id', $validated['employee_id'])
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('id', '!=', $attendance->id)->exists();
        if ($duplicate) {
            return $request->wantsJson()
                ? response()->json(['message' => 'يوجد سجل حضور آخر لهذا الموظف في التاريخ المحدد.'], 422)
                : back()->withErrors(['attendance' => 'يوجد سجل حضور آخر لهذا الموظف في التاريخ المحدد.']);
        }

        $employee = Employee::findOrFail($validated['employee_id']);
        $lateMinutes = $this->lateMinutes($validated['check_in'], $employee->shift_start ?: $this->hrSettings->start());
        $earlyLeaveMinutes = $this->earlyLeaveMinutes($validated['check_out'], $employee->shift_end ?: $this->hrSettings->end());
        $status = $lateMinutes > 0 ? 'Late' : 'Present';
        $old = $attendance->toArray();
        $attendance->update([
            'employee_id' => $employee->id, 'attendance_date' => $validated['attendance_date'],
            'check_in' => $validated['check_in'], 'check_out' => $validated['check_out'], 'status' => $status,
            'late_minutes' => $lateMinutes, 'early_leave_minutes' => $earlyLeaveMinutes,
            'worked_hours' => $this->workedHours($validated['check_in'], $validated['check_out']),
            'source' => $validated['source'] ?? 'manual',
            // أي تعديل يستلزم مراجعة السجل من جديد قبل دخوله في المسير.
            'approval_status' => 'Draft', 'approved_by' => null, 'approved_at' => null,
        ]);
        $this->decorateOvertime($attendance->load('employee.department'));
        ActivityLog::record('attendance_update', 'Attendance record updated.', $attendance, $old, $attendance->toArray(), 'attendance');
        $fresh = $attendance->fresh()->load('employee.department');
        $this->decorateOvertime($fresh);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance updated.', 'attendance' => $fresh])
            : back()->with('toast', 'تم تحديث سجل الحضور وإعادته للمراجعة.');
    }

    public function destroy(Attendance $attendance, Request $request, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureOpen($attendance->attendance_date);
        $snapshot = $attendance->toArray();
        $attendance->delete();
        ActivityLog::record('attendance_delete', 'Attendance record deleted.', null, $snapshot, null, 'attendance');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance deleted.', 'attendance_id' => $snapshot['id']])
            : back()->with('toast', 'تم حذف سجل الحضور.');
    }

    private function lateMinutes(?string $checkIn, string $shiftStart): int
    {
        if (!$checkIn) return 0;
        $actual = Carbon::parse($checkIn)->hour * 60 + Carbon::parse($checkIn)->minute;
        $start = Carbon::parse($shiftStart)->hour * 60 + Carbon::parse($shiftStart)->minute;
        return $actual > $start + $this->hrSettings->grace() ? $actual - $start : 0;
    }

    private function earlyLeaveMinutes(?string $checkOut, string $shiftEnd): int
    {
        if (!$checkOut) return 0;
        $actual = Carbon::parse($checkOut)->hour * 60 + Carbon::parse($checkOut)->minute;
        $end = Carbon::parse($shiftEnd)->hour * 60 + Carbon::parse($shiftEnd)->minute;
        return max(0, $end - $actual);
    }

    private function workedHours(?string $checkIn, ?string $checkOut): ?float
    {
        if (!$checkIn || !$checkOut) return null;
        $in = Carbon::parse($checkIn)->hour * 60 + Carbon::parse($checkIn)->minute;
        $out = Carbon::parse($checkOut)->hour * 60 + Carbon::parse($checkOut)->minute;
        return round((($out - $in + 1440) % 1440) / 60, 2);
    }

    /** Potential overtime shown on attendance; approval is handled separately through HR requests. */
    private function decorateOvertime(Attendance $attendance): void
    {
        $employee = $attendance->employee;
        $start = $employee?->shift_start ?: $this->hrSettings->start();
        $end = $employee?->shift_end ?: $this->hrSettings->end();
        $checkIn = $attendance->check_in;
        $checkOut = $attendance->check_out;
        $dayMinutes = 0;
        $nightMinutes = 0;
        if ($checkIn && $checkOut) {
            $in = Carbon::parse($checkIn)->hour * 60 + Carbon::parse($checkIn)->minute;
            $out = Carbon::parse($checkOut)->hour * 60 + Carbon::parse($checkOut)->minute;
            $startMinutes = Carbon::parse($start)->hour * 60 + Carbon::parse($start)->minute;
            $endMinutes = Carbon::parse($end)->hour * 60 + Carbon::parse($end)->minute;
            $dayMinutes = max(0, $startMinutes - $in);
            $nightMinutes = max(0, $out - $endMinutes);
        }
        $attendance->setAttribute('day_overtime_minutes', $dayMinutes);
        $attendance->setAttribute('night_overtime_minutes', $nightMinutes);
        $attendance->setAttribute('potential_overtime_minutes', $dayMinutes + $nightMinutes);
    }
}
