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
$admin_rights = $_SESSION['admin_rights'] ?? [];
$upload_dir = 'uploads/';

// Получаем все теги из БД
$stmt = $pdo->query('SELECT name FROM tags ORDER BY id');
$all_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем id тегов пользователя для быстрой проверки
$user_tag_ids = [];
if (!empty($user_tags)) {
    $stmt = $pdo->prepare('SELECT id FROM tags WHERE name IN (?' . str_repeat(',?', count($user_tags) - 1) . ')');
    $stmt->execute($user_tags);
    $user_tag_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$can_view_any = in_array('view_any_file', $admin_rights);

// Запрос файлов, доступных пользователю
if ($can_view_any) {
    // Видит вообще все файлы
    $query = '
        SELECT DISTINCT f.*, u.login AS uploader_login
        FROM files f
        JOIN users u ON f.uploader_id = u.id
        ORDER BY f.is_pinned DESC, f.uploaded_at DESC
    ';
    $stmt = $pdo->prepare($query);
    $stmt->execute();
} else {
    // Обычный запрос с ограничением по доступу
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
}
$files = $stmt->fetchAll();

// Собираем все ID файлов для массовой проверки доступа
$all_file_ids = array_column($files, 'id');

// Получаем теги всех файлов одним запросом
$file_tags_map = [];
if (!empty($all_file_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_file_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT ft.file_id, t.name 
        FROM file_tags ft 
        JOIN tags t ON ft.tag_id = t.id 
        WHERE ft.file_id IN ($placeholders)
    ");
    $stmt->execute($all_file_ids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $file_tags_map[$row['file_id']][] = $row['name'];
    }
}

// Получаем доступы по логинам для текущего пользователя одним запросом
$file_access_map = [];
if (!empty($all_file_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_file_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT file_id 
        FROM file_access 
        WHERE file_id IN ($placeholders) AND user_id = ?
    ");
    $params = $all_file_ids;
    $params[] = $user_id;
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $file_access_map[$row['file_id']] = true;
    }
}

// Группируем: закреплённые и по дням
$pinned_files = [];
$grouped_files = [];
foreach ($files as &$file) {
    // Присваиваем теги из массового запроса
    $file['tags'] = $file_tags_map[$file['id']] ?? [];

    // Проверяем реальный доступ к файлу
    $is_owner = ($file['uploader_id'] == $user_id);
    $has_tag_access = false;
    
    if (!$is_owner && !empty($file['tags']) && !empty($user_tags)) {
        $common_tags = array_intersect($file['tags'], $user_tags);
        if (!empty($common_tags)) {
            $has_tag_access = true;
        }
    }
    
    $has_login_access = isset($file_access_map[$file['id']]);
    
    // Реальный доступ = владелец ИЛИ доступ через теги ИЛИ доступ через логины
    $file['has_real_access'] = $is_owner || $has_tag_access || $has_login_access;

    if ($file['is_pinned']) {
        $pinned_files[] = $file;
    } else {
        $day = substr($file['uploaded_at'], 0, 10);
        $grouped_files[$day][] = $file;
    }
}
unset($file);

// Список всех пользователей для подсказки
$stmt = $pdo->query('SELECT login FROM users ORDER BY login');
$all_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Проверки прав для кнопок (строгая проверка)
$can_pin = in_array('pin_files', $admin_rights);
$can_delete_any = in_array('delete_any_file', $admin_rights);
$can_edit_any_file = in_array('edit_any_file', $admin_rights);

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
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .disabled-download {
            background: #333 !important;
            color: #666 !important;
            cursor: not-allowed;
            text-decoration: none;
            padding: 6px 18px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
            white-space: nowrap;
            border: 1px solid #444;
            display: inline-block;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: #1a1a1a;
            border: 2px solid #c9a84c;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .modal h2 {
            color: #c9a84c;
            margin-top: 0;
            margin-bottom: 20px;
            text-align: center;
        }

        .modal .close-btn {
            position: absolute;
            top: 10px;
            right: 15px;
            background: none;
            border: none;
            color: #ff4444;
            font-size: 28px;
            cursor: pointer;
            line-height: 1;
        }

        .modal .close-btn:hover {
            color: #ff0000;
        }

        .modal .section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }

        .modal .section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .modal .section h3 {
            color: #999;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .modal .tags-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .modal .logins-input {
            width: 100%;
            padding: 10px 12px;
            background: #0d0d0d;
            border: 1px solid #333;
            border-radius: 5px;
            color: #fff;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .modal .logins-input:focus {
            outline: none;
            border-color: #c9a84c;
        }

        .modal .save-file-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(180deg, #c9a84c, #a88a3a);
            color: #000;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .modal .save-file-btn:hover {
            box-shadow: 0 0 20px rgba(201, 168, 76, 0.4);
        }

        .edit-file-btn {
            background: none;
            border: 1px solid #c9a84c;
            color: #c9a84c;
            font-size: 14px;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 4px;
        }

        .edit-file-btn:hover {
            background: #1a1a00;
            color: #fff;
        }
    </style>
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
                <?php if ($_SESSION['is_admin'] ?? false): ?>
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
                            <?php
                            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                            $is_owner = ($file['uploader_id'] == $user_id);
                            
                            // Может ли пользователь скачивать файл?
                            // 1. Владелец - всегда может
                            // 2. Есть реальный доступ через теги/логины - может
                            // 3. Файл виден только из-за права view_any_file - НЕ может
                            $can_download = $file['has_real_access'];
                            
                            // Может ли редактировать?
                            $can_edit = $is_owner || $can_edit_any_file;
                            
                            // Может ли удалять?
                            $can_delete = $is_owner || $can_delete_any;
                            
                            // Может ли закреплять?
                            $can_pin_file = $is_owner || $can_pin;
                            ?>
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
                                    <?php if ($can_pin_file): ?>
                                        <a href="pin.php?id=<?php echo $file['id']; ?>" class="pin-btn" title="Закрепить">📌</a>
                                    <?php endif; ?>
                                    <?php if (in_array($ext, $video_extensions) && $can_download): ?>
                                        <button class="copy-link-btn" onclick="copyFileLink('<?php echo $file['filename']; ?>')"
                                            title="Копировать прямую ссылку">🔗</button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                        <a href="delete.php?id=<?php echo $file['id']; ?>" class="delete-btn"
                                            onclick="return confirm('Точно удалить файл?')" title="Удалить">✕</a>
                                    <?php endif; ?>
                                    <?php if ($can_edit): ?>
                                        <button class="edit-file-btn"
                                            onclick="openEditModal(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['original_name'], ENT_QUOTES); ?>')">✎</button>
                                    <?php endif; ?>
                                    <?php if ($can_download): ?>
                                        <a href="download.php?id=<?php echo $file['id']; ?>" class="download-btn">Скачать</a>
                                    <?php else: ?>
                                        <span class="disabled-download">Недоступно</span>
                                    <?php endif; ?>
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
                            <?php
                            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                            $is_owner = ($file['uploader_id'] == $user_id);
                            
                            // Может ли пользователь скачивать файл?
                            $can_download = $file['has_real_access'];
                            
                            // Может ли редактировать?
                            $can_edit = $is_owner || $can_edit_any_file;
                            
                            // Может ли удалять?
                            $can_delete = $is_owner || $can_delete_any;
                            
                            // Может ли закреплять?
                            $can_pin_file = $is_owner || $can_pin;
                            ?>
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
                                    <?php if ($can_pin_file): ?>
                                        <a href="pin.php?id=<?php echo $file['id']; ?>" class="pin-btn" title="Закрепить">📌</a>
                                    <?php endif; ?>
                                    <?php if (in_array($ext, $video_extensions) && $can_download): ?>
                                        <button class="copy-link-btn" onclick="copyFileLink('<?php echo $file['filename']; ?>')"
                                            title="Копировать прямую ссылку">🔗</button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                        <a href="delete.php?id=<?php echo $file['id']; ?>" class="delete-btn"
                                            onclick="return confirm('Точно удалить файл?')" title="Удалить">✕</a>
                                    <?php endif; ?>
                                    <?php if ($can_edit): ?>
                                        <button class="edit-file-btn"
                                            onclick="openEditModal(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['original_name'], ENT_QUOTES); ?>')">✎</button>
                                    <?php endif; ?>
                                    <?php if ($can_download): ?>
                                        <a href="download.php?id=<?php echo $file['id']; ?>" class="download-btn">Скачать</a>
                                    <?php else: ?>
                                        <span class="disabled-download">Недоступно</span>
                                    <?php endif; ?>
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

    <!-- Модальное окно редактирования -->
    <div class="modal-overlay" id="editModalOverlay">
        <div class="modal">
            <button class="close-btn" onclick="closeEditModal()">✕</button>
            <h2>Редактирование файла: <span id="editFileName"></span></h2>

            <form id="editFileForm">
                <input type="hidden" name="file_id" id="editFileId">

                <div class="section">
                    <h3>Теги доступа:</h3>
                    <div class="tags-grid" id="editTagsContainer">
                        <?php foreach ($all_tags as $tag): ?>
                            <label class="tag-checkbox">
                                <input type="checkbox" name="edit_tags[]" value="<?php echo $tag; ?>">
                                <span><?php echo $tag; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section">
                    <h3>Доступ по логинам (через запятую):</h3>
                    <input type="text" name="edit_logins" class="logins-input" id="editLoginsInput"
                        placeholder="user1, user2">
                    <small>Доступные: <?php echo implode(', ', $all_users); ?></small>
                </div>

                <button type="submit" class="save-file-btn">💾 Сохранить изменения</button>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(fileId, fileName) {
            document.getElementById('editFileId').value = fileId;
            document.getElementById('editFileName').textContent = fileName;

            fetch('get_file_info.php?id=' + fileId)
                .then(response => response.json())
                .then(data => {
                    document.querySelectorAll('#editTagsContainer input[type="checkbox"]').forEach(cb => cb.checked = false);
                    if (data.tags) {
                        data.tags.forEach(tag => {
                            const cb = document.querySelector(`#editTagsContainer input[value="${CSS.escape(tag)}"]`);
                            if (cb) cb.checked = true;
                        });
                    }
                    document.getElementById('editLoginsInput').value = (data.logins || []).join(', ');

                    document.getElementById('editModalOverlay').classList.add('active');
                });
        }

        function closeEditModal() {
            document.getElementById('editModalOverlay').classList.remove('active');
        }

        document.getElementById('editModalOverlay').addEventListener('click', function (e) {
            if (e.target === this) closeEditModal();
        });

        document.getElementById('editFileForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('edit_file.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeEditModal();
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                    }
                })
                .catch(err => {
                    alert('Ошибка сети');
                    console.error(err);
                });
        });

        if (!CSS.escape) {
            CSS.escape = function (value) {
                return String(value).replace(/([^\w-])/g, '\\$1');
            };
        }

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