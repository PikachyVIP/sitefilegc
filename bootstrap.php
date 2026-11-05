<?php
declare(strict_types=1);

/**
 * GC Files — common bootstrap: database, session, auth, CSRF, helpers.
 * Every page includes this file first; nothing is printed before it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

const GC_APP_NAME = 'GC Files';
const GC_APP_VERSION = '2.2.0';
const GC_ASSET_VERSION = '2.2.15';

// Hard ceiling for every uploaded file, independent of php.ini. 20 GiB.
const GC_MAX_UPLOAD_BYTES = 20 * 1024 * 1024 * 1024;

const GC_CATEGORIES = [
    'release' => 'Релиз',
    'site' => 'Сайт',
    'storage' => 'Хранилище',
    'fix' => 'Исправление',
];

// Rights known to the whole site. The keys are the legacy names already stored
// in admin_rights, so admin.php and the new pages read the same rows.
const GC_RIGHTS = [
    'admin_panel' => 'Админ-меню',
    'create_users' => 'Создание пользователей',
    'manage_rights' => 'Выдача прав',
    'create_delete_tags' => 'Создание и удаление тегов',
    'edit_user_tags' => 'Изменение тегов пользователей',
    'edit_any_file' => 'Редактирование любых файлов',
    'view_any_file' => 'Просмотр любых файлов',
    'delete_any_file' => 'Удаление любых файлов',
    'pin_files' => 'Закрепление файлов',
    'manage_updates' => 'Обновления приложений',
];

if (!empty($db_config['timezone'])) {
    $gc_timezone = (string) $db_config['timezone'];
    if (in_array($gc_timezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($gc_timezone);
    }
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    $gc_legacy_csp = defined('GC_LEGACY_CSP') && GC_LEGACY_CSP;
    $gc_script = $gc_legacy_csp ? "'self' 'unsafe-inline'" : "'self'";
    $gc_style = $gc_legacy_csp ? "'self' 'unsafe-inline'" : "'self'";
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src {$gc_style}; script-src {$gc_script}; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('Cache-Control: no-store');
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function gc_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Client address for rate limits. Never trust X-Forwarded-For here. */
function gc_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function gc_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'login' => (string) ($_SESSION['user'] ?? ''),
        'tags' => is_array($_SESSION['tags'] ?? null) ? $_SESSION['tags'] : [],
        'rights' => is_array($_SESSION['admin_rights'] ?? null) ? $_SESSION['admin_rights'] : [],
        'is_admin' => !empty($_SESSION['is_admin']),
    ];
}

function gc_has_right(string $right): bool
{
    $user = gc_user();
    if ($user === null) {
        return false;
    }
    if ($user['id'] === 1) {
        return true;
    }
    if ($right === 'admin_panel' && $user['is_admin']) {
        return true;
    }

    return in_array($right, $user['rights'], true);
}

/**
 * Any administrative right opens the admin section. The changelog editor still
 * checks gc_is_admin() separately, because it was historically narrower.
 */
function gc_can_admin(): bool
{
    $user = gc_user();
    if ($user === null) {
        return false;
    }
    if ($user['id'] === 1 || $user['is_admin']) {
        return true;
    }
    foreach (array_keys(GC_RIGHTS) as $right) {
        if (in_array($right, $user['rights'], true)) {
            return true;
        }
    }

    return false;
}

function gc_is_admin(): bool
{
    $user = gc_user();
    if ($user === null) {
        return false;
    }

    return $user['id'] === 1
        || $user['is_admin']
        || in_array('admin_panel', $user['rights'], true);
}

/**
 * The first account is the only one allowed to read another user's personal
 * files. Other administrators keep the ordinary privacy boundary.
 */
function gc_is_superadmin(): bool
{
    $user = gc_user();

    return $user !== null && $user['id'] === 1;
}

/**
 * Whether a file row may be read by the given account. The row must carry
 * uploader_id and, for a personal file, is_personal plus folder_owner.
 */
function gc_file_readable(PDO $pdo, array $file, int $userId): bool
{
    if ($userId === 1) {
        return true;
    }

    $personal = gc_bool_value($file['is_personal'] ?? false);
    if ($personal
        && ((int) ($file['uploader_id'] ?? 0) !== $userId
            || (int) ($file['folder_owner'] ?? 0) !== $userId)
    ) {
        return false;
    }

    if ((int) ($file['uploader_id'] ?? 0) === $userId) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM file_access WHERE file_id = ? AND user_id = ?');
    $stmt->execute([(int) ($file['id'] ?? 0), $userId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $tags = $_SESSION['tags'] ?? [];
    if (is_array($tags) && $tags !== []) {
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $stmt = $pdo->prepare(
            "SELECT 1 FROM file_tags ft JOIN tags t ON ft.tag_id = t.id
              WHERE ft.file_id = ? AND t.name IN ({$placeholders})"
        );
        $stmt->execute(array_merge([(int) ($file['id'] ?? 0)], array_values($tags)));

        return (bool) $stmt->fetchColumn();
    }

    return false;
}

/**
 * Preview kind for a file, based on its stored MIME type and, as a fallback,
 * its extension. Only these kinds are served inline.
 */
function gc_preview_kind(?string $mime, string $name = ''): string
{
    $mime = strtolower(trim(explode(';', (string) $mime)[0]));
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) {
        return 'image';
    }
    if (in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'], true)) {
        return 'video';
    }
    if (str_starts_with($mime, 'text/')
        || in_array($mime, [
            'application/json',
            'application/xml',
            'application/javascript',
            'application/x-javascript',
            'application/x-yaml',
            'application/x-sh',
            'application/sql',
            'application/csv',
        ], true)
    ) {
        return 'text';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp4', 'webm', 'ogv', 'ogg'], true)) {
        return 'video';
    }
    if (in_array($ext, [
        'txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'conf', 'yaml', 'yml',
        'html', 'htm', 'css', 'js', 'ts', 'php', 'sql', 'sh', 'py', 'rb', 'go',
        'rs', 'java', 'c', 'h', 'cpp', 'hpp',
    ], true)) {
        return 'text';
    }

    return '';
}

/**
 * Re-read a user's tags and rights into the current session. Used after an
 * administrator edits their own account, where the cached session would
 * otherwise keep the old rights until the next login.
 */
function gc_refresh_session_rights(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'SELECT t.name FROM tags t JOIN user_tags ut ON ut.tag_id = t.id WHERE ut.user_id = ? ORDER BY t.name'
    );
    $stmt->execute([$userId]);
    $_SESSION['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ? ORDER BY right_name');
    $stmt->execute([$userId]);
    $rights = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['admin_rights'] = $rights;
    $_SESSION['is_admin'] = in_array('admin_panel', $rights, true);
}

function gc_require_login(): array
{
    $user = gc_user();
    if ($user !== null) {
        return $user;
    }

    $next = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    gc_redirect('login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
}

function gc_redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

function gc_safe_next(mixed $next, string $fallback = 'changelog.php'): string
{
    if (!is_string($next) || $next === '') {
        return $fallback;
    }
    $decoded = rawurldecode($next);
    if (preg_match('#[\r\n\\\\]#', $next) || preg_match('#[\r\n\\\\]#', $decoded)) {
        return $fallback;
    }
    if ($next[0] !== '/' || str_starts_with($next, '//') || str_starts_with($decoded, '//')) {
        return $fallback;
    }
    if (!preg_match("#^/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*$#", $next)) {
        return $fallback;
    }
    if ($next === '/') {
        return $fallback;
    }

    return $next;
}

function gc_csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function gc_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . gc_h(gc_csrf_token()) . '">';
}

function gc_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(gc_csrf_token(), $sent)) {
        http_response_code(419);
        if (defined('GC_JSON_ERRORS') && GC_JSON_ERRORS) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Сессия истекла. Обновите страницу.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Сессия истекла. Вернитесь назад, обновите страницу и попробуйте снова.';
        }
        exit;
    }
}

function gc_flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function gc_take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flashes) ? $flashes : [];
}

function gc_size(?int $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return '—';
    }
    if ($bytes < 1024) {
        return $bytes . ' Б';
    }

    $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    $value = $bytes / 1024;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    $decimals = $value >= 10 ? 0 : 1;

    return number_format($value, $decimals, ',', ' ') . ' ' . $units[$index];
}

function gc_dt(?string $value, string $format = 'd.m.Y H:i'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    return date($format, $timestamp);
}

function gc_iso(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = strtotime($value);

    return $timestamp === false ? '' : date('c', $timestamp);
}

function gc_ext_label(string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

    return $extension === '' ? 'FILE' : strtoupper(substr($extension, 0, 4));
}

function gc_clean_ext(string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
    $extension = substr($extension, 0, 10);
    $dangerous = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs',
        'html', 'htm', 'xhtml', 'svg', 'shtml', 'asp', 'aspx', 'jsp',
    ];

    return in_array($extension, $dangerous, true) ? '' : $extension;
}

function gc_safe_folder_name(string $name): string
{
    $name = str_replace(['\\', '/'], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }

    return gc_cut($name, 0, 80);
}

function gc_upload_path_parts(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        $part = gc_safe_folder_name($part);
        if ($part === '') {
            continue;
        }
        $parts[] = $part;
        if (count($parts) >= 32) {
            break;
        }
    }

    return $parts;
}

function gc_safe_upload_name(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'file';
    }
    if (strlen($name) > 200) {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = substr($name, 0, 180);
        $name = $extension !== '' ? $base . '.' . substr($extension, 0, 16) : $base;
    }

    return $name;
}

function gc_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function gc_max_upload(): int
{
    $limits = [GC_MAX_UPLOAD_BYTES];
    $upload = gc_ini_bytes((string) ini_get('upload_max_filesize'));
    $post = gc_ini_bytes((string) ini_get('post_max_size'));
    if ($upload > 0) {
        $limits[] = $upload;
    }
    if ($post > 0) {
        $limits[] = $post;
    }

    return min($limits);
}

function gc_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл больше допустимого размера.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью, попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
        UPLOAD_ERR_NO_TMP_DIR => 'На сервере нет временной папки для загрузки.',
        UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл на диск.',
        UPLOAD_ERR_EXTENSION => 'Загрузку остановило расширение PHP.',
        default => 'Не удалось загрузить файл.',
    };
}

/**
 * Create a data directory and close it from the web server. The .htaccess and
 * the stub index.html are part of the folder contract: files inside are only
 * served through PHP.
 */
function gc_ensure_data_dir(string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        return false;
    }

    $deny = <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

    $htaccess = $path . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, $deny . "\n");
    }

    $stub = $path . '/index.html';
    if (!file_exists($stub)) {
        @file_put_contents($stub, "<!doctype html><meta charset=\"utf-8\"><title>403</title>\n");
    }

    return true;
}

function gc_mime_type(string $path): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }

    return 'application/octet-stream';
}

function gc_category_label(string $category): string
{
    return GC_CATEGORIES[$category] ?? 'Обновление';
}

function gc_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function gc_cut(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
    }

    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function gc_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value) : strtoupper($value);
}

function gc_initials(string $login): string
{
    $login = trim($login);
    if ($login === '') {
        return 'GC';
    }
    $parts = preg_split('/[\s._-]+/', $login) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= gc_cut($part, 0, 1);
        }
        if (gc_strlen($initials) >= 2) {
            break;
        }
    }

    return gc_upper($initials !== '' ? $initials : gc_cut($login, 0, 2));
}

function gc_share_url(string $token): string
{
    return gc_base_url() . '/transfer.php?t=' . rawurlencode($token);
}

function gc_bool_value(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    return in_array((string) $value, ['1', 't', 'true'], true);
}

function gc_storage_link(int $folder, string $sort = 'date', string $dir = 'desc', string $scope = 'all'): string
{
    $params = ['sort' => $sort, 'dir' => $dir];
    if ($folder > 0) {
        $params['folder'] = $folder;
    }
    if ($scope !== 'all') {
        $params['scope'] = $scope;
    }
    return 'storage.php?' . http_build_query($params);
}

/**
 * Returns the root of the user's personal folder, creating it on first use.
 * All descendants share the is_personal flag and are hidden from every other user.
 */
function gc_personal_folder_id(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT id FROM folders WHERE is_personal = TRUE AND parent_id IS NULL AND created_by = ? LIMIT 1'
    );
    $statement->execute([$userId]);
    $folderId = (int) $statement->fetchColumn();
    if ($folderId > 0) {
        return $folderId;
    }

    try {
        $statement = $pdo->prepare(
            "INSERT INTO folders (name, parent_id, created_by, is_personal)
             VALUES ('Личные файлы', NULL, ?, TRUE) RETURNING id"
        );
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    } catch (PDOException $exception) {
        $statement = $pdo->prepare(
            'SELECT id FROM folders WHERE is_personal = TRUE AND parent_id IS NULL AND created_by = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $folderId = (int) $statement->fetchColumn();
        if ($folderId > 0) {
            return $folderId;
        }
        throw $exception;
    }
}

set_exception_handler(function (Throwable $error): void {
    error_log('[GCFiles] ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $debug = gc_env('GC_DEBUG') === '1';
    $message = $debug ? $error->getMessage() : 'Внутренняя ошибка. Попробуйте обновить страницу.';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Ошибка — ' . GC_APP_NAME . '</title>'
        . '<link rel="stylesheet" href="assets/css/tokens.css">'
        . '<link rel="stylesheet" href="assets/css/components.css">'
        . '<link rel="stylesheet" href="assets/css/base.css">'
        . '<link rel="stylesheet" href="assets/css/app.css">'
        . '<link rel="stylesheet" href="assets/css/nocturne.css"></head><body class="gc_body">'
        . '<main class="gc_main"><div class="gc_wrap"><div class="gc_hero">'
        . '<div><div class="gc_hero_eyebrow">Ошибка</div><h1 class="gc_h1">Что-то сломалось</h1>'
        . '<p class="gc_hero_lead">' . gc_h($message) . '</p>'
        . '<div class="gc_btn-row gc_btn-row--space"><a class="gc_btn gc_btn--primary" href="changelog.php">На главную</a></div>'
        . '</div></div></div></main></body></html>';
    exit;
});
