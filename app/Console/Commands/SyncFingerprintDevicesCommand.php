<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\FingerprintAttendanceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncFingerprintDevicesCommand extends Command
{
    protected $signature = 'fingerprint:sync-devices
        {--device= : Device ID}
        {--timeout=10 : Device connection timeout in seconds}
        {--retries=1 : Number of retry attempts per device}
        {--force : Ignore per-device sync interval}
        {--dry-run : Preview due devices without connecting}';

    protected $description = 'Synchronize ZKTeco attendance punches into raw_attendance_logs only.';

    public function handle(FingerprintAttendanceSyncService $sync): int
    {
        $options = [
            'device_id' => $this->option('device'),
            'timeout' => (int) $this->option('timeout'),
            'retries' => (int) $this->option('retries'),
            'force' => (bool) $this->option('force'),
        ];

        if ($this->option('dry-run')) {
            $count = FingerprintDevice::query()
                ->where('is_active', true)
                ->where('sync_enabled', true)
                ->when($options['device_id'], fn ($query, $deviceId) => $query->whereKey($deviceId))
                ->get()
                ->filter(fn (FingerprintDevice $device) => $options['force'] || $device->isDueForSync())
                ->count();

            $this->info("Dry run: {$count} active fingerprint device(s) are due for raw punch synchronization.");

            return self::SUCCESS;
        }

        try {
            $summary = $sync->syncDueDevices(null, $options);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Devices checked: {$summary['devices']}");
        $this->line("Successful: {$summary['success']}");
        $this->line("Failed: {$summary['failed']}");

        return self::SUCCESS;
    }
}
