<?php
declare(strict_types=1);

/**
 * Resumable upload transport.
 *
 * The browser sends one raw slice per request. The server appends it to a
 * part file, and when the last byte arrives the part is moved to a staging
 * slot. storage.php then finalizes that slot through the normal upload path,
 * so folder/tag/access rules stay in one place.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$earlyId = $_GET['id'] ?? ($_SERVER['HTTP_X_GC_UPLOAD_ID'] ?? '');
$GLOBALS['gc_chunk_id'] = is_string($earlyId) && preg_match('/^[a-f0-9]{32}$/', $earlyId) ? $earlyId : null;
$GLOBALS['gc_chunk_started'] = microtime(true);
$GLOBALS['gc_chunk_log'] = false;

register_shutdown_function(static function (): void {
    if (empty($GLOBALS['gc_chunk_log'])) {
        return;
    }
    $error = error_get_last();
    if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    gc_upload_log('chunk_fatal', [
        'id' => $GLOBALS['gc_chunk_id'] ?? null,
        'message' => substr((string) ($error['message'] ?? ''), 0, 500),
    ]);
});

function gc_chunk_json(array $payload, int $status = 200): void
{
    if (!empty($GLOBALS['gc_chunk_log'])) {
        gc_upload_log('chunk_response', [
            'id' => $GLOBALS['gc_chunk_id'] ?? null,
            'status' => $status,
            'ok' => isset($payload['ok']) ? (bool) $payload['ok'] : null,
            'error' => isset($payload['error']) ? (string) $payload['error'] : '',
            'offset' => isset($payload['offset']) ? (int) $payload['offset'] : null,
            'done' => isset($payload['done']) ? (bool) $payload['done'] : null,
            'deleted' => isset($payload['deleted']) ? (bool) $payload['deleted'] : null,
            'duration_ms' => isset($GLOBALS['gc_chunk_started']) ? (int) round((microtime(true) - $GLOBALS['gc_chunk_started']) * 1000) : null,
        ]);
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = gc_user();
if ($user === null) {
    gc_chunk_json(['ok' => false, 'error' => 'Требуется вход.'], 401);
}
$uid = (int) $user['id'];

$sentCsrf = $_SERVER['HTTP_X_GC_CSRF'] ?? '';
if (!is_string($sentCsrf) || $sentCsrf === '' || !hash_equals(gc_csrf_token(), $sentCsrf)) {
    gc_chunk_json(['ok' => false, 'error' => 'Сессия истекла. Обновите страницу.'], 419);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    gc_chunk_json(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

// Only authenticated, CSRF-checked requests write server logs. Logging every
// rejected probe would let an anonymous client fill the disk.
$GLOBALS['gc_chunk_log'] = true;
gc_upload_log('chunk_begin', [
    'id' => $GLOBALS['gc_chunk_id'],
    'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
    'offset' => isset($_SERVER['HTTP_X_GC_OFFSET']) ? (int) $_SERVER['HTTP_X_GC_OFFSET'] : null,
    'total' => isset($_SERVER['HTTP_X_GC_TOTAL']) ? (int) $_SERVER['HTTP_X_GC_TOTAL'] : null,
]);

$uploadId = $_GET['id'] ?? ($_SERVER['HTTP_X_GC_UPLOAD_ID'] ?? '');
if (!is_string($uploadId) || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
    gc_chunk_json(['ok' => false, 'error' => 'Некорректный идентификатор загрузки.'], 400);
}
$GLOBALS['gc_chunk_id'] = $uploadId;

$partsDir = __DIR__ . '/uploads/.parts';
$stageDir = __DIR__ . '/uploads/.staging';
if (!gc_ensure_data_dir($partsDir) || !gc_ensure_data_dir($stageDir)) {
    gc_chunk_json(['ok' => false, 'error' => 'Временная папка недоступна для записи.'], 500);
}

$partPath = $partsDir . '/' . $uploadId . '.part';
$metaPath = $partsDir . '/' . $uploadId . '.json';
$lockPath = $partsDir . '/' . $uploadId . '.lock';
$stagePath = $stageDir . '/' . $uploadId . '.bin';
$stageMetaPath = $stageDir . '/' . $uploadId . '.json';

$readMeta = static function (string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
};

$lock = fopen($lockPath, 'c');
if ($lock === false) {
    gc_chunk_json(['ok' => false, 'error' => 'Не удалось создать блокировку загрузки.'], 500);
}
flock($lock, LOCK_EX);

try {
    if ($method === 'GET') {
        $stageMeta = $readMeta($stageMetaPath);
        if ($stageMeta !== null
            && (int) ($stageMeta['uid'] ?? 0) === $uid
            && is_file($stagePath)
        ) {
            gc_chunk_json([
                'ok' => true,
                'done' => true,
                'staged' => $uploadId,
                'offset' => (int) ($stageMeta['size'] ?? filesize($stagePath)),
                'total' => (int) ($stageMeta['size'] ?? filesize($stagePath)),
            ]);
        }

        $meta = $readMeta($metaPath);
        if ($meta === null || (int) ($meta['uid'] ?? 0) !== $uid) {
            gc_chunk_json(['ok' => false, 'error' => 'Загрузка не найдена.'], 404);
        }
        $offset = is_file($partPath) ? (int) filesize($partPath) : 0;
        $total = (int) ($meta['size'] ?? 0);
        if ($offset >= $total) {
            gc_chunk_json([
                'ok' => true,
                'done' => true,
                'staged' => $uploadId,
                'offset' => $total,
                'total' => $total,
            ]);
        }
        gc_chunk_json(['ok' => true, 'done' => false, 'offset' => $offset, 'total' => $total]);
    }

    if ($method === 'DELETE') {
        $partMeta = $readMeta($metaPath);
        $stageMeta = $readMeta($stageMetaPath);
        if ($partMeta !== null && (int) ($partMeta['uid'] ?? 0) !== $uid) {
            gc_chunk_json(['ok' => false, 'error' => 'Загрузка принадлежит другому пользователю.'], 403);
        }
        if ($stageMeta !== null && (int) ($stageMeta['uid'] ?? 0) !== $uid) {
            gc_chunk_json(['ok' => false, 'error' => 'Загрузка принадлежит другому пользователю.'], 403);
        }
        if ($partMeta === null && $stageMeta === null) {
            gc_chunk_json(['ok' => true, 'deleted' => false]);
        }

        @unlink($partPath);
        @unlink($metaPath);
        @unlink($stagePath);
        @unlink($stageMetaPath);
        gc_chunk_json(['ok' => true, 'deleted' => true]);
    }

    $offset = isset($_SERVER['HTTP_X_GC_OFFSET']) ? (int) $_SERVER['HTTP_X_GC_OFFSET'] : -1;
    $total = isset($_SERVER['HTTP_X_GC_TOTAL']) ? (int) $_SERVER['HTTP_X_GC_TOTAL'] : 0;
    $meta = $readMeta($metaPath);

    if ($meta === null) {
        if ($offset !== 0) {
            gc_chunk_json(['ok' => false, 'error' => 'Часть загрузки потеряна. Начните файл заново.', 'restart' => true], 409);
        }

        $encoded = $_SERVER['HTTP_X_GC_META'] ?? '';
        $decoded = is_string($encoded) && $encoded !== ''
            ? base64_decode(strtr($encoded, '-_', '+/'), true)
            : false;
        $incoming = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($incoming)) {
            gc_chunk_json(['ok' => false, 'error' => 'Не переданы данные файла.'], 400);
        }

        $name = isset($incoming['name']) && is_string($incoming['name'])
            ? gc_safe_upload_name($incoming['name'])
            : '';
        $relPath = isset($incoming['path']) && is_string($incoming['path']) && $incoming['path'] !== ''
            ? $incoming['path']
            : $name;
        $parts = gc_upload_path_parts($relPath);
        if ($parts === []) {
            gc_chunk_json(['ok' => false, 'error' => 'Некорректное имя файла.'], 400);
        }
        $name = gc_safe_upload_name((string) array_pop($parts));
        $relPath = implode('/', array_merge($parts, [$name]));

        $total = isset($incoming['size']) ? (int) $incoming['size'] : $total;
        gc_upload_log('chunk_meta', [
            'id' => $uploadId,
            'name' => $name,
            'path' => $relPath,
            'size' => $total,
        ]);
        $max = gc_max_upload();
        if ($total <= 0) {
            gc_chunk_json(['ok' => false, 'error' => 'Пустой файл загрузить нельзя.'], 400);
        }
        if ($max > 0 && $total > $max) {
            gc_chunk_json(['ok' => false, 'error' => 'Файл больше допустимого размера ' . gc_size($max) . '.'], 413);
        }

        $meta = [
            'uid' => $uid,
            'name' => $name,
            'path' => $relPath,
            'size' => $total,
            'created' => time(),
        ];
        $tmpMeta = $metaPath . '.tmp';
        if (@file_put_contents($tmpMeta, json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX) === false
            || !@rename($tmpMeta, $metaPath)
        ) {
            @unlink($tmpMeta);
            gc_chunk_json(['ok' => false, 'error' => 'Не удалось сохранить состояние загрузки.'], 500);
        }
    } else {
        if ((int) ($meta['uid'] ?? 0) !== $uid) {
            gc_chunk_json(['ok' => false, 'error' => 'Загрузка принадлежит другому пользователю.'], 403);
        }
        $total = (int) ($meta['size'] ?? 0);
    }

    if ($total <= 0) {
        gc_chunk_json(['ok' => false, 'error' => 'Некорректный размер файла.'], 400);
    }

    $current = is_file($partPath) ? (int) filesize($partPath) : 0;
    if ($offset !== $current) {
        gc_chunk_json([
            'ok' => false,
            'error' => 'Смещение не совпадает с сервером.',
            'offset' => $current,
            'total' => $total,
        ], 409);
    }

    if ($current > $total) {
        gc_chunk_json(['ok' => false, 'error' => 'Размер файла изменился.', 'restart' => true], 409);
    }

    if ($current < $total) {
        $input = fopen('php://input', 'rb');
        $output = fopen($partPath, 'ab');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            gc_chunk_json(['ok' => false, 'error' => 'Не удалось открыть временный файл.'], 500);
        }
        $written = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if ($written === false || $written <= 0) {
            gc_chunk_json(['ok' => false, 'error' => 'Пустой фрагмент загрузки.', 'offset' => $current, 'total' => $total], 400);
        }

        $newSize = (int) filesize($partPath);
        if ($newSize > $total) {
            $handle = fopen($partPath, 'r+');
            if ($handle !== false) {
                ftruncate($handle, $current);
                fclose($handle);
            }
            gc_chunk_json(['ok' => false, 'error' => 'Фрагмент больше оставшегося размера файла.', 'offset' => $current, 'total' => $total], 413);
        }
        $current = $newSize;
    }

    if ($current === $total) {
        @unlink($stagePath);
        @unlink($stageMetaPath);
        if (!@rename($partPath, $stagePath)) {
            gc_chunk_json(['ok' => false, 'error' => 'Не удалось подготовить файл к сохранению.'], 500);
        }
        $stageMeta = [
            'uid' => $uid,
            'name' => (string) ($meta['name'] ?? 'file'),
            'path' => (string) ($meta['path'] ?? $meta['name'] ?? 'file'),
            'size' => $total,
            'created' => time(),
        ];
        if (@file_put_contents($stageMetaPath, json_encode($stageMeta, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            @rename($stagePath, $partPath);
            gc_chunk_json(['ok' => false, 'error' => 'Не удалось сохранить данные файла.'], 500);
        }
        @unlink($metaPath);

        gc_chunk_json([
            'ok' => true,
            'done' => true,
            'staged' => $uploadId,
            'offset' => $total,
            'total' => $total,
        ]);
    }

    gc_chunk_json(['ok' => true, 'done' => false, 'offset' => $current, 'total' => $total]);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
