<?php

namespace App\Models;

use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FingerprintDeviceSyncLog extends Model
{
    protected $fillable = [
        'device_id',
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'records_received',
        'imported_count',
        'skipped_count',
        'duplicate_count',
        'failed_count',
        'error_message',
        'initiated_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sync_type' => FingerprintSyncType::class,
            'status' => FingerprintSyncStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
            'records_received' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
            'duplicate_count' => 'integer',
            'failed_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'device_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
