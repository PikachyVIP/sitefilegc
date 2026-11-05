<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = gc_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    gc_redirect('files.php');
}

gc_csrf_check();

$upload_dir = __DIR__ . '/uploads';
$all_tags = $pdo->query('SELECT name FROM tags ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

if (!gc_ensure_data_dir($upload_dir)) {
    gc_redirect('files.php?error=2');
}

if (!isset($_FILES['userfile']) || !is_array($_FILES['userfile'])
    || (int) ($_FILES['userfile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    gc_redirect('files.php?error=1');
}

$tmp_name = (string) $_FILES['userfile']['tmp_name'];
$size = (int) ($_FILES['userfile']['size'] ?? 0);
$max = gc_max_upload();
if (!is_uploaded_file($tmp_name) || $size <= 0 || ($max > 0 && $size > $max)) {
    gc_redirect('files.php?error=1');
}

$original_name = gc_safe_upload_name((string) ($_FILES['userfile']['name'] ?? 'file'));
// No extension at all: an uploaded file must never be executable, even when
// the web server ignores uploads/.htaccess.
$safe_name = bin2hex(random_bytes(16));
$target = $upload_dir . '/' . $safe_name;

if (!move_uploaded_file($tmp_name, $target)) {
    gc_redirect('files.php?error=2');
}

try {
    $stmt = $pdo->prepare('INSERT INTO files (filename, original_name, uploader_id) VALUES (?, ?, ?) RETURNING id');
    $stmt->execute([$safe_name, $original_name, (int) $user['id']]);
    $file_id = (int) $stmt->fetchColumn();

    if (isset($_POST['tags']) && is_array($_POST['tags'])) {
        $stmt_tag = $pdo->prepare('INSERT INTO file_tags (file_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?)) ON CONFLICT DO NOTHING');
        foreach ($_POST['tags'] as $tag) {
            if (is_string($tag) && in_array($tag, $all_tags, true)) {
                $stmt_tag->execute([$file_id, $tag]);
            }
        }
    }

    if (!empty($_POST['logins']) && is_string($_POST['logins'])) {
        $logins = array_map('trim', explode(',', $_POST['logins']));
        $stmt_access = $pdo->prepare('INSERT INTO file_access (file_id, user_id) VALUES (?, (SELECT id FROM users WHERE login = ?)) ON CONFLICT DO NOTHING');
        foreach ($logins as $login) {
            if ($login !== '') {
                $stmt_access->execute([$file_id, $login]);
            }
        }
    }
} catch (Throwable $e) {
    @unlink($target);
    gc_redirect('files.php?error=2');
}

gc_redirect('files.php?uploaded=1');
