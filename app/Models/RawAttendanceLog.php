<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\RawAttendanceType;
use App\Enums\RawProcessingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawAttendanceLog extends Model
{
    protected $fillable = [
        'employee_id',
        'fingerprint_device_id',
        'raw_device_user_id',
        'source',
        'raw_type',
        'raw_timestamp',
        'raw_payload',
        'import_hash',
        'imported_at',
        'device_id',
        'created_by',
        'processing_status',
        'processed_at',
        'is_duplicate',
        'duplicate_of_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'raw_type' => RawAttendanceType::class,
            'raw_timestamp' => 'datetime',
            'raw_payload' => 'array',
            'imported_at' => 'datetime',
            'processing_status' => RawProcessingStatus::class,
            'processed_at' => 'datetime',
            'is_duplicate' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function fingerprintDevice(): BelongsTo
    {
        return $this->belongsTo(FingerprintDevice::class, 'fingerprint_device_id');
    }
}
