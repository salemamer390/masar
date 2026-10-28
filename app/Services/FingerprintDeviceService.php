<?php

namespace App\Services;

use App\Models\FingerprintDevice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FingerprintDeviceService extends BaseService
{
    public function __construct(private readonly DeviceDriverResolverService $driverResolver) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return FingerprintDevice::query()
            ->withCount('syncLogs')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('physical_location', 'like', "%{$search}%");
            })
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('is_active', (bool) $filters['status']))
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }

    public function create(array $data): FingerprintDevice
    {
        return $this->transaction(function () use ($data) {
            $device = FingerprintDevice::create($data);
            $this->logAction('fingerprint_device.created', $device);

            return $device;
        });
    }

    public function update(FingerprintDevice $device, array $data): FingerprintDevice
    {
        return $this->transaction(function () use ($device, $data) {
            $device->update($data);
            $this->logAction('fingerprint_device.updated', $device);

            return $device->refresh();
        });
    }

    public function delete(FingerprintDevice $device): void
    {
        $this->transaction(function () use ($device) {
            $device->delete();
            $this->logAction('fingerprint_device.deleted', null, ['device_id' => $device->id]);
        });
    }

    public function lookups(): array
    {
        return [
            'drivers' => $this->driverResolver->supportedDrivers(),
            'timezones' => ['Africa/Cairo', 'UTC', 'Asia/Riyadh', 'Europe/London'],
        ];
    }
}
