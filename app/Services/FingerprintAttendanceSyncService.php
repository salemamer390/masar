<?php

namespace App\Services;

use App\Data\FingerprintRawPunch;
use App\Enums\AttendanceSource;
use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceSyncLog;
use App\Models\RawAttendanceLog;
use Illuminate\Support\Collection;
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
            $result = $this->importPunches($device, $punches);
            $log->update(['status' => FingerprintSyncStatus::Success->value, 'completed_at' => now(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'records_received' => $result['records_received'], 'imported_count' => $result['imported_count'], 'skipped_count' => $result['skipped_count'], 'duplicate_count' => $result['duplicate_count'], 'failed_count' => $result['failed_count'], 'metadata' => ['unresolved_count' => $result['unresolved_count'], 'cursor_timestamp' => $result['cursor_timestamp']]]);
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

    private function importPunches(FingerprintDevice $device, Collection $punches): array
    {
        $result = ['records_received' => $punches->count(), 'imported_count' => 0, 'skipped_count' => 0, 'duplicate_count' => 0, 'failed_count' => 0, 'unresolved_count' => 0, 'cursor_timestamp' => $device->sync_cursor_timestamp?->format('Y-m-d H:i:s'), 'cursor_hash' => $device->sync_cursor_hash];
        $employees = Employee::query()->get()->flatMap(fn (Employee $e) => collect([(string) $e->employee_code => $e, (string) ($e->fingerprint_id ?? '') => $e]))->filter(fn ($e, $key) => $key !== '')->all();
        foreach ($punches as $punch) {
            if (!$punch instanceof FingerprintRawPunch) { $result['skipped_count']++; continue; }
            $employee = $employees[$punch->rawDeviceUserId] ?? null;
            if (!$employee) $result['unresolved_count']++;
            $hash = $punch->importHash($device->id);
            if (RawAttendanceLog::where('import_hash', $hash)->exists()) { $result['duplicate_count']++; continue; }
            try {
                RawAttendanceLog::create(['employee_id' => $employee?->id, 'fingerprint_device_id' => $device->id, 'raw_device_user_id' => $punch->rawDeviceUserId, 'source' => AttendanceSource::Fingerprint->value, 'raw_type' => $punch->rawType->value, 'raw_timestamp' => $punch->punchedAt, 'raw_payload' => $punch->payload, 'import_hash' => $hash, 'imported_at' => now(), 'device_id' => (string) $device->id, 'processing_status' => 'processed', 'processed_at' => now()]);
                if ($employee) $this->upsertAttendance($employee, $punch);
                $result['imported_count']++;
                if (!$result['cursor_timestamp'] || $punch->punchedAt->format('Y-m-d H:i:s') >= $result['cursor_timestamp']) { $result['cursor_timestamp'] = $punch->punchedAt->format('Y-m-d H:i:s'); $result['cursor_hash'] = $hash; }
            } catch (Throwable) { $result['failed_count']++; }
        }
        return $result;
    }

    private function upsertAttendance(Employee $employee, FingerprintRawPunch $punch): void
    {
        $attendance = Attendance::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $punch->punchedAt->toDateString()]);
        $time = $punch->punchedAt->format('H:i:s');
        if ($punch->rawType->value === 'check_out') { if (!$attendance->check_out || $time > $attendance->check_out) $attendance->check_out = $time; }
        elseif (!$attendance->check_in || $time < $attendance->check_in) $attendance->check_in = $time;
        $attendance->source = AttendanceSource::Fingerprint->value;
        $attendance->approval_status = 'Approved';
        $attendance->status = $attendance->check_in ? 'Present' : 'Absent';
        $attendance->save();
    }
}
