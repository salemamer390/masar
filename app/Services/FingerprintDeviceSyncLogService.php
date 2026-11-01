<?php

namespace App\Services;

use App\Models\FingerprintDeviceSyncLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FingerprintDeviceSyncLogService
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $user = auth()->user();

        return FingerprintDeviceSyncLog::query()
            ->with(['device', 'initiator'])
            // Company accounts only see logs of their own devices; a
            // company-less platform account keeps platform-wide visibility.
            ->when($user?->company_id, fn ($query, $companyId) => $query->whereHas(
                'device',
                fn ($device) => $device->where('company_id', $companyId)
            ))
            ->when($filters['device_id'] ?? null, fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['sync_type'] ?? null, fn ($query, $type) => $query->where('sync_type', $type))
            ->latest('started_at')
            ->paginate(20)
            ->withQueryString();
    }
}
