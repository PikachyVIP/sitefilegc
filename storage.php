<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();
$uid = (int) $user['id'];
$isSuperadmin = gc_is_superadmin();
$uploadDir = __DIR__ . '/uploads';

$folderId = isset($_GET['folder']) ? (int) $_GET['folder'] : 0;
$sort = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : 'date';
if (!in_array($sort, ['name', 'date', 'size', 'type'], true)) {
    $sort = 'date';
}
$dir = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$scope = isset($_GET['scope']) && is_string($_GET['scope']) ? $_GET['scope'] : 'all';
if ($scope === 'mine') {
    $scope = 'personal';
} elseif (!in_array($scope, ['all', 'personal'], true)) {
    $scope = 'all';
}

$adminPersonal = $isSuperadmin && $scope === 'personal';
$personalRootId = gc_personal_folder_id($pdo, $uid);

$diskFree = @disk_free_space($uploadDir);
$diskTotal = @disk_total_space($uploadDir);
$diskFreeBytes = null;
$diskTotalBytes = null;
$diskUsedPercent = null;
$diskFreePercent = null;
if (is_float($diskFree) && is_float($diskTotal) && $diskTotal > 0) {
    $diskFreeBytes = max(0, (int) $diskFree);
    $diskTotalBytes = (int) $diskTotal;
    $diskFreePercent = max(0.0, min(100.0, ($diskFreeBytes / $diskTotalBytes) * 100));
    $diskUsedPercent = 100 - $diskFreePercent;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gc_csrf_check();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $returnFolder = isset($_POST['folder']) ? (int) $_POST['folder'] : 0;
    $returnSort = isset($_POST['sort']) && is_string($_POST['sort']) ? $_POST['sort'] : 'date';
    if (!in_array($returnSort, ['name', 'date', 'size', 'type'], true)) {
        $returnSort = 'date';
    }
    $returnDir = isset($_POST['dir']) && $_POST['dir'] === 'asc' ? 'asc' : 'desc';
    $returnScope = isset($_POST['scope']) && is_string($_POST['scope']) ? $_POST['scope'] : 'all';
    if ($returnScope === 'mine') {
        $returnScope = 'personal';
    } elseif (!in_array($returnScope, ['all', 'personal'], true)) {
        $returnScope = 'all';
    }
    $returnUrl = gc_storage_link($returnFolder, $returnSort, $returnDir, $returnScope);

    if ($action === 'upload') {
        $isAjax = (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        $respondJson = static function (array $payload, int $status = 200) use ($isAjax): void {
            if (!$isAjax) {
                return;
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        };
        $GLOBALS['gc_commit_started'] = microtime(true);
        gc_upload_log('commit_begin', [
            'scope' => $returnScope,
            'folder' => $returnFolder,
            'ajax' => $isAjax,
            'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
        ]);
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            gc_upload_log('commit_fatal', ['message' => substr((string) ($error['message'] ?? ''), 0, 500)]);
        });

        $targetFolder = $returnFolder;
        if ($returnScope === 'personal' && $targetFolder <= 0) {
            $targetFolder = $personalRootId;
        }
        if ($targetFolder > 0) {
            $check = $pdo->prepare('SELECT is_personal, created_by FROM folders WHERE id = ?');
            $check->execute([$targetFolder]);
            $targetRow = $check->fetch();
            $targetIsPersonal = $targetRow && gc_bool_value($targetRow['is_personal']);
            $targetOwner = $targetRow ? (int) $targetRow['created_by'] : 0;

            if ($returnScope === 'personal') {
                if (!$targetRow || !$targetIsPersonal || $targetOwner !== $uid) {
                    $targetFolder = $personalRootId;
                }
            } elseif (!$targetRow || $targetIsPersonal) {
                $targetFolder = 0;
            }
        }

        $sources = [
            ['field' => 'files', 'paths' => $_POST['paths'] ?? []],
            ['field' => 'dir_files', 'paths' => $_POST['dir_paths'] ?? []],
            ['field' => 'file', 'paths' => []],
        ];
        $queue = [];
        foreach ($sources as $source) {
            $incoming = $_FILES[$source['field']] ?? null;
            if (!is_array($incoming) || !isset($incoming['name'])) {
                continue;
            }
            $paths = is_array($source['paths']) ? array_values($source['paths']) : [$source['paths']];
            if (is_array($incoming['name'])) {
                foreach (array_keys($incoming['name']) as $index) {
                    $queue[] = [
                        'name' => (string) $incoming['name'][$index],
                        'path' => isset($paths[$index]) && is_string($paths[$index]) ? $paths[$index] : (string) $incoming['name'][$index],
                        'tmp_name' => (string) $incoming['tmp_name'][$index],
                        'error' => (int) $incoming['error'][$index],
                        'size' => (int) $incoming['size'][$index],
                    ];
                }
            } else {
                $queue[] = [
                    'name' => (string) $incoming['name'],
                    'path' => isset($paths[0]) && is_string($paths[0]) ? $paths[0] : (string) $incoming['name'],
                    'tmp_name' => (string) $incoming['tmp_name'],
                    'error' => (int) $incoming['error'],
                    'size' => (int) $incoming['size'],
                ];
            }
        }
        $queue = array_values(array_filter($queue, static fn (array $item): bool => $item['error'] !== UPLOAD_ERR_NO_FILE));

        $failUpload = static function (string $message, int $status = 400) use ($respondJson, $returnUrl): void {
            gc_upload_log('commit_fail', ['status' => $status, 'message' => $message]);
            $respondJson(['ok' => false, 'uploaded' => 0, 'errors' => [$message]], $status);
            gc_flash('error', $message);
            gc_redirect($returnUrl);
        };

        $stagedIds = $_POST['staged_upload'] ?? [];
        if (is_string($stagedIds)) {
            $stagedIds = [$stagedIds];
        }
        $stagedIds = is_array($stagedIds)
            ? array_values(array_filter($stagedIds, static fn ($value): bool => is_string($value) && $value !== ''))
            : [];
        $stageDir = $uploadDir . '/.staging';
        foreach ($stagedIds as $stagedId) {
            if (!preg_match('/^[a-f0-9]{32}$/', $stagedId)) {
                $failUpload('Некорректный идентификатор загрузки.');
            }
            $stagePath = $stageDir . '/' . $stagedId . '.bin';
            $stageMetaPath = $stageDir . '/' . $stagedId . '.json';
            $stageRaw = is_file($stageMetaPath) ? @file_get_contents($stageMetaPath) : false;
            $stageMeta = is_string($stageRaw) ? json_decode($stageRaw, true) : null;
            if (!is_array($stageMeta) || (int) ($stageMeta['uid'] ?? 0) !== $uid) {
                $failUpload('Загрузка не найдена или принадлежит другому пользователю.', 404);
            }
            if (!is_file($stagePath)) {
                $failUpload('Временный файл загрузки не найден.', 404);
            }
            $stageSize = (int) ($stageMeta['size'] ?? 0);
            if ($stageSize <= 0 || (int) filesize($stagePath) !== $stageSize) {
                $failUpload('Размер загруженного файла не совпадает.', 409);
            }
            $stageMax = gc_max_upload();
            if ($stageMax > 0 && $stageSize > $stageMax) {
                $failUpload('Файл больше допустимого размера ' . gc_size($stageMax) . '.', 413);
            }
            $queue[] = [
                'name' => (string) ($stageMeta['name'] ?? 'file'),
                'path' => (string) ($stageMeta['path'] ?? $stageMeta['name'] ?? 'file'),
                'tmp_name' => $stagePath,
                'error' => UPLOAD_ERR_OK,
                'size' => $stageSize,
                'staged' => true,
                'meta_path' => $stageMetaPath,
            ];
        }

        if ($queue === []) {
            $respondJson(['ok' => false, 'uploaded' => 0, 'errors' => ['Файл не выбран.']]);
            gc_flash('error', 'Файл не выбран.');
            gc_redirect($returnUrl);
        }
        gc_upload_log('commit_queue', [
            'staged' => count($stagedIds),
            'files' => count($queue),
            'bytes' => array_sum(array_map(static fn (array $item): int => (int) $item['size'], $queue)),
            'target_folder' => $targetFolder,
            'personal' => $returnScope === 'personal',
        ]);

        if (!gc_ensure_data_dir($uploadDir)) {
            $respondJson(['ok' => false, 'uploaded' => 0, 'errors' => ['Папка uploads недоступна для записи.']], 500);
            gc_flash('error', 'Папка uploads недоступна для записи.');
            gc_redirect($returnUrl);
        }

        $tagIds = [];
        if (isset($_POST['tags']) && is_array($_POST['tags'])) {
            foreach ($_POST['tags'] as $rawTag) {
                $tagId = (int) $rawTag;
                if ($tagId > 0) {
                    $tagIds[$tagId] = $tagId;
                }
            }
        }
        $tagIds = array_values($tagIds);
        if ($tagIds !== []) {
            $in = implode(',', array_fill(0, count($tagIds), '?'));
            $statement = $pdo->prepare('SELECT id FROM tags WHERE id IN (' . $in . ')');
            $statement->execute($tagIds);
            $tagIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }

        $loginIds = [];
        if (isset($_POST['logins']) && is_string($_POST['logins'])) {
            $parts = preg_split('/[\s,;]+/', $_POST['logins']);
            $logins = [];
            foreach ($parts ?: [] as $login) {
                $login = trim($login);
                if ($login !== '') {
                    $logins[$login] = $login;
                }
            }
            $logins = array_slice(array_values($logins), 0, 50);
            if ($logins !== []) {
                $in = implode(',', array_fill(0, count($logins), '?'));
                $statement = $pdo->prepare('SELECT id FROM users WHERE login IN (' . $in . ')');
                $statement->execute($logins);
                $loginIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            }
        }

        $personalUpload = $returnScope === 'personal';
        if (!$personalUpload && $tagIds === [] && $loginIds === [] && $targetFolder <= 0) {
            $personalUpload = true;
            $targetFolder = $personalRootId;
        }

        $folderCache = [];
        $resolveUploadFolder = static function (array $parts) use ($pdo, $uid, $tagIds, $targetFolder, $personalUpload, &$folderCache): int {
            $parent = $targetFolder;
            $cacheKey = $targetFolder . '|' . strtolower(implode('/', $parts));
            if (isset($folderCache[$cacheKey])) {
                return $folderCache[$cacheKey];
            }

            foreach ($parts as $part) {
                $statement = $pdo->prepare('SELECT id FROM folders WHERE COALESCE(parent_id, 0) = ? AND lower(name) = lower(?) AND is_personal = ? LIMIT 1');
                $statement->execute([$parent, $part, $personalUpload ? 'true' : 'false']);
                $folderId = (int) $statement->fetchColumn();
                if ($folderId <= 0) {
                    try {
                        $statement = $pdo->prepare('INSERT INTO folders (name, parent_id, created_by, is_personal) VALUES (?, ?, ?, ?) RETURNING id');
                        $statement->execute([$part, $parent > 0 ? $parent : null, $uid, $personalUpload ? 'true' : 'false']);
                        $folderId = (int) $statement->fetchColumn();
                        if ($tagIds !== []) {
                            $tagStatement = $pdo->prepare('INSERT INTO folder_tags (folder_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                            foreach ($tagIds as $tagId) {
                                $tagStatement->execute([$folderId, $tagId]);
                            }
                        }
                    } catch (Throwable $exception) {
                        $statement = $pdo->prepare('SELECT id FROM folders WHERE COALESCE(parent_id, 0) = ? AND lower(name) = lower(?) AND is_personal = ? LIMIT 1');
                        $statement->execute([$parent, $part, $personalUpload ? 'true' : 'false']);
                        $folderId = (int) $statement->fetchColumn();
                        if ($folderId <= 0) {
                            throw $exception;
                        }
                    }
                }
                $parent = $folderId;
            }

            $folderCache[$cacheKey] = $parent;

            return $parent;
        };

        $uploaded = 0;
        $errors = [];
        $max = gc_max_upload();
        foreach ($queue as $item) {
            $isStaged = !empty($item['staged']);
            $label = $item['path'] !== '' ? $item['path'] : ($item['name'] !== '' ? $item['name'] : 'файл');
            $itemStarted = microtime(true);
            gc_upload_log('commit_item_begin', [
                'name' => $label,
                'size' => (int) $item['size'],
                'staged' => $isStaged,
            ]);
            if ($item['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $label . ': ' . gc_upload_error($item['error']);
                gc_upload_log('commit_item_fail', ['name' => $label, 'reason' => 'php_upload_error', 'code' => (int) $item['error']]);
                continue;
            }
            if ($max > 0 && $item['size'] > $max) {
                $errors[] = $label . ': больше допустимого размера ' . gc_size($max) . '.';
                gc_upload_log('commit_item_fail', ['name' => $label, 'reason' => 'too_large', 'size' => (int) $item['size']]);
                continue;
            }
            if (!$isStaged && !is_uploaded_file($item['tmp_name'])) {
                $errors[] = $label . ': загрузка не подтверждена сервером.';
                gc_upload_log('commit_item_fail', ['name' => $label, 'reason' => 'not_uploaded_file']);
                continue;
            }

            $parts = gc_upload_path_parts($item['path'] !== '' ? $item['path'] : $item['name']);
            $filename = array_pop($parts);
            $original = gc_safe_upload_name($filename !== null ? $filename : $item['name']);
            $ext = gc_clean_ext($original);
            $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
            $path = $uploadDir . '/' . $stored;

            $placed = $isStaged
                ? @rename($item['tmp_name'], $path)
                : move_uploaded_file($item['tmp_name'], $path);
            if (!$placed) {
                $errors[] = $label . ': не удалось сохранить на сервере.';
                gc_upload_log('commit_item_fail', ['name' => $label, 'reason' => 'move_failed', 'stored' => $stored]);
                continue;
            }

            try {
                $fileFolder = $parts === [] ? $targetFolder : $resolveUploadFolder($parts);
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'INSERT INTO files (filename, original_name, uploader_id, folder_id, file_size, mime_type)
                     VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
                );
                $statement->execute([
                    $stored,
                    $original,
                    $uid,
                    $fileFolder > 0 ? $fileFolder : null,
                    $item['size'],
                    gc_mime_type($path),
                ]);
                $fileId = (int) $statement->fetchColumn();

                $tagStatement = $pdo->prepare('INSERT INTO file_tags (file_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                foreach ($tagIds as $tagId) {
                    $tagStatement->execute([$fileId, $tagId]);
                }

                $accessStatement = $pdo->prepare('INSERT INTO file_access (file_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                foreach ($loginIds as $loginId) {
                    $accessStatement->execute([$fileId, $loginId]);
                }

                $pdo->commit();
                $uploaded++;
                gc_upload_log('commit_item_ok', [
                    'name' => $label,
                    'file_id' => $fileId,
                    'folder_id' => $fileFolder,
                    'size' => (int) $item['size'],
                    'duration_ms' => (int) round((microtime(true) - $itemStarted) * 1000),
                ]);
                if ($isStaged && !empty($item['meta_path'])) {
                    @unlink((string) $item['meta_path']);
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($path);
                if ($isStaged && !empty($item['meta_path'])) {
                    @unlink((string) $item['meta_path']);
                }
                $errors[] = $label . ': не удалось сохранить в базе.';
                gc_upload_log('commit_item_fail', [
                    'name' => $label,
                    'reason' => 'database',
                    'class' => get_class($exception),
                    'message' => substr($exception->getMessage(), 0, 400),
                ]);
            }
        }

        $message = $uploaded > 0
            ? 'Загружено файлов: ' . $uploaded . ($errors !== [] ? ', с ошибками: ' . count($errors) : '') . '.'
            : 'Ни один файл не загружен.';
        gc_upload_log('commit_done', [
            'uploaded' => $uploaded,
            'errors' => count($errors),
            'messages' => array_slice($errors, 0, 10),
            'duration_ms' => isset($GLOBALS['gc_commit_started']) ? (int) round((microtime(true) - $GLOBALS['gc_commit_started']) * 1000) : null,
        ]);
        $respondJson([
            'ok' => $uploaded > 0,
            'uploaded' => $uploaded,
            'errors' => $errors,
            'message' => $message,
            'redirect' => $returnUrl,
        ]);

        if ($uploaded > 0) {
            gc_flash('ok', 'Загружено файлов: ' . $uploaded . '.');
            if ($personalUpload && $returnScope === 'all') {
                gc_flash('info', 'Файлы без тегов и личного доступа сохранены в «Личные файлы».');
            }
        }
        foreach (array_slice($errors, 0, 5) as $error) {
            gc_flash('error', $error);
        }
        gc_redirect($returnUrl);
    }

    if ($action === 'folder_create') {
        $parentId = $returnFolder;
        $parentPersonal = false;
        if ($returnScope === 'personal' && $parentId <= 0) {
            $parentId = $personalRootId;
        }
        if ($parentId > 0) {
            $check = $pdo->prepare('SELECT is_personal, created_by FROM folders WHERE id = ?');
            $check->execute([$parentId]);
            $parentRow = $check->fetch();
            if (!$parentRow) {
                $parentId = 0;
            } else {
                $parentPersonal = gc_bool_value($parentRow['is_personal']);
                if ($parentPersonal && (int) $parentRow['created_by'] !== $uid) {
                    gc_flash('error', 'Личная папка доступна только её владельцу.');
                    gc_redirect($returnUrl);
                }
            }
        }
        $name = isset($_POST['folder_name']) && is_string($_POST['folder_name']) ? trim($_POST['folder_name']) : '';
        if ($name === '' || gc_strlen($name) > 80) {
            gc_flash('error', 'Название папки должно быть от 1 до 80 символов.');
            gc_redirect($returnUrl);
        }

        $statement = $pdo->prepare('SELECT 1 FROM folders WHERE COALESCE(parent_id, 0) = ? AND lower(name) = lower(?) AND is_personal = ?');
        $statement->execute([$parentId, $name, $parentPersonal ? 'true' : 'false']);
        if ($statement->fetchColumn()) {
            gc_flash('error', 'Папка с таким названием уже есть в этом месте.');
            gc_redirect($returnUrl);
        }

        $statement = $pdo->prepare('INSERT INTO folders (name, parent_id, created_by, is_personal) VALUES (?, ?, ?, ?) RETURNING id');
        $statement->execute([$name, $parentId > 0 ? $parentId : null, $uid, $parentPersonal ? 'true' : 'false']);
        $newFolderId = (int) $statement->fetchColumn();

        gc_flash('ok', 'Папка «' . $name . '» создана.');
        gc_redirect(gc_storage_link($newFolderId, $returnSort, $returnDir, $returnScope));
    }

    if ($action === 'folder_rename') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name = isset($_POST['folder_name']) && is_string($_POST['folder_name']) ? trim($_POST['folder_name']) : '';
        $statement = $pdo->prepare('SELECT id, name, parent_id, created_by, is_personal FROM folders WHERE id = ?');
        $statement->execute([$id]);
        $folderRow = $statement->fetch();

        $isPersonalFolder = $folderRow && gc_bool_value($folderRow['is_personal']);
        if ($isPersonalFolder && (int) ($folderRow['parent_id'] ?? 0) === 0) {
            gc_flash('error', 'Личную папку нельзя переименовать.');
            gc_redirect($returnUrl);
        }
        $allowed = $folderRow && ($isPersonalFolder
            ? (int) $folderRow['created_by'] === $uid
            : ((int) $folderRow['created_by'] === $uid || gc_is_admin() || gc_has_right('edit_any_file')));
        if (!$allowed) {
            gc_flash('error', 'Переименовать папку может только её создатель или администратор.');
            gc_redirect($returnUrl);
        }
        if ($name === '' || gc_strlen($name) > 80) {
            gc_flash('error', 'Название папки должно быть от 1 до 80 символов.');
            gc_redirect($returnUrl);
        }

        $parentId = (int) ($folderRow['parent_id'] ?? 0);
        $statement = $pdo->prepare('SELECT 1 FROM folders WHERE COALESCE(parent_id, 0) = ? AND lower(name) = lower(?) AND is_personal = ? AND id <> ?');
        $statement->execute([$parentId, $name, $isPersonalFolder ? 'true' : 'false', $id]);
        if ($statement->fetchColumn()) {
            gc_flash('error', 'Папка с таким названием уже есть в этом месте.');
            gc_redirect($returnUrl);
        }

        $pdo->prepare('UPDATE folders SET name = ? WHERE id = ?')->execute([$name, $id]);
        gc_flash('ok', 'Папка переименована.');
        gc_redirect($returnUrl);
    }

    if ($action === 'folder_delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare('SELECT id, name, parent_id, created_by, is_personal FROM folders WHERE id = ?');
        $statement->execute([$id]);
        $folderRow = $statement->fetch();

        $isPersonalFolder = $folderRow && gc_bool_value($folderRow['is_personal']);
        if ($isPersonalFolder && (int) ($folderRow['parent_id'] ?? 0) === 0) {
            gc_flash('error', 'Корневую личную папку нельзя удалить.');
            gc_redirect($returnUrl);
        }
        if ($isPersonalFolder) {
            $allowed = $folderRow && (int) $folderRow['created_by'] === $uid;
        } else {
            $allowed = $folderRow && ((int) $folderRow['created_by'] === $uid || gc_is_admin() || gc_has_right('edit_any_file'));
        }
        if (!$allowed) {
            gc_flash('error', 'Удалить папку может только её создатель или администратор.');
            gc_redirect($returnUrl);
        }

        $parentId = (int) ($folderRow['parent_id'] ?? 0);
        $mode = isset($_POST['mode']) && $_POST['mode'] === 'with_files' ? 'with_files' : 'move';
        $treeIds = [$id];
        $next = [];
        do {
            $known = implode(',', array_map('intval', $treeIds));
            $statement = $pdo->query('SELECT id FROM folders WHERE parent_id IN (' . $known . ')');
            $next = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $next = array_values(array_diff($next, $treeIds));
            if ($next !== []) {
                $treeIds = array_merge($treeIds, $next);
            }
        } while ($next !== []);
        $treeIn = implode(',', array_map('intval', $treeIds));
        $targetParentId = $isPersonalFolder ? gc_personal_folder_id($pdo, (int) $folderRow['created_by']) : 0;
        $filePaths = [];
        $fileCount = 0;

        try {
            $pdo->beginTransaction();
            if ($mode === 'with_files') {
                $statement = $pdo->query('SELECT filename FROM files WHERE folder_id IN (' . $treeIn . ')');
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
                    $storedName = (string) $storedName;
                    if ($storedName !== '' && basename($storedName) === $storedName) {
                        $filePaths[] = $uploadDir . '/' . $storedName;
                    }
                    $fileCount++;
                }
                $pdo->prepare('DELETE FROM files WHERE folder_id IN (' . $treeIn . ')')->execute();
            } else {
                $statement = $pdo->prepare('UPDATE files SET folder_id = ? WHERE folder_id IN (' . $treeIn . ')');
                $statement->execute([$targetParentId > 0 ? $targetParentId : null]);
                $fileCount = $statement->rowCount();
            }
            $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            gc_flash('error', 'Не удалось удалить папку. Попробуйте ещё раз.');
            gc_redirect($returnUrl);
        }

        $unlinkFailed = 0;
        foreach ($filePaths as $filePath) {
            if (is_file($filePath) && !@unlink($filePath)) {
                $unlinkFailed++;
            }
        }
        if ($mode === 'with_files') {
            $message = 'Папка удалена вместе с файлами: ' . $fileCount . '.';
            if ($unlinkFailed > 0) {
                $message .= ' Не удалось удалить файлов с диска: ' . $unlinkFailed . '.';
            }
            gc_flash($unlinkFailed > 0 ? 'error' : 'ok', $message);
        } else {
            gc_flash('ok', 'Папка удалена. Файлы перемещены в корень: ' . $fileCount . '.');
        }
        gc_redirect(gc_storage_link($parentId, $returnSort, $returnDir, $returnScope));
    }

    if (in_array($action, ['folder_tag_add', 'folder_tag_delete', 'folder_tags_sync'], true)) {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare('SELECT id, name, created_by, is_personal FROM folders WHERE id = ?');
        $statement->execute([$id]);
        $folderRow = $statement->fetch();

        $isPersonalFolder = $folderRow && gc_bool_value($folderRow['is_personal']);
        $allowed = $folderRow && ($isPersonalFolder
            ? (int) $folderRow['created_by'] === $uid
            : ((int) $folderRow['created_by'] === $uid || gc_is_admin() || gc_has_right('edit_any_file')));
        if (!$allowed) {
            gc_flash('error', 'Настраивать теги папки может только её создатель или администратор.');
            gc_redirect($returnUrl);
        }

        if ($action === 'folder_tags_sync') {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM folder_tags WHERE folder_id = ?');
            $statement->execute([$id]);
            if ((int) $statement->fetchColumn() === 0) {
                gc_flash('error', 'У папки нет тегов — сначала добавьте хотя бы один.');
                gc_redirect($returnUrl);
            }

            $statement = $pdo->prepare(
                'WITH RECURSIVE sub AS ('
                . ' SELECT id FROM folders WHERE id = ?'
                . ' UNION ALL'
                . ' SELECT f.id FROM folders f JOIN sub s ON f.parent_id = s.id'
                . ')'
                . ' INSERT INTO file_tags (file_id, tag_id)'
                . ' SELECT f.id, ft.tag_id FROM files f'
                . ' JOIN sub s ON s.id = f.folder_id'
                . ' CROSS JOIN folder_tags ft'
                . ' WHERE ft.folder_id = ?'
                . ' ON CONFLICT DO NOTHING'
            );
            $statement->execute([$id, $id]);
            gc_flash('ok', 'Теги папки синхронизированы с файлами: добавлено связей — ' . $statement->rowCount() . '.');
            gc_redirect($returnUrl);
        }

        $tagId = isset($_POST['tag_id']) ? (int) $_POST['tag_id'] : 0;
        $statement = $pdo->prepare('SELECT 1 FROM tags WHERE id = ?');
        $statement->execute([$tagId]);
        if (!$statement->fetchColumn()) {
            gc_flash('error', 'Тег не найден.');
            gc_redirect($returnUrl);
        }

        if ($action === 'folder_tag_add') {
            $pdo->prepare('INSERT INTO folder_tags (folder_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
                ->execute([$id, $tagId]);
            gc_flash('ok', 'Тег добавлен папке.');
        } else {
            $pdo->prepare('DELETE FROM folder_tags WHERE folder_id = ? AND tag_id = ?')->execute([$id, $tagId]);
            gc_flash('ok', 'Тег убран у папки.');
        }
        gc_redirect($returnUrl);
    }

    if (in_array($action, ['file_tag_add', 'file_tag_delete'], true)) {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare(
            'SELECT f.id, f.original_name, f.uploader_id, COALESCE(fo.is_personal, FALSE) AS is_personal,'
            . ' fo.created_by AS folder_owner'
            . ' FROM files f LEFT JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?'
        );
        $statement->execute([$id]);
        $fileRow = $statement->fetch();

        $personalFile = $fileRow && gc_bool_value($fileRow['is_personal']);
        $allowed = $fileRow && ($personalFile
            ? ((int) $fileRow['uploader_id'] === $uid && (int) $fileRow['folder_owner'] === $uid)
            : ((int) $fileRow['uploader_id'] === $uid || gc_is_admin() || gc_has_right('edit_any_file')));
        if (!$allowed) {
            gc_flash('error', 'Менять теги файла может только автор или администратор.');
            gc_redirect($returnUrl);
        }

        $tagId = isset($_POST['tag_id']) ? (int) $_POST['tag_id'] : 0;
        $statement = $pdo->prepare('SELECT 1 FROM tags WHERE id = ?');
        $statement->execute([$tagId]);
        if (!$statement->fetchColumn()) {
            gc_flash('error', 'Тег не найден.');
            gc_redirect($returnUrl);
        }

        if ($action === 'file_tag_add') {
            $pdo->prepare('INSERT INTO file_tags (file_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
                ->execute([$id, $tagId]);
            gc_flash('ok', 'Тег добавлен файлу.');
        } else {
            $pdo->prepare('DELETE FROM file_tags WHERE file_id = ? AND tag_id = ?')->execute([$id, $tagId]);
            gc_flash('ok', 'Тег убран у файла.');
        }
        gc_redirect($returnUrl);
    }

    if ($action === 'file_move') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $targetFolder = isset($_POST['target_folder']) ? (int) $_POST['target_folder'] : 0;
        if ($targetFolder < 0) {
            $targetFolder = 0;
        }

        $statement = $pdo->prepare(
            'SELECT f.id, f.original_name, f.uploader_id, f.folder_id,'
            . ' COALESCE(fo.is_personal, FALSE) AS is_personal, fo.created_by AS folder_owner'
            . ' FROM files f LEFT JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?'
        );
        $statement->execute([$id]);
        $fileRow = $statement->fetch();

        if (!$fileRow) {
            gc_flash('error', 'Файл не найден.');
            gc_redirect($returnUrl);
        }
        $sourcePersonal = gc_bool_value($fileRow['is_personal']);
        if ($sourcePersonal) {
            if ((int) $fileRow['uploader_id'] !== $uid || (int) $fileRow['folder_owner'] !== $uid) {
                gc_flash('error', 'Личный файл может перемещать только его владелец.');
                gc_redirect($returnUrl);
            }
        } elseif ((int) $fileRow['uploader_id'] !== $uid && !gc_has_right('edit_any_file')) {
            gc_flash('error', 'Перемещать этот файл может только автор или администратор с правом редактирования.');
            gc_redirect($returnUrl);
        }

        $targetLabel = 'в корень';
        if ($targetFolder > 0) {
            $statement = $pdo->prepare('SELECT name, is_personal, created_by FROM folders WHERE id = ?');
            $statement->execute([$targetFolder]);
            $targetRow = $statement->fetch();
            if (!$targetRow) {
                gc_flash('error', 'Папка назначения не найдена.');
                gc_redirect($returnUrl);
            }
            $targetPersonal = gc_bool_value($targetRow['is_personal']);
            if ($targetPersonal !== $sourcePersonal) {
                gc_flash('error', 'Нельзя смешивать личные и общие файлы.');
                gc_redirect($returnUrl);
            }
            if ($targetPersonal && ((int) $targetRow['created_by'] !== $uid || (int) $fileRow['uploader_id'] !== $uid)) {
                gc_flash('error', 'В личную папку можно переместить только свой файл.');
                gc_redirect($returnUrl);
            }
            $targetLabel = 'в «' . (string) $targetRow['name'] . '»';
        } elseif ($sourcePersonal) {
            if ($personalRootId <= 0) {
                gc_flash('error', 'Личный корень не найден.');
                gc_redirect($returnUrl);
            }
            $targetFolder = (int) $personalRootId;
            $targetLabel = 'в корень «Личные файлы»';
        }

        $currentFolder = $fileRow['folder_id'] !== null ? (int) $fileRow['folder_id'] : 0;
        if ($currentFolder === $targetFolder) {
            gc_flash('info', 'Файл уже находится в этом месте.');
            gc_redirect($returnUrl);
        }

        $pdo->prepare('UPDATE files SET folder_id = ? WHERE id = ?')
            ->execute([$targetFolder > 0 ? $targetFolder : null, $id]);
        gc_flash('ok', 'Файл «' . (string) $fileRow['original_name'] . '» перемещён ' . $targetLabel . '.');
        gc_redirect($returnUrl);
    }

    if ($action === 'folder_move') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $targetFolder = isset($_POST['target_folder']) ? (int) $_POST['target_folder'] : 0;
        if ($targetFolder < 0) {
            $targetFolder = 0;
        }

        $statement = $pdo->prepare('SELECT id, name, parent_id, created_by, is_personal FROM folders WHERE id = ?');
        $statement->execute([$id]);
        $folderRow = $statement->fetch();
        if (!$folderRow) {
            gc_flash('error', 'Папка не найдена.');
            gc_redirect($returnUrl);
        }

        $sourcePersonal = gc_bool_value($folderRow['is_personal']);
        $sourceRoot = $folderRow['parent_id'] === null;
        $allowed = $sourcePersonal
            ? (int) $folderRow['created_by'] === $uid
            : ((int) $folderRow['created_by'] === $uid || gc_is_admin() || gc_has_right('edit_any_file'));
        if (!$allowed || ($sourcePersonal && $sourceRoot)) {
            gc_flash('error', 'Эту папку нельзя переместить.');
            gc_redirect($returnUrl);
        }

        if ($sourcePersonal && $targetFolder <= 0) {
            if ($personalRootId <= 0) {
                gc_flash('error', 'Личный корень не найден.');
                gc_redirect($returnUrl);
            }
            $targetFolder = (int) $personalRootId;
        }

        $targetLabel = 'в корень';
        if ($targetFolder > 0) {
            $statement = $pdo->prepare('SELECT id, name, parent_id, created_by, is_personal FROM folders WHERE id = ?');
            $statement->execute([$targetFolder]);
            $targetRow = $statement->fetch();
            if (!$targetRow) {
                gc_flash('error', 'Папка назначения не найдена.');
                gc_redirect($returnUrl);
            }
            $targetPersonal = gc_bool_value($targetRow['is_personal']);
            if ($targetPersonal !== $sourcePersonal) {
                gc_flash('error', 'Нельзя перемешать личные и общие папки.');
                gc_redirect($returnUrl);
            }
            if ($targetPersonal && (int) $targetRow['created_by'] !== $uid) {
                gc_flash('error', 'В личную папку можно переместить только свою папку.');
                gc_redirect($returnUrl);
            }
            if ($targetFolder === $id) {
                gc_flash('error', 'Нельзя переместить папку внутрь себя.');
                gc_redirect($returnUrl);
            }
            $statement = $pdo->prepare(
                'WITH RECURSIVE descendants AS ('
                . ' SELECT id FROM folders WHERE id = ?'
                . ' UNION ALL'
                . ' SELECT f.id FROM folders f JOIN descendants d ON f.parent_id = d.id'
                . ') SELECT 1 FROM descendants WHERE id = ? LIMIT 1'
            );
            $statement->execute([$id, $targetFolder]);
            if ($statement->fetchColumn()) {
                gc_flash('error', 'Нельзя переместить папку внутрь её вложенной папки.');
                gc_redirect($returnUrl);
            }
            $targetLabel = 'в «' . (string) $targetRow['name'] . '»';
        } elseif ($sourcePersonal) {
            gc_flash('error', 'Папка назначения не найдена.');
            gc_redirect($returnUrl);
        }

        $currentParent = $folderRow['parent_id'] !== null ? (int) $folderRow['parent_id'] : 0;
        if ($currentParent === $targetFolder) {
            gc_flash('info', 'Папка уже находится в этом месте.');
            gc_redirect($returnUrl);
        }

        $statement = $pdo->prepare('SELECT 1 FROM folders WHERE COALESCE(parent_id, 0) = ? AND lower(name) = lower(?) AND is_personal = ? AND id <> ?');
        $statement->execute([$targetFolder, (string) $folderRow['name'], $sourcePersonal ? 'true' : 'false', $id]);
        if ($statement->fetchColumn()) {
            gc_flash('error', 'Папка с таким названием уже есть в этом месте.');
            gc_redirect($returnUrl);
        }

        $pdo->prepare('UPDATE folders SET parent_id = ? WHERE id = ?')
            ->execute([$targetFolder > 0 ? $targetFolder : null, $id]);
        gc_flash('ok', 'Папка «' . (string) $folderRow['name'] . '» перемещена ' . $targetLabel . '.');
        gc_redirect($returnUrl);
    }

    if ($action === 'file_delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare(
            'SELECT f.id, f.filename, f.uploader_id, COALESCE(fo.is_personal, FALSE) AS is_personal,'
            . ' fo.created_by AS folder_owner'
            . ' FROM files f LEFT JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?'
        );
        $statement->execute([$id]);
        $fileRow = $statement->fetch();

        if (!$fileRow) {
            gc_flash('error', 'Файл не найден.');
            gc_redirect($returnUrl);
        }
        $personalFile = gc_bool_value($fileRow['is_personal']);
        if ($personalFile) {
            if ((int) $fileRow['uploader_id'] !== $uid || (int) $fileRow['folder_owner'] !== $uid) {
                gc_flash('error', 'Личный файл может удалить только его владелец.');
                gc_redirect($returnUrl);
            }
        } elseif ((int) $fileRow['uploader_id'] !== $uid && !gc_has_right('delete_any_file')) {
            gc_flash('error', 'Удалить этот файл может только автор или администратор с правом удаления.');
            gc_redirect($returnUrl);
        }

        $stored = (string) $fileRow['filename'];
        if ($stored !== '' && !str_contains($stored, '/') && !str_contains($stored, '\\')) {
            $path = $uploadDir . '/' . $stored;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        gc_flash('ok', 'Файл удалён.');
        gc_redirect($returnUrl);
    }

    if ($action === 'file_pin') {
        if (!gc_has_right('pin_files')) {
            gc_flash('error', 'Закреплять файлы может только администратор с правом закрепления.');
            gc_redirect($returnUrl);
        }
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $pdo->prepare('UPDATE files SET is_pinned = NOT is_pinned WHERE id = ?')->execute([$id]);
        gc_redirect($returnUrl);
    }

    gc_redirect($returnUrl);
}

$folders = $pdo->query(
    'SELECT fo.id, fo.name, fo.parent_id, fo.created_by, fo.is_personal, u.login AS creator_login'
    . ' FROM folders fo JOIN users u ON u.id = fo.created_by'
    . ' ORDER BY lower(fo.name), fo.id'
)->fetchAll();
$regularMap = [];
$personalMap = [];
foreach ($folders as $folderRow) {
    $id = (int) $folderRow['id'];
    $parent = $folderRow['parent_id'] !== null ? (int) $folderRow['parent_id'] : 0;
    $node = [
        'id' => $id,
        'name' => (string) $folderRow['name'],
        'parent_id' => $parent,
        'created_by' => (int) $folderRow['created_by'],
        'is_personal' => gc_bool_value($folderRow['is_personal']),
        'creator_login' => (string) $folderRow['creator_login'],
    ];
    if ($node['is_personal']) {
        if ($adminPersonal || $node['created_by'] === $uid) {
            $personalMap[$id] = $node;
        }
        continue;
    }
    $regularMap[$id] = $node;
}

$folderTags = [];
$folderTagRows = [];
foreach ($pdo->query('SELECT ft.folder_id, t.id AS tag_id, t.name FROM folder_tags ft JOIN tags t ON t.id = ft.tag_id ORDER BY t.name')->fetchAll() as $tagRow) {
    $folderTags[(int) $tagRow['folder_id']][] = (string) $tagRow['name'];
    $folderTagRows[(int) $tagRow['folder_id']][] = [
        'id' => (int) $tagRow['tag_id'],
        'name' => (string) $tagRow['name'],
    ];
}

$userTagSet = [];
if (isset($user['tags']) && is_array($user['tags'])) {
    foreach ($user['tags'] as $tagName) {
        $userTagSet[(string) $tagName] = true;
    }
}

$viewAll = gc_has_right('view_any_file');
$visibleFolderIds = [];
foreach ($regularMap as $id => $folderRow) {
    $tagsForFolder = $folderTagRows[$id] ?? [];
    $visible = $tagsForFolder === [] || $viewAll || (int) $folderRow['created_by'] === $uid;
    if (!$visible) {
        foreach ($tagsForFolder as $tagForFolder) {
            if (isset($userTagSet[$tagForFolder['name']])) {
                $visible = true;
                break;
            }
        }
    }
    if ($visible) {
        $visibleFolderIds[$id] = true;
    }
}
$regularMap = array_intersect_key($regularMap, $visibleFolderIds);
do {
    $changed = false;
    foreach ($regularMap as $id => $folderRow) {
        if ($folderRow['parent_id'] > 0 && !isset($regularMap[$folderRow['parent_id']])) {
            unset($regularMap[$id]);
            $changed = true;
        }
    }
} while ($changed);

$regularChildren = [];
foreach ($regularMap as $id => $folderRow) {
    $regularChildren[$folderRow['parent_id']][] = $id;
}

$personalChildren = [];
foreach ($personalMap as $id => $folderRow) {
    $personalChildren[$folderRow['parent_id']][] = $id;
}

if (!isset($personalMap[$personalRootId])) {
    $personalMap = [];
    $personalChildren = [];
}

if ($adminPersonal) {
    $folderMap = $personalMap;
    $children = $personalChildren;
    if ($folderId > 0 && !isset($folderMap[$folderId])) {
        $folderId = 0;
    }
} elseif ($scope === 'personal') {
    $folderMap = $personalMap;
    $children = $personalChildren;
    if ($folderId <= 0 || !isset($folderMap[$folderId])) {
        $folderId = $personalRootId;
    }
} else {
    $folderMap = $regularMap;
    $children = $regularChildren;
    if ($folderId > 0 && !isset($folderMap[$folderId])) {
        $folderId = 0;
    }
}

$pathFolders = [];
$cursor = $folderId;
$seen = [];
while ($cursor > 0 && isset($folderMap[$cursor]) && !isset($seen[$cursor])) {
    $seen[$cursor] = true;
    array_unshift($pathFolders, $folderMap[$cursor]);
    $cursor = $folderMap[$cursor]['parent_id'];
}

$personalIds = array_keys($personalMap);
$personalIn = $personalIds !== [] ? implode(',', array_map('intval', $personalIds)) : 'NULL';
$personalFileSql = $adminPersonal
    ? ' AND f.folder_id IN (' . $personalIn . ')'
    : ' AND f.uploader_id = ? AND f.folder_id IN (' . $personalIn . ')';
$personalFileParams = $adminPersonal ? [] : [$uid];

$regularIds = array_keys($regularMap);
$visibleRegularSql = $regularIds !== []
    ? ' AND (f.folder_id IS NULL OR f.folder_id IN (' . implode(',', array_map('intval', $regularIds)) . '))'
    : ' AND f.folder_id IS NULL';
$nonPersonalSql = ' AND (f.folder_id IS NULL OR f.folder_id NOT IN (SELECT id FROM folders WHERE is_personal = TRUE))';
if ($viewAll) {
    $allAccessSql = $visibleRegularSql . $nonPersonalSql;
    $allAccessParams = [];
} else {
    $allAccessSql = ' AND (f.uploader_id = ?'
        . ' OR EXISTS (SELECT 1 FROM file_access fa WHERE fa.file_id = f.id AND fa.user_id = ?)'
        . ' OR EXISTS (SELECT 1 FROM file_tags ft JOIN user_tags ut ON ut.tag_id = ft.tag_id'
        . ' WHERE ft.file_id = f.id AND ut.user_id = ?))' . $visibleRegularSql . $nonPersonalSql;
    $allAccessParams = [$uid, $uid, $uid];
}

if ($scope === 'personal') {
    $accessSql = $personalFileSql;
    $accessParams = $personalFileParams;
} else {
    $accessSql = $allAccessSql;
    $accessParams = $allAccessParams;
}

$filterSql = $accessSql;
$filterParams = $accessParams;

$countStatement = $pdo->prepare('SELECT f.folder_id, COUNT(*) AS c FROM files f WHERE 1 = 1' . $filterSql . ' GROUP BY f.folder_id');
$countStatement->execute($filterParams);
$folderCounts = [];
$totalVisible = 0;
foreach ($countStatement->fetchAll() as $countRow) {
    $key = $countRow['folder_id'] !== null ? (int) $countRow['folder_id'] : 0;
    $count = (int) $countRow['c'];
    $folderCounts[$key] = $count;
    $totalVisible += $count;
}
$folderCounts[0] = $folderCounts[0] ?? 0;

$scopeCounts = [];
$scopeStatement = $pdo->prepare('SELECT COUNT(*) FROM files f WHERE 1 = 1' . $allAccessSql);
$scopeStatement->execute($allAccessParams);
$scopeCounts['all'] = (int) $scopeStatement->fetchColumn();

$scopeStatement = $pdo->prepare('SELECT COUNT(*) FROM files f WHERE 1 = 1' . $personalFileSql);
$scopeStatement->execute($personalFileParams);
$scopeCounts['personal'] = (int) $scopeStatement->fetchColumn();

$orderBy = [
    'name' => 'lower(f.original_name)',
    'date' => 'f.uploaded_at',
    'size' => 'f.file_size',
    'type' => "lower(coalesce(f.mime_type, ''))",
][$sort];
$direction = $dir === 'asc' ? 'ASC' : 'DESC';

$fileStatement = $pdo->prepare(
    'SELECT f.id, f.original_name, f.file_size, f.mime_type, f.uploaded_at, f.is_pinned, f.folder_id, f.uploader_id, u.login AS uploader'
    . ' FROM files f JOIN users u ON u.id = f.uploader_id'
    . ' WHERE f.folder_id IS NOT DISTINCT FROM ?' . $filterSql
    . ' ORDER BY f.is_pinned DESC, ' . $orderBy . ' ' . $direction . ', f.id DESC'
);
$fileStatement->execute(array_merge([$folderId > 0 ? $folderId : null], $filterParams));
$files = $fileStatement->fetchAll();

$fileTags = [];
$fileTagRowsById = [];
if ($files !== []) {
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $files);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tagStatement = $pdo->prepare(
        'SELECT ft.file_id, t.id AS tag_id, t.name FROM file_tags ft JOIN tags t ON t.id = ft.tag_id'
        . ' WHERE ft.file_id IN (' . $in . ') ORDER BY t.name'
    );
    $tagStatement->execute($ids);
    foreach ($tagStatement->fetchAll() as $tagRow) {
        $fileTags[(int) $tagRow['file_id']][] = (string) $tagRow['name'];
        $fileTagRowsById[(int) $tagRow['file_id']][] = [
            'id' => (int) $tagRow['tag_id'],
            'name' => (string) $tagRow['name'],
        ];
    }
}

$accessStatement = $pdo->prepare('SELECT file_id FROM file_access WHERE user_id = ?');
$accessStatement->execute([$uid]);
$directAccess = [];
foreach ($accessStatement->fetchAll(PDO::FETCH_COLUMN) as $fileId) {
    $directAccess[(int) $fileId] = true;
}

$canPin = gc_has_right('pin_files');
$canDeleteAny = gc_has_right('delete_any_file');
$canEditAny = gc_has_right('edit_any_file');
$isPersonalRootRow = static function (array $folderRow): bool {
    return gc_bool_value($folderRow['is_personal'] ?? false)
        && (int) ($folderRow['parent_id'] ?? 0) === 0;
};
$canManageCurrent = !$adminPersonal && $folderId > 0
    && !$isPersonalRootRow($folderMap[$folderId] ?? [])
    && ((int) ($folderMap[$folderId]['created_by'] ?? 0) === $uid || gc_is_admin() || $canEditAny);

$rootFolderId = $adminPersonal ? 0 : ($scope === 'personal' ? $personalRootId : 0);

$renderTree = function (int $parent, int $depth = 0) use (&$renderTree, $children, $folderMap, $folderCounts, $folderId, $sort, $dir, $scope, $adminPersonal): void {
    if (empty($children[$parent])) {
        return;
    }
    echo '<ul>';
    foreach ($children[$parent] as $id) {
        $node = $folderMap[$id];
        $hasChildren = !empty($children[$id]);
        $active = $id === $folderId;
        $count = $folderCounts[$id] ?? 0;
        $treeLabel = $node['name'];
        if ($adminPersonal && $node['parent_id'] === 0) {
            $treeLabel .= ' · ' . $node['creator_login'];
        }
        echo '<li class="gc_tree_item">';
        echo '<div class="gc_tree_row' . ($active ? ' is-active' : '') . '">';
        echo '<button type="button" class="gc_tree_toggle' . ($hasChildren ? '' : ' is-empty') . '"'
            . ' aria-expanded="true"' . ($hasChildren ? ' aria-controls="tree-' . $id . '"' : '') . '>'
            . '<span class="gc_ico gc_ico--chevron"></span></button>';
        echo '<a class="gc_tree_link" data-drop-folder="' . $id . '" href="' . gc_h(gc_storage_link($id, $sort, $dir, $scope)) . '" title="' . gc_h($treeLabel) . '">';
        echo '<span class="gc_ico gc_ico--folder"></span>';
        echo '<span class="gc_tree_name">' . gc_h($treeLabel) . '</span>';
        echo '<span class="gc_tree_count">' . $count . '</span>';
        echo '</a></div>';
        if ($hasChildren) {
            echo '<ul id="tree-' . $id . '">';
            $renderTree($id, $depth + 1);
            echo '</ul>';
        }
        echo '</li>';
    }
    echo '</ul>';
};

$currentName = $folderId > 0 ? $folderMap[$folderId]['name'] : ($adminPersonal ? 'Все личные' : 'Корень хранилища');
if ($adminPersonal && $folderId > 0 && (int) $folderMap[$folderId]['parent_id'] === 0) {
    $currentName .= ' · ' . $folderMap[$folderId]['creator_login'];
}
$maxSizeLabel = gc_size(gc_max_upload());
$allTags = $pdo->query('SELECT id, name FROM tags ORDER BY name')->fetchAll();
$tagListAttr = static function (array $rows): string {
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
    return gc_h($json === false ? '[]' : $json);
};
$canManageFolderRow = static function (array $folderRow) use ($uid, $canEditAny, $isPersonalRootRow): bool {
    if ($isPersonalRootRow($folderRow)) {
        return false;
    }
    return (int) $folderRow['created_by'] === $uid || gc_is_admin() || $canEditAny;
};
$currentFolderTags = $folderTagRows[$folderId] ?? [];

$childFolders = $children[$folderId] ?? [];
$fileWord = static function (int $n): string {
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return 'файл';
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
        return 'файла';
    }
    return 'файлов';
};
$scopeLabels = ['all' => 'Все файлы', 'personal' => 'Личные файлы'];
$scopeIcons = ['all' => 'folder', 'personal' => 'user'];
$scopeLinks = [
    'all' => gc_storage_link(0, $sort, $dir, 'all'),
    'personal' => gc_storage_link($adminPersonal ? 0 : $personalRootId, $sort, $dir, 'personal'),
];
$rootFolderLabel = $adminPersonal ? 'Все личные' : ($scope === 'personal' ? 'Личные файлы' : 'Корень');
$roleTags = isset($user['tags']) && is_array($user['tags']) ? array_values($user['tags']) : [];
$rightLabels = [];
if (isset($user['rights']) && is_array($user['rights'])) {
    foreach ($user['rights'] as $rightName) {
        $rightLabels[] = GC_RIGHTS[$rightName] ?? (string) $rightName;
    }
}

gc_head('Хранилище', 'storage');
?>
<div class="gc_wrap gc_wrap--drive">
    <?php gc_flashes(); ?>

    <section class="gc_drive_head">
        <div>
            <div class="gc_hero_eyebrow">Файлы команды</div>
            <h1 class="gc_h1 gc_drive_title">Хранилище</h1>
            <p class="gc_drive_lead">Все общие файлы и личные файлы. Личные видит только тот, кто их загрузил<?= $adminPersonal ? ', и суперпользователь' : '' ?>.</p>
        </div>
        <div class="gc_drive_aside">
            <?php if ($diskFreeBytes !== null && $diskTotalBytes !== null && $diskUsedPercent !== null && $diskFreePercent !== null) {
                $diskRadius = 27;
                $diskCircumference = 2 * M_PI * $diskRadius;
                $diskOffset = $diskCircumference * (1 - ($diskUsedPercent / 100));
            ?>
            <div class="gc_disk<?= $diskFreePercent < 10 ? ' is-low' : '' ?>" role="status" aria-label="Свободно на диске <?= gc_h(gc_size($diskFreeBytes)) ?> из <?= gc_h(gc_size($diskTotalBytes)) ?>">
                <div class="gc_disk_ring">
                    <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                        <circle class="gc_disk_track" cx="32" cy="32" r="<?= $diskRadius ?>"></circle>
                        <circle class="gc_disk_fill" cx="32" cy="32" r="<?= $diskRadius ?>"
                            stroke-dasharray="<?= gc_h((string) $diskCircumference) ?>"
                            stroke-dashoffset="<?= gc_h((string) $diskOffset) ?>"></circle>
                    </svg>
                    <div class="gc_disk_center">
                        <b><?= gc_h(gc_size($diskFreeBytes)) ?></b>
                        <span>свободно</span>
                    </div>
                </div>
                <div class="gc_disk_meta">
                    <strong>Диск</strong>
                    <span>занято <?= gc_h((string) round($diskUsedPercent)) ?>%</span>
                    <small>из <?= gc_h(gc_size($diskTotalBytes)) ?></small>
                </div>
            </div>
            <?php } ?>
            <div class="gc_drive_stats">
                <div class="gc_hero_stat"><b><?= $totalVisible ?></b><span>файлов в этом виде</span></div>
                <div class="gc_hero_stat"><b><?= count($folderMap) ?></b><span>папок</span></div>
                <div class="gc_hero_stat"><b><?= count($roleTags) ?></b><span>ролей</span></div>
            </div>
        </div>
    </section>

    <div class="gc_storage gc_storage--drive">
        <aside class="gc_card gc_sidebar gc_drive_sidebar">
            <div class="gc_sidebar_head">
                <h2>Хранилище</h2>
                <span class="gc_badge"><?= count($folderMap) ?></span>
            </div>

            <nav class="gc_drive_scopes" aria-label="Разделы хранилища">
                <?php foreach ($scopeLabels as $scopeKey => $scopeLabel) { ?>
                <a class="gc_drive_scope<?= $scope === $scopeKey ? ' is-active' : '' ?>" href="<?= gc_h($scopeLinks[$scopeKey]) ?>">
                    <span class="gc_ico gc_ico--<?= gc_h($scopeIcons[$scopeKey]) ?>" aria-hidden="true"></span>
                    <span><?= gc_h($scopeLabel) ?></span>
                    <b><?= (int) $scopeCounts[$scopeKey] ?></b>
                </a>
                <?php } ?>
            </nav>

            <div class="gc_drive_side_title gc_tree_head">
                <span>Папки</span>
                <span class="gc_tree_nav" aria-label="Прокрутка дерева папок">
                    <button type="button" class="gc_tree_navbtn" data-tree-nav="-1" aria-label="Прокрутить влево" title="Влево">
                        <span class="gc_ico gc_ico--chevron"></span>
                    </button>
                    <button type="button" class="gc_tree_navbtn" data-tree-nav="1" aria-label="Прокрутить вправо" title="Вправо">
                        <span class="gc_ico gc_ico--chevron"></span>
                    </button>
                </span>
            </div>
            <div class="gc_tree_scroll">
            <ul class="gc_tree">
                <li class="gc_tree_item gc_tree_root">
                    <div class="gc_tree_row<?= $folderId === $rootFolderId ? ' is-active' : '' ?>">
                        <a class="gc_tree_link" data-drop-folder="<?= $rootFolderId ?>" href="<?= gc_h(gc_storage_link($rootFolderId, $sort, $dir, $scope)) ?>">
                            <span class="gc_ico gc_ico--folder"></span>
                            <span class="gc_tree_name"><?= gc_h($rootFolderLabel) ?></span>
                            <span class="gc_tree_count"><?= (int) ($folderCounts[$rootFolderId] ?? 0) ?></span>
                        </a>
                    </div>
                    <?php $renderTree($rootFolderId); ?>
                </li>
            </ul>
            </div>

            <?php if (!$adminPersonal) { ?>
            <details class="gc_tree_new" id="new-folder">
                <summary><span class="gc_ico gc_ico--plus"></span> Новая папка<?= $folderId > 0 ? ' здесь' : '' ?></summary>
                <form method="post" action="storage.php">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="folder_create">
                    <input type="hidden" name="folder" value="<?= $folderId ?>">
                    <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                    <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                    <input class="gc_input" type="text" name="folder_name" maxlength="80" required placeholder="Название папки">
                    <button type="submit" class="gc_btn gc_btn--outline gc_btn--sm gc_btn--block">Создать</button>
                </form>
            </details>

            <?php if ($canManageCurrent) { ?>
            <details class="gc_tree_manage">
                <summary><span class="gc_ico gc_ico--gear"></span> Управление папкой</summary>
                <form method="post" action="storage.php">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="folder_rename">
                    <input type="hidden" name="id" value="<?= $folderId ?>">
                    <input type="hidden" name="folder" value="<?= $folderId ?>">
                    <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                    <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                    <input class="gc_input" type="text" name="folder_name" maxlength="80" required value="<?= gc_h($folderMap[$folderId]['name']) ?>">
                    <button type="submit" class="gc_btn gc_btn--ghost gc_btn--sm gc_btn--block">Переименовать</button>
                </form>
                <?php if ($scope !== 'personal' || $folderId !== $personalRootId) { ?>
                <button type="button" class="gc_btn gc_btn--ghost gc_btn--sm gc_btn--block gc_text-danger"
                        data-folder-delete="<?= $folderId ?>"
                        data-folder-delete-name="<?= gc_h($folderMap[$folderId]['name']) ?>">Удалить папку</button>
                <?php } ?>
            </details>
            <?php } ?>
            <?php } ?>

            <div class="gc_drive_roles">
                <div class="gc_drive_side_title">Ваши роли</div>
                <div class="gc_taglist">
                    <?php if ($roleTags === []) { ?>
                    <span class="gc_tag gc_tag--xs">Участник</span>
                    <?php } else { ?>
                    <?php foreach ($roleTags as $roleTag) { ?>
                    <span class="gc_tag gc_tag--xs gc_tag--accent"><?= gc_h((string) $roleTag) ?></span>
                    <?php } ?>
                    <?php } ?>
                </div>
                <small class="gc_muted"><?= $rightLabels === [] ? 'Базовые права участника' : gc_h(implode(' · ', $rightLabels)) ?></small>
            </div>
        </aside>

        <div class="gc_storage_main" data-drive-view="grid">
            <div class="gc_drive_bar">
                <div class="gc_crumbs">
                    <a href="<?= gc_h(gc_storage_link($rootFolderId, $sort, $dir, $scope)) ?>" data-drop-folder="<?= $rootFolderId ?>">Хранилище</a>
                    <?php foreach ($pathFolders as $index => $pathFolder) { ?>
                    <span class="sep">/</span>
                    <?php if ($index === count($pathFolders) - 1) { ?>
                    <span><?= gc_h($pathFolder['name']) ?></span>
                    <?php } else { ?>
                    <a href="<?= gc_h(gc_storage_link((int) $pathFolder['id'], $sort, $dir, $scope)) ?>" data-drop-folder="<?= (int) $pathFolder['id'] ?>"><?= gc_h($pathFolder['name']) ?></a>
                    <?php } ?>
                    <?php } ?>
                </div>

                <div class="gc_drive_bar_actions">
                    <div class="gc_toolbar_search">
                        <span class="gc_ico gc_ico--search"></span>
                        <input class="gc_input" type="search" data-file-search placeholder="Поиск в папке" aria-label="Поиск по имени файла">
                    </div>
                    <div class="gc_viewtoggle" role="group" aria-label="Вид">
                        <button type="button" class="gc_viewbtn is-active" data-view-btn="grid" title="Сетка" aria-label="Сетка" aria-pressed="true">
                            <span class="gc_ico gc_ico--grid"></span>
                        </button>
                        <button type="button" class="gc_viewbtn" data-view-btn="list" title="Список" aria-label="Список" aria-pressed="false">
                            <span class="gc_ico gc_ico--list"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="gc_drive_toolbar">
                <div class="gc_sort">
                    <span class="gc_sort_label">Сортировка</span>
                    <?php
                    $sortLabels = ['name' => 'Имя', 'date' => 'Дата', 'size' => 'Размер', 'type' => 'Тип'];
                    foreach ($sortLabels as $key => $label) {
                        $nextDir = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';
                        $active = $sort === $key;
                        ?>
                    <a class="gc_sortbtn<?= $active ? ' is-active' : '' ?><?= $active && $dir === 'desc' ? ' is-desc' : '' ?>"
                       href="<?= gc_h(gc_storage_link($folderId, $key, $nextDir, $scope)) ?>"
                       title="Сортировать по: <?= gc_h($label) ?>">
                        <?= gc_h($label) ?>
                        <?php if ($active) { ?><span class="gc_ico gc_ico--arrow"></span><?php } ?>
                    </a>
                    <?php } ?>
                </div>

                <span class="gc_toolbar_count"><?= count($files) ?> <?= $fileWord(count($files)) ?></span>

                <div class="gc_toolbar_actions">
                    <?php if ($folderId > 0) { ?>
                    <a class="gc_btn gc_btn--ghost gc_btn--sm" href="folder_download.php?folder=<?= $folderId ?>" title="Скачать папку архивом">
                        <span class="gc_ico gc_ico--download"></span> Скачать
                    </a>
                    <?php } ?>
                    <?php if ($canManageCurrent && $folderId > 0) { ?>
                    <button type="button" class="gc_btn gc_btn--ghost gc_btn--sm" title="Теги папки"
                            data-folder-tags="<?= $folderId ?>"
                            data-folder-name="<?= gc_h($currentName) ?>"
                            data-folder-tag-list="<?= $tagListAttr($currentFolderTags) ?>">
                        <span class="gc_ico gc_ico--permission"></span> Теги
                    </button>
                    <?php } ?>
                    <?php if (!$adminPersonal) { ?>
                    <button type="button" class="gc_btn gc_btn--primary" data-open-details="upload">
                        <span class="gc_ico gc_ico--upload"></span> Загрузить
                    </button>
                    <button type="button" class="gc_btn gc_btn--outline" data-open-details="new-folder">
                        <span class="gc_ico gc_ico--plus"></span> Папка
                    </button>
                    <?php } ?>
                </div>
            </div>

            <?php if (!$adminPersonal) { ?>
            <details class="gc_upload_panel" id="upload"<?= $files === [] && $childFolders === [] ? ' open' : '' ?>>
                <summary>
                    <h2>Загрузить файлы и папки</h2>
                    <span class="gc_upload_hintline"><?= gc_h($currentName) ?> · <?= gc_h($scopeLabels[$scope]) ?></span>
                    <span class="gc_ico gc_ico--chevron"></span>
                </summary>
                <form method="post" action="storage.php" enctype="multipart/form-data" id="gcUploadForm" data-upload-form>
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="folder" value="<?= $folderId ?>">
                    <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                    <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">

                    <div class="gc_upload_row">
                        <div class="gc_upload_pick">
                            <label class="gc_drop" for="file">
                                <input type="file" id="file" name="files[]" multiple>
                                <span class="gc_drop_icon gc_ico gc_ico--upload" aria-hidden="true"></span>
                                <strong>Перетащите файлы или папки</strong>
                                <span class="gc_drop_file" data-drop-name></span>
                                <small class="gc_drop_hint" data-drop-hint>Можно несколько · до <?= gc_h($maxSizeLabel) ?> каждый</small>
                            </label>
                            <div class="gc_upload_pick_foot">
                                <label class="gc_btn gc_btn--outline gc_btn--sm" for="dir_files">
                                    <span class="gc_ico gc_ico--folder"></span> Выбрать папку
                                </label>
                                <input type="file" id="dir_files" name="dir_files[]" webkitdirectory directory multiple hidden data-folder-input>
                                <span class="gc_drop_dir_name" data-dir-name></span>
                            </div>
                        </div>

                        <div>
                            <?php if ($allTags !== []) { ?>
                            <div class="gc_field">
                                <span class="gc_label">Теги видимости</span>
                                <div class="gc_checks">
                                    <?php foreach ($allTags as $tagRow) { ?>
                                    <label class="gc_check">
                                        <input type="checkbox" name="tags[]" value="<?= (int) $tagRow['id'] ?>">
                                        <span><?= gc_h($tagRow['name']) ?></span>
                                    </label>
                                    <?php } ?>
                                </div>
                            </div>
                            <?php } ?>

                            <div class="gc_field">
                                <label class="gc_label" for="logins">Личный доступ <span class="gc_muted">(логины через запятую)</span></label>
                                <input class="gc_input" id="logins" type="text" name="logins" placeholder="login1, login2">
                            </div>

                            <p class="gc_upload_note">
                                <?php if ($scope === 'personal') { ?>
                                Эти файлы видит только владелец, даже если у них есть теги или личный доступ.
                                <?php } else { ?>
                                Без тегов и личного доступа файл попадёт в «Личные файлы» и будет виден только вам.
                                <?php } ?>
                            </p>

                            <div class="gc_upload_progress" data-upload-progress hidden>
                                <div class="gc_upload_bar"><span data-upload-bar></span></div>
                                <div class="gc_upload_progress_meta">
                                    <span data-upload-percent>0%</span>
                                    <small data-upload-status>Загрузка…</small>
                                </div>
                            </div>

                            <button type="submit" class="gc_btn gc_btn--primary gc_btn--block">
                                <span class="gc_ico gc_ico--upload"></span> Загрузить файлы
                            </button>
                        </div>
                    </div>
                </form>
            </details>
            <?php } ?>

            <?php if ($childFolders !== []) { ?>
            <section class="gc_drive_section">
                <div class="gc_drive_section_head">
                    <h2>Папки</h2>
                    <span class="gc_badge"><?= count($childFolders) ?></span>
                </div>
                <div class="gc_drive_folders">
                    <?php foreach ($childFolders as $childId) {
                        $child = $folderMap[$childId];
                        $childCount = $folderCounts[$childId] ?? 0;
                        $childTagRows = $folderTagRows[$childId] ?? [];
                        $childTags = $folderTags[$childId] ?? [];
                        $canManageChild = !$adminPersonal && $canManageFolderRow($child);
                        $childLabel = $child['name'];
                        if ($adminPersonal && $child['parent_id'] === 0) {
                            $childLabel .= ' · ' . $child['creator_login'];
                        }
                        ?>
                    <div class="gc_drive_folder" data-drop-folder="<?= $childId ?>" data-folder-card data-folder-id="<?= $childId ?>" draggable="<?= $canManageChild ? 'true' : 'false' ?>">
                        <a class="gc_drive_folder_open" href="<?= gc_h(gc_storage_link($childId, $sort, $dir, $scope)) ?>" draggable="<?= $canManageChild ? 'true' : 'false' ?>">
                            <span class="gc_drive_folder_icon"><span class="gc_ico gc_ico--folder"></span></span>
                            <span class="gc_drive_folder_name"><?= gc_h($childLabel) ?></span>
                            <span class="gc_drive_folder_meta"><?= $childCount ?> <?= $fileWord($childCount) ?></span>
                            <?php if ($childTags !== []) { ?>
                            <span class="gc_drive_folder_tags">
                                <?php foreach (array_slice($childTags, 0, 2) as $folderTag) { ?>
                                <span class="gc_tag gc_tag--xs"><?= gc_h($folderTag) ?></span>
                                <?php } ?>
                                <?php if (count($childTags) > 2) { ?><span class="gc_tag gc_tag--xs gc_tag--more">+<?= count($childTags) - 2 ?></span><?php } ?>
                            </span>
                            <?php } ?>
                        </a>
                        <div class="gc_drive_folder_acts">
                            <a class="gc_iconbtn" href="folder_download.php?folder=<?= $childId ?>" title="Скачать папку" aria-label="Скачать папку">
                                <span class="gc_ico gc_ico--download"></span>
                            </a>
                            <?php if ($canManageChild) { ?>
                            <button type="button" class="gc_iconbtn" title="Теги папки" aria-label="Теги папки"
                                    data-folder-tags="<?= $childId ?>"
                                    data-folder-name="<?= gc_h($childLabel) ?>"
                                    data-folder-tag-list="<?= $tagListAttr($childTagRows) ?>">
                                <span class="gc_ico gc_ico--permission"></span>
                            </button>
                            <button type="button" class="gc_iconbtn" title="Редактировать папку" aria-label="Редактировать папку"
                                    data-folder-edit="<?= $childId ?>" data-folder-name="<?= gc_h($childLabel) ?>">
                                <span class="gc_ico gc_ico--rename"></span>
                            </button>
                            <button type="button" class="gc_iconbtn gc_iconbtn--danger" title="Удалить папку" aria-label="Удалить папку"
                                    data-folder-delete="<?= $childId ?>"
                                    data-folder-delete-name="<?= gc_h($child['name']) ?>">
                                <span class="gc_ico gc_ico--delete"></span>
                            </button>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </section>
            <?php } ?>

            <section class="gc_drive_section">
                <div class="gc_drive_section_head">
                    <h2>Файлы</h2>
                    <span class="gc_badge"><?= count($files) ?></span>
                    <?php if ($childFolders !== []) { ?>
                    <span class="gc_drive_hint">Перетащите файл или папку на папку, чтобы переместить</span>
                    <?php } ?>
                </div>

                <div class="gc_drive_grid" data-drive-grid>
                    <?php foreach ($files as $file) {
                        $fileId = (int) $file['id'];
                        $isPinned = (bool) $file['is_pinned'];
                        $isOwner = (int) $file['uploader_id'] === $uid;
                        $tags = $fileTags[$fileId] ?? [];
                        $hasDirect = isset($directAccess[$fileId]);
                        $hasTag = false;
                        foreach ($tags as $tagName) {
                            if (isset($userTagSet[$tagName])) {
                                $hasTag = true;
                                break;
                            }
                        }
                        $canDownload = $isSuperadmin || $isOwner || $hasDirect || $hasTag;
                        $previewKind = gc_preview_kind((string) ($file['mime_type'] ?? ''), (string) $file['original_name']);
                        $canPreview = $canDownload && $previewKind !== '';
                        $canDelete = !$adminPersonal && ($isOwner || $canDeleteAny);
                        $canMove = !$adminPersonal && ($isOwner || $canEditAny);
                        $canManageFile = !$adminPersonal && ($isOwner || $canEditAny || gc_is_admin());
                        $accessLabel = $isOwner ? 'Мой файл' : ($isSuperadmin ? 'Суперпользователь' : ($hasDirect ? 'Личный доступ' : ($hasTag ? 'По тегу' : ($viewAll ? 'Просмотр' : 'Только просмотр'))));
                        $accessTone = $isOwner ? 'accent' : ($hasDirect ? 'info' : 'violet');
                        ?>
                    <article class="gc_drive_tile<?= $isPinned ? ' is-pinned' : '' ?>" data-file-card data-file-id="<?= $fileId ?>" draggable="<?= $canMove ? 'true' : 'false' ?>" data-file-name="<?= gc_h($file['original_name']) ?>">
                        <?php if ($isPinned) { ?><span class="gc_drive_pin" title="Закреплён"><span class="gc_ico gc_ico--pin"></span></span><?php } ?>
                        <?php if ($canDownload) { ?>
                        <a class="gc_drive_open" href="download.php?id=<?= $fileId ?>" title="Скачать" draggable="false">
                        <?php } else { ?>
                        <span class="gc_drive_open is-locked" title="Файл виден, но скачивание не разрешено">
                        <?php } ?>
                            <span class="gc_drive_fileicon"><span class="gc_ico gc_ico--file"></span><b><?= gc_h(gc_ext_label((string) $file['original_name'])) ?></b></span>
                            <span class="gc_drive_name"><?= gc_h($file['original_name']) ?></span>
                            <span class="gc_drive_meta"><?= gc_h(gc_size((int) $file['file_size'])) ?> · <?= gc_h(gc_dt($file['uploaded_at'], 'd.m.Y')) ?></span>
                        <?php if ($canDownload) { ?></a><?php } else { ?></span><?php } ?>

                        <div class="gc_drive_tags">
                            <span class="gc_tag gc_tag--xs gc_tag--<?= gc_h($accessTone) ?>"><?= gc_h($accessLabel) ?></span>
                            <?php if ($adminPersonal) { ?>
                            <span class="gc_tag gc_tag--xs">Владелец: <?= gc_h($file['uploader']) ?></span>
                            <?php } ?>
                            <?php foreach (array_slice($tags, 0, 2) as $tagName) { ?>
                            <span class="gc_tag gc_tag--xs"><?= gc_h($tagName) ?></span>
                            <?php } ?>
                            <?php if (count($tags) > 2) { ?>
                            <span class="gc_tag gc_tag--xs gc_tag--more" title="<?= gc_h(implode(', ', $tags)) ?>">+<?= count($tags) - 2 ?></span>
                            <?php } ?>
                        </div>

                        <div class="gc_drive_tileacts">
                            <?php if ($canPreview) { ?>
                            <button type="button" class="gc_iconbtn" title="Открыть для просмотра" aria-label="Открыть для просмотра"
                                    data-file-preview="<?= $fileId ?>"
                                    data-file-name="<?= gc_h($file['original_name']) ?>"
                                    data-preview-kind="<?= gc_h($previewKind) ?>"
                                    data-preview-download="download.php?id=<?= $fileId ?>">
                                <span class="gc_ico gc_ico--eye"></span>
                            </button>
                            <?php } ?>
                            <?php if ($canDownload) { ?>
                            <a class="gc_iconbtn" href="download.php?id=<?= $fileId ?>" title="Скачать" aria-label="Скачать"><span class="gc_ico gc_ico--download"></span></a>
                            <?php } ?>
                            <?php if ($canManageFile) { ?>
                            <button type="button" class="gc_iconbtn" title="Теги файла" aria-label="Теги файла"
                                    data-file-tags="<?= $fileId ?>"
                                    data-file-name="<?= gc_h($file['original_name']) ?>"
                                    data-file-tag-list="<?= $tagListAttr($fileTagRowsById[$fileId] ?? []) ?>">
                                <span class="gc_ico gc_ico--permission"></span>
                            </button>
                            <?php } ?>
                            <?php if ($canPin && !$adminPersonal) { ?>
                            <form method="post" action="storage.php">
                                <?= gc_csrf_field() ?>
                                <input type="hidden" name="action" value="file_pin">
                                <input type="hidden" name="id" value="<?= $fileId ?>">
                                <input type="hidden" name="folder" value="<?= $folderId ?>">
                                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                                <button type="submit" class="gc_iconbtn<?= $isPinned ? ' is-active' : '' ?>" title="<?= $isPinned ? 'Открепить' : 'Закрепить' ?>" aria-label="<?= $isPinned ? 'Открепить' : 'Закрепить' ?>"><span class="gc_ico gc_ico--pin"></span></button>
                            </form>
                            <?php } ?>
                            <?php if ($canDelete) { ?>
                            <form method="post" action="storage.php" data-confirm="Удалить файл «<?= gc_h($file['original_name']) ?>» безвозвратно?">
                                <?= gc_csrf_field() ?>
                                <input type="hidden" name="action" value="file_delete">
                                <input type="hidden" name="id" value="<?= $fileId ?>">
                                <input type="hidden" name="folder" value="<?= $folderId ?>">
                                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                                <button type="submit" class="gc_iconbtn gc_iconbtn--danger" title="Удалить" aria-label="Удалить"><span class="gc_ico gc_ico--delete"></span></button>
                            </form>
                            <?php } ?>
                        </div>
                    </article>
                    <?php } ?>
                </div>

                <div class="gc_filetable" data-drive-list>
                    <div class="gc_files_head">
                        <span>Файл</span>
                        <span>Доступ и теги</span>
                        <span>Размер</span>
                        <span>Дата</span>
                        <span class="is-right">Действия</span>
                    </div>

                    <?php foreach ($files as $file) {
                        $fileId = (int) $file['id'];
                        $isPinned = (bool) $file['is_pinned'];
                        $isOwner = (int) $file['uploader_id'] === $uid;
                        $tags = $fileTags[$fileId] ?? [];
                        $hasDirect = isset($directAccess[$fileId]);
                        $hasTag = false;
                        foreach ($tags as $tagName) {
                            if (isset($userTagSet[$tagName])) {
                                $hasTag = true;
                                break;
                            }
                        }
                        $canDownload = $isSuperadmin || $isOwner || $hasDirect || $hasTag;
                        $previewKind = gc_preview_kind((string) ($file['mime_type'] ?? ''), (string) $file['original_name']);
                        $canPreview = $canDownload && $previewKind !== '';
                        $canDelete = !$adminPersonal && ($isOwner || $canDeleteAny);
                        $canMove = !$adminPersonal && ($isOwner || $canEditAny);
                        $canManageFile = !$adminPersonal && ($isOwner || $canEditAny || gc_is_admin());
                        $accessLabel = $isOwner ? 'Мой файл' : ($isSuperadmin ? 'Суперпользователь' : ($hasDirect ? 'Личный доступ' : ($hasTag ? 'По тегу' : ($viewAll ? 'Просмотр' : 'Только просмотр'))));
                        $accessTone = $isOwner ? 'accent' : ($hasDirect ? 'info' : 'violet');
                        ?>
                    <div class="gc_filerow<?= $isPinned ? ' is-pinned' : '' ?>" data-file-id="<?= $fileId ?>" draggable="<?= $canMove ? 'true' : 'false' ?>" data-file-name="<?= gc_h($file['original_name']) ?>">
                        <div class="gc_cell--name">
                            <span class="gc_fileicon">
                                <span class="gc_ico gc_ico--file"></span>
                                <b><?= gc_h(gc_ext_label((string) $file['original_name'])) ?></b>
                            </span>
                            <div class="gc_filemain">
                                <?php if ($canDownload) { ?>
                                <a class="gc_filename" href="download.php?id=<?= $fileId ?>" title="Скачать" draggable="false"><?= gc_h($file['original_name']) ?></a>
                                <?php } else { ?>
                                <span class="gc_filename is-locked" title="Файл виден, но скачивание не разрешено"><?= gc_h($file['original_name']) ?></span>
                                <?php } ?>
                                <div class="gc_filemeta">
                                    <span><span class="gc_ico gc_ico--user"></span> <?= gc_h($file['uploader']) ?></span>
                                    <?php if ($isPinned) { ?><span><span class="gc_ico gc_ico--pin"></span> закреплён</span><?php } ?>
                                </div>
                            </div>
                        </div>

                        <div class="gc_cell--tags">
                            <span class="gc_tag gc_tag--xs gc_tag--<?= gc_h($accessTone) ?>"><?= gc_h($accessLabel) ?></span>
                            <?php foreach (array_slice($tags, 0, 2) as $tagName) { ?>
                            <span class="gc_tag gc_tag--xs"><?= gc_h($tagName) ?></span>
                            <?php } ?>
                            <?php if (count($tags) > 2) { ?>
                            <span class="gc_tag gc_tag--xs gc_tag--more" title="<?= gc_h(implode(', ', $tags)) ?>">+<?= count($tags) - 2 ?></span>
                            <?php } ?>
                        </div>

                        <div class="gc_cell--size"><?= gc_h(gc_size((int) $file['file_size'])) ?></div>
                        <div class="gc_cell--date"><?= gc_h(gc_dt($file['uploaded_at'])) ?></div>

                        <div class="gc_cell--acts">
                            <?php if ($canPreview) { ?>
                            <button type="button" class="gc_iconbtn" title="Открыть для просмотра" aria-label="Открыть для просмотра"
                                    data-file-preview="<?= $fileId ?>"
                                    data-file-name="<?= gc_h($file['original_name']) ?>"
                                    data-preview-kind="<?= gc_h($previewKind) ?>"
                                    data-preview-download="download.php?id=<?= $fileId ?>">
                                <span class="gc_ico gc_ico--eye"></span>
                            </button>
                            <?php } ?>
                            <?php if ($canDownload) { ?>
                            <a class="gc_iconbtn" href="download.php?id=<?= $fileId ?>" title="Скачать" aria-label="Скачать">
                                <span class="gc_ico gc_ico--download"></span>
                            </a>
                            <?php } else { ?>
                            <span class="gc_iconbtn" title="Скачивание недоступно" aria-label="Скачивание недоступно">
                                <span class="gc_ico gc_ico--permission"></span>
                            </span>
                            <?php } ?>

                            <?php if ($canManageFile) { ?>
                            <button type="button" class="gc_iconbtn" title="Теги файла" aria-label="Теги файла"
                                    data-file-tags="<?= $fileId ?>"
                                    data-file-name="<?= gc_h($file['original_name']) ?>"
                                    data-file-tag-list="<?= $tagListAttr($fileTagRowsById[$fileId] ?? []) ?>">
                                <span class="gc_ico gc_ico--permission"></span>
                            </button>
                            <?php } ?>

                            <?php if ($canPin && !$adminPersonal) { ?>
                            <form method="post" action="storage.php">
                                <?= gc_csrf_field() ?>
                                <input type="hidden" name="action" value="file_pin">
                                <input type="hidden" name="id" value="<?= $fileId ?>">
                                <input type="hidden" name="folder" value="<?= $folderId ?>">
                                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                                <button type="submit" class="gc_iconbtn<?= $isPinned ? ' is-active' : '' ?>" title="<?= $isPinned ? 'Открепить' : 'Закрепить' ?>" aria-label="<?= $isPinned ? 'Открепить' : 'Закрепить' ?>">
                                    <span class="gc_ico gc_ico--pin"></span>
                                </button>
                            </form>
                            <?php } ?>

                            <?php if ($canDelete) { ?>
                            <form method="post" action="storage.php" data-confirm="Удалить файл «<?= gc_h($file['original_name']) ?>» безвозвратно?">
                                <?= gc_csrf_field() ?>
                                <input type="hidden" name="action" value="file_delete">
                                <input type="hidden" name="id" value="<?= $fileId ?>">
                                <input type="hidden" name="folder" value="<?= $folderId ?>">
                                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                                <button type="submit" class="gc_iconbtn gc_iconbtn--danger" title="Удалить" aria-label="Удалить">
                                    <span class="gc_ico gc_ico--delete"></span>
                                </button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <?php if ($files === []) { ?>
                <div class="gc_empty" data-file-empty data-server-empty>
                    <span class="gc_ico gc_ico--folder"></span>
                    <h3 data-empty-title data-empty-default="В этой папке пусто">В этой папке пусто</h3>
                    <p><?= $childFolders === [] ? 'Загрузите первый файл или создайте папку.' : 'Внутри этой папки пока нет файлов.' ?></p>
                </div>
                <?php } else { ?>
                <div class="gc_empty" data-file-empty hidden>
                    <span class="gc_ico gc_ico--search"></span>
                    <h3 data-empty-title data-empty-default="Ничего не найдено">Ничего не найдено</h3>
                    <p>Попробуйте другое имя или очистите поиск.</p>
                </div>
                <?php } ?>
            </section>
        </div>
    </div>

    <form method="post" action="storage.php" id="gcMoveForm" hidden>
        <?= gc_csrf_field() ?>
        <input type="hidden" name="action" value="file_move">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="target_folder" value="">
        <input type="hidden" name="folder" value="<?= $folderId ?>">
        <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
        <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
        <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
    </form>

    <dialog class="gc_modal" id="gcFolderTagsDialog">
        <div class="gc_modal_panel">
            <div class="gc_modal_head">
                <div>
                    <span class="gc_modal_eyebrow">Папка</span>
                    <h2 data-folder-title>Теги папки</h2>
                </div>
                <button type="button" class="gc_iconbtn" data-close-dialog title="Закрыть" aria-label="Закрыть">
                    <span class="gc_ico gc_ico--close"></span>
                </button>
            </div>
            <p class="gc_modal_lead">Папку видят только пользователи с одним из её тегов. На файлы внутри теги папки не влияют, пока вы не синхронизируете их.</p>
            <div class="gc_modal_tags" data-folder-current></div>
            <?php if ($allTags !== []) { ?>
            <form method="post" action="storage.php" class="gc_modal_form">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="folder_tag_add">
                <input type="hidden" name="id" value="" data-folder-id>
                <input type="hidden" name="folder" value="<?= $folderId ?>">
                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                <div class="gc_field">
                    <label class="gc_label" for="folderTagSelect">Добавить тег</label>
                    <select class="gc_select" id="folderTagSelect" name="tag_id" required>
                        <option value="">Выберите тег</option>
                        <?php foreach ($allTags as $tagRow) { ?>
                        <option value="<?= (int) $tagRow['id'] ?>"><?= gc_h($tagRow['name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <button type="submit" class="gc_btn gc_btn--primary">Добавить</button>
            </form>
            <?php } ?>
            <div class="gc_modal_actions">
                <form method="post" action="storage.php" data-confirm="Выдать всем файлам в этой папке и её подпапках теги папки?">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="folder_tags_sync">
                    <input type="hidden" name="id" value="" data-folder-id>
                    <input type="hidden" name="folder" value="<?= $folderId ?>">
                    <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                    <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                    <button type="submit" class="gc_btn gc_btn--outline">
                        <span class="gc_ico gc_ico--permission"></span> Синхронизировать с файлами
                    </button>
                </form>
                <form method="post" action="storage.php" id="gcFolderTagRemoveForm">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="folder_tag_delete">
                    <input type="hidden" name="id" value="" data-folder-id>
                    <input type="hidden" name="tag_id" value="">
                    <input type="hidden" name="folder" value="<?= $folderId ?>">
                    <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                    <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                </form>
            </div>
        </div>
    </dialog>

    <dialog class="gc_modal" id="gcFileTagsDialog">
        <div class="gc_modal_panel">
            <div class="gc_modal_head">
                <div>
                    <span class="gc_modal_eyebrow">Файл</span>
                    <h2 data-file-title>Теги файла</h2>
                </div>
                <button type="button" class="gc_iconbtn" data-close-dialog title="Закрыть" aria-label="Закрыть">
                    <span class="gc_ico gc_ico--close"></span>
                </button>
            </div>
            <p class="gc_modal_lead">Теги файла определяют, кто видит и может скачать файл. Синхронизации здесь нет.</p>
            <div class="gc_modal_tags" data-file-current></div>
            <?php if ($allTags !== []) { ?>
            <form method="post" action="storage.php" class="gc_modal_form">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="file_tag_add">
                <input type="hidden" name="id" value="" data-file-id>
                <input type="hidden" name="folder" value="<?= $folderId ?>">
                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                <div class="gc_field">
                    <label class="gc_label" for="fileTagSelect">Добавить тег</label>
                    <select class="gc_select" id="fileTagSelect" name="tag_id" required>
                        <option value="">Выберите тег</option>
                        <?php foreach ($allTags as $tagRow) { ?>
                        <option value="<?= (int) $tagRow['id'] ?>"><?= gc_h($tagRow['name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <button type="submit" class="gc_btn gc_btn--primary">Добавить</button>
            </form>
            <?php } ?>
            <form method="post" action="storage.php" id="gcFileTagRemoveForm">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="file_tag_delete">
                <input type="hidden" name="id" value="" data-file-id>
                <input type="hidden" name="tag_id" value="">
                <input type="hidden" name="folder" value="<?= $folderId ?>">
                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
            </form>
        </div>
    </dialog>

    <dialog class="gc_modal" id="gcFolderEditDialog">
        <div class="gc_modal_panel">
            <div class="gc_modal_head">
                <div>
                    <span class="gc_modal_eyebrow">Папка</span>
                    <h2>Редактировать папку</h2>
                </div>
                <button type="button" class="gc_iconbtn" data-close-dialog title="Закрыть" aria-label="Закрыть">
                    <span class="gc_ico gc_ico--close"></span>
                </button>
            </div>
            <form method="post" action="storage.php" class="gc_modal_form gc_modal_form--stack">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="folder_rename">
                <input type="hidden" name="id" value="" data-folder-id>
                <input type="hidden" name="folder" value="<?= $folderId ?>">
                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                <div class="gc_field">
                    <label class="gc_label" for="folderEditName">Название</label>
                    <input class="gc_input" id="folderEditName" type="text" name="folder_name" maxlength="80" required data-folder-name-input>
                </div>
                <button type="submit" class="gc_btn gc_btn--primary">Сохранить</button>
            </form>
        </div>
    </dialog>

    <dialog class="gc_modal" id="gcFolderDeleteDialog">
        <div class="gc_modal_panel">
            <div class="gc_modal_head">
                <div>
                    <span class="gc_modal_eyebrow">Удаление</span>
                    <h2>Удалить папку?</h2>
                </div>
                <button type="button" class="gc_iconbtn" data-close-dialog title="Закрыть" aria-label="Закрыть">
                    <span class="gc_ico gc_ico--close"></span>
                </button>
            </div>
            <p class="gc_modal_lead">
                Папка «<b data-folder-delete-name></b>» и все вложенные папки будут удалены.
                Выберите, что сделать с файлами внутри.
            </p>
            <form method="post" action="storage.php" class="gc_modal_form gc_modal_form--stack" data-folder-delete-form>
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="folder_delete">
                <input type="hidden" name="id" value="" data-folder-delete-id>
                <input type="hidden" name="folder" value="<?= $folderId ?>">
                <input type="hidden" name="sort" value="<?= gc_h($sort) ?>">
                <input type="hidden" name="dir" value="<?= gc_h($dir) ?>">
                <input type="hidden" name="scope" value="<?= gc_h($scope) ?>">
                <div class="gc_delete_choices">
                    <button type="submit" name="mode" value="move" class="gc_delete_choice">
                        <span class="gc_delete_choice_icon"><span class="gc_ico gc_ico--folder"></span></span>
                        <strong>Перенести в корень</strong>
                        <small>Файлы останутся, вложенные папки исчезнут.</small>
                    </button>
                    <button type="submit" name="mode" value="with_files" class="gc_delete_choice gc_delete_choice--danger">
                        <span class="gc_delete_choice_icon"><span class="gc_ico gc_ico--delete"></span></span>
                        <strong>Удалить с файлами</strong>
                        <small>Файлы будут удалены с диска без восстановления.</small>
                    </button>
                </div>
            </form>
            <div class="gc_modal_actions">
                <button type="button" class="gc_btn gc_btn--ghost" data-close-dialog>Отмена</button>
            </div>
        </div>
    </dialog>

    <dialog class="gc_modal gc_preview_modal" id="gcPreviewDialog">
        <div class="gc_modal_panel gc_preview_panel">
            <div class="gc_modal_head">
                <div>
                    <span class="gc_modal_eyebrow">Просмотр</span>
                    <h2 data-preview-title>Файл</h2>
                </div>
                <button type="button" class="gc_iconbtn" data-preview-close title="Закрыть" aria-label="Закрыть">
                    <span class="gc_ico gc_ico--close"></span>
                </button>
            </div>
            <div class="gc_preview_body" data-preview-body>
                <p class="gc_muted">Загрузка…</p>
            </div>
            <div class="gc_preview_foot">
                <span class="gc_muted" data-preview-note></span>
                <a class="gc_btn gc_btn--outline gc_btn--sm" href="#" data-preview-download>
                    <span class="gc_ico gc_ico--download"></span> Скачать
                </a>
            </div>
        </div>
    </dialog>
</div>
<?php gc_foot(); ?>