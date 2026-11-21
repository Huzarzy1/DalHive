<?php
declare(strict_types=1);

// Serves dalplex.json. If the file is 30+ days old (or missing), it first
// refreshes it from BestTime, so no cron job is needed.

const MAX_AGE     = 30 * 24 * 60 * 60; // refresh when the file is 30+ days old
const RETRY_AFTER = 60 * 60;           // after a failed refresh, wait an hour
const VENUE_NAME  = 'Dalplex';
const VENUE_ADDR  = '6260 South St, Halifax, NS B3H 4R2';

$dataFile = __DIR__ . '/dalplex.json';
$lockFile = __DIR__ . '/dalplex.lock'; // lock so only one request calls the API
$failFile = __DIR__ . '/dalplex.fail'; // its mtime = time of the last failed refresh

function isStale(string $file): bool {
    clearstatcache(true, $file);
    return !is_file($file) || time() - filemtime($file) >= MAX_AGE;
}

function recentlyFailed(string $file): bool {
    clearstatcache(true, $file);
    return is_file($file) && time() - filemtime($file) < RETRY_AFTER;
}

// Ask BestTime for the venue's weekly busyness forecast.
function fetchForecast(string $apiKey): array {
    $query = http_build_query([
        'api_key_private' => $apiKey,
        'venue_name'      => VENUE_NAME,
        'venue_address'   => VENUE_ADDR,
    ]);
    $url = 'https://besttime.app/api/v1/forecasts?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Fallback for hosts without the cURL extension 
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 20,
            'ignore_errors' => true, // still read the body on non-200 responses
            'header'        => "Content-Length: 0\r\n",
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status   = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    // Errors never include the URL, since it contains the API key.
    if ($response === false) throw new RuntimeException('BestTime request failed');
    if ($status !== 200) throw new RuntimeException("BestTime HTTP $status");

    $body = json_decode($response, true);
    if (!is_array($body) || ($body['status'] ?? '') !== 'OK') {
        throw new RuntimeException('BestTime returned an error status');
    }
    return $body;
}

// Reshape into { name, populartimes: { Day: { hour: pct } } }.
// day_raw has 24 values but index 0 is 6 AM (not midnight), so shift to clock hours.
function toFrontendShape(array $body): array {
    $analysis = $body['analysis'] ?? null;
    if (!is_array($analysis) || count($analysis) !== 7) {
        throw new RuntimeException('Unexpected response: expected 7 days in analysis');
    }

    $populartimes = [];
    foreach ($analysis as $day) {
        $raw  = $day['day_raw'] ?? null;
        $name = $day['day_info']['day_text'] ?? null;
        if (!is_array($raw) || count($raw) !== 24 || !is_string($name)) {
            throw new RuntimeException('Unexpected response: bad day data');
        }
        $hours = [];
        foreach (array_values($raw) as $i => $pct) {
            $hours[($i + 6) % 24] = $pct;
        }
        $populartimes[$name] = $hours;
    }

    return [
        'name'         => $body['venue_info']['venue_name'] ?? VENUE_NAME,
        'populartimes' => $populartimes,
    ];
}

// Fetch fresh data and save it to disk.
function refresh(string $apiKey, string $dataFile): void {
    $shaped = toFrontendShape(fetchForecast($apiKey)); // throws before we touch the file

    // Write to a temp file then rename, so readers never see a half-written file.
    $tmp = $dataFile . '.tmp';
    $ok  = file_put_contents($tmp, json_encode($shaped, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT));
    if ($ok === false || !rename($tmp, $dataFile)) {
        throw new RuntimeException('Could not write data file');
    }
    chmod($dataFile, 0644);
}

// Refresh if needed 

if (isStale($dataFile) && !recentlyFailed($failFile)) {
    $config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    $apiKey = trim((string)($config['besttime_api_key'] ?? ''));

    if ($apiKey !== '') {
        $lock = fopen($lockFile, 'c');
        if ($lock !== false && flock($lock, LOCK_EX)) {
            // Re-check: another request may have refreshed while we waited for the lock.
            if (isStale($dataFile)) {
                try {
                    refresh($apiKey, $dataFile);
                    @unlink($failFile);
                } catch (Throwable $e) {
                    // Keep serving the old file; back off before trying again.
                    error_log('dalplex refresh failed: ' . $e->getMessage());
                    touch($failFile);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

// Serve the file

header('Content-Type: application/json');

if (!is_file($dataFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'No data yet']);
    exit;
}

header('Cache-Control: public, max-age=3600');
readfile($dataFile);
