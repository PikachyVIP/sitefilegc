<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = isset($_GET['t']) && is_string($_GET['t']) ? strtolower(trim($_GET['t'])) : '';
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    gc_flash('error', 'Ссылка на передачу повреждена.');
    gc_redirect('transfer.php');
}

$statement = $pdo->prepare(
    'SELECT id, filename, original_name, mime_type, file_size, expires_at FROM transfers WHERE token = ?'
);
$statement->execute([$token]);
$transfer = $statement->fetch();

if (!$transfer) {
    gc_flash('error', 'Передача не найдена или уже удалена.');
    gc_redirect('transfer.php');
}

if (strtotime((string) $transfer['expires_at']) <= time()) {
    gc_flash('error', 'Срок хранения файла истёк.');
    gc_redirect('transfer.php?t=' . $token);
}

$stored = (string) $transfer['filename'];
if ($stored === '' || str_contains($stored, '/') || str_contains($stored, '\\')) {
    gc_flash('error', 'Имя файла на сервере повреждено.');
    gc_redirect('transfer.php');
}

$path = __DIR__ . '/uploads/' . $stored;
if (!is_file($path)) {
    gc_flash('error', 'Файл передачи отсутствует на сервере.');
    gc_redirect('transfer.php');
}

$pdo->prepare('UPDATE transfers SET downloads = downloads + 1 WHERE id = ?')->execute([(int) $transfer['id']]);

$original = str_replace(["\r", "\n", '"'], '', (string) $transfer['original_name']);
if ($original === '') {
    $original = 'file';
}

$mime = (string) $transfer['mime_type'];
if ($mime === '') {
    $mime = 'application/octet-stream';
}

session_write_close();

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $original . '"; filename*=UTF-8\'\'' . rawurlencode($original));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

readfile($path);
exit;
