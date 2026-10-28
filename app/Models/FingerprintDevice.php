<?php

namespace App\Models;

use App\Enums\FingerprintDeviceDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;

class FingerprintDevice extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'name',
        'device_type',
        'driver',
        'ip_address',
        'port',
        'communication_key',
        'serial_number',
        'firmware_version',
        'timezone',
        'physical_location',
        'sync_enabled',
        'sync_interval_minutes',
        'is_active',
        'last_connected_at',
        'last_sync_at',
        'health_status',
        'consecutive_failures',
        'last_connection_failure_at',
        'sync_cursor_timestamp',
        'sync_cursor_hash',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'driver' => FingerprintDeviceDriver::class,
            'port' => 'integer',
            'communication_key' => 'integer',
            'sync_enabled' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_connection_failure_at' => 'datetime',
            'sync_cursor_timestamp' => 'datetime',
        ];
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(FingerprintDeviceSyncLog::class, 'device_id');
    }

    public function isDueForSync(): bool
    {
        if (! $this->is_active || ! $this->sync_enabled) {
            return false;
        }

        if (! $this->last_sync_at) {
            return true;
        }

        return $this->last_sync_at->lte(now()->subMinutes(max(1, $this->sync_interval_minutes)));
    }
}
