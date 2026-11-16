#!/usr/bin/env php
<?php
/*
 * eacapture.php - Linux command-line version of EACapture.
 *
 * Finds a suitable ATSC 3.0 HDHomeRun tuner, checks in with the server every
 * 8 hours to get a list of capture operations, records each one at its
 * CaptureTime, and uploads the recording UploadDelay seconds later.
 *
 * Requires PHP 8.1+ with the curl and sockets extensions
 * (Debian/Ubuntu: apt install php-cli php-curl).
 *
 * Usage:
 *   php eacapture.php [--data-dir=DIR] [--device-ip=IP]
 *
 * Data goes in --data-dir, by default ~/eacapture.
 */

declare(strict_types=1);

const APP_VERSION = '1.0.0.0';
const SERVER_URL = 'https://eastest.silicondust.com/api/register';
const CHECK_IN_INTERVAL_SECONDS = 8 * 3600;
const RETRY_SECONDS = 600;
const PUSH_INTERVAL_SECONDS = 60;
const MINIMUM_FIRMWARE_VERSION = '20261107';
const DISCOVER_PORT = 65001;

main();

function main(): void
{
    $options = getopt('', ['data-dir:', 'device-ip:', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php eacapture.php [--data-dir=DIR] [--device-ip=IP]\n";
        exit(0);
    }

    // 8.1 is needed for array_is_list() and the CurlHandle types. Versions
    // older than 7.4 can't parse this file, so they never get this far.
    if (PHP_VERSION_ID < 80100) {
        fwrite(STDERR, 'EACapture needs PHP 8.1 or newer (this is ' . PHP_VERSION . ").\n");
        exit(1);
    }

    foreach (['curl', 'sockets'] as $extension) {
        if (!extension_loaded($extension)) {
            fwrite(STDERR, "The PHP '$extension' extension is required.\n");
            exit(1);
        }
    }

    useSystemTimezone();

    $dataDir = (string)($options['data-dir'] ?? defaultDataDir());

    if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true)) {
        fwrite(STDERR, "Could not create data folder $dataDir\n");
        exit(1);
    }

    $lock = fopen("$dataDir/eacapture.lock", 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "EACapture is already running.\n");
        exit(1);
    }

    $deviceIpOpt = $options['device-ip'] ?? getenv('HDHR_IP') ?: '';
    $deviceIp = is_array($deviceIpOpt) ? implode(',', $deviceIpOpt) : (string)$deviceIpOpt;

    $app = new EACapture($dataDir, $deviceIp !== '' ? $deviceIp : null);
    $app->run();
}

class EACapture
{
    private string $stateFile;
    private string $capturesDir;
    private array $config;
    private ?string $deviceIp;

    // Until the first successful check-in, nothing but check-ins runs.
    private bool $registered = false;
    private float $nextCheckInAt = 0;

    private ?array $device = null;
    private array $deviceErrors = [];
    private bool $deviceLineupUpdated = false;

    // Job key => whether that job's CaptureTime had passed at the last tick.
    private array $jobStates = [];
    private ?int $lastLoggedNextCapture = null;
    private float $nextPushAt = 0;

    private CurlMultiHandle $multi;
    private array $transfers = [];

    public function __construct(string $dataDir, ?string $deviceIp = null)
    {
        $this->stateFile = "$dataDir/checkin_state.json";
        $this->capturesDir = "$dataDir/Captures";
        $this->config = defaultConfig();
        $this->multi = curl_multi_init();
        $this->deviceIp = $deviceIp !== null && trim($deviceIp) !== '' ? trim($deviceIp) : null;
    }

    public function run(): void
    {
        $this->log($this->loadState() ? 'Loaded configuration' : 'First launch');

        // Nothing is recording yet, so every .tmp without a sidecar is a
        // dead partial capture.
        $deleted = $this->deleteLeftoverTempFiles();
        if ($deleted > 0) {
            $this->log("Deleted $deleted leftover partial capture file(s)");
        }

        while (true) {
            if (microtime(true) >= $this->nextCheckInAt && !$this->isRecording()) {
                $this->runCheckIn();
            }

            if ($this->registered) {
                $this->checkCaptureJobs();

                if (microtime(true) >= $this->nextPushAt) {
                    $this->runPush();
                }
            }

            $this->pumpTransfers(1.0);
        }
    }

    private function runCheckIn(): void
    {
        $this->runDiscovery();
        $result = $this->sendCheckIn();

        if (isset($result['config'])) {
            $config = $result['config'];
            $this->log('Check-in succeeded.');
            $this->log('    Clock delta: ' . formatDelta($config['DeltaUtcSeconds']));
            if (($config['UserMessage'] ?? '') !== '') {
                $this->log('    Message from server: ' . $config['UserMessage']);
            }

            if (!$this->registered) {
                $this->registered = true;
                $this->nextPushAt = 0;
            }

            $this->nextCheckInAt = microtime(true) + CHECK_IN_INTERVAL_SECONDS;
            return;
        }

        if (isset($result['decodeError'])) {
            $this->log('Check-in succeeded.');
            $this->log('    Could not decode response: ' . $result['decodeError']);
        } else {
            $this->log('Check-in failed: ' . $result['error']);
        }

        $this->log($this->registered ? 'Will retry check-in in 10 minutes.' : 'Will retry in 10 minutes.');
        $this->nextCheckInAt = microtime(true) + RETRY_SECONDS;
    }

    /**
     * Returns ['config' => ...] on success, ['decodeError' => ...] if the
     * server replied but the reply couldn't be decoded, or ['error' => ...].
     */
    private function sendCheckIn(): array
    {
        if ($this->device === null) {
            return ['error' => 'no suitable device was found - make sure your tuner(s) are connected to the internet.'];
        }

        if ($this->deviceErrors) {
            return ['error' => "HDHomeRun {$this->device['DeviceID']} failed " . count($this->deviceErrors) . ' check(s) - see the errors above.'];
        }

        $body = json_encode([
            'HostID' => $this->config['HostID'] ?? '',
            'AppVersion' => APP_VERSION,
            'Lineup' => $this->device['Lineup'],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $separator = str_contains(SERVER_URL, '?') ? '&' : '?';
        $url = SERVER_URL . $separator . 'DeviceAuth=' . rawurlencode($this->device['DeviceAuth']);

        [$status, $response, $error] = httpRequest($url, 30, $body);
        if ($error !== null) {
            return ['error' => $error];
        }
        if ($status < 200 || $status >= 300) {
            return ['error' => "Server returned $status"];
        }

        try {
            $config = decodeCheckInResponse($response);
        } catch (RuntimeException $e) {
            return ['decodeError' => $e->getMessage()];
        }

        $pcUtc = microtime(true);
        $config['PCUtc'] = $pcUtc;
        $config['DeltaUtcSeconds'] = $config['CurrentTime'] - $pcUtc;
        $config['DeviceID'] = $this->device['DeviceID'];
        $config['LineupUpdated'] = $this->deviceLineupUpdated ? 1 : 0;

        $this->saveState($config);
        $this->config = $config;

        return ['config' => $config];
    }

    private function runDiscovery(): void
    {
        if ($this->deviceIp !== null) {
            $devices = [];
            $targets = array_filter(array_map('trim', explode(',', $this->deviceIp)));
            foreach ($targets as $target) {
                $this->log("Querying HDHomeRun at $target...");
                $device = discoverDeviceByIp($target);
                if ($device !== null) {
                    $devices[] = $device;
                } else {
                    $this->log("Could not find or contact HDHomeRun at $target");
                }
            }
        } else {
            try {
                $devices = discoverDevices();
            } catch (RuntimeException $e) {
                // Keep the previously chosen device.
                $this->log('Device discovery failed: ' . $e->getMessage());
                return;
            }
        }

        $chosen = $devices ? $this->chooseDevice($devices) : null;

        $this->device = $chosen['device'] ?? null;
        $this->deviceErrors = $chosen['errors'] ?? [];
        $this->deviceLineupUpdated = $chosen['lineupUpdated'] ?? false;

        if (!$devices) {
            $this->log($this->deviceIp !== null ? 'No devices responded at specified IP(s).' : 'Device discovery found no devices.');
            return;
        }

        if ($chosen === null) {
            $this->log('No suitable device found among the discovered devices.');
            return;
        }

        $this->log('Chosen: ' . $this->device['DeviceID']);
        foreach ($this->deviceErrors as $error) {
            $this->log($error);
        }
    }

    private function chooseDevice(array $devices): ?array
    {
        $preferredId = (string)($this->config['DeviceID'] ?? '');
        $isPreferred = fn(array $device) => $preferredId !== '' && strcasecmp((string)$device['DeviceID'], $preferredId) === 0;

        $chosen = null;
        $chosenInfo = null;

        foreach ($devices as $device) {
            $info = fetchDiscoverJson($device);
            if ($info === null || ($info['LineupURL'] ?? '') === '') {
                continue;
            }

            $this->log("Found: {$info['FriendlyName']} {$device['DeviceID']}");

            if (!str_contains($info['FirmwareName'] ?? '', 'atsc3')) {
                continue;
            }

            // Take the first qualifying device, but let the one used for the
            // last successful check-in replace it if it turns up later.
            if ($chosen === null || $isPreferred($device)) {
                $chosen = $device;
                $chosenInfo = $info;
            }
        }

        if ($chosen === null) {
            return null;
        }

        $errors = [];

        if (empty($chosen['DeviceAuth']) && !empty($chosenInfo['DeviceAuth'])) {
            $chosen['DeviceAuth'] = $chosenInfo['DeviceAuth'];
        }

        if (($chosen['DeviceAuth'] ?? '') === '') {
            $errors[] = "ERROR: HDHomeRun {$chosen['DeviceID']} requires internet access";
        }

        if ($chosenInfo['FirmwareVersion'] === null || strcmp($chosenInfo['FirmwareVersion'], MINIMUM_FIRMWARE_VERSION) < 0) {
            $errors[] = "ERROR: HDHomeRun {$chosen['DeviceID']} not running latest firmware - please run HDHomeRun app to upgrade the firmware";
        }

        $chosen['Lineup'] = fetchLineup($chosenInfo['LineupURL']);
        [$hasAtsc3Channel, $hasSignalStrength] = findAtsc3Channel($chosen['Lineup']);
        if (!$hasAtsc3Channel) {
            $errors[] = 'ERROR: No ATSC 3.0 channels detected';
        }

        // Accept the lineup if it has SignalStrength now, or if an earlier
        // check-in with this same device already recorded that it did.
        $lineupUpdated = $hasSignalStrength || (($this->config['LineupUpdated'] ?? 0) == 1 && $isPreferred($chosen));
        if (!$lineupUpdated) {
            $errors[] = 'ERROR: Please run a new channel scan in the HDHomeRun app: Settings menu, Channels';
        }

        return ['device' => $chosen, 'errors' => $errors, 'lineupUpdated' => $lineupUpdated];
    }

    private function checkCaptureJobs(): void
    {
        // CaptureTime is on the server's clock.
        $delta = (float)($this->config['DeltaUtcSeconds'] ?? 0);
        $now = microtime(true) + $delta;

        $seenKeys = [];
        $nextCaptureTime = null;

        foreach ($this->config['Operations'] as $job) {
            $key = $job['CaptureTune'] . '|' . $job['CaptureTime'] . '|' . $job['UploadPut'];
            $seenKeys[$key] = true;

            if ($job['CaptureTime'] > $now && ($nextCaptureTime === null || $job['CaptureTime'] < $nextCaptureTime)) {
                $nextCaptureTime = $job['CaptureTime'];
            }

            $started = $now >= $job['CaptureTime'];

            if (!array_key_exists($key, $this->jobStates)) {
                // A job already underway when first seen is deliberately not
                // started - the recording would be missing its beginning.
                if ($started) {
                    $this->log("Capture already in progress: {$job['CaptureTune']} (started " . gmdate('Y-m-d H:i:s\Z', $job['CaptureTime']) . ", upload as {$job['UploadPut']})");
                }
            } elseif ($started && !$this->jobStates[$key]) {
                $this->startCapture($key, $job);
            }

            $this->jobStates[$key] = $started;
        }

        $this->jobStates = array_intersect_key($this->jobStates, $seenKeys);

        // Hold "Next capture" back until the current capture has recorded and
        // uploaded, so it doesn't land in the middle of that capture's lines.
        $busy = $this->isRecording() || $this->hasPendingUploads();
        if (!$busy && $nextCaptureTime !== $this->lastLoggedNextCapture) {
            $this->lastLoggedNextCapture = $nextCaptureTime;
            if ($nextCaptureTime !== null) {
                $this->log('Next capture to run at ' . formatLocalTime($nextCaptureTime - $delta));
            }
        }
    }

    private function startCapture(string $key, array $job): void
    {
        foreach ($this->transfers as $transfer) {
            if ($transfer['kind'] === 'capture' && $transfer['key'] === $key) {
                return;
            }
        }

        // A new capture is due: stop retrying earlier failed uploads.
        $this->abandonFailedUploads();

        @mkdir($this->capturesDir, 0777, true);
        $path = $this->capturesDir . '/' . bin2hex(random_bytes(16)) . '.tmp';
        $file = fopen($path, 'wb');
        if ($file === false) {
            $this->log("Capture failed: {$job['CaptureTune']} (could not create $path)");
            return;
        }

        $this->log("Capturing {$job['CaptureTune']} to " . basename($path));

        // No overall timeout: the capture ends when the tuner closes the
        // connection.
        $curl = curl_init($job['CaptureTune']);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $file,
            CURLOPT_FAILONERROR => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $this->addTransfer($curl, ['kind' => 'capture', 'key' => $key, 'job' => $job, 'path' => $path, 'file' => $file]);
    }

    private function finishCapture(array $transfer, ?string $error): void
    {
        $job = $transfer['job'];
        $path = $transfer['path'];

        if ($error !== null) {
            $this->log("Capture failed: {$job['CaptureTune']} ($error). Partial data (if any) left at: $path");
            return;
        }

        clearstatcache(true, $path);
        if (filesize($path) === 0) {
            @unlink($path);
            $this->log("Capture for {$job['CaptureTune']} produced no data - nothing to save.");
            return;
        }

        // The sidecar (same name, .json) marks the capture as finished and
        // says where and when to upload it.
        $sidecar = [
            'CaptureTune' => $job['CaptureTune'],
            'CaptureTime' => $job['CaptureTime'],
            'UploadPut' => $job['UploadPut'],
            'UploadDelay' => $job['UploadDelay'],
            'CompletedPCUtc' => time(),
            'UploadFailures' => 0,
        ];

        if (!writeJsonFile(sidecarPathFor($path), $sidecar)) {
            $this->log("Could not write sidecar for $path");
            return;
        }

        $this->log('Upload to run at ' . formatLocalTime($sidecar['CompletedPCUtc'] + $sidecar['UploadDelay']));
    }

    private function isRecording(): bool
    {
        foreach ($this->transfers as $transfer) {
            if ($transfer['kind'] === 'capture') {
                return true;
            }
        }
        return false;
    }

    private function deleteLeftoverTempFiles(): int
    {
        $deleted = 0;
        foreach (glob($this->capturesDir . '/*.tmp') ?: [] as $path) {
            if (!file_exists(sidecarPathFor($path)) && @unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function runPush(): void
    {
        $this->nextPushAt = microtime(true) + PUSH_INTERVAL_SECONDS;

        if ($this->currentUpload() !== null) {
            return;
        }

        foreach ($this->sidecarFiles() as $sidecarPath) {
            if ($this->tryStartUpload($sidecarPath)) {
                return;
            }
        }
    }

    private function tryStartUpload(string $sidecarPath): bool
    {
        $name = basename($sidecarPath);

        $json = @file_get_contents($sidecarPath);
        if ($json === false) {
            $this->log("Could not read sidecar $name. Will retry.");
            return false;
        }

        $sidecar = json_decode($json, true);
        if (!is_array($sidecar)) {
            $this->log("Sidecar $name is not valid JSON. Leaving it in place.");
            return false;
        }

        $url = (string)($sidecar['UploadPut'] ?? '');
        if ($url === '') {
            $this->log("Sidecar $name is missing UploadPut - leaving it in place.");
            return false;
        }
        if (!isAbsoluteUrl($url)) {
            $this->log("Sidecar $name has an invalid UploadPut ('$url') - not a valid absolute URL. Leaving it in place.");
            return false;
        }

        if (time() < (int)$sidecar['CompletedPCUtc'] + (int)$sidecar['UploadDelay']) {
            return false;
        }

        $dataPath = dataPathFor($sidecarPath);
        if (!file_exists($dataPath)) {
            if (@unlink($sidecarPath)) {
                $this->log("Deleted orphaned sidecar $name - no matching capture data (" . basename($dataPath) . ') was found.');
            } else {
                $this->log("No capture data found for sidecar $name (expected " . basename($dataPath) . "), and couldn't remove the sidecar either. Will retry.");
            }
            return false;
        }

        $file = fopen($dataPath, 'rb');
        if ($file === false) {
            $this->log("Could not open $dataPath. Will retry.");
            return false;
        }

        $this->log('Uploading ' . basename($dataPath));

        // CURLOPT_UPLOAD over HTTP is a PUT. No overall timeout - captures
        // can be large.
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $file,
            CURLOPT_INFILESIZE => filesize($dataPath),
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $this->addTransfer($curl, [
            'kind' => 'upload',
            'sidecarPath' => $sidecarPath,
            'dataPath' => $dataPath,
            'sidecar' => $sidecar,
            'file' => $file,
        ]);

        return true;
    }

    private function finishUpload(array $transfer, int $status, ?string $error): void
    {
        $sidecar = $transfer['sidecar'];
        $url = $sidecar['UploadPut'];

        if ($error !== null || $status < 200 || $status >= 300) {
            $this->log("Push failed for $url: " . ($error ?? "server returned $status") . '. Will retry.');

            // Re-apply UploadDelay as a cooldown before the next attempt.
            $sidecar['CompletedPCUtc'] = time();
            $sidecar['UploadFailures'] = (int)($sidecar['UploadFailures'] ?? 0) + 1;
            writeJsonFile($transfer['sidecarPath'], $sidecar);
            return;
        }

        if (!@unlink($transfer['dataPath']) || !@unlink($transfer['sidecarPath'])) {
            $this->log("Pushed $url but couldn't remove the local copy.");
        }

        $this->nextPushAt = 0;
    }

    private function abandonFailedUploads(): void
    {
        $uploading = $this->currentUpload()['sidecarPath'] ?? null;

        foreach ($this->sidecarFiles() as $sidecarPath) {
            // Linux would happily delete the file being uploaded, so skip it.
            if ($sidecarPath === $uploading) {
                continue;
            }

            $sidecar = readJsonFile($sidecarPath);
            $failures = (int)($sidecar['UploadFailures'] ?? 0);
            if ($failures === 0) {
                continue;
            }

            $dataPath = dataPathFor($sidecarPath);
            @unlink($dataPath);
            if (@unlink($sidecarPath)) {
                $this->log("Upload abandoned after $failures failed attempt(s): " . basename($dataPath));
            }
        }
    }

    private function hasPendingUploads(): bool
    {
        foreach ($this->sidecarFiles() as $sidecarPath) {
            $sidecar = readJsonFile($sidecarPath);
            if ($sidecar !== null && isAbsoluteUrl((string)($sidecar['UploadPut'] ?? '')) && file_exists(dataPathFor($sidecarPath))) {
                return true;
            }
        }
        return false;
    }

    private function currentUpload(): ?array
    {
        foreach ($this->transfers as $transfer) {
            if ($transfer['kind'] === 'upload') {
                return $transfer;
            }
        }
        return null;
    }

    private function sidecarFiles(): array
    {
        return glob($this->capturesDir . '/*.json') ?: [];
    }

    private function addTransfer(CurlHandle $curl, array $transfer): void
    {
        $transfer['curl'] = $curl;
        $this->transfers[spl_object_id($curl)] = $transfer;
        curl_multi_add_handle($this->multi, $curl);
    }

    /**
     * Moves captures/uploads along for the given number of seconds. Also
     * serves as the main loop's 1-second sleep.
     */
    private function pumpTransfers(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (($remaining = $deadline - microtime(true)) > 0) {
            if (!$this->transfers) {
                usleep((int)($remaining * 1000000));
                return;
            }

            curl_multi_exec($this->multi, $running);

            while ($info = curl_multi_info_read($this->multi)) {
                $this->finishTransfer($info['handle'], $info['result']);
            }

            if ($this->transfers && curl_multi_select($this->multi, $remaining) === -1) {
                usleep(10000);
            }
        }
    }

    private function finishTransfer(CurlHandle $curl, int $result): void
    {
        $id = spl_object_id($curl);
        $transfer = $this->transfers[$id];
        unset($this->transfers[$id]);

        $error = $result === CURLE_OK ? null : (curl_error($curl) ?: curl_strerror($result));
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_multi_remove_handle($this->multi, $curl);
        fclose($transfer['file']);

        if ($transfer['kind'] === 'capture') {
            $this->finishCapture($transfer, $error);
        } else {
            $this->finishUpload($transfer, $status, $error);
        }
    }

    private function loadState(): bool
    {
        $saved = readJsonFile($this->stateFile);
        if ($saved === null || !is_array($saved['Operations'] ?? null)) {
            return false;
        }

        $this->config = $saved + defaultConfig();
        return true;
    }

    private function saveState(array $config): void
    {
        @mkdir(dirname($this->stateFile), 0777, true);
        if (!writeJsonFile($this->stateFile, $config)) {
            $this->log("Could not save check-in state to {$this->stateFile}");
        }
    }

    private function log(string $message): void
    {
        echo date('Y-m-d H:i:s') . '  ' . $message . PHP_EOL;
    }
}

/**
 * Times are Unix seconds (UTC). DeltaUtcSeconds is server time minus this
 * machine's time, measured at the last live check-in.
 */
function defaultConfig(): array
{
    return [
        'CurrentTime' => time(),
        'HostID' => null,
        'UserMessage' => null,
        'PCUtc' => null,
        'DeltaUtcSeconds' => null,
        'DeviceID' => null,
        'LineupUpdated' => 0,
        'Operations' => [],
    ];
}

function decodeCheckInResponse(string $json): array
{
    if (trim($json) === '') {
        throw new RuntimeException('Response JSON was empty.');
    }

    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        throw new RuntimeException('Response was not valid JSON, or didn\'t match the expected shape.');
    }

    if (!is_array($raw['Operations'] ?? null)) {
        throw new RuntimeException("Missing required field 'Operations'.");
    }

    $operations = [];
    foreach ($raw['Operations'] as $op) {
        if (!is_array($op) || ($op['CaptureTune'] ?? '') === '') {
            throw new RuntimeException("Missing required field 'CaptureTune' in an Operations entry.");
        }
        if (($op['UploadPut'] ?? '') === '') {
            throw new RuntimeException("Missing required field 'UploadPut' in an Operations entry.");
        }

        $operations[] = [
            'CaptureTune' => (string)$op['CaptureTune'],
            'CaptureTime' => (int)($op['CaptureTime'] ?? 0),
            'UploadPut' => (string)$op['UploadPut'],
            'UploadDelay' => (int)($op['UploadDelay'] ?? 0),
        ];
    }

    usort($operations, fn($a, $b) => $a['CaptureTime'] <=> $b['CaptureTime']);

    return [
        'CurrentTime' => (int)($raw['CurrentTime'] ?? 0),
        'HostID' => $raw['HostID'] ?? null,
        'UserMessage' => $raw['UserMessage'] ?? null,
        'Operations' => $operations,
    ] + defaultConfig();
}

function discoverDevices(): array
{
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) {
        throw new RuntimeException('failed to open socket: ' . socket_strerror(socket_last_error()));
    }

    socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
    if (!socket_bind($socket, '0.0.0.0', 0)) {
        throw new RuntimeException('failed to bind socket: ' . socket_strerror(socket_last_error($socket)));
    }

    $request = discoverRequestPacket();
    $addresses = broadcastAddresses();
    $devices = [];

    for ($send = 0; $send < 2; $send++) {
        foreach ($addresses as $address) {
            @socket_sendto($socket, $request, strlen($request), 0, $address, DISCOVER_PORT);
        }

        $deadline = microtime(true) + 0.25;
        while (($remaining = $deadline - microtime(true)) > 0) {
            $read = [$socket];
            $write = $except = null;
            if (!@socket_select($read, $write, $except, 0, (int)($remaining * 1000000))) {
                break;
            }

            $packet = '';
            $ip = '';
            $port = 0;
            if (@socket_recvfrom($socket, $packet, 2048, 0, $ip, $port) === false) {
                continue;
            }

            $device = parseDiscoverReply($packet, $ip);
            if ($device !== null && !isset($devices[$device['Key']])) {
                $devices[$device['Key']] = $device;
            }
        }
    }

    socket_close($socket);
    return array_values($devices);
}

/**
 * 255.255.255.255 only goes out of one interface, so each active IPv4
 * interface's subnet broadcast address is added to reach every network.
 */
function broadcastAddresses(): array
{
    $addresses = ['255.255.255.255'];

    foreach (net_get_interfaces() ?: [] as $interface) {
        if (empty($interface['up'])) {
            continue;
        }

        foreach ($interface['unicast'] ?? [] as $unicast) {
            if (($unicast['family'] ?? null) !== AF_INET || empty($unicast['address']) || empty($unicast['netmask'])) {
                continue;
            }

            $ip = ip2long($unicast['address']);
            $mask = ip2long($unicast['netmask']);
            if ($ip === false || $mask === false || $mask === 0 || $mask === 0xFFFFFFFF || ($ip >> 24) === 127) {
                continue;
            }

            $broadcast = long2ip(($ip | ~$mask) & 0xFFFFFFFF);
            if (!in_array($broadcast, $addresses, true)) {
                $addresses[] = $broadcast;
            }
        }
    }

    return $addresses;
}

function discoverRequestPacket(): string
{
    // type 0x0002 (discover request), length 6, tag 0x26 (device type),
    // length 4, value 0x00000001 (tuner), then CRC (little-endian).
    $body = "\x00\x02\x00\x06\x26\x04\x00\x00\x00\x01";
    return $body . pack('V', hdhomerunCrc($body));
}

/**
 * Layout: type (2, BE), length (2, BE), TLV fields, CRC (4, LE).
 * Returns null if the packet is invalid.
 */
function parseDiscoverReply(string $msg, string $remoteIp): ?array
{
    $packetLength = strlen($msg);
    if ($packetLength < 8) {
        return null;
    }

    $dataLength = $packetLength - 4;
    if (hdhomerunCrc(substr($msg, 0, $dataLength)) !== unpack('V', $msg, $dataLength)[1]) {
        return null;
    }

    ['type' => $type, 'length' => $length] = unpack('ntype/nlength', $msg);
    if ($type !== 3 || 4 + $length !== $dataLength) {
        return null;
    }

    $device = [
        'Key' => null,
        'DeviceID' => null,
        'LineupURL' => null,
        'StorageURL' => null,
        'BaseURL' => null,
        'DeviceAuth' => null,
        'StorageID' => null,
        'Lineup' => null,
    ];

    $textTags = [0x27 => 'LineupURL', 0x28 => 'StorageURL', 0x2a => 'BaseURL', 0x2b => 'DeviceAuth', 0x2c => 'StorageID'];

    $pos = 4;
    while ($pos + 2 <= $dataLength) {
        $tag = ord($msg[$pos]);
        $tagLength = ord($msg[$pos + 1]);
        $pos += 2;

        if ($pos + $tagLength > $dataLength) {
            return null;
        }

        if ($tag === 0x02 && $tagLength >= 4) {
            $deviceId = unpack('N', $msg, $pos)[1];
            if (!isDeviceIdValid($deviceId)) {
                return null;
            }
            $device['DeviceID'] = sprintf('%08X', $deviceId);
        } elseif (isset($textTags[$tag]) && $tagLength > 0) {
            $device[$textTags[$tag]] = substr($msg, $pos, $tagLength);
        }

        $pos += $tagLength;
    }

    $device['BaseURL'] ??= "http://$remoteIp";
    $device['Key'] = $device['DeviceID'] ?? $device['StorageID'] ?? $device['BaseURL'];

    return $device;
}

function hdhomerunCrc(string $data): int
{
    $crc = 0xFFFFFFFF;
    $length = strlen($data);

    for ($i = 0; $i < $length; $i++) {
        $x = ($crc & 0xFF) ^ ord($data[$i]);
        $crc >>= 8;
        if ($x & 0x01) $crc ^= 0x77073096;
        if ($x & 0x02) $crc ^= 0xEE0E612C;
        if ($x & 0x04) $crc ^= 0x076DC419;
        if ($x & 0x08) $crc ^= 0x0EDB8832;
        if ($x & 0x10) $crc ^= 0x1DB71064;
        if ($x & 0x20) $crc ^= 0x3B6E20C8;
        if ($x & 0x40) $crc ^= 0x76DC4190;
        if ($x & 0x80) $crc ^= 0xEDB88320;
    }

    return $crc ^ 0xFFFFFFFF;
}

function isDeviceIdValid(int $deviceId): bool
{
    if ($deviceId === 0 || $deviceId === 0xFFFFFFFF) {
        return false;
    }

    $lookup = [0xA, 0x5, 0xF, 0x6, 0x7, 0xC, 0x1, 0xB, 0x9, 0x2, 0x8, 0xD, 0x4, 0x3, 0xE, 0x0];
    $nibble = fn(int $shift) => ($deviceId >> $shift) & 0xF;

    $checksum = $lookup[$nibble(28)] ^ $nibble(24)
        ^ $lookup[$nibble(20)] ^ $nibble(16)
        ^ $lookup[$nibble(12)] ^ $nibble(8)
        ^ $lookup[$nibble(4)] ^ $nibble(0);

    return $checksum === 0;
}

function fetchDiscoverJson(array $device): ?array
{
    if (($device['BaseURL'] ?? '') === '') {
        return null;
    }

    $parsed = json_decode((string)httpGetString(rtrim($device['BaseURL'], '/') . '/discover.json'), true);
    if (!is_array($parsed)) {
        return null;
    }

    $field = fn(string $name) => is_string($parsed[$name] ?? null) ? $parsed[$name] : null;

    return [
        'FriendlyName' => $field('FriendlyName'),
        'FirmwareName' => $field('FirmwareName'),
        'FirmwareVersion' => $field('FirmwareVersion'),
        'LineupURL' => $field('LineupURL'),
        'DeviceAuth' => $field('DeviceAuth'),
    ];
}

function discoverDeviceByIp(string $target): ?array
{
    $target = trim($target);
    if (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://')) {
        $baseUrl = "http://$target";
    } else {
        $baseUrl = rtrim($target, '/');
    }

    $json = httpGetString("$baseUrl/discover.json");
    if ($json === null) {
        return null;
    }

    $parsed = json_decode($json, true);
    if (!is_array($parsed) || empty($parsed['DeviceID'])) {
        return null;
    }

    $field = fn(string $name) => is_string($parsed[$name] ?? null) ? $parsed[$name] : null;

    return [
        'Key' => $field('DeviceID') ?? $baseUrl,
        'DeviceID' => $field('DeviceID'),
        'LineupURL' => $field('LineupURL') ?? ($baseUrl . '/lineup.json'),
        'StorageURL' => $field('StorageURL'),
        'BaseURL' => $field('BaseURL') ?? $baseUrl,
        'DeviceAuth' => $field('DeviceAuth'),
        'StorageID' => $field('StorageID'),
        'Lineup' => null,
    ];
}

/** Entries are kept exactly as the device sent them, to pass on to the server. */
function fetchLineup(string $lineupUrl): ?array
{
    $lineup = json_decode((string)httpGetString($lineupUrl . '?tuning&show=all'), true);
    return is_array($lineup) && array_is_list($lineup) ? $lineup : null;
}

/**
 * Returns [hasAtsc3Channel, hasSignalStrength]. Only the first atsc3 entry's
 * SignalStrength counts - not SignalStrength on other channels. Entries with
 * "Subscribed": 0 are skipped; entries without Subscribed are used.
 */
function findAtsc3Channel(?array $lineup): array
{
    foreach ($lineup ?? [] as $entry) {
        if (!is_array($entry) || ($entry['Modulation'] ?? null) !== 'atsc3') {
            continue;
        }
        if (($entry['Subscribed'] ?? null) === 0) {
            continue;
        }
        return [true, array_key_exists('SignalStrength', $entry)];
    }
    return [false, false];
}

/** Returns [status code, response body, error message or null]. */
function httpRequest(string $url, int $timeoutSeconds, ?string $jsonBody = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ]);

    if ($jsonBody !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        ]);
    }

    $body = curl_exec($curl);
    $error = null;
    if ($body === false) {
        $error = curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'The request timed out.' : curl_error($curl);
        $body = '';
    }

    return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body, $error];
}

function httpGetString(string $url): ?string
{
    [$status, $body, $error] = httpRequest($url, 10);
    return ($error === null && $status >= 200 && $status < 300) ? $body : null;
}

function readJsonFile(string $path): ?array
{
    $json = @file_get_contents($path);
    $data = $json === false ? null : json_decode($json, true);
    return is_array($data) ? $data : null;
}

function writeJsonFile(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return @file_put_contents($path, $json) !== false;
}

function sidecarPathFor(string $dataPath): string
{
    return substr($dataPath, 0, -strlen('.tmp')) . '.json';
}

function dataPathFor(string $sidecarPath): string
{
    return substr($sidecarPath, 0, -strlen('.json')) . '.tmp';
}

function isAbsoluteUrl(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts) && isset($parts['scheme'], $parts['host']);
}

function formatDelta(?float $seconds): string
{
    if ($seconds === null) {
        return 'n/a';
    }
    $rounded = round($seconds, 1);
    return ($rounded == 0 ? '0' : sprintf('%+.1f', $rounded)) . ' sec';
}

function formatLocalTime(float $unixSeconds): string
{
    return date('Y-m-d H:i:s', (int)round($unixSeconds));
}

function defaultDataDir(): string
{
    return (getenv('HOME') ?: '.') . '/eacapture';
}

/** PHP defaults to UTC unless date.timezone is set; use the system zone. */
function useSystemTimezone(): void
{
    if (ini_get('date.timezone')) {
        return;
    }

    $zone = getenv('TZ') ?: null;
    if ($zone === null && is_link('/etc/localtime') && preg_match('#zoneinfo/(.+)$#', (string)readlink('/etc/localtime'), $m)) {
        $zone = $m[1];
    }
    if ($zone === null && is_readable('/etc/timezone')) {
        $zone = trim((string)file_get_contents('/etc/timezone'));
    }

    if (!$zone || !@date_default_timezone_set($zone)) {
        date_default_timezone_set('UTC');
    }
}
