<?php

namespace App\Console\Commands;

use App\Models\FingerprintDevice;
use App\Models\User;
use App\Notifications\FingerprintDeviceStaleNotification;
use Illuminate\Console\Command;

class DetectStaleFingerprintSyncCommand extends Command
{
    protected $signature = 'operations:detect-stale-sync {--mark-offline : Mark stale active devices as offline}';
    protected $description = 'Detect stale fingerprint device synchronization state and alert the responsible users.';

    public function handle(): int
    {
        $devices = FingerprintDevice::query()->where('is_active', true)->where('sync_enabled', true)->get();
        $stale = $devices->filter(fn (FingerprintDevice $device) => !$device->last_sync_at || $device->isDueForSync());
        $notified = 0;
        foreach ($stale as $device) {
            $recipients = User::with('role.permissions')->where('is_active', true)
                ->where('company_id', $device->company_id)->get()
                ->filter(fn (User $user) => $user->hasPermission('fingerprint-devices.view'));
            foreach ($recipients as $recipient) {
                // One alert per device per day: the device staying offline must
                // not bury the centre under repeats.
                $alreadySent = $recipient->notifications()
                    ->where('type', FingerprintDeviceStaleNotification::class)
                    ->where('data->device_id', $device->id)
                    ->whereDate('created_at', today())
                    ->exists();
                if (!$alreadySent) {
                    $recipient->notify(new FingerprintDeviceStaleNotification($device));
                    $notified++;
                }
            }
        }
        if ($this->option('mark-offline')) {
            $stale->each(fn (FingerprintDevice $device) => $device->update(['health_status' => 'offline']));
        }
        $this->info('Stale devices: '.$stale->count().', notifications: '.$notified);
        return self::SUCCESS;
    }
}
