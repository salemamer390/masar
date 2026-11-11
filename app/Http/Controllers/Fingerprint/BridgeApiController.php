<?php

namespace App\Http\Controllers\Fingerprint;

use App\Data\FingerprintRawPunch;
use App\Enums\FingerprintSyncType;
use App\Enums\RawAttendanceType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFingerprintBridge;
use App\Models\FingerprintDevice;
use App\Services\FingerprintAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token-authenticated JSON API used by the small local bridge program that
 * runs on a PC inside the company, pulls punches from LAN-only devices and
 * pushes them here. Authentication happens in AuthenticateFingerprintBridge.
 */
class BridgeApiController extends Controller
{
    private const MAX_PUNCHES_PER_REQUEST = 500;

    public function __construct(private readonly FingerprintAttendanceSyncService $syncService) {}

    /** GET /api/fingerprint-bridge/handshake - config + reachability check. */
    public function handshake(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $device->forceFill(['push_last_seen_at' => now()])->save();

        return response()->json([
            'ok' => true,
            'device' => [
                'name' => $device->name,
                'serial' => $device->serial_number,
                'timezone' => $device->timezone,
                'sync_interval_minutes' => $device->sync_interval_minutes,
            ],
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    /** POST /api/fingerprint-bridge/punches - batch punch upload. */
    public function punches(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $validated = $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:'.self::MAX_PUNCHES_PER_REQUEST],
            'punches.*.user_id' => ['required', 'string', 'min:1', 'max:32', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'punches.*.timestamp' => ['required', 'date_format:Y-m-d H:i:s'],
            'punches.*.state' => ['nullable', 'integer', 'min:0', 'max:255'],
            'punches.*.verify_mode' => ['nullable', 'integer', 'min:0', 'max:255'],
            'punches.*.record_id' => ['nullable', 'string', 'max:32'],
        ]);

        $timezone = $device->timezone ?: config('app.timezone');
        $punches = [];

        foreach ($validated['punches'] as $row) {
            $state = isset($row['state']) ? (int) $row['state'] : null;
            $verify = isset($row['verify_mode']) ? (int) $row['verify_mode'] : null;

            $punches[] = new FingerprintRawPunch(
                rawDeviceUserId: $row['user_id'],
                punchedAt: CarbonImmutable::createFromFormat('Y-m-d H:i:s', $row['timestamp'], $timezone),
                rawType: in_array($state, [1, 2, 5], true) ? RawAttendanceType::CheckOut : RawAttendanceType::CheckIn,
                deviceRecordId: $row['record_id'] ?? null,
                punchState: $state,
                verifyMode: $verify,
                payload: ['source' => 'local_bridge', 'verify_mode' => $verify, 'punch_state' => $state],
            );
        }

        $log = $this->syncService->importDevicePunches($device, $punches, FingerprintSyncType::BridgePush);

        return response()->json([
            'ok' => true,
            'received' => $log->records_received,
            'imported' => $log->imported_count,
            'duplicates' => $log->duplicate_count,
            'skipped' => $log->skipped_count,
            'unresolved' => (int) ($log->metadata['unresolved_count'] ?? 0),
            'sync_log_id' => $log->id,
        ]);
    }

    private function device(Request $request): FingerprintDevice
    {
        return $request->attributes->get(AuthenticateFingerprintBridge::DEVICE_ATTRIBUTE);
    }
}
