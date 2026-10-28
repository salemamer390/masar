<?php

namespace App\Services;

use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceSyncLog;
use Throwable;

class DeviceConnectionService extends BaseService
{
    public function __construct(private readonly DeviceDriverResolverService $driverResolver) {}

    public function testConnection(FingerprintDevice $device, ?int $initiatedBy = null, int $timeoutSeconds = 5): FingerprintDeviceSyncLog
    {
        return $this->runInfrastructureAction($device, FingerprintSyncType::ConnectionTest, $initiatedBy, function () use ($device, $timeoutSeconds) {
            return $this->driverResolver->resolve($device)->testConnection($device, $timeoutSeconds);
        });
    }

    public function retrieveDeviceInfo(FingerprintDevice $device, ?int $initiatedBy = null, int $timeoutSeconds = 5): FingerprintDeviceSyncLog
    {
        return $this->runInfrastructureAction($device, FingerprintSyncType::DeviceInfo, $initiatedBy, function () use ($device, $timeoutSeconds) {
            return $this->driverResolver->resolve($device)->getDeviceInfo($device, $timeoutSeconds);
        });
    }

    private function runInfrastructureAction(FingerprintDevice $device, FingerprintSyncType $type, ?int $initiatedBy, callable $callback): FingerprintDeviceSyncLog
    {
        $started = microtime(true);
        $log = FingerprintDeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => $type->value,
            'status' => FingerprintSyncStatus::Running->value,
            'started_at' => now(),
            'initiated_by' => $initiatedBy,
            'records_received' => 0,
        ]);

        try {
            $result = $callback();
            $connected = (bool) ($result['connected'] ?? false);
            $durationMs = (int) ($result['duration_ms'] ?? round((microtime(true) - $started) * 1000));

            $log->update([
                'status' => $connected ? FingerprintSyncStatus::Success->value : FingerprintSyncStatus::Failed->value,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
                'error_message' => $connected ? null : ($result['error'] ?? 'Connection failed.'),
                'metadata' => $result,
            ]);

            if ($connected) {
                $device->update([
                    'last_connected_at' => now(),
                    'health_status' => 'online',
                    'consecutive_failures' => 0,
                    'last_connection_failure_at' => null,
                ]);
            } else {
                $device->update([
                    'health_status' => 'offline',
                    'consecutive_failures' => $device->consecutive_failures + 1,
                    'last_connection_failure_at' => now(),
                ]);
            }

            $this->logAction($connected ? 'fingerprint_device.connection_success' : 'fingerprint_device.connection_failed', $device, [
                'sync_log_id' => $log->id,
                'sync_type' => $type->value,
            ]);

            return $log->refresh();
        } catch (Throwable $exception) {
            $log->update([
                'status' => FingerprintSyncStatus::Failed->value,
                'completed_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_message' => $exception->getMessage(),
                'metadata' => ['exception' => $exception::class],
            ]);

            $device->update([
                'health_status' => 'offline',
                'consecutive_failures' => $device->consecutive_failures + 1,
                'last_connection_failure_at' => now(),
            ]);

            $this->logAction('fingerprint_device.connection_exception', $device, [
                'sync_log_id' => $log->id,
                'error' => $exception->getMessage(),
            ]);

            return $log->refresh();
        }
    }
}
