<?php
declare(strict_types=1);

/**
 * JSON API for the converter page. Every action is scoped to the logged-in GC
 * user; job ids are validated by converter_lib.php before they touch the disk.
 */

define('GC_JSON_ERRORS', true);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/converter_lib.php';
require_once __DIR__ . '/converter_engine.php';

function gc_api_out(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gc_api_fail(string $message, int $code = 400): never
{
    gc_api_out(['error' => $message], $code);
}

function gc_api_job(int $userId, string $id): array
{
    try {
        $dir = gc_conv_job_dir($userId, $id);
    } catch (InvalidArgumentException) {
        gc_api_fail('Некорректная задача.', 400);
    }
    $job = gc_conv_job_load($dir);
    if ($job === null) {
        gc_api_fail('Задача не найдена.', 404);
    }

    return [$dir, $job];
}

if (($_GET['action'] ?? '') === 'health') {
    $tools = gc_conv_tools();
    $catalog = gc_conv_catalog();
    $formats = 0;
    $groups = [];
    foreach ($catalog as $key => $group) {
        $count = count($group['outputs']);
        $formats += $count;
        $groups[$key] = $count;
    }
    gc_api_out([
        'ok' => $formats > 0,
        'formats' => $formats,
        'groups' => $groups,
        'gd' => function_exists('imagecreatetruecolor'),
        'ffmpeg' => $tools['ffmpeg'] !== null,
        'exec' => function_exists('exec'),
        'proc_open' => function_exists('proc_open'),
        'max_upload' => gc_conv_max_upload(),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
    ]);
}

if (($_GET['action'] ?? '') === 'selftest'
    && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    $out = sys_get_temp_dir() . '/gc-selftest-' . bin2hex(random_bytes(4)) . '.webp';
    $log = $out . '.log';
    try {
        gc_conv_do_image(__DIR__ . '/assets/img/logo.png', 'png', $out, 'webp', 'medium', $log);
        gc_api_out(['ok' => true, 'webp_bytes' => (int) filesize($out)]);
    } catch (Throwable $e) {
        gc_api_out(['ok' => false, 'error' => $e->getMessage()], 500);
    } finally {
        @unlink($out);
        @unlink($log);
    }
}

$user = gc_user();
if ($user === null) {
    gc_api_fail('Требуется вход.', 401);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$writeActions = ['upload', 'convert', 'delete'];

if (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        gc_api_fail('Метод не поддерживается.', 405);
    }
    gc_csrf_check();
}

try {
    switch ($action) {
        case 'upload':
            if (!isset($_FILES['file'])) {
                gc_api_fail('Файл не выбран.');
            }
            $file = $_FILES['file'];
            $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($code !== UPLOAD_ERR_OK) {
                gc_api_fail(gc_upload_error($code));
            }
            $job = gc_conv_job_create((int) $user['id'], $file);
            gc_api_out([
                'job' => gc_conv_public_job($job),
                'formats' => gc_conv_format_payload((string) $job['input_ext']),
            ]);

        case 'convert':
            $id = (string) ($_POST['id'] ?? '');
            [$dir, $job] = gc_api_job((int) $user['id'], $id);

            if (in_array((string) $job['status'], ['queued', 'running'], true)) {
                gc_api_fail('Эта задача уже выполняется.', 409);
            }

            $format = strtolower((string) ($_POST['format'] ?? ''));
            $group = gc_conv_group_for_ext((string) $job['input_ext']);
            if ($group === null || !isset($group['outputs'][$format])) {
                gc_api_fail('Этот формат недоступен для файла.');
            }

            $spec = $group['outputs'][$format];
            $quality = (string) ($_POST['quality'] ?? '');
            $choices = gc_conv_quality_choices($spec['quality'] ?? null);
            if ($choices !== []) {
                $allowed = array_column($choices, 'value');
                if (!in_array($quality, $allowed, true)) {
                    $quality = $allowed[1] ?? $allowed[0];
                }
            } else {
                $quality = '';
            }

            $job = gc_conv_job_update($dir, [
                'status' => 'queued',
                'format' => $format,
                'quality' => $quality !== '' ? $quality : null,
                'output' => null,
                'output_name' => null,
                'output_size' => null,
                'error' => null,
                'started' => null,
                'finished' => null,
            ]);

            // If the host forbids detached processes, run in-request instead of
            // leaving the job stuck in "queued" forever.
            if (!gc_conv_spawn($dir)) {
                $job = gc_conv_run_job($dir) ?? $job;
                gc_api_out(['job' => gc_conv_public_job($job), 'inline' => true]);
            }

            gc_api_out(['job' => gc_conv_public_job($job), 'inline' => false]);

        case 'status':
            $id = (string) ($_GET['id'] ?? '');
            [, $job] = gc_api_job((int) $user['id'], $id);
            gc_api_out(['job' => gc_conv_public_job($job)]);

        case 'history':
            gc_api_out(['jobs' => gc_conv_history((int) $user['id'])]);

        case 'delete':
            $id = (string) ($_POST['id'] ?? '');
            [$dir, $job] = gc_api_job((int) $user['id'], $id);
            if (in_array((string) $job['status'], ['queued', 'running'], true)) {
                gc_api_fail('Нельзя удалить задачу, пока она выполняется.', 409);
            }
            gc_conv_rmdir($dir);
            gc_api_out(['ok' => true]);

        case 'download':
        case 'view':
            $id = (string) ($_GET['id'] ?? '');
            [$dir, $job] = gc_api_job((int) $user['id'], $id);
            if ((string) $job['status'] !== 'done' || empty($job['output'])) {
                gc_api_fail('Результат ещё не готов.', 404);
            }
            $path = $dir . '/' . (string) $job['output'];
            $real = realpath($path);
            $dirReal = realpath($dir);
            if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR) || !is_file($real)) {
                gc_api_fail('Файл результата не найден.', 404);
            }

            $name = (string) ($job['output_name'] ?? basename($real));
            $ext = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
            $inline = $action === 'view';
            if ($inline && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'pdf', 'txt', 'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'mp4', 'webm'], true)) {
                $inline = false;
            }

            $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $name) ?: 'file.' . $ext;
            header('Content-Type: ' . gc_conv_mime($ext));
            header('Content-Length: ' . (string) filesize($real));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($ascii, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($real);
            exit;

        default:
            gc_api_fail('Неизвестное действие.', 400);
    }
} catch (Throwable $error) {
    gc_api_fail($error->getMessage(), 400);
}
