<?php

namespace App\Data;

use App\Enums\RawAttendanceType;
use Carbon\CarbonImmutable;

class FingerprintRawPunch
{
    public function __construct(
        public readonly string $rawDeviceUserId,
        public readonly CarbonImmutable $punchedAt,
        public readonly RawAttendanceType $rawType,
        public readonly ?string $deviceRecordId = null,
        public readonly ?int $punchState = null,
        public readonly ?int $verifyMode = null,
        public readonly array $payload = [],
    ) {}

    public function importHash(int $deviceId): string
    {
        return hash('sha256', implode('|', [
            $deviceId,
            $this->deviceRecordId ?: 'no-record-id',
            $this->rawDeviceUserId,
            $this->punchedAt->format('Y-m-d H:i:s'),
            $this->rawType->value,
            $this->punchState ?? 'none',
        ]));
    }
}
