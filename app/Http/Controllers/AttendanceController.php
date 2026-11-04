<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AttendancePeriodArchive;
use App\Models\Employee;
use App\Models\Department;
use App\Services\PayrollPeriodGuard;
use App\Services\HrOperationalSettingsService;
use App\Services\DashboardDataService;
use App\Services\PayrollRunService;
use App\Services\AttendanceMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly DashboardDataService $dashboard,
        private readonly AttendanceMetricsService $attendanceMetrics,
    )
    {
    }
    public function index(Request $request)
    {
        $today = today();
        $period = $this->hrSettings->periodContaining($today->copy());
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();
        $query = Attendance::with(['employee.department', 'employee.job'])->orderByDesc('attendance_date')->orderByDesc('id');
        if (!$request->filled('from_date')) $query->whereDate('attendance_date', '>=', $periodStart);
        if (!$request->filled('to_date')) $query->whereDate('attendance_date', '<=', $periodEnd);
        if ($request->filled('from_date')) { $query->whereDate('attendance_date', '>=', $request->from_date); }
        if ($request->filled('to_date')) { $query->whereDate('attendance_date', '<=', $request->to_date); }
        if ($request->filled('employee_id')) { $query->where('employee_id', $request->employee_id); }
        if ($request->filled('department_id')) { $query->whereHas('employee', fn ($q) => $q->where('department_id', $request->department_id)); }
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->whereHas('employee', fn ($employee) => $employee
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%"));
        }

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

        $pendingPeriods = app(\App\Services\AttendancePeriodArchiveService::class)->pendingPeriods();
        $defaultShiftStart = $this->hrSettings->start();
        $defaultShiftEnd = $this->hrSettings->end();
        return view('attendance.index', compact('attendances', 'presentToday', 'absentToday', 'lateToday', 'avgCheckIn', 'employees', 'departments', 'defaultShiftStart', 'defaultShiftEnd', 'periodStart', 'periodEnd', 'pendingPeriods'));
    }

    public function export(Request $request)
    {
        $period = $this->hrSettings->periodContaining(today()->copy());
        $query = Attendance::with(['employee.department'])
            ->orderByDesc('attendance_date')->orderByDesc('id');
        if (!$request->filled('from_date')) $query->whereDate('attendance_date', '>=', $period['start']->toDateString());
        if (!$request->filled('to_date')) $query->whereDate('attendance_date', '<=', $period['end']->toDateString());
        if ($request->filled('from_date')) $query->whereDate('attendance_date', '>=', $request->date('from_date'));
        if ($request->filled('to_date')) $query->whereDate('attendance_date', '<=', $request->date('to_date'));
        if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
        if ($request->filled('department_id')) $query->whereHas('employee', fn ($q) => $q->where('department_id', $request->integer('department_id')));
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->whereHas('employee', fn ($q) => $q->where('full_name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%"));
        }
        $records = $query->get();
        $this->decorateOvertimeCollection($records);
        $filename = 'attendance-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'كود الموظف', 'الموظف', 'القسم', 'الحضور', 'الانصراف', 'التأخير', 'الانصراف المبكر', 'الإضافي النهاري', 'الإضافي الليلي', 'الحالة'], ',');
            foreach ($records as $attendance) {
                fputcsv($out, [
                    $attendance->attendance_date?->format('d/m/Y'), $attendance->employee?->employee_code,
                    $attendance->employee?->full_name, $attendance->employee?->department?->name,
                    $attendance->check_in, $attendance->check_out, $attendance->formatted_late,
                    sprintf('%02d:%02d', intdiv((int) ($attendance->early_leave_minutes ?? 0), 60), ((int) ($attendance->early_leave_minutes ?? 0)) % 60),
                    sprintf('%02d:%02d', intdiv((int) ($attendance->day_overtime_minutes ?? 0), 60), ((int) ($attendance->day_overtime_minutes ?? 0)) % 60),
                    sprintf('%02d:%02d', intdiv((int) ($attendance->night_overtime_minutes ?? 0), 60), ((int) ($attendance->night_overtime_minutes ?? 0)) % 60),
                    arabic_label($attendance->status),
                ], ',');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'],
            'check_in' => ['required', 'date_format:H:i'], 'check_out' => ['required', 'date_format:H:i'], 'status' => ['required', 'string', 'max:50'],
            'late_minutes' => ['nullable', 'integer', 'min:0'], 'source' => ['nullable', 'string', 'max:50'],
        ]);
        $periodGuard->ensureOpen($validated['attendance_date']); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($validated['attendance_date']);
        $employee = Employee::findOrFail($validated['employee_id']);
        $checkIn = $validated['check_in'] ?? null;
        $checkOut = $validated['check_out'] ?? null;
        $metrics = $this->attendanceMetrics->metrics($employee, $checkIn, $checkOut, $validated['status']);
        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'attendance_date' => $validated['attendance_date']],
            ['check_in' => $checkIn, 'check_out' => $checkOut, ...$metrics, 'source' => $validated['source'] ?? 'manual', 'approval_status' => 'Approved', 'approved_by' => null, 'approved_at' => null]
        );
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $attendance->employee_id);
        $this->decorateOvertime($attendance->load('employee.department'));
        ActivityLog::record('attendance_record', 'Attendance record created or updated.', $attendance, null, $attendance->toArray(), 'attendance');
        $fresh = $attendance->fresh()->load('employee.department');
        $this->decorateOvertime($fresh);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance saved.', 'attendance' => $fresh])
            : back()->with('toast', 'تم حفظ سجل الحضور كمسودة.');
    }

    public function clockIn(Request $request, PayrollPeriodGuard $periodGuard)
    {
        return $this->clock($request, $periodGuard, 'in');
    }

    public function clockOut(Request $request, PayrollPeriodGuard $periodGuard)
    {
        return $this->clock($request, $periodGuard, 'out');
    }

    private function clock(Request $request, PayrollPeriodGuard $periodGuard, string $direction)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'attendance_date' => ['nullable', 'date'],
        ]);
        $date = $validated['attendance_date'] ?? today()->toDateString();
        $periodGuard->ensureOpen($date); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($date);
        $employee = Employee::findOrFail($validated['employee_id']);
        $attendance = Attendance::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $date]);
        if ($direction === 'in' && $attendance->check_in) {
            abort(422, 'تم تسجيل حضور هذا الموظف بالفعل لهذا اليوم.');
        }
        if ($direction === 'out' && !$attendance->check_in) {
            abort(422, 'يجب تسجيل الحضور أولًا قبل تسجيل الانصراف.');
        }
        if ($direction === 'out' && $attendance->check_out) {
            abort(422, 'تم تسجيل انصراف هذا الموظف بالفعل لهذا اليوم.');
        }
        $now = now()->format('H:i');
        $checkIn = $direction === 'in' ? $now : $attendance->check_in;
        $checkOut = $direction === 'out' ? $now : $attendance->check_out;
        $metrics = $this->attendanceMetrics->metrics($employee, $checkIn, $checkOut);
        $attendance->fill([
            'check_in' => $checkIn, 'check_out' => $checkOut,
            ...$metrics,
            'source' => 'manual', 'approval_status' => 'Approved', 'approved_by' => null, 'approved_at' => null,
        ])->save();
        app(PayrollRunService::class)->refreshEmployeeInOpenDrafts((int) $employee->id);
        ActivityLog::record('attendance_clock_'.$direction, 'Attendance clock event recorded.', $attendance, null, $attendance->toArray(), 'attendance');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => $direction === 'in' ? 'تم تسجيل الحضور.' : 'تم تسجيل الانصراف.', 'attendance' => $attendance->fresh()->load('employee.department')])
            : back()->with('toast', $direction === 'in' ? 'تم تسجيل الحضور بنجاح.' : 'تم تسجيل الانصراف بنجاح.');
    }

    public function update(Request $request, Attendance $attendance, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'],
            'check_in' => ['required', 'date_format:H:i'], 'check_out' => ['required', 'date_format:H:i'],
            'status' => ['required', 'string', 'max:50'], 'source' => ['nullable', 'string', 'max:50'],
        ]);

        $periodGuard->ensureOpen($attendance->attendance_date); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($attendance->attendance_date);
        $periodGuard->ensureOpen($validated['attendance_date']); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($validated['attendance_date']);
        $duplicate = Attendance::query()->where('employee_id', $validated['employee_id'])
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('id', '!=', $attendance->id)->exists();
        if ($duplicate) {
            return $request->wantsJson()
                ? response()->json(['message' => 'يوجد سجل حضور آخر لهذا الموظف في التاريخ المحدد.'], 422)
                : back()->withErrors(['attendance' => 'يوجد سجل حضور آخر لهذا الموظف في التاريخ المحدد.']);
        }

        $employee = Employee::findOrFail($validated['employee_id']);
        $metrics = $this->attendanceMetrics->metrics($employee, $validated['check_in'], $validated['check_out']);
        $old = $attendance->toArray();
        $attendance->update([
            'employee_id' => $employee->id, 'attendance_date' => $validated['attendance_date'],
            'check_in' => $validated['check_in'], 'check_out' => $validated['check_out'], ...$metrics,
            'source' => $validated['source'] ?? 'manual',
            // أي تعديل يستلزم مراجعة السجل من جديد قبل دخوله في المسير.
            'approval_status' => 'Approved', 'approved_by' => null, 'approved_at' => null,
        ]);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $attendance->employee_id);
        $this->decorateOvertime($attendance->load('employee.department'));
        ActivityLog::record('attendance_update', 'Attendance record updated.', $attendance, $old, $attendance->toArray(), 'attendance');
        $fresh = $attendance->fresh()->load('employee.department');
        $this->decorateOvertime($fresh);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance updated.', 'attendance' => $fresh])
            : back()->with('toast', 'تم تحديث سجل الحضور وتأكيده فورًا.');
    }

    public function destroy(Attendance $attendance, Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureOpen($attendance->attendance_date); app(\App\Services\AttendancePeriodArchiveService::class)->ensureDateOpen($attendance->attendance_date);
        $snapshot = $attendance->toArray();
        $attendance->delete();
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $snapshot['employee_id']);
        ActivityLog::record('attendance_delete', 'Attendance record deleted.', null, $snapshot, null, 'attendance');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Attendance deleted.', 'attendance_id' => $snapshot['id']])
            : back()->with('toast', 'تم حذف سجل الحضور.');
    }

    /**
     * Potential overtime shown on attendance; approval is handled separately
     * through HR requests.  Overtime is the time worked outside the employee's
     * shift, split according to the company's configured night window.
     */
    private function decorateOvertime(Attendance $attendance): void
    {
        $employee = $attendance->employee;
        $start = $employee?->shift_start ?: $this->hrSettings->start();
        $end = $employee?->shift_end ?: $this->hrSettings->end();
        $checkIn = $attendance->check_in;
        $checkOut = $attendance->check_out;
        $dayMinutes = 0.0;
        $nightMinutes = 0.0;
        if ($checkIn && $checkOut) {
            $in = $this->timeToMinutes($checkIn);
            $out = $this->timeToMinutes($checkOut);
            $startMinutes = $this->timeToMinutes($start);
            $endMinutes = $this->timeToMinutes($end);
            if ($endMinutes <= $startMinutes) $endMinutes += 1440;
            if ($out <= $in) $out += 1440;

            // Only time before the shift or after the shift is potential OT.
            $segments = [];
            if ($in < $startMinutes) $segments[] = [$in, min($startMinutes, $out)];
            if ($out > $endMinutes) $segments[] = [max($endMinutes, $in), $out];

            foreach ($segments as [$from, $to]) {
                if ($to <= $from) continue;
                $total = $to - $from;
                $night = $this->nightOverlap($from, $to);
                $nightMinutes += $night;
                $dayMinutes += max(0, $total - $night);
            }
        }
        $attendance->setAttribute('day_overtime_minutes', (int) round($dayMinutes));
        $attendance->setAttribute('night_overtime_minutes', (int) round($nightMinutes));
        $attendance->setAttribute('potential_overtime_minutes', (int) round($dayMinutes + $nightMinutes));
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }

    private function nightOverlap(float $from, float $to): float
    {
        $nightStart = $this->timeToMinutes($this->hrSettings->nightStart());
        $nightEnd = $this->timeToMinutes($this->hrSettings->nightEnd());
        if ($nightEnd <= $nightStart) $nightEnd += 1440;

        $overlap = 0.0;
        for ($day = -1; $day <= 1; $day++) {
            $offset = 1440 * $day;
            $overlap += max(0.0, min($to, $nightEnd + $offset) - max($from, $nightStart + $offset));
        }
        return $overlap;
    }

    public function decorateOvertimeCollection($attendances): void
    {
        $attendances->each(fn (Attendance $attendance) => $this->decorateOvertime($attendance));
    }

    /** «الشهور السابقة»: saved attendance of finished payroll periods. */
    public function archiveIndex()
    {
        $archives = AttendancePeriodArchive::where('status', AttendancePeriodArchive::CLOSED)->orderByDesc('period_end')->get();
        $current = $this->hrSettings->periodContaining(today()->copy());

        return view('attendance.archive', ['archives' => $archives, 'archive' => null, 'rows' => [], 'periodStart' => $current['start']->toDateString(), 'periodEnd' => $current['end']->toDateString()]);
    }

    public function archiveShow(AttendancePeriodArchive $archive)
    {
        abort_unless($archive->isClosed(), 404);
        $archives = AttendancePeriodArchive::where('status', AttendancePeriodArchive::CLOSED)->orderByDesc('period_end')->get();
        $current = $this->hrSettings->periodContaining(today()->copy());

        return view('attendance.archive', ['archives' => $archives, 'archive' => $archive, 'rows' => $archive->rows, 'periodStart' => $current['start']->toDateString(), 'periodEnd' => $current['end']->toDateString()]);
    }

    public function archiveExport(AttendancePeriodArchive $archive)
    {
        abort_unless($archive->isClosed(), 404);
        $filename = 'attendance-archive-' . $archive->period_start->format('Y-m-d') . '_' . $archive->period_end->format('Y-m-d') . '.csv';
        $hm = fn ($m) => sprintf('%02d:%02d', intdiv((int) $m, 60), ((int) $m) % 60);

        return response()->streamDownload(function () use ($archive, $hm) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'كود الموظف', 'الموظف', 'القسم', 'الحضور', 'الانصراف', 'التأخير', 'الانصراف المبكر', 'الإضافي النهاري', 'الإضافي الليلي', 'الحالة'], ',');
            foreach ($archive->rows as $row) {
                fputcsv($out, [
                    $row['date'] ? Carbon::parse($row['date'])->format('d/m/Y') : '', $row['employee_code'], $row['employee_name'], $row['department'],
                    $row['check_in'], $row['check_out'], $hm($row['late_minutes']), $hm($row['early_leave_minutes']),
                    $hm($row['day_overtime_minutes']), $hm($row['night_overtime_minutes']), arabic_label($row['status']),
                ], ',');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
