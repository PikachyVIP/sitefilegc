<?php
declare(strict_types=1);

/**
 * GC Convert bridge: configuration, per-user converter accounts and tokens.
 *
 * The converter is a separate Python service (FastAPI). GC Files never exposes
 * its credentials to the browser: every request goes through converter_proxy.php,
 * which swaps the sentinel token for the real JWT of the mapped account.
 *
 * This file is denied by .htaccess and must never be requested directly.
 */

require_once __DIR__ . '/bootstrap.php';

/** Base URL of the converter service, without a trailing slash. */
function gc_conv_upstream(): string
{
    $url = gc_env('GC_CONVERT_URL');
    if ($url === '') {
        $url = 'http://127.0.0.1:3313';
    }

    return rtrim($url, '/');
}

/** Shared secret used to derive per-user converter passwords. */
function gc_conv_secret(): string
{
    static $secret = null;
    if (is_string($secret) && $secret !== '') {
        return $secret;
    }

    $fromEnv = gc_env('GC_CONVERT_SECRET');
    if ($fromEnv !== '') {
        return $secret = $fromEnv;
    }

    $dir = __DIR__ . '/uploads/.converter';
    if (!gc_ensure_data_dir($dir)) {
        throw new RuntimeException('Cannot create the converter secret directory');
    }

    $path = $dir . '/secret';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open the converter secret');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the converter secret');
        }
        $stored = trim((string) stream_get_contents($handle));
        if (strlen($stored) >= 32) {
            return $secret = $stored;
        }

        $generated = bin2hex(random_bytes(32));
        if (ftruncate($handle, 0) === false || rewind($handle) === false
            || fwrite($handle, $generated) === false || !fflush($handle)) {
            throw new RuntimeException('Cannot write the converter secret');
        }
        @chmod($path, 0600);

        return $secret = $generated;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Deterministic converter password for a GC Files user id. */
function gc_conv_password(int $userId): string
{
    return hash_hmac('sha256', 'gc-convert:user:' . $userId, gc_conv_secret());
}

function gc_conv_username(int $userId): string
{
    return 'gc_' . $userId;
}

/**
 * Small JSON request to the converter. Returns [status, decoded body, raw body].
 *
 * @param array<string, mixed>|null $payload
 * @return array{0:int, 1:mixed, 2:string}
 */
function gc_conv_api(string $method, string $path, ?array $payload = null, string $token = '', int $timeout = 20): array
{
    $ch = curl_init(gc_conv_upstream() . $path);
    if ($ch === false) {
        throw new RuntimeException('Cannot initialise cURL');
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Converter is unreachable: ' . $error);
    }

    $decoded = json_decode((string) $raw, true);

    return [$status, $decoded, (string) $raw];
}

/**
 * Authenticate an existing converter account.
 *
 * @return array{token:string, expires:int}
 */
function gc_conv_authenticate(string $username, string $password): array
{
    [$status, $body] = gc_conv_api('POST', '/api/users/authenticate', [
        'username' => $username,
        'password' => $password,
    ]);

    if ($status !== 200 || !is_array($body) || empty($body['access_token'])) {
        $detail = is_array($body) && isset($body['detail']) && is_string($body['detail'])
            ? $body['detail']
            : 'HTTP ' . $status;
        throw new RuntimeException('Converter authentication failed for ' . $username . ': ' . $detail);
    }

    $expiresIn = isset($body['expires_in']) && is_numeric($body['expires_in']) ? (int) $body['expires_in'] : 3600;

    return [
        'token' => (string) $body['access_token'],
        'expires' => time() + max(60, $expiresIn - 60),
    ];
}

/** Admin token used only to provision per-user accounts. */
function gc_conv_admin_token(bool $force = false): string
{
    $cached = $_SESSION['gc_conv_admin'] ?? null;
    if (!$force && is_array($cached) && !empty($cached['token']) && (int) ($cached['expires'] ?? 0) > time()) {
        return (string) $cached['token'];
    }
    unset($_SESSION['gc_conv_admin']);

    $envToken = gc_env('GC_CONVERT_ADMIN_TOKEN');
    if ($envToken !== '') {
        $_SESSION['gc_conv_admin'] = ['token' => $envToken, 'expires' => time() + 300];
        return $envToken;
    }

    $adminUser = gc_env('GC_CONVERT_ADMIN_USER');
    $adminPassword = gc_env('GC_CONVERT_ADMIN_PASSWORD');
    if ($adminUser !== '' && $adminPassword !== '') {
        $auth = gc_conv_authenticate($adminUser, $adminPassword);
        $_SESSION['gc_conv_admin'] = $auth;
        return $auth['token'];
    }

    $serviceUser = 'gc-service';
    $servicePassword = hash_hmac('sha256', 'gc-convert:service', gc_conv_secret());

    try {
        $auth = gc_conv_authenticate($serviceUser, $servicePassword);
        $_SESSION['gc_conv_admin'] = $auth;
        return $auth['token'];
    } catch (RuntimeException $ignored) {
        // The service account does not exist yet (or its password changed).
    }

    try {
        [$status, $body] = gc_conv_api('GET', '/api/users/bootstrap-status');
        $requiresSetup = is_array($body) && !empty($body['requires_setup']);
    } catch (RuntimeException $error) {
        throw new RuntimeException('Converter is unreachable: ' . $error->getMessage());
    }

    if (!$requiresSetup || $status !== 200) {
        throw new RuntimeException(
            'Converter already has users but no gc-service account. '
            . 'Set GC_CONVERT_ADMIN_TOKEN (an admin JWT) or GC_CONVERT_ADMIN_USER/GC_CONVERT_ADMIN_PASSWORD.'
        );
    }

    [$createStatus, $createBody] = gc_conv_api('POST', '/api/users', [
        'username' => $serviceUser,
        'password' => $servicePassword,
        'email' => null,
        'full_name' => 'GC Files service',
        'role' => 'admin',
        'disabled' => false,
    ]);

    if ($createStatus !== 201 && $createStatus !== 409) {
        $detail = is_array($createBody) && isset($createBody['detail']) && is_string($createBody['detail'])
            ? $createBody['detail']
            : 'HTTP ' . $createStatus;
        throw new RuntimeException('Cannot create the converter service account: ' . $detail);
    }

    $auth = gc_conv_authenticate($serviceUser, $servicePassword);
    $_SESSION['gc_conv_admin'] = $auth;

    return $auth['token'];
}

/**
 * Ensure the GC Files user has a converter account and return a valid JWT.
 *
 * @param array{id:int} $user
 */
function gc_conv_token(array $user, bool $force = false): string
{
    $userId = (int) $user['id'];
    $cacheKey = 'gc_conv_token_' . $userId;

    if (!$force) {
        $cached = $_SESSION[$cacheKey] ?? null;
        if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires'] ?? 0) > time()) {
            $token = (string) $cached['token'];
            // A converter restart may invalidate JWTs while this session still
            // holds one that has not expired on paper. Re-check it once a minute.
            $checkedKey = 'gc_conv_checked_' . $userId;
            $checkedAt = (int) ($_SESSION[$checkedKey] ?? 0);
            if ($checkedAt > time() - 60) {
                return $token;
            }
            [$status] = gc_conv_api('GET', '/api/users/me', null, $token, 10);
            if ($status === 200) {
                $_SESSION[$checkedKey] = time();
                return $token;
            }
        }
    }

    $username = gc_conv_username($userId);
    $password = gc_conv_password($userId);

    try {
        $auth = gc_conv_authenticate($username, $password);
    } catch (RuntimeException $missing) {
        $adminToken = gc_conv_admin_token();
        $payload = [
            'username' => $username,
            'password' => $password,
            'email' => null,
            'full_name' => 'GC Files #' . $userId,
            'role' => 'member',
            'disabled' => false,
        ];
        [$status, $body] = gc_conv_api('POST', '/api/users', $payload, $adminToken);

        // A converter restart invalidates the cached admin JWT too; refresh it
        // once instead of failing the user's first visit.
        if ($status === 401) {
            $adminToken = gc_conv_admin_token(true);
            [$status, $body] = gc_conv_api('POST', '/api/users', $payload, $adminToken);
        }

        if ($status !== 201 && $status !== 409) {
            $detail = is_array($body) && isset($body['detail']) && is_string($body['detail'])
                ? $body['detail']
                : 'HTTP ' . $status;
            throw new RuntimeException('Cannot create the converter account for GC user ' . $userId . ': ' . $detail);
        }

        // 409 means the account exists with another password (secret rotated).
        // Fall through to authentication; if that fails, the admin must reset it.
        $auth = gc_conv_authenticate($username, $password);
    }

    $_SESSION[$cacheKey] = $auth;

    return $auth['token'];
}
