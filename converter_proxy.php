<?php
declare(strict_types=1);

/**
 * Same-origin reverse proxy for the GC Convert service.
 *
 * /convert/*  ->  http://127.0.0.1:3313/convert/*
 *
 * The browser only ever talks to GC Files. The proxy:
 *   - requires a GC Files session;
 *   - maps the GC user to a converter account and injects its JWT;
 *   - streams request and response bodies (uploads and downloads never fit RAM);
 *   - strips converter cookies and frame-blocking headers.
 */

require_once __DIR__ . '/converter_lib.php';

// bootstrap.php queued GC Files' own security headers; the proxied app must be
// frameable from this origin and supplies its own content type.
header_remove('X-Frame-Options');
header_remove('Content-Security-Policy');
header_remove('Cache-Control');

/**
 * Send a small JSON error and stop.
 */
function gc_conv_fail(int $status, string $message): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['detail' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = gc_user();
if ($user === null) {
    gc_conv_fail(401, 'Not authenticated');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    gc_conv_fail(405, 'Method not allowed');
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
if ($path !== '/convert' && !str_starts_with($path, '/convert/')) {
    gc_conv_fail(404, 'Not found');
}
// The converter runs with root_path=/convert: its static files and API are
// served under /convert/*, so forward the prefix unchanged instead of
// stripping it (stripping breaks StaticFiles' root_path handling).
$path = rawurldecode($path);

// No traversal and no control characters in the upstream path.
if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
    gc_conv_fail(400, 'Bad path');
}

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$upstreamUrl = gc_conv_upstream() . $path . ($query !== '' ? '?' . $query : '');

try {
    $token = gc_conv_token($user);
} catch (Throwable $error) {
    error_log('[GCFiles] converter token: ' . $error->getMessage());
    gc_conv_fail(502, 'Конвертер недоступен: не удалось создать сессию');
}

// The session must not stay locked while a large upload or download runs:
// parallel converter requests from the same browser would queue behind it.
session_write_close();
set_time_limit(0);
ignore_user_abort(false);

$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

$forwardHeaders = ['Authorization: Bearer ' . $token, 'Accept: ' . (string) ($_SERVER['HTTP_ACCEPT'] ?? '*/*')];
foreach (['HTTP_CONTENT_TYPE' => 'Content-Type', 'HTTP_RANGE' => 'Range', 'HTTP_IF_NONE_MATCH' => 'If-None-Match', 'HTTP_IF_MODIFIED_SINCE' => 'If-Modified-Since', 'HTTP_X_REQUESTED_WITH' => 'X-Requested-With'] as $serverKey => $headerName) {
    $value = (string) ($_SERVER[$serverKey] ?? '');
    if ($value !== '' && $headerName !== 'Content-Type') {
        $forwardHeaders[] = $headerName . ': ' . $value;
    }
}

/**
 * Build a readable multipart body from PHP's parsed $_POST/$_FILES.
 *
 * php://input is empty for multipart/form-data, so the parsed arrays are the
 * only portable source. File bytes are streamed from the upload tmp files.
 *
 * @return array{0: array<int, array{type:string, data?:string, handle?:resource, size?:int}>, 1: int, 2: string}
 */
function gc_conv_multipart_segments(): array
{
    $boundary = '----GCConvert' . bin2hex(random_bytes(12));
    $segments = [];
    $length = 0;

    $addString = static function (string $value) use (&$segments, &$length): void {
        $segments[] = ['type' => 'string', 'data' => $value];
        $length += strlen($value);
    };

    foreach ($_POST as $name => $value) {
        if (!is_string($value)) {
            continue;
        }
        $safeName = str_replace(["\r", "\n", '"'], '', (string) $name);
        $addString('--' . $boundary . "\r\n");
        $addString('Content-Disposition: form-data; name="' . $safeName . '"' . "\r\n\r\n");
        $addString($value . "\r\n");
    }

    foreach ($_FILES as $field => $info) {
        if (!is_array($info) || !isset($info['tmp_name'])) {
            continue;
        }
        $names = is_array($info['name']) ? $info['name'] : [$info['name']];
        $tmpNames = is_array($info['tmp_name']) ? $info['tmp_name'] : [$info['tmp_name']];
        $types = is_array($info['type']) ? $info['type'] : [$info['type']];

        foreach ($tmpNames as $index => $tmpName) {
            if (!is_string($tmpName) || $tmpName === '' || !is_file($tmpName)) {
                continue;
            }
            $original = is_string($names[$index] ?? null) ? (string) $names[$index] : 'file';
            $original = basename(str_replace('\\', '/', $original));
            $original = str_replace(["\r", "\n", '"'], '', $original);
            $mime = is_string($types[$index] ?? null) && $types[$index] !== ''
                ? (string) $types[$index]
                : 'application/octet-stream';
            $mime = preg_replace('#[^A-Za-z0-9.+/-]#', '', $mime) ?: 'application/octet-stream';
            $handle = fopen($tmpName, 'rb');
            if ($handle === false) {
                continue;
            }
            $size = (int) filesize($tmpName);
            $safeField = str_replace(["\r", "\n", '"'], '', (string) $field);
            $addString('--' . $boundary . "\r\n");
            $addString('Content-Disposition: form-data; name="' . $safeField . '"; filename="' . $original . '"' . "\r\n");
            $addString('Content-Type: ' . $mime . "\r\n\r\n");
            $segments[] = ['type' => 'file', 'handle' => $handle, 'size' => $size];
            $length += $size;
            $addString("\r\n");
        }
    }

    $addString('--' . $boundary . "--\r\n");

    return [$segments, $length, 'multipart/form-data; boundary=' . $boundary];
}

$bodySegments = [];
$inputHandle = null;
$bodyLength = 0;
$bodyContentType = '';

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    if (str_starts_with(strtolower($contentType), 'multipart/form-data')) {
        [$bodySegments, $bodyLength, $bodyContentType] = gc_conv_multipart_segments();
        $forwardHeaders[] = 'Content-Type: ' . $bodyContentType;
    } elseif ($contentType !== '' && $contentLength > 0) {
        $forwardHeaders[] = 'Content-Type: ' . $contentType;
        $bodyLength = $contentLength;
        $inputHandle = fopen('php://input', 'rb');
    }
}

/**
 * cURL read callback over the multipart segment list.
 *
 * @param array<int, array{type:string, data?:string, handle?:resource, size?:int}> $segments
 */
function gc_conv_read_multipart($ch, $fh, int $length, array &$segments, int &$index, int &$offset): string
{
    $output = '';
    while (strlen($output) < $length && $index < count($segments)) {
        $segment = $segments[$index];
        if ($segment['type'] === 'string') {
            $chunk = substr($segment['data'], $offset, $length - strlen($output));
            $output .= $chunk;
            $offset += strlen($chunk);
            if ($offset >= strlen($segment['data'])) {
                $index++;
                $offset = 0;
            }
            continue;
        }

        $handle = $segment['handle'];
        $remaining = (int) $segment['size'] - $offset;
        if ($remaining <= 0) {
            fclose($handle);
            $index++;
            $offset = 0;
            continue;
        }
        $chunk = fread($handle, min($length - strlen($output), $remaining));
        if ($chunk === false || $chunk === '') {
            fclose($handle);
            $index++;
            $offset = 0;
            continue;
        }
        $output .= $chunk;
        $offset += strlen($chunk);
        if ($offset >= (int) $segment['size']) {
            fclose($handle);
            $index++;
            $offset = 0;
        }
    }

    return $output;
}

$responseHeaders = [];
$responseStatus = 0;
$headersSent = false;

$sendHeaders = static function () use (&$responseHeaders, &$responseStatus, &$headersSent): void {
    if ($headersSent) {
        return;
    }
    $headersSent = true;
    http_response_code($responseStatus > 0 ? $responseStatus : 502);

    $blocked = ['transfer-encoding', 'connection', 'keep-alive', 'set-cookie', 'x-frame-options', 'content-security-policy', 'strict-transport-security'];
    foreach ($responseHeaders as $name => $values) {
        if (in_array(strtolower($name), $blocked, true)) {
            continue;
        }
        header($name . ': ' . implode(', ', $values), false);
    }
    header('X-Content-Type-Options: nosniff');
};

$ch = curl_init($upstreamUrl);
if ($ch === false) {
    gc_conv_fail(502, 'Не удалось инициализировать прокси');
}

curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders, &$responseStatus): int {
    $trimmed = trim($line);
    if ($trimmed === '') {
        return strlen($line);
    }
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches) === 1) {
        $responseStatus = (int) $matches[1];
        $responseHeaders = [];
        return strlen($line);
    }
    $parts = explode(':', $trimmed, 2);
    if (count($parts) === 2) {
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        $responseHeaders[$name][] = $value;
    }
    return strlen($line);
});
curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $data) use (&$headersSent, $sendHeaders): int {
    if (!$headersSent) {
        $sendHeaders();
    }
    echo $data;
    flush();
    return strlen($data);
});

if ($bodyLength > 0) {
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, $bodyLength);
    if ($bodySegments !== []) {
        $index = 0;
        $offset = 0;
        curl_setopt($ch, CURLOPT_READFUNCTION, static function ($ch, $fh, int $length) use (&$bodySegments, &$index, &$offset): string {
            return gc_conv_read_multipart($ch, $fh, $length, $bodySegments, $index, $offset);
        });
    } elseif ($inputHandle !== false && $inputHandle !== null) {
        curl_setopt($ch, CURLOPT_READFUNCTION, static function ($ch, $fh, int $length) use ($inputHandle): string {
            $chunk = fread($inputHandle, $length);
            return $chunk === false ? '' : $chunk;
        });
    }
}

$ok = curl_exec($ch);
$curlError = curl_error($ch);
$curlStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($inputHandle !== false && $inputHandle !== null) {
    fclose($inputHandle);
}

if ($ok === false) {
    if (!$headersSent) {
        error_log('[GCFiles] converter proxy: ' . $curlError);
        gc_conv_fail(502, 'Конвертер недоступен');
    }
    exit;
}

if (!$headersSent) {
    $responseStatus = $responseStatus > 0 ? $responseStatus : $curlStatus;
    $sendHeaders();
}
