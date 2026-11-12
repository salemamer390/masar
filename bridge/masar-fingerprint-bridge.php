<?php

/**
 * Masar local fingerprint bridge.
 *
 * Runs on a PC inside the company network. Pulls attendance punches from a
 * LAN fingerprint device (ZKTeco TCP protocol) and pushes them to the hosted
 * Masar server over its token-authenticated bridge API. The server decides
 * how punches are stored; this program never modifies attendance itself.
 *
 * Usage:
 *   php masar-fingerprint-bridge.php config.json           (loop forever)
 *   php masar-fingerprint-bridge.php config.json --once    (single pass)
 *
 * Requirements: PHP 8.0+ CLI with openssl extension. No other dependencies.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This program runs from the command line only.\n");
    exit(1);
}

const BRIDGE_VERSION = '1.0.0';
const CMD_CONNECT = 1000;
const CMD_EXIT = 1001;
const CMD_AUTH = 1102;
const CMD_ATTLOG_RRQ = 13;
const CMD_ACK_OK = 2000;
const CMD_ACK_UNAUTH = 2005;
const CMD_PREPARE_DATA = 1500;
const CMD_DATA = 1501;
const TCP_HEADER_1 = 0x5050;
const TCP_HEADER_2 = 0x7d82;
const USHRT_MAX = 65535;
const BATCH_SIZE = 200;

function bridge_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$message}\n");
}

function bridge_fail(string $message): void
{
    bridge_log("ERROR: {$message}");
    exit(1);
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$configPath = $argv[1] ?? null;
$runOnce = in_array('--once', $argv, true);

if (! $configPath || ! is_file($configPath)) {
    bridge_fail('Usage: php masar-fingerprint-bridge.php config.json [--once]');
}

$config = json_decode((string) file_get_contents($configPath), true);

if (! is_array($config)) {
    bridge_fail("config file is not valid JSON: {$configPath}");
}

$masarUrl = rtrim(trim((string) ($config['masar_url'] ?? '')), '/');
$deviceToken = trim((string) ($config['device_token'] ?? ''));
$deviceSerial = trim((string) ($config['device_serial'] ?? ''));
$deviceIp = trim((string) ($config['device_ip'] ?? ''));
$devicePort = (int) ($config['device_port'] ?? 4370);
$commKey = (int) ($config['device_comm_key'] ?? 0);
$interval = max(15, (int) ($config['interval_seconds'] ?? 60));
$stateFile = (string) ($config['state_file'] ?? (pathinfo($configPath, PATHINFO_DIRNAME) . '/bridge-state.json'));
$sinceDaysFirstRun = max(1, (int) ($config['since_days_first_run'] ?? 7));
$allowInsecure = (bool) ($config['allow_insecure_http'] ?? false);

foreach (['masar_url' => $masarUrl, 'device_token' => $deviceToken, 'device_serial' => $deviceSerial, 'device_ip' => $deviceIp] as $key => $value) {
    if ($value === '') {
        bridge_fail("missing required config key: {$key}");
    }
}

if (str_starts_with(strtolower($masarUrl), 'http://') && ! $allowInsecure) {
    bridge_fail('masar_url uses plain http. The device token would travel unencrypted. Use an https URL, or set "allow_insecure_http": true for local testing only.');
}

if (! str_starts_with(strtolower($masarUrl), 'https://') && ! str_starts_with(strtolower($masarUrl), 'http://')) {
    bridge_fail('masar_url must start with https://');
}

// ---------------------------------------------------------------------------
// Small ZKTeco TCP client (same wire protocol as the Masar server driver)
// ---------------------------------------------------------------------------

class ZkTcpClient
{
    private mixed $connection = null;
    private int $sessionId = 0;
    private int $replyId = USHRT_MAX - 1;

    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly int $commKey,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function connect(): void
    {
        $errno = 0;
        $error = '';
        $this->connection = @fsockopen($this->ip, $this->port, $errno, $error, $this->timeoutSeconds);

        if (! is_resource($this->connection)) {
            throw new RuntimeException("cannot reach device {$this->ip}:{$this->port} - {$error} ({$errno})");
        }

        stream_set_timeout($this->connection, $this->timeoutSeconds);

        $response = $this->sendCommand(CMD_CONNECT);
        $this->sessionId = $response['session_id'];

        if ($response['command'] === CMD_ACK_OK) {
            return;
        }

        if ($response['command'] !== CMD_ACK_UNAUTH) {
            throw new RuntimeException('device handshake failed with response code ' . $response['command']);
        }

        $auth = $this->sendCommand(CMD_AUTH, $this->makeCommunicationKey($this->commKey, $this->sessionId));

        if ($auth['command'] !== CMD_ACK_OK) {
            throw new RuntimeException('device authentication failed - check device_comm_key');
        }
    }

    /** @return array<int, array{user_id:string,timestamp:string,state:int,verify:int,record_id:string}> */
    public function pullAttendance(string $timezone): array
    {
        $response = $this->sendCommand(CMD_ATTLOG_RRQ);

        if ($response['command'] === CMD_DATA) {
            $bytes = $response['payload'];
        } else {
            if (! in_array($response['command'], [CMD_ACK_OK, CMD_PREPARE_DATA], true)) {
                throw new RuntimeException('unexpected attendance response: ' . $response['command']);
            }

            $expected = null;
            if (strlen($response['payload']) >= 4) {
                $length = unpack('Vlength', substr($response['payload'], 0, 4))['length'] ?? 0;
                $expected = $length ? (int) $length : null;
            }

            $bytes = '';
            while ($expected === null || strlen($bytes) < $expected) {
                $packet = $this->readPacket();
                if ($packet['command'] === CMD_DATA) {
                    $bytes .= $packet['payload'];
                }
                if ($expected === null && $bytes !== '') {
                    break;
                }
            }

            if ($expected !== null) {
                $bytes = substr($bytes, 0, $expected);
            }
        }

        return $this->parseAttendance($bytes, $timezone);
    }

    public function disconnect(): void
    {
        if (is_resource($this->connection)) {
            try {
                $this->sendCommand(CMD_EXIT);
            } catch (Throwable) {
            }
            fclose($this->connection);
        }
        $this->connection = null;
        $this->sessionId = 0;
        $this->replyId = USHRT_MAX - 1;
    }

    private function parseAttendance(string $bytes, string $timezone): array
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

        for ($i = 0; $i < $total; $i++) {
            $record = substr($bytes, $i * $recordSize, $recordSize);

            if ($recordSize === 40) {
                if (strlen($record) < 32) {
                    continue;
                }
                $uid = unpack('vuid', substr($record, 0, 2))['uid'] ?? $i;
                $userId = trim(substr($record, 2, 9), "\0\t\n\r ");
                $verify = ord($record[26]);
                $encoded = unpack('Vtime', substr($record, 27, 4))['time'] ?? 0;
                $state = ord($record[31]);
                if ($userId === '') {
                    $userId = (string) $uid;
                }
            } else {
                if (strlen($record) < 14) {
                    continue;
                }
                $uid = unpack('vuid', substr($record, 0, 2))['uid'] ?? $i;
                $userId = (string) (unpack('Vuser_id', substr($record, 2, 4))['user_id'] ?? $uid);
                $encoded = unpack('Vtime', substr($record, 8, 4))['time'] ?? 0;
                $verify = ord($record[12]);
                $state = ord($record[13]);
            }

            $records[] = [
                'user_id' => $userId,
                'timestamp' => $this->decodeTimestamp($encoded, $timezone),
                'state' => $state,
                'verify' => $verify,
                'record_id' => (string) $uid,
            ];
        }

        return $records;
    }

    private function decodeTimestamp(int $encoded, string $timezone): string
    {
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

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new DateTimeZone($timezone)
        );

        return $dt->format('Y-m-d H:i:s');
    }

    private function sendCommand(int $command, string $payload = ''): array
    {
        if (! is_resource($this->connection)) {
            throw new RuntimeException('device socket is not connected');
        }

        $replyId = $this->replyId + 1;
        if ($replyId >= USHRT_MAX) {
            $replyId -= USHRT_MAX;
        }
        $this->replyId = $replyId;

        $checksumBody = pack('vvvv', $command, 0, $this->sessionId, $this->replyId) . $payload;
        $checksum = $this->checksum($checksumBody);
        $body = pack('vvvv', $command, $checksum, $this->sessionId, $replyId) . $payload;
        $packet = pack('vvV', TCP_HEADER_1, TCP_HEADER_2, strlen($body)) . $body;

        $written = fwrite($this->connection, $packet);
        if ($written === false || $written < strlen($packet)) {
            throw new RuntimeException('failed to write command packet');
        }

        $response = $this->readPacket();
        $this->replyId = $response['reply_id'];

        return $response;
    }

    private function readPacket(): array
    {
        $prefix = $this->readBytes(8);
        $prefixData = unpack('vheader1/vheader2/Vlength', $prefix);
        $body = ($prefixData['header1'] ?? null) === TCP_HEADER_1
            ? $this->readBytes((int) $prefixData['length'])
            : $prefix;

        if (strlen($body) < 8) {
            throw new RuntimeException('incomplete response packet');
        }

        $header = unpack('vcommand/vchecksum/vsession_id/vreply_id', substr($body, 0, 8));

        return [
            'command' => (int) $header['command'],
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
                throw new RuntimeException('device connection closed or timed out');
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
            if ($checksum > USHRT_MAX) {
                $checksum -= USHRT_MAX;
            }
            $index += 2;
            $length -= 2;
        }

        if ($length > 0) {
            $checksum += $bytes[count($bytes) - 1];
        }

        while ($checksum > USHRT_MAX) {
            $checksum -= USHRT_MAX;
        }

        $checksum = $checksum > 0 ? -$checksum : abs($checksum);
        $checksum--;

        while ($checksum < 0) {
            $checksum += USHRT_MAX;
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
        $bytes = [$bytes[0] ^ ord('Z'), $bytes[1] ^ ord('K'), $bytes[2] ^ ord('S'), $bytes[3] ^ ord('O')];
        $words = array_values(unpack('v*', pack('C*', ...$bytes)));
        $bytes = array_values(unpack('C*', pack('vv', $words[1], $words[0])));
        $tick = $ticks & 0xff;

        return pack('C*', $bytes[0] ^ $tick, $bytes[1] ^ $tick, $tick, $bytes[3] ^ $tick);
    }
}

// ---------------------------------------------------------------------------
// Masar API client
// ---------------------------------------------------------------------------

function masar_request(string $method, string $url, string $token, string $serial, ?array $payload): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'X-Device-Serial: ' . $serial,
        'Accept: application/json',
        'User-Agent: masar-fingerprint-bridge/' . BRIDGE_VERSION,
    ];

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload);
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;

    $decoded = json_decode((string) $result, true);

    return [$status, is_array($decoded) ? $decoded : ['raw' => substr((string) $result, 0, 500)]];
}

// ---------------------------------------------------------------------------
// State (last sent cursor) - server-side dedupe is the real guard; the cursor
// only saves bandwidth.
// ---------------------------------------------------------------------------

function load_state(string $stateFile): array
{
    if (is_file($stateFile)) {
        $state = json_decode((string) file_get_contents($stateFile), true);
        if (is_array($state)) {
            return $state;
        }
    }

    return ['last_timestamp' => null, 'recent_hashes' => []];
}

function save_state(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

bridge_log('masar fingerprint bridge ' . BRIDGE_VERSION . " starting (device {$deviceSerial} @ {$deviceIp}:{$devicePort})");

// Verify the token before touching the device.
[$status, $hello] = masar_request('POST', $masarUrl . '/api/fingerprint-bridge/handshake', $deviceToken, $deviceSerial, ['device_serial' => $deviceSerial]);
if ($status !== 200 || ! ($hello['ok'] ?? false)) {
    bridge_fail('handshake with Masar failed (HTTP ' . $status . '): ' . json_encode($hello));
}
bridge_log('connected to Masar: device "' . ($hello['device']['name'] ?? $deviceSerial) . '"');

$timezone = (string) ($hello['device']['timezone'] ?? date_default_timezone_get());

do {
    try {
        $client = new ZkTcpClient($deviceIp, $devicePort, $commKey);
        $client->connect();
        $records = $client->pullAttendance($timezone);
        $client->disconnect();

        $state = load_state($stateFile);
        $cursor = $state['last_timestamp'] ?? date('Y-m-d H:i:s', time() - $sinceDaysFirstRun * 86400);
        $recent = array_flip((array) ($state['recent_hashes'] ?? []));

        $fresh = [];
        foreach ($records as $record) {
            $hash = hash('sha256', implode('|', [$record['record_id'], $record['user_id'], $record['timestamp'], $record['state']]));
            if (isset($recent[$hash])) {
                continue;
            }
            if ($record['timestamp'] < $cursor && ! isset($recent[$hash])) {
                // Older than the cursor and not recently sent: already uploaded.
                continue;
            }
            $record['hash'] = $hash;
            $fresh[] = $record;
        }

        if ($fresh === []) {
            bridge_log('no new punches (' . count($records) . ' on device)');
        } else {
            bridge_log(count($fresh) . ' new punch(es) to send (' . count($records) . ' on device)');

            foreach (array_chunk($fresh, BATCH_SIZE) as $chunk) {
                $payload = [
                    'device_serial' => $deviceSerial,
                    'punches' => array_map(fn (array $r) => [
                        'user_id' => $r['user_id'],
                        'timestamp' => $r['timestamp'],
                        'state' => $r['state'],
                        'verify_mode' => $r['verify'],
                        'record_id' => $r['record_id'],
                    ], $chunk),
                ];

                [$status, $response] = masar_request('POST', $masarUrl . '/api/fingerprint-bridge/punches', $deviceToken, $deviceSerial, $payload);

                if ($status !== 200 || ! ($response['ok'] ?? false)) {
                    bridge_log('ERROR: upload rejected (HTTP ' . $status . '): ' . json_encode($response) . ' - will retry next cycle');
                    continue 2; // keep state untouched; retry everything next cycle
                }

                bridge_log('uploaded: imported=' . ($response['imported'] ?? '?') . ' duplicates=' . ($response['duplicates'] ?? '?') . ' unresolved=' . ($response['unresolved'] ?? '?'));

                foreach ($chunk as $record) {
                    $recent[$record['hash']] = true;
                    if ($record['timestamp'] > ($state['last_timestamp'] ?? '')) {
                        $state['last_timestamp'] = $record['timestamp'];
                    }
                }
            }

            // Keep the local hash window bounded (server dedupe is authoritative).
            $state['recent_hashes'] = array_slice(array_keys($recent), -5000);
            save_state($stateFile, $state);
        }
    } catch (Throwable $e) {
        bridge_log('ERROR: ' . $e->getMessage());
    }

    if (! $runOnce) {
        sleep($interval);
    }
} while (! $runOnce);

bridge_log('done');
