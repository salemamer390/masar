<?php

namespace App\Http\Controllers\Fingerprint;

use App\Data\FingerprintRawPunch;
use App\Enums\FingerprintConnectionMode;
use App\Enums\FingerprintSyncType;
use App\Enums\RawAttendanceType;
use App\Http\Controllers\Controller;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceCommand;
use App\Services\FingerprintAttendanceSyncService;
use App\Services\FingerprintPushTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ZKTeco ADMS / push-protocol receiver ("iclock").
 *
 * Trust boundary: ingestion exists ONLY on the per-device secret path
 * (/iclock/{token}/...). The token is a 48-char random secret generated per
 * device; only its hash is stored. The fixed ZK-standard path never imports
 * anything - it answers with a plain error so serial-only requests (the
 * serial is an identity, not authentication) cannot write attendance data.
 *
 * Production additionally requires HTTPS: the secret travels in the URL path,
 * so plain HTTP would expose it in transit. Devices whose firmware cannot use
 * a custom ADMS path or speak HTTPS must use the local bridge instead.
 */
class AdmsController extends Controller
{
    private const MAX_PUNCHES_PER_REQUEST = 500;

    public function __construct(
        private readonly FingerprintPushTokenService $tokens,
        private readonly FingerprintAttendanceSyncService $syncService,
    ) {}

    /** GET/POST /iclock/cdata (fixed path - unauthenticated, imports nothing). */
    public function unauthenticated(Request $request): Response
    {
        // Uniform answer for known and unknown serials so the endpoint cannot
        // be used to probe which serials are registered. Nothing is stored and
        // no device record is touched by strangers.
        Log::warning('fingerprint.adms.unauthenticated_request', [
            'ip' => $request->ip(),
            'sn' => substr((string) $request->query('SN', ''), 0, 64),
            'method' => $request->method(),
        ]);

        return $this->plain('ERROR: this server requires a device-specific secure path. Configure the full URL provided by your Odir device page.', 403);
    }

    /** GET /iclock/{token}/cdata - device handshake. */
    public function handshake(Request $request, string $token): Response
    {
        $device = $this->resolveDevice($request, $token);

        if (! $device) {
            return $this->plain('ERROR', 403);
        }

        $device->forceFill(['push_last_seen_at' => now()])->save();

        $sn = $device->serial_number;
        // Echo the stamp of the last accepted upload so incremental devices
        // resume where they stopped; None until the first upload arrives.
        $stamp = Cache::get("fingerprint:adms-stamp:{$device->id}", 'None');

        // Standard iclock option block. ATTLOGStamp echoes the last accepted
        // upload stamp so incremental devices resume where they stopped.
        return $this->plain(implode("\n", [
            "GET OPTION FROM: {$sn}",
            "ATTLOGStamp={$stamp}",
            'OPERLOGStamp=None',
            'ATTPHOTOStamp=None',
            'ErrorDelay=60',
            'Delay=30',
            'ResLogDelay=0',
            'RELOGCNT=0',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=1111000000',
            'Realtime=1',
            'Encrypt=0',
        ]));
    }

    /** POST /iclock/{token}/cdata?table=ATTLOG - punch upload. */
    public function receiveData(Request $request, string $token): Response
    {
        $device = $this->resolveDevice($request, $token);

        if (! $device) {
            return $this->plain('ERROR', 403);
        }

        $table = strtoupper((string) $request->query('table', ''));

        if ($table !== 'ATTLOG') {
            // OPERLOG and other tables are acknowledged but not imported.
            return $this->plain('OK');
        }

        $punches = $this->parseAttlog((string) $request->getContent(), $device);

        if ($punches === null) {
            return $this->plain('ERROR: bad ATTLOG payload', 400);
        }

        $this->syncService->importDevicePunches($device, $punches, FingerprintSyncType::AdmsPush);

        $stamp = trim((string) $request->query('Stamp', ''));
        if ($stamp !== '' && strlen($stamp) <= 64) {
            Cache::put("fingerprint:adms-stamp:{$device->id}", $stamp, now()->addDays(30));
        }

        return $this->plain('OK');
    }

    /** GET /iclock/{token}/getrequest - device polls for pending commands. */
    public function getRequest(Request $request, string $token): Response
    {
        $device = $this->resolveDevice($request, $token);

        if (! $device) {
            return $this->plain('ERROR', 403);
        }

        $device->forceFill(['push_last_seen_at' => now()])->save();

        $command = FingerprintDeviceCommand::query()
            ->where('device_id', $device->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->first();

        if (! $command) {
            return $this->plain('OK');
        }

        $command->update(['status' => 'sent', 'sent_at' => now()]);

        return $this->plain("C:{$command->id}:{$command->command}");
    }

    /** POST /iclock/{token}/devicecmd - device reports a command result. */
    public function deviceCmd(Request $request, string $token): Response
    {
        $device = $this->resolveDevice($request, $token);

        if (! $device) {
            return $this->plain('ERROR', 403);
        }

        $body = (string) $request->getContent();
        parse_str($body, $fields);
        $commandId = (int) ($fields['ID'] ?? $request->input('ID', 0));

        if ($commandId > 0) {
            FingerprintDeviceCommand::query()
                ->where('device_id', $device->id)
                ->whereKey($commandId)
                ->where('status', 'sent')
                ->update([
                    'status' => ((int) ($fields['Return'] ?? -1)) === 0 ? 'acknowledged' : 'failed',
                    'result' => substr($body, 0, 2000),
                    'acknowledged_at' => now(),
                ]);
        }

        return $this->plain('OK');
    }

    /**
     * Resolve the device from the path secret and verify the reported serial.
     * Both must agree: the secret authenticates, the serial cross-checks.
     */
    private function resolveDevice(Request $request, string $token): ?FingerprintDevice
    {
        if (strlen($token) < 32) {
            return null;
        }

        $device = FingerprintDevice::query()
            ->where('push_token_hash', $this->tokens->hash($token))
            ->where('connection_mode', FingerprintConnectionMode::AdmsPush->value)
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return null;
        }

        $sn = trim((string) $request->query('SN', ''));

        if ($sn === '' || ! $device->serial_number || ! hash_equals((string) $device->serial_number, $sn)) {
            return null;
        }

        return $device;
    }

    /**
     * Parse ZK ATTLOG lines: PIN \t datetime \t status \t verify \t workcode ...
     * Some firmwares prefix the PIN field with "PIN=". Status uses the same
     * mapping as the TCP driver: 1, 2, 5 are outbound punches.
     *
     * @return array<int, FingerprintRawPunch>|null null on a malformed payload
     */
    private function parseAttlog(string $body, FingerprintDevice $device): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $punches = [];
        $timezone = $device->timezone ?: config('app.timezone');

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (count($punches) >= self::MAX_PUNCHES_PER_REQUEST) {
                break;
            }

            $fields = preg_split('/\s+/', $line);

            if (! $fields || count($fields) < 3) {
                return null;
            }

            $pin = preg_replace('/^PIN=/i', '', $fields[0]);
            $dateTime = trim($fields[1].' '.($fields[2] ?? ''));

            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateTime)) {
                // Some firmwares send datetime as a single field.
                $dateTime = trim($fields[1].' '.($fields[2] ?? ''));
                if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateTime)) {
                    return null;
                }
            }

            if ($pin === '' || strlen($pin) > 32) {
                return null;
            }

            $statusField = $fields[3] ?? '0';
            $verifyField = $fields[4] ?? '0';

            if (! is_numeric($statusField) || ! is_numeric($verifyField)) {
                return null;
            }

            $status = (int) $statusField;
            $verify = (int) $verifyField;

            try {
                $punchedAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateTime, $timezone);
            } catch (\Throwable) {
                return null;
            }

            if (! $punchedAt || $punchedAt->year < 2020) {
                return null;
            }

            $punches[] = new FingerprintRawPunch(
                rawDeviceUserId: $pin,
                punchedAt: $punchedAt,
                rawType: in_array($status, [1, 2, 5], true) ? RawAttendanceType::CheckOut : RawAttendanceType::CheckIn,
                deviceRecordId: null,
                punchState: $status,
                verifyMode: $verify,
                payload: [
                    'source' => 'adms_push',
                    'verify_mode' => $verify,
                    'punch_state' => $status,
                    'raw_line' => substr($line, 0, 200),
                ],
            );
        }

        return $punches;
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body."\n", $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
