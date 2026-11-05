<?php
/**
 * Подключение к PostgreSQL.
 *
 * Настройки берутся из внешнего INI-файла (секция gc_database_api):
 *   host, port (по умолчанию 5432), database, user_name, user_password
 * Дополнительно можно указать:
 *   sslmode          — disable | allow | prefer | require
 *   timezone         — например Europe/Moscow (необязательно)
 *   update_base_url  — абсолютный адрес сайта для ссылок в API обновлений (необязательно)
 *
 * Путь к конфигу можно переопределить переменной окружения GC_DB_CONFIG,
 * имя секции — переменной GC_DB_SECTION.
 */

// Признак того, что ответ должен быть в формате JSON (ставится JSON-скриптами до подключения).
if (!defined('GC_JSON_ERRORS')) {
    define('GC_JSON_ERRORS', false);
}

/**
 * Значение переменной окружения.
 *
 * getenv() не видит директивы SetEnv под mod_php (они попадают в $_SERVER),
 * поэтому смотрим оба места: так конфиг можно задать и в vhost, и в пуле php-fpm.
 */
function gc_env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = $_SERVER[$name] ?? '';
    }

    return is_string($value) ? trim($value) : '';
}

$config_path = gc_env('GC_DB_CONFIG') ?: '/../../../../../../var/db/configfile.ini';
$db_config_name = gc_env('GC_DB_SECTION') ?: 'gc_database_api';

/**
 * Аккуратно сообщаем об ошибке в зависимости от того, какой ответ ждёт клиент.
 */
function gc_fail(string $message, int $code = 503): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: ' . (GC_JSON_ERRORS ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));
        header('Cache-Control: no-store');
    }

    if (GC_JSON_ERRORS) {
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'Ошибка: ' . $message;
    }
    exit;
}

set_exception_handler(function (Throwable $error): void {
    error_log('[GCFiles] ' . $error->getMessage());
    $debug = gc_env('GC_DEBUG') === '1';
    gc_fail($debug ? $error->getMessage() : 'Сервис временно недоступен. Попробуйте позже.');
});

if (!file_exists($config_path)) {
    throw new Exception("Config file not found at: " . $config_path);
}

$config = parse_ini_file($config_path, true, INI_SCANNER_TYPED);

if ($config === false) {
    $error = error_get_last();
    throw new RuntimeException("Failed to parse INI file: {$config_path}. " . ($error['message'] ?? 'Unknown error'));
}

if (!isset($config[$db_config_name])) {
    throw new Exception("No database section in config");
}

$db_config = $config[$db_config_name];

$required_keys = ['host', 'database', 'user_name', 'user_password'];
foreach ($required_keys as $key) {
    if (!isset($db_config[$key]) || trim((string) $db_config[$key]) === '') {
        throw new Exception("Missing or empty database configuration: {$key}");
    }
}

// Порт PostgreSQL по умолчанию — 5432.
$port = 5432;
if (isset($db_config['port']) && trim((string) $db_config['port']) !== '') {
    $port = (int) $db_config['port'];
}
if ($port < 1 || $port > 65535) {
    throw new Exception("Invalid port number: {$db_config['port']}");
}

try {
    $_options_db = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $_form_db = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $db_config['host'],
        $port,
        $db_config['database']
    );

    if (!empty($db_config['sslmode'])) {
        $_form_db .= ';sslmode=' . $db_config['sslmode'];
    }

    $pdo = new PDO($_form_db, $db_config['user_name'], $db_config['user_password'], $_options_db);

    // Кодировка клиента (аналог прежнего SET NAMES utf8mb4).
    $pdo->exec("SET NAMES 'UTF8'");

    if (!empty($db_config['timezone'])) {
        $timezone = preg_replace('/[^A-Za-z0-9_\/+\-]/', '', (string) $db_config['timezone']);
        if ($timezone !== '') {
            $pdo->exec("SET TIME ZONE '{$timezone}'");
        }
    }
} catch (PDOException $e) {
    throw new RuntimeException('Не удалось подключиться к PostgreSQL: ' . $e->getMessage(), 1002, $e);
}

/**
 * Абсолютный адрес каталога сайта (для ссылок в API обновлений).
 */
function gc_base_url(): string
{
    global $db_config;

    if (!empty($db_config['update_base_url'])) {
        return rtrim((string) $db_config['update_base_url'], '/');
    }
    $configured = gc_env('GC_BASE_URL');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    // Do not build public links from HTTP_HOST: it is caller-controlled and
    // poisons share/update URLs. Set update_base_url (or GC_BASE_URL) when the
    // site is served behind a proxy or on a hostname different from SERVER_NAME.
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
