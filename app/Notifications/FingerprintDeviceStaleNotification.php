<?php

namespace App\Notifications;

use App\Models\FingerprintDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One alert per device per day while an active, sync-enabled fingerprint
 * device stops reporting: attendance silently freezes otherwise.
 */
class FingerprintDeviceStaleNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly FingerprintDevice $device) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $lastSync = $this->device->last_sync_at?->format('d/m/Y H:i');

        return [
            'kind' => 'fingerprint_device_stale',
            'device_id' => $this->device->id,
            'device_name' => $this->device->name,
            'last_sync_at' => $this->device->last_sync_at?->toDateTimeString(),
            'reminder_date' => today()->toDateString(),
            'message' => $lastSync
                ? "جهاز البصمة «{$this->device->name}» لم يزامن منذ {$lastSync}. تحقق من اتصال الجهاز حتى لا يتوقف تسجيل الحضور."
                : "جهاز البصمة «{$this->device->name}» لم يسجل أي مزامنة ناجحة حتى الآن. تحقق من اتصال الجهاز حتى لا يتوقف تسجيل الحضور.",
            'url' => route('fingerprint.devices.index'),
        ];
    }
}
