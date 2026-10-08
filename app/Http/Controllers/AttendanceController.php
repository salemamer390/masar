<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
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
        $query = Attendance::with(['employee.department', 'employee.job'])->orderByDesc('attendance_date');
        if ($request->filled('date')) { $query->whereDate('attendance_date', $request->date); }

        $attendances = $query->paginate(25)->withQueryString();
        $todaySummary = $this->dashboard->attendance($request->user(), $today);
        $presentToday = $todaySummary['counts']['present'];
        $absentToday = $todaySummary['counts']['absent'];
        $lateToday = $todaySummary['counts']['late'];
        $todayRecords = Attendance::whereDate('attendance_date', $today)->get();
        $minutes = $todayRecords->filter(fn (Attendance $record) => filled($record->check_in))->map(fn (Attendance $record) => Carbon::parse($record->check_in)->hour * 60 + Carbon::parse($record->check_in)->minute);
        $average = (int) round($minutes->avg() ?? 0);
        $avgCheckIn = $minutes->isEmpty() ? '—' : sprintf('%02d:%02d', intdiv($average, 60), $average % 60);
        $employees = Employee::orderBy('full_name')->get();

        return view('attendance.index', compact('attendances', 'presentToday', 'absentToday', 'lateToday', 'avgCheckIn', 'employees'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'],
            'check_in' => ['nullable'], 'check_out' => ['nullable'], 'status' => ['required', 'string', 'max:50'],
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
        ActivityLog::record('attendance_record', 'Attendance record created or updated.', $attendance, null, $attendance->toArray(), 'attendance');
        return $request->wantsJson() ? response()->json(['success' => true, 'message' => 'Attendance saved.', 'attendance' => $attendance]) : back()->with('toast', 'تم حفظ سجل الحضور كمسودة.');
    }

    public function approve(Attendance $attendance, Request $request, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureOpen($attendance->attendance_date);
        $oldValues = $attendance->only(['approval_status', 'approved_by', 'approved_at']);
        $attendance->update(['approval_status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLog::record('attendance_approve', 'Attendance record approved.', $attendance, $oldValues, $attendance->only(['approval_status', 'approved_by', 'approved_at']), 'attendance');
        return $request->wantsJson() ? response()->json(['success' => true, 'message' => 'Attendance record approved.']) : back()->with('toast', 'تم اعتماد سجل الحضور.');
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
}
