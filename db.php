<?php
$config_path = __DIR__ . '/../../../../../../var/db_config/configfiletransfer.ini';
$db_config_name = 'gc_database_api';

if (!file_exists($config_path)) {
    throw new Exception("Config file not found at: " . $config_path . "\n", 1001);
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

$required_keys = ['host', 'port', 'database', 'user_name', 'user_password'];
foreach ($required_keys as $key) {
    if (!isset($db_config[$key]) || empty(trim($db_config[$key]))) {
        throw new Exception("Missing or empty database configuration: {$key}");
    }
}
if (!filter_var($db_config['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
    throw new Exception("Invalid port number: {$db_config['port']}");
}

try {
    $_options_db = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $_form_db = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
        $db_config['host'],
        $db_config['port'],
        $db_config['database']
    );

    $pdo = new PDO($_form_db, $db_config['user_name'], $db_config['user_password'], $_options_db);

    // Кодировка отдельным запросом (без MYSQL_ATTR_INIT_COMMAND)
    $pdo->exec("SET NAMES utf8mb4");

} catch (PDOException $e) {
    die('Ошибка подключения к БД: ' . $e->getMessage());
}
?>