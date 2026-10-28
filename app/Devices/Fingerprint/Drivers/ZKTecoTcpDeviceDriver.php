<?php

namespace App\Devices\Fingerprint\Drivers;

use App\Contracts\Fingerprint\FingerprintDeviceDriverInterface;
use App\Data\FingerprintRawPunch;
use App\Enums\RawAttendanceType;
use App\Models\FingerprintDevice;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class ZKTecoTcpDeviceDriver implements FingerprintDeviceDriverInterface
{
    private const CMD_CONNECT = 1000;
    private const CMD_EXIT = 1001;
    private const CMD_AUTH = 1102;
    private const CMD_ATTLOG_RRQ = 13;
    private const CMD_ACK_OK = 2000;
    private const CMD_ACK_UNAUTH = 2005;
    private const CMD_PREPARE_DATA = 1500;
    private const CMD_DATA = 1501;
    private const TCP_HEADER_1 = 0x5050;
    private const TCP_HEADER_2 = 0x7d82;
    private const USHRT_MAX = 65535;

    private mixed $connection = null;
    private int $sessionId = 0;
    private int $replyId = self::USHRT_MAX - 1;

    public function connect(FingerprintDevice $device): bool
    {
        $result = $this->openSocket($device, 5);

        if (! $result['connected']) {
            return false;
        }

        try {
            return $this->authenticate($device)['connected'];
        } catch (Throwable) {
            $this->disconnect();

            return false;
        }
    }

    public function disconnect(): void
    {
        if (is_resource($this->connection)) {
            try {
                $this->sendCommand(self::CMD_EXIT);
            } catch (Throwable) {
                //
            }

            fclose($this->connection);
        }

        $this->connection = null;
        $this->sessionId = 0;
        $this->replyId = self::USHRT_MAX - 1;
    }

    public function testConnection(FingerprintDevice $device, int $timeoutSeconds = 5): array
    {
        $startedAt = microtime(true);

        try {
            $socket = $this->openSocket($device, $timeoutSeconds);

            if (! $socket['connected']) {
                return $socket;
            }

            $session = $this->authenticate($device);
            $connected = $session['connected'];
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'connected' => $connected,
                'duration_ms' => $durationMs,
                'error' => $connected ? null : $session['error'],
                'session_id' => $connected ? $session['session_id'] : null,
                'auth_required' => $session['auth_required'],
                'response_code' => $session['response_code'],
            ];
        } catch (Throwable $exception) {
            return [
                'connected' => false,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ];
        } finally {
            $this->disconnect();
        }
    }

    public function getDeviceInfo(FingerprintDevice $device, int $timeoutSeconds = 5): array
    {
        $result = $this->testConnection($device, $timeoutSeconds);

        return $result + [
            'driver' => 'zkteco_tcp',
            'device_type' => $device->device_type,
            'host' => $device->ip_address,
            'port' => $device->port,
            'serial_number' => $device->serial_number,
            'firmware_version' => $device->firmware_version,
            'protocol' => 'ZKTeco standalone TCP/IP',
        ];
    }

    public function pullAttendance(FingerprintDevice $device, int $timeoutSeconds = 10, int $retries = 1): iterable
    {
        $attempt = 0;

        beginning:
        $attempt++;

        try {
            $socket = $this->openSocket($device, $timeoutSeconds);

            if (! $socket['connected']) {
                throw new RuntimeException($socket['error'] ?? 'Unable to connect to device.');
            }

            $session = $this->authenticate($device);

            if (! $session['connected']) {
                throw new RuntimeException($session['error']);
            }

            $attendanceBytes = $this->requestAttendanceBytes();

            return $this->parseAttendanceBytes($attendanceBytes, $device->timezone ?: config('app.timezone'));
        } catch (Throwable $exception) {
            $this->disconnect();

            if ($attempt <= max(1, $retries)) {
                usleep(250000);
                goto beginning;
            }

            throw $exception;
        } finally {
            $this->disconnect();
        }
    }

    private function openSocket(FingerprintDevice $device, int $timeoutSeconds): array
    {
        $startedAt = microtime(true);
        $errno = 0;
        $error = '';

        $this->connection = @fsockopen(
            $device->ip_address,
            $device->port,
            $errno,
            $error,
            max(1, $timeoutSeconds)
        );

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! is_resource($this->connection)) {
            return [
                'connected' => false,
                'duration_ms' => $durationMs,
                'error' => $error ?: "Connection failed with code {$errno}.",
            ];
        }

        stream_set_timeout($this->connection, max(1, $timeoutSeconds));

        return [
            'connected' => true,
            'duration_ms' => $durationMs,
            'error' => null,
        ];
    }

    private function authenticate(FingerprintDevice $device): array
    {
        $response = $this->sendCommand(self::CMD_CONNECT);
        $this->sessionId = $response['session_id'];

        if ($response['command'] === self::CMD_ACK_OK) {
            return [
                'connected' => true,
                'error' => null,
                'session_id' => $this->sessionId,
                'auth_required' => false,
                'response_code' => $response['command'],
            ];
        }

        if ($response['command'] !== self::CMD_ACK_UNAUTH) {
            return [
                'connected' => false,
                'error' => 'ZKTeco command handshake failed with response code '.$response['command'].'.',
                'session_id' => $this->sessionId,
                'auth_required' => false,
                'response_code' => $response['command'],
            ];
        }

        $communicationKey = (int) ($device->communication_key ?? 0);

        $auth = $this->sendCommand(self::CMD_AUTH, $this->makeCommunicationKey($communicationKey, $this->sessionId));
        $connected = $auth['command'] === self::CMD_ACK_OK;

        return [
            'connected' => $connected,
            'error' => $connected ? null : 'ZKTeco authentication failed with response code '.$auth['command'].'. If the device has a Comm Key, enter it and try again.',
            'session_id' => $this->sessionId,
            'auth_required' => true,
            'response_code' => $auth['command'],
        ];
    }

    private function requestAttendanceBytes(): string
    {
        $response = $this->sendCommand(self::CMD_ATTLOG_RRQ);

        if ($response['command'] === self::CMD_DATA) {
            return $response['payload'];
        }

        if (! in_array($response['command'], [self::CMD_ACK_OK, self::CMD_PREPARE_DATA], true)) {
            throw new RuntimeException('Unexpected ZKTeco attendance response command: '.$response['command']);
        }

        $expectedBytes = $this->extractPreparedDataLength($response['payload']);
        $payload = '';

        while ($expectedBytes === null || strlen($payload) < $expectedBytes) {
            $packet = $this->readPacket();

            if ($packet['command'] === self::CMD_DATA) {
                $payload .= $packet['payload'];
            }

            if ($expectedBytes === null && $payload !== '') {
                break;
            }
        }

        return $expectedBytes === null ? $payload : substr($payload, 0, $expectedBytes);
    }

    private function sendCommand(int $command, string $payload = ''): array
    {
        if (! is_resource($this->connection)) {
            throw new RuntimeException('Device socket is not connected.');
        }

        $packet = $this->createPacket($command, $payload);
        $written = fwrite($this->connection, $packet);

        if ($written === false || $written < strlen($packet)) {
            throw new RuntimeException('Failed to write complete ZKTeco command packet.');
        }

        $response = $this->readPacket();
        $this->replyId = $response['reply_id'];

        return $response;
    }

    private function createPacket(int $command, string $payload): string
    {
        $replyId = $this->nextReplyId();
        $checksumBody = pack('vvvv', $command, 0, $this->sessionId, $this->replyId).$payload;
        $checksum = $this->checksum($checksumBody);
        $body = pack('vvvv', $command, $checksum, $this->sessionId, $replyId).$payload;

        return pack('vvV', self::TCP_HEADER_1, self::TCP_HEADER_2, strlen($body)).$body;
    }

    private function nextReplyId(): int
    {
        $replyId = $this->replyId + 1;

        if ($replyId >= self::USHRT_MAX) {
            $replyId -= self::USHRT_MAX;
        }

        return $replyId;
    }

    private function readPacket(): array
    {
        $prefix = $this->readBytes(8);
        $prefixData = unpack('vheader1/vheader2/Vlength', $prefix);

        if (($prefixData['header1'] ?? null) === self::TCP_HEADER_1) {
            $body = $this->readBytes((int) $prefixData['length']);
        } else {
            $body = $prefix;
        }

        if (strlen($body) < 8) {
            throw new RuntimeException('Incomplete ZKTeco response packet.');
        }

        $header = unpack('vcommand/vchecksum/vsession_id/vreply_id', substr($body, 0, 8));

        return [
            'command' => (int) $header['command'],
            'checksum' => (int) $header['checksum'],
            'session_id' => (int) $header['session_id'],
            'reply_id' => (int) $header['reply_id'],
            'payload' => substr($body, 8),
        ];
    }

    private function readBytes(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($this->connection, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->connection);

                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('ZKTeco TCP read timed out.');
                }

                throw new RuntimeException('ZKTeco TCP connection closed unexpectedly.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function checksum(string $body): int
    {
        $bytes = array_values(unpack('C*', $body));
        $length = count($bytes);
        $checksum = 0;
        $index = 0;

        while ($length > 1) {
            $checksum += $bytes[$index] + ($bytes[$index + 1] << 8);

            if ($checksum > self::USHRT_MAX) {
                $checksum -= self::USHRT_MAX;
            }

            $index += 2;
            $length -= 2;
        }

        if ($length > 0) {
            $checksum += $bytes[count($bytes) - 1];
        }

        while ($checksum > self::USHRT_MAX) {
            $checksum -= self::USHRT_MAX;
        }

        $checksum = $checksum > 0 ? -$checksum : abs($checksum);
        $checksum--;

        while ($checksum < 0) {
            $checksum += self::USHRT_MAX;
        }

        return $checksum;
    }

    private function makeCommunicationKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $scrambled = 0;

        for ($i = 0; $i < 32; $i++) {
            $scrambled = ($scrambled << 1) | (($key >> $i) & 1);
        }

        $scrambled = ($scrambled + $sessionId) & 0xffffffff;
        $bytes = array_values(unpack('C*', pack('V', $scrambled)));
        $bytes = [
            $bytes[0] ^ ord('Z'),
            $bytes[1] ^ ord('K'),
            $bytes[2] ^ ord('S'),
            $bytes[3] ^ ord('O'),
        ];

        $words = array_values(unpack('v*', pack('C*', ...$bytes)));
        $bytes = array_values(unpack('C*', pack('vv', $words[1], $words[0])));
        $tick = $ticks & 0xff;

        return pack('C*', $bytes[0] ^ $tick, $bytes[1] ^ $tick, $tick, $bytes[3] ^ $tick);
    }

    private function extractPreparedDataLength(string $payload): ?int
    {
        if (strlen($payload) < 4) {
            return null;
        }

        $length = unpack('Vlength', substr($payload, 0, 4))['length'] ?? null;

        return $length ? (int) $length : null;
    }

    /**
     * @return array<int, FingerprintRawPunch>
     */
    private function parseAttendanceBytes(string $bytes, string $timezone): array
    {
        if ($bytes === '') {
            return [];
        }

        if (strlen($bytes) >= 4) {
            $length = unpack('Vlength', substr($bytes, 0, 4))['length'] ?? null;
            if ($length === strlen($bytes) - 4) {
                $bytes = substr($bytes, 4);
            }
        }

        $recordSize = strlen($bytes) % 40 === 0 ? 40 : 16;
        $records = [];
        $total = intdiv(strlen($bytes), $recordSize);

        for ($index = 0; $index < $total; $index++) {
            $record = substr($bytes, $index * $recordSize, $recordSize);
            $punch = $recordSize === 40
                ? $this->parseFortyByteRecord($record, $index, $timezone)
                : $this->parseSixteenByteRecord($record, $index, $timezone);

            if ($punch) {
                $records[] = $punch;
            }
        }

        return $records;
    }

    private function parseFortyByteRecord(string $record, int $index, string $timezone): ?FingerprintRawPunch
    {
        if (strlen($record) < 32) {
            return null;
        }

        $uid = unpack('vuid', substr($record, 0, 2))['uid'] ?? $index;
        $rawUserId = trim(substr($record, 2, 9), "\0\t\n\r ");
        $verifyMode = ord($record[26] ?? "\0");
        $timestamp = $this->decodeTimestamp(substr($record, 27, 4), $timezone);
        $punchState = ord($record[31] ?? "\0");

        if ($rawUserId === '') {
            $rawUserId = (string) $uid;
        }

        return new FingerprintRawPunch(
            rawDeviceUserId: $rawUserId,
            punchedAt: $timestamp,
            rawType: $this->mapPunchType($punchState),
            deviceRecordId: (string) $uid,
            punchState: $punchState,
            verifyMode: $verifyMode,
            payload: [
                'record_size' => 40,
                'record_index' => $index,
                'device_record_id' => $uid,
                'verify_mode' => $verifyMode,
                'punch_state' => $punchState,
                'raw_hex' => bin2hex($record),
            ],
        );
    }

    private function parseSixteenByteRecord(string $record, int $index, string $timezone): ?FingerprintRawPunch
    {
        if (strlen($record) < 14) {
            return null;
        }

        $uid = unpack('vuid', substr($record, 0, 2))['uid'] ?? $index;
        $rawUserId = (string) (unpack('Vuser_id', substr($record, 2, 4))['user_id'] ?? $uid);
        $timestamp = $this->decodeTimestamp(substr($record, 8, 4), $timezone);
        $verifyMode = ord($record[12] ?? "\0");
        $punchState = ord($record[13] ?? "\0");

        return new FingerprintRawPunch(
            rawDeviceUserId: $rawUserId,
            punchedAt: $timestamp,
            rawType: $this->mapPunchType($punchState),
            deviceRecordId: (string) $uid,
            punchState: $punchState,
            verifyMode: $verifyMode,
            payload: [
                'record_size' => 16,
                'record_index' => $index,
                'device_record_id' => $uid,
                'verify_mode' => $verifyMode,
                'punch_state' => $punchState,
                'raw_hex' => bin2hex($record),
            ],
        );
    }

    private function decodeTimestamp(string $bytes, string $timezone): CarbonImmutable
    {
        $encoded = unpack('Vtime', str_pad($bytes, 4, "\0"))['time'] ?? 0;
        $second = $encoded % 60;
        $encoded = intdiv($encoded, 60);
        $minute = $encoded % 60;
        $encoded = intdiv($encoded, 60);
        $hour = $encoded % 24;
        $encoded = intdiv($encoded, 24);
        $day = ($encoded % 31) + 1;
        $encoded = intdiv($encoded, 31);
        $month = ($encoded % 12) + 1;
        $year = intdiv($encoded, 12) + 2000;

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $timezone);
    }

    private function mapPunchType(int $punchState): RawAttendanceType
    {
        return in_array($punchState, [1, 2, 5], true)
            ? RawAttendanceType::CheckOut
            : RawAttendanceType::CheckIn;
    }
}
