<?php

require_once __DIR__ . '/db.php';

class CdbApiException extends Exception
{
}

// Sleeps `throttle_usec` after every request, regardless of success, so
// callers never need to remember to throttle themselves.
function cdb_throttle(): void
{
    usleep(cdb_mirror_config()['throttle_usec']);
}

function cdb_curl_handle(string $url): CurlHandle
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . cdb_mirror_config()['user_agent'],
            'Accept: application/json',
        ],
    ]);
    return $ch;
}

// GET a path relative to api_base (e.g. "/packages/") and decode JSON.
function cdb_api_get(string $path): mixed
{
    $url = cdb_mirror_config()['api_base'] . $path;
    $ch = cdb_curl_handle($url);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    cdb_throttle();

    if ($errno !== 0) {
        throw new CdbApiException("cURL error fetching $url: $error");
    }
    if ($status < 200 || $status >= 300) {
        throw new CdbApiException("HTTP $status fetching $url");
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new CdbApiException("Invalid JSON from $url: " . json_last_error_msg());
    }
    return $data;
}

// Download a URL (absolute, or relative to site_base) to a local file path.
// Creates parent directories as needed. Returns the number of bytes written.
function cdb_download_file(string $url, string $destPath): int
{
    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = cdb_mirror_config()['site_base'] . $url;
    }

    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new CdbApiException("Could not create directory: $dir");
    }

    $tmpPath = $destPath . '.part';
    $fp = fopen($tmpPath, 'wb');
    if ($fp === false) {
        throw new CdbApiException("Could not open $tmpPath for writing");
    }

    $ch = cdb_curl_handle($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    cdb_throttle();

    if ($errno !== 0 || $status < 200 || $status >= 300) {
        @unlink($tmpPath);
        throw new CdbApiException("Failed to download $url (HTTP $status): $error");
    }

    rename($tmpPath, $destPath);
    return filesize($destPath);
}
