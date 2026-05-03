<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$user_id = $_SESSION['user_id'];
$user_tags = $_SESSION['tags'] ?? [];
$all_tags = ['Глава GC', 'Команда GC', 'Програмист GC', 'Художник GC', 'Билдер GC', 'Игрок', 'Доверенный'];
$upload_dir = 'uploads/';

// Получаем id тегов пользователя для быстрой проверки
$user_tag_ids = [];
if (!empty($user_tags)) {
    $stmt = $pdo->prepare('SELECT id FROM tags WHERE name IN (?' . str_repeat(',?', count($user_tags) - 1) . ')');
    $stmt->execute($user_tags);
    $user_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Запрос файлов, доступных пользователю
$query = '
    SELECT DISTINCT f.*, u.login AS uploader_login
    FROM files f
    JOIN users u ON f.uploader_id = u.id
    LEFT JOIN file_access fa ON f.id = fa.file_id
    LEFT JOIN file_tags ft ON f.id = ft.file_id
    WHERE 
        f.uploader_id = ?
        OR fa.user_id = ?
        OR ft.tag_id IN (' . (!empty($user_tag_ids) ? implode(',', array_fill(0, count($user_tag_ids), '?')) : '0') . ')
    ORDER BY f.is_pinned DESC, f.uploaded_at DESC
';

$params = [$user_id, $user_id];
if (!empty($user_tag_ids)) {
    $params = array_merge($params, $user_tag_ids);
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$files = $stmt->fetchAll();

// Группируем: закреплённые и по дням
$pinned_files = [];
$grouped_files = [];
foreach ($files as $file) {
    $stmt = $pdo->prepare('SELECT t.name FROM tags t JOIN file_tags ft ON t.id = ft.tag_id WHERE ft.file_id = ?');
    $stmt->execute([$file['id']]);
    $file['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($file['is_pinned']) {
        $pinned_files[] = $file;
    } else {
        $day = substr($file['uploaded_at'], 0, 10);
        $grouped_files[$day][] = $file;
    }
}

// Список всех пользователей для подсказки
$stmt = $pdo->query('SELECT login FROM users ORDER BY login');
$all_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Проверки прав для кнопок
$can_pin = in_array('Глава GC', $user_tags) || in_array('Команда GC', $user_tags);
$can_delete_any = in_array('Глава GC', $user_tags);

// Список видео-расширений для кнопки копирования ссылки
$video_extensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:,">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Файлы — Наш Файлообменник</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="files-page">
    <div class="container">
        <header class="header">
            <h1>📁 Файлообменник</h1>
            <div class="user-info">
                <span class="user-name">👤 <?php echo htmlspecialchars($user); ?></span>
                <span class="user-tags">
                    <?php foreach ($user_tags as $tag): ?>
                        <span class="tag-badge"><?php echo htmlspecialchars($tag); ?></span>
                    <?php endforeach; ?>
                </span>
                <?php if ($user === 'admin'): ?>
                    <a href="admin.php" class="admin-btn">⚙ Админ</a>
                <?php endif; ?>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </header>

        <!-- Форма загрузки -->
        <div class="upload-section">
            <h2>📤 Загрузить файл</h2>
            <form action="upload.php" method="POST" enctype="multipart/form-data" class="upload-form-full"
                id="upload-form">
                <div class="upload-row">
                    <div class="file-input-wrapper">
                        <input type="file" name="userfile" id="userfile" required>
                    </div>
                    <button type="submit" class="upload-btn" id="upload-btn">Загрузить</button>
                </div>

                <div class="progress-container" id="progress-container" style="display: none;">
                    <div class="progress-bar" id="progress-bar"></div>
                    <span class="progress-text" id="progress-text">Идёт загрузка...</span>
                </div>

                <div class="access-controls">
                    <div class="access-block">
                        <h3>Виден по тегам:</h3>
                        <div class="tags-grid">
                            <?php foreach ($all_tags as $tag): ?>
                                <label class="tag-checkbox">
                                    <input type="checkbox" name="tags[]" value="<?php echo $tag; ?>">
                                    <span><?php echo $tag; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="access-block">
                        <h3>Виден по логинам (через запятую):</h3>
                        <input type="text" name="logins" placeholder="user, vasya, petya" class="logins-input">
                        <small>Доступные: <?php echo implode(', ', $all_users); ?></small>
                    </div>
                </div>
            </form>
        </div>

        <script>
            const form = document.getElementById('upload-form');
            const fileInput = document.getElementById('userfile');
            const uploadBtn = document.getElementById('upload-btn');
            const progressContainer = document.getElementById('progress-container');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');

            form.addEventListener('submit', function (e) {
                const file = fileInput.files[0];
                if (!file) return;

                uploadBtn.disabled = true;
                uploadBtn.textContent = 'Загружаем...';
                progressContainer.style.display = 'block';
                progressBar.style.width = '90%';
                progressBar.style.transition = 'width 10s ease-in-out';
                setTimeout(function () {
                    progressBar.style.width = '95%';
                }, 50);
            });
        </script>

        <!-- Список файлов -->
        <div class="files-section">
            <h2>📂 Доступные файлы</h2>

            <?php if (count($pinned_files) > 0): ?>
                <div class="pinned-section">
                    <h3>📌 Закреплённые</h3>
                    <ul class="file-list pinned-list">
                        <?php foreach ($pinned_files as $file): ?>
                            <?php $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)); ?>
                            <li class="pinned-item">
                                <span class="pin-icon">📌</span>
                                <div class="file-info">
                                    <span class="file-name">📄 <?php echo htmlspecialchars($file['original_name']); ?></span>
                                    <span class="file-meta">
                                        От: <strong><?php echo htmlspecialchars($file['uploader_login']); ?></strong>
                                        <span
                                            class="file-date"><?php echo date('d.m.Y H:i', strtotime($file['uploaded_at'])); ?></span>
                                        <?php if (!empty($file['tags'])): ?>
                                            <span class="file-tags">
                                                <?php foreach ($file['tags'] as $t): ?>
                                                    <span class="tag-mini"><?php echo htmlspecialchars($t); ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="file-actions">
                                    <?php if ($can_pin): ?>
                                        <a href="pin.php?id=<?php echo $file['id']; ?>" class="pin-btn" title="Закрепить">📌</a>
                                    <?php endif; ?>
                                    <?php if (in_array($ext, $video_extensions)): ?>
                                        <button class="copy-link-btn" onclick="copyFileLink('<?php echo $file['filename']; ?>')"
                                            title="Копировать прямую ссылку">🔗</button>
                                    <?php endif; ?>
                                    <?php if ($can_delete_any || $user_id == $file['uploader_id']): ?>
                                        <a href="delete.php?id=<?php echo $file['id']; ?>" class="delete-btn"
                                            onclick="return confirm('Точно удалить файл?')" title="Удалить">✕</a>
                                    <?php endif; ?>
                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="download-btn">Скачать</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (count($grouped_files) > 0): ?>
                <?php $is_first = true; ?>
                <?php foreach ($grouped_files as $day => $day_files): ?>
                    <?php if (!$is_first): ?>
                        <div class="day-separator">
                            <span><?php echo date('d.m.Y', strtotime($day)); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="day-header">
                            <span><?php echo date('d.m.Y', strtotime($day)); ?></span>
                        </div>
                    <?php endif; ?>

                    <ul class="file-list">
                        <?php foreach ($day_files as $file): ?>
                            <?php $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)); ?>
                            <li>
                                <div class="file-info">
                                    <span class="file-name">📄 <?php echo htmlspecialchars($file['original_name']); ?></span>
                                    <span class="file-meta">
                                        От: <strong><?php echo htmlspecialchars($file['uploader_login']); ?></strong>
                                        <span class="file-date"><?php echo date('H:i', strtotime($file['uploaded_at'])); ?></span>
                                        <?php if (!empty($file['tags'])): ?>
                                            <span class="file-tags">
                                                <?php foreach ($file['tags'] as $t): ?>
                                                    <span class="tag-mini"><?php echo htmlspecialchars($t); ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="file-actions">
                                    <?php if ($can_pin): ?>
                                        <a href="pin.php?id=<?php echo $file['id']; ?>" class="pin-btn" title="Закрепить">📌</a>
                                    <?php endif; ?>
                                    <?php if (in_array($ext, $video_extensions)): ?>
                                        <button class="copy-link-btn" onclick="copyFileLink('<?php echo $file['filename']; ?>')"
                                            title="Копировать прямую ссылку">🔗</button>
                                    <?php endif; ?>
                                    <?php if ($can_delete_any || $user_id == $file['uploader_id']): ?>
                                        <a href="delete.php?id=<?php echo $file['id']; ?>" class="delete-btn"
                                            onclick="return confirm('Точно удалить файл?')" title="Удалить">✕</a>
                                    <?php endif; ?>
                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="download-btn">Скачать</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php $is_first = false; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty">Нет доступных файлов. Загрузи первый!</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyFileLink(filename) {
            var basePath = window.location.pathname.replace(/\/[^\/]*$/, '');
            var link = window.location.origin + basePath + '/uploads/' + filename;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(function () {
                    showToast('✅ Ссылка скопирована!');
                }).catch(function () {
                    fallbackCopy(link);
                });
            } else {
                fallbackCopy(link);
            }
        }

        function fallbackCopy(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.top = '0';
            textarea.style.left = '0';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                document.execCommand('copy');
                showToast('✅ Ссылка скопирована!');
            } catch (err) {
                showToast('❌ Не удалось скопировать');
            }
            document.body.removeChild(textarea);
        }

        function showToast(message) {
            var toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(function () {
                toast.classList.add('show');
            }, 10);

            setTimeout(function () {
                toast.classList.remove('show');
                setTimeout(function () {
                    if (toast.parentNode) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 2000);
        }
    </script>
</body>

</html>