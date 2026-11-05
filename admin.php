<?php
define('GC_LEGACY_CSP', true);
require_once __DIR__ . '/bootstrap.php';

// Проверка прав на доступ к админ-панели
if (!gc_is_admin()) {
    gc_redirect('index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    gc_csrf_check();
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
$message = '';
$error = '';

// Полный список прав и их подписи (используется и в обработчиках, и в вёрстке)
$all_rights_list = [
    'admin_panel',
    'create_users',
    'manage_rights',
    'create_delete_tags',
    'edit_user_tags',
    'edit_any_file',
    'view_any_file',
    'delete_any_file',
    'pin_files',
    'manage_updates'
];
// The superadmin (id = 1) has every right even when admin_rights is empty.
if ((int) ($_SESSION['user_id'] ?? 0) === 1) {
    $admin_rights = $all_rights_list;
}

$rights_labels = [
    'admin_panel' => 'Админ-меню',
    'create_users' => 'Создание пользователей',
    'manage_rights' => 'Выдача прав',
    'create_delete_tags' => 'Создание/удаление тегов',
    'edit_user_tags' => 'Изменять теги пользователям',
    'edit_any_file' => 'Изменять теги чужим файлам',
    'view_any_file' => 'Видеть недоступные файлы',
    'delete_any_file' => 'Удалять чужие файлы',
    'pin_files' => 'Прикреплять файлы',
    'manage_updates' => 'Обновления приложения'
];

/**
 * Размер в удобочитаемом виде.
 */
function format_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' ГБ';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' МБ';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' КБ';
    }
    return $bytes . ' Б';
}

/**
 * Значение из php.ini вида "64M" в байтах.
 */
function ini_size(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $number = (int) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            return $number * 1073741824;
        case 'm':
            return $number * 1048576;
        case 'k':
            return $number * 1024;
        default:
            return $number;
    }
}

/**
 * Текст ошибки загрузки файла.
 */
function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'файл больше upload_max_filesize (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_FORM_SIZE:
            return 'файл больше MAX_FILE_SIZE, указанного в форме';
        case UPLOAD_ERR_PARTIAL:
            return 'файл загрузился не полностью';
        case UPLOAD_ERR_NO_FILE:
            return 'файл не выбран';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'на сервере нет временного каталога';
        case UPLOAD_ERR_CANT_WRITE:
            return 'не удалось записать файл на диск';
        case UPLOAD_ERR_EXTENSION:
            return 'загрузку остановило расширение PHP';
        default:
            return 'неизвестная ошибка загрузки';
    }
}

// Если файл превысил post_max_size, PHP приходит с пустыми $_POST и $_FILES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = '❌ Файл слишком большой: post_max_size = ' . ini_get('post_max_size')
        . '. Увеличьте upload_max_filesize и post_max_size в php.ini.';
}

// === СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ (только с правом create_users) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user' && in_array('create_users', $admin_rights)) {
    $new_login = trim($_POST['new_login'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $new_tags = $_POST['new_tags'] ?? [];
    $new_rights = $_POST['new_rights'] ?? [];
    
    if ($new_login === '' || $new_password === '') {
        $error = 'Логин и пароль обязательны!';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$new_login]);
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким логином уже существует!';
        } else {
            $pdo->beginTransaction();
            try {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (login, password) VALUES (?, ?) RETURNING id');
                $stmt->execute([$new_login, $password_hash]);
                $new_user_id = (int) $stmt->fetchColumn();
                
                // Теги
                if (!empty($new_tags)) {
                    $stmt_tag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
                    foreach ($new_tags as $tag) {
                        $stmt_tag->execute([$new_user_id, $tag]);
                    }
                }
                
                // Права администратора
                if (!empty($new_rights)) {
                    $stmt_right = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?)');
                    foreach ($new_rights as $right) {
                        $stmt_right->execute([$new_user_id, $right]);
                    }
                }
                
                $pdo->commit();
                $message = "✅ Пользователь «{$new_login}» создан!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка создания пользователя: ' . $e->getMessage();
            }
        }
    }
}

// === СОЗДАНИЕ ТЕГА (только с правом create_delete_tags) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tag' && in_array('create_delete_tags', $admin_rights)) {
    $new_tag = trim($_POST['new_tag'] ?? '');
    
    if ($new_tag === '') {
        $error = 'Название тега обязательно!';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$new_tag]);
        if ($stmt->fetch()) {
            $error = 'Тег с таким названием уже существует!';
        } else {
            $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
            $stmt->execute([$new_tag]);
            $message = "✅ Тег «{$new_tag}» создан!";
        }
    }
}

// === СОХРАНЕНИЕ ТЕГОВ ПОЛЬЗОВАТЕЛЕЙ (edit_user_tags) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tags') {
    if (!in_array('edit_user_tags', $admin_rights)) {
        $error = '❌ У вас нет прав на изменение тегов пользователей!';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM user_tags');
            if (isset($_POST['user_tags']) && is_array($_POST['user_tags'])) {
                $stmt = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
                foreach ($_POST['user_tags'] as $user_id => $tags) {
                    if (is_array($tags)) {
                        foreach ($tags as $tag) {
                            $stmt->execute([(int)$user_id, $tag]);
                        }
                    }
                }
            }
            $pdo->commit();
            $message = '✅ Теги сохранены!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = '❌ Ошибка сохранения тегов!';
        }
    }
}

// === СОХРАНЕНИЕ ПРАВ ПОЛЬЗОВАТЕЛЕЙ (manage_rights) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rights' && in_array('manage_rights', $admin_rights)) {
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM admin_rights');
        // Гарантируем, что админ всегда имеет все права
        $stmt_ensure = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (1, ?) ON CONFLICT (user_id, right_name) DO NOTHING');
        foreach ($all_rights_list as $r) {
            $stmt_ensure->execute([$r]);
        }
        
        if (isset($_POST['user_rights']) && is_array($_POST['user_rights'])) {
            $stmt = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?)');
            foreach ($_POST['user_rights'] as $user_id => $rights) {
                if (is_array($rights) && (int)$user_id != 1) {
                    foreach ($rights as $right) {
                        $stmt->execute([(int)$user_id, $right]);
                    }
                }
            }
        }
        $pdo->commit();
        $message = '✅ Права сохранены!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = '❌ Ошибка сохранения прав!';
    }
}

// === ЗАГРУЗКА ОБНОВЛЕНИЯ ПРИЛОЖЕНИЯ (manage_updates) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_release') {
    if (!in_array('manage_updates', $admin_rights)) {
        $error = '❌ У вас нет прав на публикацию обновлений!';
    } else {
        $release_version = trim($_POST['release_version'] ?? '');
        $release_channel = ($_POST['release_channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
        $release_notes = trim($_POST['release_notes'] ?? '');
        $release_mandatory = isset($_POST['release_mandatory']);
        $file_error = (int) ($_FILES['release_file']['error'] ?? UPLOAD_ERR_NO_FILE);

        if (!preg_match('/^\d+(\.\d+){1,3}(-[0-9A-Za-z.\-]+)?$/', $release_version)) {
            $error = '❌ Версия должна быть в формате 1.0.7 или 1.0.7-beta!';
        } elseif ($file_error !== UPLOAD_ERR_OK) {
            $error = '❌ Файл обновления не загружен: ' . upload_error_text($file_error);
        } else {
            $original_name = basename((string) $_FILES['release_file']['name']);

            if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'msi') {
                $error = '❌ Нужен установщик в формате .msi';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM app_releases WHERE channel = ? AND version = ?');
                $stmt->execute([$release_channel, $release_version]);

                if ($stmt->fetch()) {
                    $error = "❌ Версия {$release_version} в канале {$release_channel} уже загружена!";
                } else {
                    $updates_dir = __DIR__ . '/updates';
                    if (!is_dir($updates_dir)) {
                        mkdir($updates_dir, 0755, true);
                    }

                    $safe_name = bin2hex(random_bytes(16)) . '.msi';
                    $target = $updates_dir . '/' . $safe_name;

                    if (!move_uploaded_file($_FILES['release_file']['tmp_name'], $target)) {
                        $error = '❌ Не удалось сохранить файл на сервере';
                    } else {
                        try {
                            $stmt = $pdo->prepare(
                                'INSERT INTO app_releases
                                    (version, channel, filename, original_name, file_size, sha256, notes, is_mandatory, published_by)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                 RETURNING id'
                            );
                            $stmt->execute([
                                $release_version,
                                $release_channel,
                                $safe_name,
                                $original_name,
                                (int) filesize($target),
                                hash_file('sha256', $target),
                                $release_notes,
                                $release_mandatory ? 'true' : 'false',
                                (int) $_SESSION['user_id'],
                            ]);
                            $stmt->fetchColumn();
                            $message = "✅ Обновление {$release_version} ({$release_channel}) опубликовано!";
                        } catch (Throwable $e) {
                            @unlink($target);
                            $error = '❌ Ошибка сохранения релиза: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// === УДАЛЕНИЕ РЕЛИЗА (manage_updates) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['delete_release']) && in_array('manage_updates', $admin_rights)) {
    $release_id = (int) $_POST['delete_release'];
    $stmt = $pdo->prepare('SELECT filename FROM app_releases WHERE id = ?');
    $stmt->execute([$release_id]);
    $release_file = $stmt->fetchColumn();

    if ($release_file) {
        $path = __DIR__ . '/updates/' . basename((string) $release_file);
        if (is_file($path)) {
            @unlink($path);
        }
        $pdo->prepare('DELETE FROM app_releases WHERE id = ?')->execute([$release_id]);
        $message = '✅ Релиз удалён!';
    }
}

// === ВКЛЮЧЕНИЕ/ОТКЛЮЧЕНИЕ РЕЛИЗА (manage_updates) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['toggle_release']) && in_array('manage_updates', $admin_rights)) {
    $pdo->prepare('UPDATE app_releases SET is_active = NOT is_active WHERE id = ?')->execute([(int) $_POST['toggle_release']]);
    $message = '✅ Статус релиза изменён!';
}

// === УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ (create_users) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['delete_user']) && in_array('create_users', $admin_rights)) {
    $delete_id = (int) $_POST['delete_user'];
    if ($delete_id != 1) {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$delete_id]);
        $message = '✅ Пользователь удалён!';
    } else {
        $error = '❌ Нельзя удалить главного админа!';
    }
}

// === УДАЛЕНИЕ ТЕГА (create_delete_tags) ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['delete_tag']) && in_array('create_delete_tags', $admin_rights)) {
    $delete_tag_id = (int) $_POST['delete_tag'];
    $stmt = $pdo->prepare('DELETE FROM tags WHERE id = ?');
    $stmt->execute([$delete_tag_id]);
    $message = '✅ Тег удалён!';
}

// === ПОЛУЧАЕМ ДАННЫЕ ===

// Все пользователи
$stmt = $pdo->query('SELECT id, login FROM users ORDER BY login');
$users = $stmt->fetchAll();

// Все теги
$stmt = $pdo->query('SELECT id, name FROM tags ORDER BY id');
$all_tags = $stmt->fetchAll();

// Теги пользователей
$user_tags_data = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('SELECT t.id, t.name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ?');
    $stmt->execute([$user['id']]);
    $user_tags_data[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
}

// Права пользователей
$user_rights_data = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $user_rights_data[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Релизы приложения (для автообновления)
$releases = [];
$releases_error = '';
try {
    $stmt = $pdo->query(
        'SELECT id, version, channel, original_name, file_size, sha256, notes, downloads, published_at,
                CASE WHEN is_mandatory THEN 1 ELSE 0 END AS is_mandatory,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
         FROM app_releases
         ORDER BY published_at DESC, id DESC'
    );
    $releases = $stmt->fetchAll();
} catch (Throwable $e) {
    $releases_error = '⚠ Таблица app_releases недоступна. Выполните setup.php или примените schema.sql.';
}

$upload_limit = ini_size((string) ini_get('upload_max_filesize'));
$post_limit = ini_size((string) ini_get('post_max_size'));
$can_upload = in_array('manage_updates', $admin_rights);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:,">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .rights-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .rights-grid label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            padding: 5px 12px;
            background: #0d0d0d;
            border: 1px solid #333;
            border-radius: 15px;
            font-size: 12px;
            color: #ccc;
            transition: all 0.2s;
        }
        .rights-grid label:hover {
            border-color: #c9a84c;
        }
        .rights-grid label:has(input:checked) {
            color: #c9a84c;
            border-color: #c9a84c;
            background: #1a1a00;
        }
    </style>
</head>
<body class="admin-page">
    <div class="container">
        <header class="header">
            <h1>⚙ Админ-панель</h1>
            <a href="files.php" class="back-btn">← Назад к файлам</a>
        </header>
        
        <?php if ($message): ?>
            <div class="success-message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($can_upload): ?>
        <div class="admin-block">
            <h2>⬆ Обновления приложения</h2>

            <?php if ($releases_error): ?>
                <div class="error-message"><?php echo htmlspecialchars($releases_error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="inline-form">
                <?php echo gc_csrf_field(); ?>
                <input type="hidden" name="action" value="upload_release">

                <div class="form-row">
                    <input type="text" name="release_version" placeholder="Версия, напр. 1.0.7" required>
                    <select name="release_channel">
                        <option value="stable">stable — все пользователи</option>
                        <option value="beta">beta — тестовый канал</option>
                    </select>
                </div>

                <div class="form-row">
                    <input type="file" name="release_file" accept=".msi" required>
                </div>

                <div class="form-row">
                    <textarea name="release_notes" rows="4"
                              placeholder="Что нового — этот текст покажется в приложении"></textarea>
                </div>

                <div class="form-row">
                    <label class="tag-checkbox">
                        <input type="checkbox" name="release_mandatory">
                        <span>Обязательное обновление (отложить нельзя)</span>
                    </label>
                </div>

                <small style="color:#666;font-size:11px;">
                    Лимиты PHP: upload_max_filesize = <?php echo htmlspecialchars((string) ini_get('upload_max_filesize')); ?>,
                    post_max_size = <?php echo htmlspecialchars((string) ini_get('post_max_size')); ?>.
                </small>

                <?php if ($upload_limit > 0 && $upload_limit < 41943040): ?>
                    <div class="error-message" style="margin:0;font-size:12px;">
                        ⚠ Лимит загрузки (<?php echo format_size($upload_limit); ?>) меньше размера установщика MSI (~40 МБ).
                        Поднимите <b>upload_max_filesize</b> и <b>post_max_size</b> в php.ini (например, 256M) и перезапустите PHP.
                    </div>
                <?php endif; ?>

                <?php if ($post_limit > 0 && $post_limit < $upload_limit): ?>
                    <div class="error-message" style="margin:0;font-size:12px;">
                        ⚠ post_max_size меньше upload_max_filesize — загрузка не пройдёт. Сделайте post_max_size не меньше upload_max_filesize.
                    </div>
                <?php endif; ?>

                <button type="submit" class="action-btn gold-btn">Опубликовать обновление</button>
            </form>

            <h3 style="color:#c9a84c;font-size:14px;margin:22px 0 10px;">Релизы (<?php echo count($releases); ?>)</h3>

            <?php if (empty($releases)): ?>
                <p style="color:#666;font-size:12px;">Пока ничего не опубликовано.</p>
            <?php else: ?>
                <?php foreach ($releases as $release): ?>
                    <div class="user-edit-card">
                        <div class="user-card-header">
                            <h3>
                                v<?php echo htmlspecialchars((string) $release['version']); ?>
                                <span class="admin-mark"><?php echo htmlspecialchars((string) $release['channel']); ?></span>
                                <?php if ((int) $release['is_active'] === 1): ?>
                                    <span class="tag-badge">активен</span>
                                <?php else: ?>
                                    <span class="tag-badge" style="border-color:#666;color:#888;">выключен</span>
                                <?php endif; ?>
                                <?php if ((int) $release['is_mandatory'] === 1): ?>
                                    <span class="tag-badge" style="border-color:#ff4444;color:#ff4444;">обязательный</span>
                                <?php endif; ?>
                            </h3>
                            <div class="file-actions">
                                <a href="update_download.php?id=<?php echo (int) $release['id']; ?>"
                                   class="admin-btn" title="Скачать файл релиза">Скачать</a>
                                <form method="POST" style="display:inline">
                                    <?php echo gc_csrf_field(); ?>
                                    <input type="hidden" name="toggle_release" value="<?php echo (int) $release['id']; ?>">
                                    <button type="submit" class="admin-btn" title="Включить/выключить раздачу"><?php echo (int) $release['is_active'] === 1 ? 'Выключить' : 'Включить'; ?></button>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Удалить релиз вместе с файлом?')">
                                    <?php echo gc_csrf_field(); ?>
                                    <input type="hidden" name="delete_release" value="<?php echo (int) $release['id']; ?>">
                                    <button type="submit" class="delete-user-btn">Удалить</button>
                                </form>
                            </div>
                        </div>

                        <div class="release-meta">
                            <?php echo htmlspecialchars((string) $release['original_name']); ?> ·
                            <?php echo format_size((int) $release['file_size']); ?> ·
                            скачиваний: <?php echo (int) $release['downloads']; ?> ·
                            <?php echo htmlspecialchars((string) $release['published_at']); ?>
                        </div>

                        <?php if (trim((string) $release['notes']) !== ''): ?>
                            <div class="release-notes"><?php echo nl2br(htmlspecialchars((string) $release['notes'])); ?></div>
                        <?php endif; ?>

                        <div class="release-hash">sha256: <?php echo htmlspecialchars((string) $release['sha256']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="admin-grid">
            
            <!-- ЛЕВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <?php if (in_array('create_users', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>👤 Создать пользователя</h2>
                    <form method="POST" class="inline-form">
                        <?php echo gc_csrf_field(); ?>
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-row">
                            <input type="text" name="new_login" placeholder="Логин" required>
                            <input type="text" name="new_password" placeholder="Пароль" required>
                        </div>
                        <?php if (in_array('edit_user_tags', $admin_rights)): ?>
                        <div class="form-row">
                            <div class="admin-tags">
                                <?php foreach ($all_tags as $tag): ?>
                                    <label>
                                        <input type="checkbox" name="new_tags[]" value="<?php echo htmlspecialchars($tag['name']); ?>">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('manage_rights', $admin_rights)): ?>
                        <div class="form-row">
                            <div class="rights-grid">
                                <?php foreach ($all_rights_list as $right): ?>
                                    <label>
                                        <input type="checkbox" name="new_rights[]" value="<?php echo $right; ?>">
                                        <?php echo $rights_labels[$right]; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="action-btn green-btn">Создать пользователя</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('create_delete_tags', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🏷 Создать тег</h2>
                    <form method="POST" class="inline-form">
                        <?php echo gc_csrf_field(); ?>
                        <input type="hidden" name="action" value="create_tag">
                        <div class="form-row">
                            <input type="text" name="new_tag" placeholder="Название тега" required>
                            <button type="submit" class="action-btn gold-btn">Создать тег</button>
                        </div>
                    </form>
                </div>
                
                <div class="admin-block">
                    <h2>📋 Все теги (<?php echo count($all_tags); ?>)</h2>
                    <div class="tag-list">
                        <?php foreach ($all_tags as $tag): ?>
                            <span class="tag-item">
                                <?php echo htmlspecialchars($tag['name']); ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Удалить тег?')">
                                    <?php echo gc_csrf_field(); ?>
                                    <input type="hidden" name="delete_tag" value="<?php echo (int) $tag['id']; ?>">
                                    <button type="submit" class="tag-delete" aria-label="Удалить тег">✕</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- ПРАВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <?php if (in_array('edit_user_tags', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🔑 Теги пользователей</h2>
                    <form method="POST" onsubmit="return confirm('Сохранить изменения тегов пользователей?')">
                        <?php echo gc_csrf_field(); ?>
                        <input type="hidden" name="action" value="save_tags">
                        
                        <?php foreach ($users as $user): ?>
                            <div class="user-edit-card">
                                <div class="user-card-header">
                                    <h3>
                                        👤 <?php echo htmlspecialchars($user['login']); ?>
                                        <?php if ($user['id'] == 1): ?>
                                            <span class="admin-mark">(админ)</span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($user['id'] != 1 && in_array('create_users', $admin_rights)): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Удалить пользователя?')">
                                            <?php echo gc_csrf_field(); ?>
                                            <input type="hidden" name="delete_user" value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit" class="delete-user-btn">Удалить</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="admin-tags">
                                    <?php foreach ($all_tags as $tag): ?>
                                        <label>
                                            <input type="checkbox" 
                                                   name="user_tags[<?php echo $user['id']; ?>][]" 
                                                   value="<?php echo htmlspecialchars($tag['name']); ?>"
                                                   <?php echo in_array($tag['name'], $user_tags_data[$user['id']] ?? []) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="save-btn">💾 Сохранить теги</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('manage_rights', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🛡 Права администраторов</h2>
                    <form method="POST">
                        <?php echo gc_csrf_field(); ?>
                        <input type="hidden" name="action" value="save_rights">
                        
                        <?php foreach ($users as $user): ?>
                            <div class="user-edit-card">
                                <div class="user-card-header">
                                    <h3>
                                        👤 <?php echo htmlspecialchars($user['login']); ?>
                                        <?php if ($user['id'] == 1): ?>
                                            <span class="admin-mark">(все права)</span>
                                        <?php endif; ?>
                                    </h3>
                                </div>
                                
                                <?php if ($user['id'] != 1): ?>
                                <div class="rights-grid">
                                    <?php foreach ($all_rights_list as $right): ?>
                                        <label>
                                            <input type="checkbox" 
                                                   name="user_rights[<?php echo $user['id']; ?>][]" 
                                                   value="<?php echo $right; ?>"
                                                   <?php echo in_array($right, $user_rights_data[$user['id']] ?? []) ? 'checked' : ''; ?>>
                                            <?php echo $rights_labels[$right]; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <p style="color: #666; font-size: 12px; padding: 5px;">Главный администратор всегда имеет полный доступ.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="save-btn">💾 Сохранить права</button>
                    </form>
                </div>
                <?php endif; ?>
                
            </div>
            
        </div>
    </div>
</body>
</html>