<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use Illuminate\Console\Command;

class DetectStaleFingerprintSyncCommand extends Command
{
    protected $signature = 'operations:detect-stale-sync {--mark-offline : Mark stale active devices as offline}';
    protected $description = 'Detect stale fingerprint device synchronization state.';

    public function handle(): int
    {
        $devices = FingerprintDevice::query()->where('is_active', true)->where('sync_enabled', true)->get();
        $stale = $devices->filter(fn (FingerprintDevice $device) => !$device->last_sync_at || $device->isDueForSync());
        if ($this->option('mark-offline')) {
            $stale->each(fn (FingerprintDevice $device) => $device->update(['health_status' => 'offline']));
        }
        $this->info('Stale devices: '.$stale->count());
        return self::SUCCESS;
    }
}
