<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/hosting_lib.php';

$user = gc_require_login();
$uid = (int) $user['id'];
$uploadDir = gc_media_upload_dir();

gc_media_ensure_schema($pdo);
gc_media_purge($pdo, $uploadDir);

/**
 * A hosted row from an existing file must remember the actual stored name and
 * metadata, while keeping the storage row untouched.
 */
function gc_hosting_file_row(PDO $pdo, int $fileId): ?array
{
    $statement = $pdo->prepare(
        'SELECT f.id, f.filename, f.original_name, f.mime_type, f.file_size,
                f.uploader_id, COALESCE(fo.is_personal, false) AS is_personal,
                fo.created_by AS folder_owner
         FROM files f
         LEFT JOIN folders fo ON fo.id = f.folder_id
         WHERE f.id = ?'
    );
    $statement->execute([$fileId]);

    return $statement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gc_csrf_check();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $ttl = isset($_POST['ttl']) ? (int) $_POST['ttl'] : 24;
    if (!in_array($ttl, [1, 24, 0], true)) {
        $ttl = 24;
    }
    $expiresAt = $ttl === 0 ? null : ($ttl === 1 ? '1 hour' : '1 day');

    if ($action === 'upload') {
        $upload = $_FILES['media'] ?? null;
        if (!is_array($upload) || !isset($upload['error'])) {
            gc_flash('error', 'Файл не выбран.');
            gc_redirect('hosting.php');
        }
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            gc_flash('error', gc_upload_error((int) $upload['error']));
            gc_redirect('hosting.php');
        }
        $max = gc_max_upload();
        if ($max > 0 && (int) $upload['size'] > $max) {
            gc_flash('error', 'Файл больше допустимого размера ' . gc_size($max) . '.');
            gc_redirect('hosting.php');
        }
        if (!is_uploaded_file((string) $upload['tmp_name'])) {
            gc_flash('error', 'Загрузка не подтверждена сервером.');
            gc_redirect('hosting.php');
        }
        if (!gc_ensure_data_dir($uploadDir)) {
            gc_flash('error', 'Папка uploads недоступна для записи.');
            gc_redirect('hosting.php');
        }

        $original = gc_safe_upload_name((string) $upload['name']);
        $mime = gc_media_mime(gc_mime_type((string) $upload['tmp_name']), $original);
        if ($mime === null) {
            gc_flash('error', 'Для хостинга подходят только фото (JPEG, PNG, GIF, WebP, BMP) и видео (MP4, WebM, OGG).');
            gc_redirect('hosting.php');
        }

        $stored = bin2hex(random_bytes(16));
        $path = $uploadDir . '/' . $stored;
        if (!move_uploaded_file((string) $upload['tmp_name'], $path)) {
            gc_flash('error', 'Не удалось сохранить файл на сервере.');
            gc_redirect('hosting.php');
        }

        $token = gc_media_token();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO hosted_media
                    (token, file_id, filename, original_name, mime_type, file_size,
                     uploader_id, is_copy, expires_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, true, '
                . ($expiresAt === null ? 'NULL' : "now() + interval '{$expiresAt}'") . ')'
            );
            $statement->execute([
                $token, $stored, $original, $mime, (int) filesize($path), $uid,
            ]);
        } catch (Throwable $exception) {
            @unlink($path);
            gc_flash('error', 'Не удалось опубликовать файл. Попробуйте ещё раз.');
            gc_redirect('hosting.php');
        }

        gc_flash('ok', 'Фото или видео опубликовано.');
        gc_redirect('hosting.php?new=' . $token);
    }

    if ($action === 'library') {
        $fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        $file = gc_hosting_file_row($pdo, $fileId);
        if (!$file || gc_preview_kind($file['mime_type'] ?? '', (string) $file['original_name']) === '') {
            gc_flash('error', 'Файл не найден или не является фото/видео.');
            gc_redirect('hosting.php');
        }
        if (gc_bool_value($file['is_personal'] ?? false) && (int) ($file['folder_owner'] ?? 0) !== $uid) {
            gc_flash('error', 'Личный файл другого пользователя нельзя опубликовать.');
            gc_redirect('hosting.php');
        }
        if (!gc_file_readable($pdo, $file, $uid)) {
            gc_flash('error', 'У вас нет доступа к этому файлу.');
            gc_redirect('hosting.php');
        }
        $mime = gc_media_mime((string) $file['mime_type'], (string) $file['original_name']);
        $path = gc_media_path((string) $file['filename']);
        if ($mime === null || $path === null) {
            gc_flash('error', 'Файл недоступен или его формат не подходит для хостинга.');
            gc_redirect('hosting.php');
        }

        $token = gc_media_token();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO hosted_media
                    (token, file_id, filename, original_name, mime_type, file_size,
                     uploader_id, is_copy, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, false, '
                . ($expiresAt === null ? 'NULL' : "now() + interval '{$expiresAt}'") . ')'
            );
            $statement->execute([
                $token, (int) $file['id'], (string) $file['filename'],
                (string) $file['original_name'], $mime, (int) $file['file_size'], $uid,
            ]);
        } catch (Throwable $exception) {
            gc_flash('error', 'Не удалось опубликовать файл. Попробуйте ещё раз.');
            gc_redirect('hosting.php');
        }

        gc_flash('ok', 'Ссылка на файл из хранилища создана.');
        gc_redirect('hosting.php?new=' . $token);
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare('SELECT id, filename, uploader_id, is_copy FROM hosted_media WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) {
            gc_flash('error', 'Публикация не найдена или уже удалена.');
            gc_redirect('hosting.php');
        }
        if ((int) $row['uploader_id'] !== $uid && !gc_is_admin()) {
            gc_flash('error', 'Удалить публикацию может только её автор.');
            gc_redirect('hosting.php');
        }
        $pdo->prepare('DELETE FROM hosted_media WHERE id = ?')->execute([$id]);
        if (gc_bool_value($row['is_copy'] ?? false)) {
            $path = gc_media_path((string) $row['filename']);
            if ($path !== null) {
                @unlink($path);
            }
        }
        gc_flash('ok', 'Публикация удалена.');
        gc_redirect('hosting.php');
    }

    gc_redirect('hosting.php');
}

$newToken = isset($_GET['new']) && is_string($_GET['new']) ? trim($_GET['new']) : '';
$newRow = $newToken !== '' ? gc_media_find($pdo, $newToken) : null;
if ($newRow && (int) $newRow['uploader_id'] !== $uid && !gc_is_admin()) {
    $newRow = null;
}

$statement = $pdo->prepare(
    'SELECT id, token, original_name, mime_type, file_size, is_copy, created_at, expires_at, views
     FROM hosted_media WHERE uploader_id = ? ORDER BY created_at DESC, id DESC'
);
$statement->execute([$uid]);
$links = $statement->fetchAll();

$candidates = [];
$statement = $pdo->query(
    'SELECT f.id, f.filename, f.original_name, f.mime_type, f.file_size, f.uploader_id,
            f.uploaded_at, COALESCE(fo.is_personal, false) AS is_personal,
            fo.created_by AS folder_owner
     FROM files f LEFT JOIN folders fo ON fo.id = f.folder_id
     ORDER BY f.uploaded_at DESC NULLS LAST, f.id DESC LIMIT 400'
);
foreach ($statement->fetchAll() as $file) {
    if (gc_bool_value($file['is_personal'] ?? false) && (int) ($file['folder_owner'] ?? 0) !== $uid) {
        continue;
    }
    if (!gc_file_readable($pdo, $file, $uid)) {
        continue;
    }
    $kind = gc_preview_kind((string) $file['mime_type'], (string) $file['original_name']);
    if ($kind !== 'image' && $kind !== 'video') {
        continue;
    }
    if (gc_media_mime((string) $file['mime_type'], (string) $file['original_name']) === null) {
        continue;
    }
    $candidates[] = $file + ['kind' => $kind];
}

gc_head('Хостинг фото и видео', 'hosting');
?>
<div class="gc_wrap">
<section class="gc_hosting">
    <header class="gc_hosting_hero">
        <div>
            <span class="gc_eyebrow">Публичные ссылки</span>
            <h1>Хостинг фото и видео</h1>
            <p>Загрузите файл или возьмите готовый из хранилища. Ссылку сможет открыть любой человек, даже без аккаунта.</p>
        </div>
        <span class="gc_hosting_count"><?= count($links) ?> <?= count($links) === 1 ? 'публикация' : 'публикаций' ?></span>
    </header>

    <?php gc_flashes(); ?>

    <?php if ($newRow) {
        $pageUrl = gc_media_page_url((string) $newRow['token']);
        $directUrl = gc_media_direct_url((string) $newRow['token'], (string) $newRow['original_name'], (string) $newRow['mime_type']);
        ?>
    <section class="gc_hosting_new" aria-label="Новая ссылка">
        <div class="gc_hosting_new_head">
            <span class="gc_ico gc_ico--hosting" aria-hidden="true"></span>
            <div>
                <strong>Ссылка готова</strong>
                <span><?= gc_h((string) $newRow['original_name']) ?></span>
            </div>
        </div>
        <div class="gc_hosting_urls">
            <label class="gc_hosting_url">
                <span>Страница</span>
                <input type="text" value="<?= gc_h($pageUrl) ?>" readonly data-select-on-focus>
                <button type="button" class="gc_iconbtn" data-copy data-copy-value="<?= gc_h($pageUrl) ?>" aria-label="Скопировать ссылку на страницу"><span class="gc_ico gc_ico--copy"></span></button>
            </label>
            <label class="gc_hosting_url">
                <span>Прямая ссылка</span>
                <input type="text" value="<?= gc_h($directUrl) ?>" readonly data-select-on-focus>
                <button type="button" class="gc_iconbtn" data-copy data-copy-value="<?= gc_h($directUrl) ?>" aria-label="Скопировать прямую ссылку"><span class="gc_ico gc_ico--copy"></span></button>
            </label>
        </div>
        <a class="gc_btn gc_btn--primary gc_btn--sm" href="<?= gc_h($pageUrl) ?>" target="_blank" rel="noopener">Открыть страницу</a>
    </section>
    <?php } ?>

    <div class="gc_hosting_layout">
        <section class="gc_panel gc_hosting_form_panel">
            <header class="gc_panel_head">
                <div>
                    <span class="gc_eyebrow">Новая публикация</span>
                    <h2>Фото или видео</h2>
                </div>
            </header>
            <form class="gc_hosting_form" id="gcHostingForm" method="post" action="hosting.php" enctype="multipart/form-data" data-hosting-form>
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="upload" data-hosting-action>
                <input type="hidden" name="source" value="upload" data-hosting-source>
                <input type="hidden" name="file_id" value="" data-hosting-file-id>

                <label class="gc_drop gc_hosting_drop" data-hosting-drop>
                    <input type="file" name="media" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,video/mp4,video/webm,video/ogg" data-hosting-file>
                    <span class="gc_drop_icon"><span class="gc_ico gc_ico--upload gc_ico--lg"></span></span>
                    <span class="gc_drop_title" data-hosting-file-title>Перетащите фото или видео</span>
                    <span class="gc_drop_hint">или нажмите, чтобы выбрать · до <?= gc_h(gc_size(gc_max_upload())) ?></span>
                </label>

                <div class="gc_hosting_or"><span>или</span></div>

                <button type="button" class="gc_btn gc_btn--outline gc_hosting_pick" data-open-library>
                    <span class="gc_ico gc_ico--folder" aria-hidden="true"></span>
                    Выбрать из хранилища
                </button>
                <div class="gc_hosting_selected" data-hosting-selected hidden>
                    <span class="gc_ico gc_ico--file" aria-hidden="true"></span>
                    <span data-hosting-selected-name></span>
                    <button type="button" class="gc_iconbtn" data-clear-library aria-label="Убрать выбранный файл"><span class="gc_ico gc_ico--close"></span></button>
                </div>

                <fieldset class="gc_field gc_hosting_ttl">
                    <legend>Срок жизни</legend>
                    <label class="gc_radio"><input type="radio" name="ttl" value="1"> 1 час</label>
                    <label class="gc_radio"><input type="radio" name="ttl" value="24" checked> 1 день</label>
                    <label class="gc_radio"><input type="radio" name="ttl" value="0"> Пока не удалю</label>
                </fieldset>

                <p class="gc_hosting_note">Файл не копируется, если выбран из хранилища. Удаление публикации не удаляет оригинал.</p>
                <button class="gc_btn gc_btn--primary gc_hosting_submit" type="submit">Опубликовать</button>
            </form>
        </section>

        <section class="gc_panel gc_hosting_list_panel">
            <header class="gc_panel_head">
                <div>
                    <span class="gc_eyebrow">Мои ссылки</span>
                    <h2>Опубликовано</h2>
                </div>
            </header>
            <?php if ($links === []) { ?>
            <p class="gc_empty_note">Пока ничего не опубликовано. Первая ссылка появится здесь.</p>
            <?php } else { ?>
            <div class="gc_hosting_list">
                <?php foreach ($links as $row) {
                    $kind = gc_preview_kind((string) $row['mime_type'], (string) $row['original_name']);
                    $pageUrl = gc_media_page_url((string) $row['token']);
                    $directUrl = gc_media_direct_url((string) $row['token'], (string) $row['original_name'], (string) $row['mime_type']);
                    $expires = $row['expires_at'] !== null ? date('d.m.Y H:i', strtotime((string) $row['expires_at'])) : 'Бессрочно';
                    ?>
                <article class="gc_hosting_item">
                    <a class="gc_hosting_thumb" href="<?= gc_h($pageUrl) ?>" target="_blank" rel="noopener">
                        <?php if ($kind === 'image') { ?>
                        <img src="<?= gc_h($directUrl) ?>" alt="" loading="lazy">
                        <?php } else { ?>
                        <span class="gc_ico gc_ico--hosting gc_ico--lg" aria-hidden="true"></span>
                        <?php } ?>
                    </a>
                    <div class="gc_hosting_item_body">
                        <div class="gc_hosting_item_top">
                            <strong title="<?= gc_h((string) $row['original_name']) ?>"><?= gc_h((string) $row['original_name']) ?></strong>
                            <span class="gc_hosting_badge"><?= $row['is_copy'] ? 'загружено' : 'из хранилища' ?></span>
                        </div>
                        <span class="gc_hosting_meta"><?= gc_h(gc_size((int) $row['file_size'])) ?> · до <?= gc_h($expires) ?> · <?= (int) $row['views'] ?> просм.</span>
                        <div class="gc_hosting_item_actions">
                            <a class="gc_textlink" href="<?= gc_h($pageUrl) ?>" target="_blank" rel="noopener">Страница</a>
                            <button type="button" class="gc_textlink" data-copy data-copy-value="<?= gc_h($directUrl) ?>">Прямая ссылка</button>
                            <form method="post" action="hosting.php" class="gc_hosting_delete" onsubmit="return confirm('Удалить публикацию? Оригинал в хранилище останется.');">
                                <?= gc_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="gc_textlink gc_textlink--danger">Удалить</button>
                            </form>
                        </div>
                    </div>
                </article>
                <?php } ?>
            </div>
            <?php } ?>
        </section>
    </div>
</section>

<dialog class="gc_modal gc_hosting_modal" id="gcLibraryDialog" aria-labelledby="gcLibraryTitle">
    <div class="gc_modal_head">
        <div>
            <span class="gc_eyebrow">Хранилище</span>
            <h2 id="gcLibraryTitle">Выбрать фото или видео</h2>
        </div>
        <button type="button" class="gc_iconbtn" data-close-library aria-label="Закрыть"><span class="gc_ico gc_ico--close"></span></button>
    </div>
    <label class="gc_hosting_search">
        <span class="gc_ico gc_ico--search" aria-hidden="true"></span>
        <input type="search" placeholder="Поиск по названию" data-library-search>
    </label>
    <div class="gc_hosting_library" data-library-list>
        <?php if ($candidates === []) { ?>
        <p class="gc_empty_note">В хранилище нет доступных фото или видео.</p>
        <?php } ?>
        <?php foreach ($candidates as $file) { ?>
        <button type="button" class="gc_hosting_library_item" data-pick-library data-id="<?= (int) $file['id'] ?>" data-name="<?= gc_h((string) $file['original_name']) ?>" data-kind="<?= gc_h((string) $file['kind']) ?>">
            <?php if ($file['kind'] === 'image') { ?>
            <img src="preview.php?id=<?= (int) $file['id'] ?>" alt="" loading="lazy">
            <?php } else { ?>
            <span class="gc_ico gc_ico--hosting gc_ico--lg" aria-hidden="true"></span>
            <?php } ?>
            <span><?= gc_h((string) $file['original_name']) ?></span>
            <small><?= gc_h(gc_size((int) $file['file_size'])) ?></small>
        </button>
        <?php } ?>
    </div>
</dialog>
</div>
<?php
gc_foot(['scripts' => ['assets/js/hosting.js']]);
