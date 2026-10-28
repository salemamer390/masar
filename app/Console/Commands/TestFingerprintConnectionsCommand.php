<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Services\DeviceConnectionService;
use Illuminate\Console\Command;

class TestFingerprintConnectionsCommand extends Command
{
    protected $signature = 'fingerprint:test-connections
        {--device= : Device ID}
        {--timeout=5 : Timeout in seconds}
        {--active-only : Test only active devices}';

    protected $description = 'Test fingerprint device connectivity without pulling attendance punches.';

    public function handle(DeviceConnectionService $connectionService): int
    {
        $devices = FingerprintDevice::query()
            ->when($this->option('device'), fn ($query, $deviceId) => $query->whereKey($deviceId))
            ->when($this->option('active-only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('No fingerprint devices matched the filters.');

            return self::SUCCESS;
        }

        $devices->each(function (FingerprintDevice $device) use ($connectionService) {
            $log = $connectionService->testConnection($device, null, (int) $this->option('timeout'));
            $this->line("{$device->name}: {$log->status->value} ({$log->duration_ms} ms)");
        });

        return self::SUCCESS;
    }
}
