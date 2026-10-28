<?php

namespace App\Services;

use App\Models\FingerprintDeviceSyncLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FingerprintDeviceSyncLogService
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return FingerprintDeviceSyncLog::query()
            ->with(['device', 'initiator'])
            ->when($filters['device_id'] ?? null, fn ($query, $deviceId) => $query->where('device_id', $deviceId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['sync_type'] ?? null, fn ($query, $type) => $query->where('sync_type', $type))
            ->latest('started_at')
            ->paginate(20)
            ->withQueryString();
    }
}
