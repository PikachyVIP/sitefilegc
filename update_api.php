<?php
/**
 * Публичный API автообновления приложения.
 *
 * GET-параметры:
 *   channel — stable (по умолчанию) | beta
 *   current — версия клиента, например 1.0.6 (необязательно)
 *   app     — slug приложения (необязательно); без него используется самое
 *             старое приложение, то есть прежнее поведение
 *
 * Ответ (JSON):
 *   {
 *     "ok": true,
 *     "channel": "stable",
 *     "app": { "slug": "gc-twitch-manager", "name": "GC Twitch Manager" },
 *     "current": "1.0.6",
 *     "update_available": true,
 *     "latest": {
 *       "version": "1.0.7",
 *       "notes": "что нового",
 *       "mandatory": false,
 *       "size": 35661073,
 *       "sha256": "…",
 *       "kind": "msi",
 *       "file_name": "GC Twitch Manager-1.0.7.msi",
 *       "published_at": "2025-01-01 12:00:00",
 *       "url": "https://site/updates/…/update_download.php?id=3"
 *     }
 *   }
 *
 * Авторизация не требуется: приложение должно уметь проверять обновления
 * до входа в Twitch.
 */

define('GC_JSON_ERRORS', true);

require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$channel = (string) ($_GET['channel'] ?? 'stable');
if (!in_array($channel, ['stable', 'beta'], true)) {
    $channel = 'stable';
}

$current = trim((string) ($_GET['current'] ?? ''));
// Оставляем только цифры, точки, дефис и буквы — защита от мусора в ответе.
$current = preg_replace('/[^0-9A-Za-z.\-]/', '', $current) ?? '';

$appSlug = isset($_GET['app']) && is_string($_GET['app']) ? trim($_GET['app']) : '';
$app = null;
$explicitApp = $appSlug !== '';

if ($explicitApp) {
    $stmt = $pdo->prepare('SELECT id, slug, name FROM apps WHERE slug = ? AND is_active');
    $stmt->execute([$appSlug]);
    $app = $stmt->fetch() ?: null;
    if ($app === null) {
        http_response_code(404);
        echo json_encode(
            ['ok' => false, 'error' => 'unknown_app', 'app' => $appSlug],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
} else {
    // Прежнее поведение: без ?app отвечаем за приложение по умолчанию —
    // самое старое в таблице. Строки со старым app_id = NULL считаются его же.
    $app = $pdo->query('SELECT id, slug, name FROM apps ORDER BY id LIMIT 1')->fetch() ?: null;
}

$sql = 'SELECT id, version, original_name, file_size, sha256, notes, published_at,
               CASE WHEN is_mandatory THEN 1 ELSE 0 END AS is_mandatory
        FROM app_releases
        WHERE channel = ? AND is_active';
$params = [$channel];

if ($app !== null) {
    if ($explicitApp) {
        $sql .= ' AND app_id = ?';
    } else {
        $sql .= ' AND (app_id = ? OR app_id IS NULL)';
    }
    $params[] = (int) $app['id'];
} else {
    $sql .= ' AND app_id IS NULL';
}

$sql .= ' ORDER BY published_at DESC, id DESC LIMIT 1';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$release = $stmt->fetch();

$payload = [
    'ok' => true,
    'channel' => $channel,
    'app' => $app === null ? null : ['slug' => (string) $app['slug'], 'name' => (string) $app['name']],
    'current' => $current,
    'update_available' => false,
    'latest' => null,
];

if ($release) {
    $version = (string) $release['version'];
    $extension = strtolower(pathinfo((string) $release['original_name'], PATHINFO_EXTENSION));

    // Если клиент не сообщил версию — считаем, что обновление ему подходит.
    $update_available = $current === '' || version_compare($version, $current, '>');

    $payload['update_available'] = $update_available;
    $payload['latest'] = [
        'version' => $version,
        'notes' => (string) $release['notes'],
        'mandatory' => ((int) $release['is_mandatory']) === 1,
        'size' => (int) $release['file_size'],
        'sha256' => strtolower((string) $release['sha256']),
        'kind' => $extension !== '' ? $extension : 'msi',
        'file_name' => (string) $release['original_name'],
        'published_at' => (string) $release['published_at'],
        'url' => gc_base_url() . '/update_download.php?id=' . (int) $release['id'],
    ];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
