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
    /** Widest date span the page will synthesize per-day rows for. */
    private const MAX_RANGE_DAYS = 92;

    public function __construct(
        private readonly HrOperationalSettingsService $hrSettings,
        private readonly DashboardDataService $dashboard,
        private readonly AttendanceMetricsService $attendanceMetrics,
    )
    {
    }
    public function index(Request $request)
    {
        $data = $this->buildRows($request);
        $rows = $data['rows'];

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $attendances = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $today = today();
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
        $periodTotals = $data['periodTotals'];
        $classifyTo = $data['classifyTo'];
        $periodStart = $data['periodStart'];
        $periodEnd = $data['periodEnd'];
        return view('attendance.index', compact('attendances', 'presentToday', 'absentToday', 'lateToday', 'avgCheckIn', 'employees', 'departments', 'defaultShiftStart', 'defaultShiftEnd', 'periodStart', 'periodEnd', 'pendingPeriods', 'periodTotals', 'classifyTo'));
    }

    public function export(Request $request)
    {
        // Same scoped rows as the screen, including synthesized absence and the
        // state filter, so the file never disagrees with what the user sees.
        $data = $this->buildRows($request);
        $filename = 'attendance-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'كود الموظف', 'الموظف', 'القسم', 'الحضور', 'الانصراف', 'التأخير', 'الانصراف المبكر', 'الإضافي النهاري', 'الإضافي الليلي', 'الحالة'], ',');
            foreach ($data['rows'] as $row) {
                $model = $row['model'];
                $minutes = fn (?int $value) => $value === null ? '—' : sprintf('%02d:%02d', intdiv($value, 60), $value % 60);
                fputcsv($out, [
                    \Carbon\Carbon::parse($row['date'])->format('d/m/Y'), $row['employee']?->employee_code,
                    $row['employee']?->full_name, $row['employee']?->department?->name,
                    $model?->check_in ?? '—', $model?->check_out ?? '—',
                    $model ? $model->formatted_late : '—',
                    $minutes($model?->early_leave_minutes !== null ? (int) $model->early_leave_minutes : null),
                    $minutes($model?->day_overtime_minutes !== null ? (int) $model->day_overtime_minutes : null),
                    $minutes($model?->night_overtime_minutes !== null ? (int) $model->night_overtime_minutes : null),
                    $row['status_label'] . ($row['virtual'] ? ' (محتسَب)' : ''),
                ], ',');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rows shown on the attendance page and exported: real records plus
     * synthesized absence/pending rows through today, classified by the same
     * per-day path as the monthly summary and payroll.
     *
     * @return array{rows: \Illuminate\Support\Collection, periodTotals: array, classifyTo: Carbon, periodStart: string, periodEnd: string}
     */
    private function buildRows(Request $request): array
    {
        $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'state' => ['nullable', 'in:present,late,absent,not_recorded'],
        ]);

        $today = today();
        $period = $this->hrSettings->periodContaining($today->copy());
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();
        $from = $request->filled('from_date') ? Carbon::parse($request->string('from_date')->toString())->startOfDay() : $period['start']->copy()->startOfDay();
        $to = $request->filled('to_date') ? Carbon::parse($request->string('to_date')->toString())->startOfDay() : $period['end']->copy()->startOfDay();
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'to_date' => 'نطاق العرض يتجاوز ' . self::MAX_RANGE_DAYS . ' يومًا. قلّص الفترة ثم أعد العرض.',
            ]);
        }

        $employeesQuery = Employee::with(['department', 'status'])->orderBy('full_name');
        if ($request->filled('employee_id')) $employeesQuery->where('id', $request->employee_id);
        if ($request->filled('department_id')) $employeesQuery->where('department_id', $request->department_id);
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $employeesQuery->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%"));
        }
        $filteredEmployees = $employeesQuery->get();
        $employeeIds = $filteredEmployees->pluck('id');

        $records = Attendance::with(['employee.department', 'employee.job'])
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->orderByDesc('attendance_date')->orderByDesc('id')->get();
        $records->each(fn (Attendance $attendance) => $this->decorateOvertime($attendance));

        $metrics = app(\App\Services\EmployeePeriodMetrics::class);
        $effectiveRecords = $records->filter(fn (Attendance $record) => !in_array($record->approval_status, Attendance::inactiveStatuses(), true));
        $leaves = \App\Models\Leave::effective()->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->get()->groupBy('employee_id');
        $requests = \App\Models\HrRequest::effective()->with('requestType')->whereIn('employee_id', $employeeIds)->get()
            ->filter(fn (\App\Models\HrRequest $item) => in_array($item->requestType?->source_key ?: $item->request_type, ['permission', 'mission'], true))
            ->groupBy('employee_id');

        $periodTotals = ['present' => 0, 'late' => 0, 'absent' => 0.0];
        $classifyTo = $to->gt($today) ? $today->copy()->startOfDay() : $to->copy();
        $dayLookup = [];
        $rows = collect();
        if ($classifyTo->gte($from)) {
            $recordsByEmployee = $effectiveRecords->groupBy('employee_id');
            foreach ($filteredEmployees as $employee) {
                $days = $metrics->days($employee, $from, $classifyTo,
                    $recordsByEmployee->get($employee->id, collect()),
                    $leaves->get($employee->id, collect()),
                    $requests->get($employee->id, collect()));
                foreach ($days as $day) {
                    $dayLookup[$employee->id . '|' . $day['date']] = $day;
                    if ($day['present']) $periodTotals['present']++;
                    if ($day['state'] === 'late') $periodTotals['late']++;
                    if ($day['state'] === 'absent') $periodTotals['absent'] += (float) $day['absence_days'];
                    if ($day['state'] === 'absent' && !$day['has_record'] && (float) $day['absence_days'] > 0) {
                        $fraction = (float) $day['absence_days'];
                        $rows->push([
                            'virtual' => true, 'model' => null, 'employee' => $employee, 'date' => $day['date'], 'state' => 'absent',
                            'status_label' => $fraction < 1 ? 'غائب (جزئي)' : 'غائب',
                            'badge_class' => 'danger', 'row_class' => 'attendance-row-absent',
                        ]);
                    } elseif ($day['state'] === 'not_recorded' && $day['infer_missing'] && $day['date'] === $today->toDateString()) {
                        $rows->push([
                            'virtual' => true, 'model' => null, 'employee' => $employee, 'date' => $day['date'], 'state' => 'not_recorded',
                            'status_label' => 'لم يُسجَّل بعد', 'badge_class' => 'secondary', 'row_class' => 'attendance-row-pending',
                        ]);
                    }
                }
            }
        }
        foreach ($records as $record) {
            $day = $dayLookup[$record->employee_id . '|' . $record->attendance_date?->toDateString()] ?? null;
            $rows->push($this->recordRow($record, $day));
        }
        if ($request->filled('state')) {
            $wantedState = $request->string('state')->toString();
            $rows = $rows->filter(fn ($row) => $row['state'] === $wantedState);
        }
        $rows = $rows->sortBy([
            fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']),
            fn ($a, $b) => strcmp((string) ($a['employee']?->full_name ?? ''), (string) ($b['employee']?->full_name ?? '')),
            fn ($a, $b) => ($b['model']?->id ?? 0) <=> ($a['model']?->id ?? 0),
        ])->values();

        $periodTotals['absent'] = round($periodTotals['absent'], 2);
        return ['rows' => $rows, 'periodTotals' => $periodTotals, 'classifyTo' => $classifyTo, 'periodStart' => $periodStart, 'periodEnd' => $periodEnd];
    }

    /**
     * A real record row derives its state and color from the same day
     * classification that drives the totals, so they can never disagree;
     * records outside the classified range keep their raw status.
     */
    private function recordRow(Attendance $record, ?array $day): array
    {
        $base = ['virtual' => false, 'model' => $record, 'employee' => $record->employee, 'date' => $record->attendance_date?->toDateString()];
        if (in_array($record->approval_status, Attendance::inactiveStatuses(), true)) {
            $cancelled = in_array($record->approval_status, ['Cancelled', 'Canceled', 'ملغي', 'ملغى', 'ملغاة'], true);
            return [...$base, 'state' => 'rejected',
                'status_label' => arabic_label($record->status) . ($cancelled ? ' (ملغي)' : ' (مرفوض)'),
                'badge_class' => 'secondary', 'row_class' => 'attendance-row-rejected'];
        }
        if ($day !== null) {
            $fraction = (float) ($day['absence_days'] ?? 0);
            [$state, $label, $badge, $class] = match ($day['state']) {
                'present' => ['present', 'حاضر', 'success', ''],
                'late' => ['late', 'متأخر', 'warning', 'attendance-row-late'],
                'absent' => $fraction > 0
                    ? ['absent', $fraction < 1 ? 'غائب (جزئي)' : 'غائب', 'danger', 'attendance-row-absent']
                    : ['excused', 'غائب (مغطى بإذن)', 'info', ''],
                'leave' => ['leave', 'إجازة', 'info', ''],
                'mission' => ['mission', 'مهمة', 'info', ''],
                'rest' => ['rest', 'راحة أسبوعية', 'secondary', ''],
                'holiday' => ['holiday', 'أجازة رسمية', 'secondary', ''],
                'before_hire' => ['outside_service', 'قبل التعيين', 'secondary', ''],
                'after_termination' => ['outside_service', 'بعد نهاية الخدمة', 'secondary', ''],
                // «لم يُسجَّل بعد» describes only today before the cutoff; a past
                // not_recorded day (e.g. a non-inferring employee) keeps the raw status.
                'not_recorded' => $record->attendance_date?->isToday()
                    ? ['not_recorded', 'لم يُسجَّل بعد', 'secondary', 'attendance-row-pending']
                    : [null, null, null, null],
                default => [null, null, null, null],
            };
            if ($state !== null) {
                return [...$base, 'state' => $state, 'status_label' => $label, 'badge_class' => $badge, 'row_class' => $class];
            }
            // Unlisted states (e.g. future) fall through to the raw status below.
        }
        $late = (int) ($record->late_minutes ?? 0) > 0 || in_array($record->status, ['Late', 'متأخر'], true);
        $absent = in_array($record->status, ['Absent', 'غائب', 'غياب'], true);
        $onLeave = in_array($record->status, ['On Leave', 'Leave', 'إجازة', 'اجازة'], true);
        $state = $absent ? 'absent' : ($late ? 'late' : ($onLeave ? 'leave' : 'present'));
        return [...$base, 'state' => $state,
            'status_label' => arabic_label($record->status), 'badge_class' => $record->status_badge_class,
            'row_class' => $absent ? 'attendance-row-absent' : ($late ? 'attendance-row-late' : '')];
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
