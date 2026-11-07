<?php

namespace App\Services;

use App\Data\FingerprintRawPunch;
use App\Enums\AttendanceSource;
use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use App\Enums\RawAttendanceType;
use App\Models\Attendance;
use App\Models\AttendanceImportBatch;
use App\Models\AttendanceImportDay;
use App\Models\AttendancePunchRevision;
use App\Models\Employee;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceSyncLog;
use App\Models\RawAttendanceLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class FingerprintAttendanceSyncService extends BaseService
{
    public function __construct(private readonly DeviceDriverResolverService $driverResolver) {}

    public function syncDevice(FingerprintDevice $device, ?int $initiatedBy = null, array $options = []): FingerprintDeviceSyncLog
    {
        $started = microtime(true);
        $log = FingerprintDeviceSyncLog::create(['device_id' => $device->id, 'sync_type' => FingerprintSyncType::AttendancePull->value, 'status' => FingerprintSyncStatus::Running->value, 'started_at' => now(), 'initiated_by' => $initiatedBy]);
        try {
            $punches = collect($this->driverResolver->resolve($device)->pullAttendance($device, (int) ($options['timeout'] ?? 10), (int) ($options['retries'] ?? 1)));
            $result = $this->importPunches($device, $punches, $log->id);
            $log->update(['status' => FingerprintSyncStatus::Success->value, 'completed_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'records_received' => $result['records_received'], 'imported_count' => $result['imported_count'], 'skipped_count' => $result['skipped_count'], 'duplicate_count' => $result['duplicate_count'], 'failed_count' => $result['failed_count'], 'metadata' => ['unresolved_count' => $result['unresolved_count'], 'exception_days' => $result['exception_days'], 'auto_resolved' => $result['auto_resolved'], 'cursor_timestamp' => $result['cursor_timestamp']]]);
            $device->update(['last_connected_at' => now(), 'last_sync_at' => now(), 'health_status' => 'online', 'consecutive_failures' => 0, 'last_connection_failure_at' => null, 'sync_cursor_timestamp' => $result['cursor_timestamp'], 'sync_cursor_hash' => $result['cursor_hash']]);
            return $log->refresh();
        } catch (Throwable $exception) {
            $log->update(['status' => FingerprintSyncStatus::Failed->value, 'completed_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'failed_count' => 1, 'error_message' => $exception->getMessage()]);
            $device->update(['health_status' => 'offline', 'consecutive_failures' => (int) $device->consecutive_failures + 1, 'last_connection_failure_at' => now()]);
            return $log->refresh();
        }
    }

    public function syncDueDevices(?int $initiatedBy = null, array $options = []): array
    {
        $summary = ['devices' => 0, 'success' => 0, 'failed' => 0, 'logs' => []];
        $this->dueDevices($options)->each(function (FingerprintDevice $device) use (&$summary, $initiatedBy, $options): void {
            $summary['devices']++;
            $log = $this->syncDevice($device, $initiatedBy, $options);
            $summary['logs'][] = $log->id;
            $log->status === FingerprintSyncStatus::Success ? $summary['success']++ : $summary['failed']++;
        });
        return $summary;
    }

    private function dueDevices(array $options): Collection
    {
        return FingerprintDevice::query()->where('is_active', true)->where('sync_enabled', true)->when($options['device_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get()->filter(fn (FingerprintDevice $d) => ($options['force'] ?? false) || $d->isDueForSync())->values();
    }

    private function importPunches(FingerprintDevice $device, Collection $punches, ?int $syncLogId = null): array
    {
        $result = ['records_received' => $punches->count(), 'imported_count' => 0, 'skipped_count' => 0, 'duplicate_count' => 0, 'failed_count' => 0, 'unresolved_count' => 0, 'exception_days' => 0, 'auto_resolved' => 0, 'cursor_timestamp' => $device->sync_cursor_timestamp?->format('Y-m-d H:i:s'), 'cursor_hash' => $device->sync_cursor_hash];
        // A device belongs to exactly one company, and punches may only resolve
        // to that company's employees. The scheduled sync runs in console context
        // where no authenticated user activates the company global scope, so the
        // company filter must be explicit here; employee codes are only unique
        // per company and may legitimately repeat across companies.
        // mapWithKeys, not flatMap: flatMap collapses through array_merge, which
        // re-indexes numeric keys and silently drops purely numeric employee
        // codes (the common case for device user IDs) from the match map.
        $employees = Employee::query()->where('company_id', $device->company_id)->get()->mapWithKeys(fn (Employee $e) => [(string) $e->employee_code => $e, (string) ($e->fingerprint_id ?? '') => $e])->filter(fn (Employee $e, $key) => $key !== '')->all();
        $affected = [];
        foreach ($punches as $punch) {
            if (!$punch instanceof FingerprintRawPunch) { $result['skipped_count']++; continue; }
            $employee = $employees[$punch->rawDeviceUserId] ?? null;
            if (!$employee) $result['unresolved_count']++;
            $hash = $punch->importHash($device->id);
            if (RawAttendanceLog::where('import_hash', $hash)->exists()) { $result['duplicate_count']++; continue; }
            try {
                RawAttendanceLog::create(['employee_id' => $employee?->id, 'fingerprint_device_id' => $device->id, 'raw_device_user_id' => $punch->rawDeviceUserId, 'source' => AttendanceSource::Fingerprint->value, 'raw_type' => $punch->rawType->value, 'raw_timestamp' => $punch->punchedAt, 'raw_payload' => $punch->payload, 'import_hash' => $hash, 'imported_at' => now(), 'device_id' => (string) $device->id, 'processing_status' => 'processed', 'processed_at' => now()]);
                // The raw punch is always kept; attendance of a closed period is not touched.
                // Closedness is judged on the attributed work date, not the calendar day of the punch.
                if ($employee) {
                    $workDate = $this->attributeDate($employee, $punch->punchedAt, $punch->rawType);
                    if (app(AttendancePeriodArchiveService::class)->isClosed((int) $employee->company_id, $workDate)) {
                        $result['skipped_count']++;
                    } else {
                        $affected[$employee->id . '|' . $workDate] = true;
                    }
                }
                $result['imported_count']++;
                if (!$result['cursor_timestamp'] || $punch->punchedAt->format('Y-m-d H:i:s') >= $result['cursor_timestamp']) { $result['cursor_timestamp'] = $punch->punchedAt->format('Y-m-d H:i:s'); $result['cursor_hash'] = $hash; }
            } catch (Throwable) { $result['failed_count']++; }
        }
        // Reconcile each touched employee-day as a whole: the sync never reduces a
        // multi-punch day to first/last. Regular days (exactly one in + one out) commit
        // straight to attendance; anything else parks in the punch-exceptions queue.
        $batch = null;
        foreach (array_keys($affected) as $pair) {
            [$employeeId, $workDate] = explode('|', $pair, 2);
            $employee = collect($employees)->first(fn (Employee $e) => (int) $e->id === (int) $employeeId) ?? Employee::find($employeeId);
            if (!$employee) continue;
            try {
                $this->reconcileDay($device, $employee, $workDate, $syncLogId, $batch, $result);
            } catch (Throwable) { $result['failed_count']++; }
        }
        return $result;
    }

    /**
     * Store one resolved punch and reconcile its day. Test and single-punch entry
     * point; the bulk sync path uses importPunches().
     */
    private function processPunch(Employee $employee, FingerprintRawPunch $punch, ?FingerprintDevice $device = null): void
    {
        $hash = $punch->importHash($device?->id ?? 0);
        if (!RawAttendanceLog::where('import_hash', $hash)->exists()) {
            RawAttendanceLog::create(['employee_id' => $employee->id, 'fingerprint_device_id' => $device?->id, 'raw_device_user_id' => $punch->rawDeviceUserId, 'source' => AttendanceSource::Fingerprint->value, 'raw_type' => $punch->rawType->value, 'raw_timestamp' => $punch->punchedAt, 'raw_payload' => $punch->payload, 'import_hash' => $hash, 'imported_at' => now(), 'device_id' => (string) ($device?->id ?? ''), 'processing_status' => 'processed', 'processed_at' => now()]);
        }
        $workDate = $this->attributeDate($employee, $punch->punchedAt, $punch->rawType);
        if (app(AttendancePeriodArchiveService::class)->isClosed((int) $employee->company_id, $workDate)) return;
        $result = ['exception_days' => 0, 'auto_resolved' => 0, 'skipped_count' => 0];
        $batch = null;
        $this->reconcileDay($device, $employee, $workDate, null, $batch, $result);
    }

    /**
     * Shift-aware work-date attribution, identical to the pre-existing upsert rule:
     * overnight shifts pull an early-morning punch back to the day the shift started.
     */
    private function attributeDate(Employee $employee, \Carbon\CarbonInterface $punchedAt, RawAttendanceType $type): string
    {
        $date = $punchedAt->toDateString();
        $start = OvertimeWindowMatcher::timeToMinutes($employee->shift_start ?: app(HrOperationalSettingsService::class)->start());
        $end = OvertimeWindowMatcher::timeToMinutes($employee->shift_end ?: app(HrOperationalSettingsService::class)->end());
        $minute = $punchedAt->hour * 60 + $punchedAt->minute;
        if ($end <= $start && (($type->value === 'check_out' && $minute < $start) || ($type->value === 'check_in' && $minute < $start - 360))) {
            $date = $punchedAt->copy()->subDay()->toDateString();
        }
        return $date;
    }

    /** All stored fingerprint punches attributed to one employee-day, chronological. */
    private function dayPunches(Employee $employee, string $workDate): Collection
    {
        return RawAttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('source', AttendanceSource::Fingerprint->value)
            ->whereBetween('raw_timestamp', [Carbon::parse($workDate)->subDay()->startOfDay(), Carbon::parse($workDate)->addDays(2)->endOfDay()])
            ->orderBy('raw_timestamp')->orderBy('id')->get()
            ->filter(fn (RawAttendanceLog $log) => $this->attributeDate($employee, $log->raw_timestamp, $log->raw_type) === $workDate)
            ->values();
    }

    private function reconcileDay(?FingerprintDevice $device, Employee $employee, string $workDate, ?int $syncLogId, ?AttendanceImportBatch &$batch, array &$result): void
    {
        $logs = $this->dayPunches($employee, $workDate);
        if ($logs->isEmpty()) return;
        $ins = $logs->filter(fn (RawAttendanceLog $log) => $log->raw_type === RawAttendanceType::CheckIn);
        $outs = $logs->filter(fn (RawAttendanceLog $log) => $log->raw_type === RawAttendanceType::CheckOut);
        $regular = $logs->count() === 2 && $ins->count() === 1 && $outs->count() === 1;
        $issue = $regular ? null : ($logs->count() > 2 ? 'extra' : 'missing');

        $day = AttendanceImportDay::query()->where('employee_id', $employee->id)->whereDate('work_date', $workDate)->orderByDesc('id')->first();
        // A reviewer-excluded day stays excluded: raw punches remain, nothing is recreated.
        if ($day && $day->resolution === 'deleted') return;

        $deviceLabel = 'جهاز ' . ($device?->name ?? 'البصمة');
        $punchRows = $logs->map(fn (RawAttendanceLog $log) => ['at' => $log->raw_timestamp->format('Y-m-d H:i:s'), 'ref' => $deviceLabel . ' · ' . $log->raw_type->value])->all();

        if ($day && $day->resolution === 'resolved') {
            // A reviewer already decided this day: later syncs only grow the audit
            // trail. They never recommit or overwrite the saved attendance, even
            // when the raw punches now look regular on their own.
            if (!$regular) {
                // A new irregularity after the review re-opens the day for a fresh
                // look; the saved attendance record is still left untouched.
                $this->updateDayWithRevision($day, $punchRows, $issue, 'pending', 'sync-append', 'وصلت بصمة جديدة بعد المراجعة وأعادت اليوم للانتظار', $day->attendance_id);
            } else {
                $this->updateDayWithRevision($day, $punchRows, $day->issue, 'resolved', 'sync-append', 'مزامنة جهاز البصمة حدّثت بصمات اليوم', $day->attendance_id);
            }
            return;
        }

        if ($regular) {
            $attendance = $this->commitRegularDay($employee, $workDate, $ins->first(), $outs->first());
            if ($day) { // pending: Amer's rule (2026-11-05) - the exit punch arrived and the day is complete
                $this->updateDayWithRevision($day, $punchRows, null, 'resolved', 'sync-resolve', 'وصلت بصمة الانصراف واكتمل اليوم بالمزامنة، فسُجّل تلقائيًا', $attendance->id);
                $result['auto_resolved']++;
            }
            return;
        }

        if (!$day) {
            $batch ??= AttendanceImportBatch::create([
                'company_id' => $employee->company_id, 'user_id' => null,
                'file_name' => 'مزامنة ' . $deviceLabel . ' — ' . now()->format('Y-m-d H:i'),
                'layout' => 'fingerprint',
                'original_data' => ['device_id' => $device?->id, 'device_name' => $device?->name, 'sync_log_id' => $syncLogId, 'synced_at' => now()->toDateTimeString()],
            ]);
            AttendanceImportDay::create([
                'batch_id' => $batch->id, 'employee_id' => $employee->id, 'work_date' => $workDate,
                'original_punches' => $punchRows, 'current_punches' => $punchRows,
                'issue' => $issue, 'resolution' => 'pending',
            ]);
            $result['exception_days']++;
            return;
        }

        $this->updateDayWithRevision($day, $punchRows, $issue, 'pending', 'sync-append', 'مزامنة جهاز البصمة حدّثت بصمات اليوم', $day->attendance_id);
    }

    private function commitRegularDay(Employee $employee, string $workDate, RawAttendanceLog $in, RawAttendanceLog $out): Attendance
    {
        $attendance = Attendance::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $workDate]);
        $attendance->check_in = $in->raw_timestamp->format('H:i:s');
        $attendance->check_out = $out->raw_timestamp->format('H:i:s');
        $attendance->source = AttendanceSource::Fingerprint->value;
        $attendance->approval_status = 'Approved';
        $attendance->forceFill(app(AttendanceMetricsService::class)->metrics($employee, $attendance->check_in, $attendance->check_out, $attendance->status));
        $attendance->save();
        app(PayrollRunService::class)->refreshEmployeeInOpenDrafts((int) $employee->id);
        return $attendance;
    }

    /** Rewrite the mutable punch list only when the sync actually changed something. */
    private function updateDayWithRevision(AttendanceImportDay $day, array $punchRows, ?string $issue, string $resolution, string $action, string $reason, ?int $attendanceId): void
    {
        $existing = collect($day->current_punches ?? [])->map(fn ($p) => is_array($p) ? ($p['at'] ?? $p) : $p)->sort()->values()->all();
        $incoming = collect($punchRows)->pluck('at')->sort()->values()->all();
        if ($existing === $incoming && $day->issue === $issue && $day->resolution === $resolution && (int) $day->attendance_id === (int) $attendanceId) return;
        DB::transaction(function () use ($day, $punchRows, $issue, $resolution, $action, $reason, $attendanceId): void {
            $day->refresh();
            $before = ['punches' => $day->current_punches, 'issue' => $day->issue, 'resolution' => $day->resolution, 'attendance_id' => $day->attendance_id];
            $day->fill(['current_punches' => $punchRows, 'issue' => $issue, 'resolution' => $resolution, 'attendance_id' => $attendanceId])->save();
            AttendancePunchRevision::create(['import_day_id' => $day->id, 'user_id' => null, 'action' => $action, 'reason' => $reason, 'before_data' => $before, 'after_data' => ['punches' => $punchRows, 'issue' => $issue, 'resolution' => $resolution, 'attendance_id' => $attendanceId], 'created_at' => now()]);
        });
    }
}
