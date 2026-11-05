<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

if (!gc_has_right('manage_updates')) {
    gc_flash('error', 'Нет права «Обновления приложений».');
    gc_redirect('admin_panel.php');
}

$updatesDir = __DIR__ . '/updates';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gc_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'app_save') {
        $appId = (int) ($_POST['app_id'] ?? 0);
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']);

        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            gc_flash('error', 'Slug: 2–41 символ, латиница в нижнем регистре, цифры и дефис.');
        } elseif (gc_strlen($name) < 2 || gc_strlen($name) > 80) {
            gc_flash('error', 'Название приложения: от 2 до 80 символов.');
        } elseif (gc_strlen($description) > 500) {
            gc_flash('error', 'Описание: не больше 500 символов.');
        } else {
            $stmt = $pdo->prepare('SELECT id FROM apps WHERE lower(slug) = ? AND id <> ?');
            $stmt->execute([$slug, $appId]);
            if ($stmt->fetchColumn()) {
                gc_flash('error', 'Приложение с таким slug уже есть.');
            } else {
                try {
                    if ($appId > 0) {
                        $stmt = $pdo->prepare(
                            'UPDATE apps SET slug = ?, name = ?, description = ?, is_active = ? WHERE id = ?'
                        );
                        $stmt->execute([$slug, $name, $description, $isActive ? 'true' : 'false', $appId]);
                        gc_flash('ok', 'Приложение сохранено.');
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO apps (slug, name, description, is_active) VALUES (?, ?, ?, ?) RETURNING id'
                        );
                        $stmt->execute([$slug, $name, $description, $isActive ? 'true' : 'false']);
                        $appId = (int) $stmt->fetchColumn();
                        gc_flash('ok', 'Приложение создано.');
                    }
                } catch (Throwable $e) {
                    gc_flash('error', 'Не удалось сохранить приложение: возможно, slug занят.');
                }
            }
        }
        gc_redirect($appId > 0 ? 'admin_releases.php?app=' . $appId : 'admin_releases.php');
    }

    if ($action === 'app_delete') {
        $appId = (int) ($_POST['app_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT count(*) FROM app_releases WHERE app_id = ?');
        $stmt->execute([$appId]);
        if ($appId <= 0) {
            gc_flash('error', 'Приложение не найдено.');
        } elseif ((int) $stmt->fetchColumn() > 0) {
            gc_flash('error', 'Сначала удалите релизы этого приложения.');
        } else {
            $pdo->prepare('DELETE FROM apps WHERE id = ?')->execute([$appId]);
            gc_flash('ok', 'Приложение удалено.');
        }
        gc_redirect('admin_releases.php');
    }

    if ($action === 'release_upload') {
        $appId = (int) ($_POST['app_id'] ?? 0);
        $channel = (string) ($_POST['channel'] ?? 'stable');
        $version = trim((string) ($_POST['version'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $mandatory = isset($_POST['is_mandatory']);

        $stmt = $pdo->prepare('SELECT id, slug FROM apps WHERE id = ?');
        $stmt->execute([$appId]);
        $app = $stmt->fetch();

        if ($app === false) {
            gc_flash('error', 'Выберите приложение.');
        } elseif (!in_array($channel, ['stable', 'beta'], true)) {
            gc_flash('error', 'Неизвестный канал.');
        } elseif (!preg_match('/^[0-9A-Za-z._-]{1,40}$/', $version)) {
            gc_flash('error', 'Версия: до 40 символов, цифры, буквы, точка, дефис, подчёркивание.');
        } elseif (gc_strlen($notes) > 2000) {
            gc_flash('error', 'Список изменений: не больше 2000 символов.');
        } elseif (!isset($_FILES['release']) || !is_array($_FILES['release'])) {
            gc_flash('error', 'Файл не выбран.');
        } elseif (($_FILES['release']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            gc_flash('error', gc_upload_error((int) ($_FILES['release']['error'] ?? UPLOAD_ERR_NO_FILE)));
        } else {
            $file = $_FILES['release'];
            $size = (int) ($file['size'] ?? 0);
            $original = (string) ($file['name'] ?? '');
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            if ($size <= 0 || $size > gc_max_upload()) {
                gc_flash('error', 'Файл пустой или больше лимита ' . gc_size(gc_max_upload()) . '.');
            } elseif (!in_array($ext, ['msi', 'msix', 'zip', 'exe'], true)) {
                gc_flash('error', 'Допустимые форматы сборки: msi, msix, zip, exe.');
            } elseif (!gc_ensure_data_dir($updatesDir)) {
                gc_flash('error', 'Папка updates недоступна для записи.');
            } elseif (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                gc_flash('error', 'Загрузка не похожа на HTTP-загрузку файла.');
            } else {
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                $target = $updatesDir . '/' . $stored;
                if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                    gc_flash('error', 'Не удалось сохранить файл сборки.');
                } else {
                    $sha = hash_file('sha256', $target) ?: '';
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            'UPDATE app_releases SET is_active = false WHERE app_id = ? AND channel = ? AND is_active'
                        )->execute([$appId, $channel]);

                        $stmt = $pdo->prepare(
                            'INSERT INTO app_releases
                                (app_id, version, channel, filename, original_name, file_size, sha256,
                                 notes, is_mandatory, is_active, published_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, true, ?)'
                        );
                        $stmt->execute([
                            $appId,
                            $version,
                            $channel,
                            $stored,
                            $original,
                            $size,
                            $sha,
                            $notes,
                            $mandatory ? 'true' : 'false',
                            (int) $user['id'],
                        ]);
                        $pdo->commit();
                        gc_flash('ok', 'Сборка ' . $version . ' загружена в ' . $channel . '.');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        @unlink($target);
                        gc_flash('error', 'Не удалось записать релиз: такая версия уже существует?');
                    }
                }
            }
        }
        gc_redirect('admin_releases.php' . ($appId > 0 ? '?app=' . $appId : ''));
    }

    if ($action === 'release_toggle') {
        $releaseId = (int) ($_POST['release_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, app_id, channel, CASE WHEN is_active THEN 1 ELSE 0 END AS is_active FROM app_releases WHERE id = ?');
        $stmt->execute([$releaseId]);
        $release = $stmt->fetch();
        if ($release === false) {
            gc_flash('error', 'Релиз не найден.');
        } else {
            $appId = $release['app_id'] !== null ? (int) $release['app_id'] : null;
            if ($appId === null) {
                $appId = (int) ($pdo->query('SELECT id FROM apps ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            }
            $activating = !$release['is_active'];
            if ($activating && $appId > 0) {
                $pdo->prepare(
                    'UPDATE app_releases SET is_active = false
                     WHERE app_id = ? AND channel = ? AND is_active AND id <> ?'
                )->execute([$appId, $release['channel'], $releaseId]);
            }
            $pdo->prepare('UPDATE app_releases SET is_active = ?, app_id = COALESCE(app_id, ?) WHERE id = ?')
                ->execute([$activating ? 'true' : 'false', $appId > 0 ? $appId : null, $releaseId]);
            gc_flash('ok', $activating ? 'Релиз включён.' : 'Релиз снят с раздачи.');
        }
        gc_redirect('admin_releases.php');
    }

    if ($action === 'release_delete') {
        $releaseId = (int) ($_POST['release_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT filename FROM app_releases WHERE id = ?');
        $stmt->execute([$releaseId]);
        $filename = $stmt->fetchColumn();
        if ($filename === false) {
            gc_flash('error', 'Релиз не найден.');
        } else {
            $dir = realpath($updatesDir);
            $path = realpath($updatesDir . '/' . (string) $filename);
            if ($path !== false && $dir !== false && str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
                @unlink($path);
            }
            $pdo->prepare('DELETE FROM app_releases WHERE id = ?')->execute([$releaseId]);
            gc_flash('ok', 'Релиз удалён.');
        }
        gc_redirect('admin_releases.php');
    }

    gc_flash('error', 'Неизвестное действие.');
    gc_redirect('admin_releases.php');
}

$apps = $pdo->query(
    'SELECT a.id, a.slug, a.name, a.description,
            CASE WHEN a.is_active THEN 1 ELSE 0 END AS is_active,
            (SELECT count(*) FROM app_releases r WHERE r.app_id = a.id) AS releases_count,
            (SELECT count(*) FROM app_releases r WHERE r.app_id = a.id AND r.is_active) AS active_count
     FROM apps a ORDER BY a.name, a.id'
)->fetchAll();

$editId = isset($_GET['app']) ? (int) $_GET['app'] : 0;
$editApp = null;
foreach ($apps as $row) {
    if ((int) $row['id'] === $editId) {
        $editApp = $row;
        break;
    }
}

$filterApp = $editId;
$releasesSql =
    'SELECT r.id, r.app_id, r.version, r.channel, r.filename, r.original_name, r.file_size,
            r.notes,
            CASE WHEN r.is_mandatory THEN 1 ELSE 0 END AS is_mandatory,
            CASE WHEN r.is_active THEN 1 ELSE 0 END AS is_active,
            r.downloads, r.published_at,
            a.name AS app_name, a.slug AS app_slug
     FROM app_releases r
     LEFT JOIN apps a ON a.id = r.app_id';
$params = [];
if ($filterApp > 0) {
    $releasesSql .= ' WHERE r.app_id = ?';
    $params[] = $filterApp;
}
$releasesSql .= ' ORDER BY r.published_at DESC, r.id DESC LIMIT 100';
$stmt = $pdo->prepare($releasesSql);
$stmt->execute($params);
$releases = $stmt->fetchAll();

$defaultAppName = $apps === [] ? null : (string) $apps[0]['name'];

gc_head('Приложения', 'admin');
gc_flashes();
?>

<div class="gc_wrap">
    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Сборки и каналы</div>
            <h1 class="gc_hero_title">Приложения<span class="gc_accent">.</span></h1>
            <p class="gc_hero_lead">
                Приложения команды и их сборки. В каждом канале активна одна версия:
                новая загрузка автоматически снимает предыдущую с раздачи.
            </p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat"><b><?= count($apps) ?></b><span>приложений</span></div>
            <a class="gc_btn gc_btn--ghost gc_btn--sm" href="apps.php">Открыть раздел</a>
        </div>
    </section>

    <?php gc_admin_nav('releases'); ?>

    <?php if ($editApp !== null) { ?>
    <section class="gc_section">
        <div class="gc_card gc_admin_editor">
            <div class="gc_panel_head">
                <div>
                    <span class="gc_hero_eyebrow">Приложение</span>
                    <h2><?= gc_h((string) $editApp['name']) ?></h2>
                    <p><?= (int) $editApp['releases_count'] ?> релизов · <?= (int) $editApp['active_count'] ?> активных</p>
                </div>
                <a class="gc_btn gc_btn--ghost gc_btn--sm" href="admin_releases.php">Закрыть</a>
            </div>

            <form method="post" class="gc_admin_form">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="app_save">
                <input type="hidden" name="app_id" value="<?= (int) $editApp['id'] ?>">
                <div class="gc_form_grid">
                    <div class="gc_field">
                        <label class="gc_label" for="app-name">Название</label>
                        <input class="gc_input" id="app-name" name="name" maxlength="80" required value="<?= gc_h((string) $editApp['name']) ?>">
                    </div>
                    <div class="gc_field">
                        <label class="gc_label" for="app-slug">Slug</label>
                        <input class="gc_input" id="app-slug" name="slug" maxlength="41" required value="<?= gc_h((string) $editApp['slug']) ?>" pattern="[a-z0-9][a-z0-9-]{1,40}">
                    </div>
                </div>
                <div class="gc_field">
                    <label class="gc_label" for="app-desc">Описание</label>
                    <textarea class="gc_textarea" id="app-desc" name="description" maxlength="500" placeholder="Одно-два предложения для карточки в разделе приложений"><?= gc_h((string) $editApp['description']) ?></textarea>
                </div>
                <label class="gc_check">
                    <input type="checkbox" name="is_active" <?= $editApp['is_active'] ? 'checked' : '' ?>>
                    <span>Показывать в разделе «Приложения»</span>
                </label>
                <div class="gc_btn-row">
                    <button class="gc_btn gc_btn--primary" type="submit">Сохранить</button>
                </div>
            </form>

            <?php if ((int) $editApp['releases_count'] === 0) { ?>
            <form method="post" class="gc_admin_danger" data-confirm="Удалить приложение «<?= gc_h((string) $editApp['name']) ?>»?">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="app_delete">
                <input type="hidden" name="app_id" value="<?= (int) $editApp['id'] ?>">
                <button class="gc_btn gc_btn--ghost gc_btn--sm gc_text-danger" type="submit">Удалить приложение</button>
            </form>
            <?php } ?>
        </div>
    </section>
    <?php } ?>

    <details class="gc_upload_panel"<?= $apps === [] ? ' open' : '' ?>>
        <summary>
            <span class="gc_ico gc_ico--plus" aria-hidden="true"></span>
            <span>Новое приложение</span>
            <span class="gc_ico gc_ico--chevron" aria-hidden="true"></span>
        </summary>
        <form method="post" class="gc_admin_form">
            <?= gc_csrf_field() ?>
            <input type="hidden" name="action" value="app_save">
            <div class="gc_form_grid">
                <div class="gc_field">
                    <label class="gc_label" for="new-app-name">Название</label>
                    <input class="gc_input" id="new-app-name" name="name" maxlength="80" required placeholder="GC Something">
                </div>
                <div class="gc_field">
                    <label class="gc_label" for="new-app-slug">Slug</label>
                    <input class="gc_input" id="new-app-slug" name="slug" maxlength="41" required placeholder="gc-something" pattern="[a-z0-9][a-z0-9-]{1,40}">
                </div>
            </div>
            <div class="gc_field">
                <label class="gc_label" for="new-app-desc">Описание</label>
                <textarea class="gc_textarea" id="new-app-desc" name="description" maxlength="500" placeholder="Что это за приложение"></textarea>
            </div>
            <label class="gc_check">
                <input type="checkbox" name="is_active" checked>
                <span>Сразу показывать в разделе «Приложения»</span>
            </label>
            <div class="gc_btn-row">
                <button class="gc_btn gc_btn--primary" type="submit">Создать приложение</button>
            </div>
        </form>
    </details>

    <section class="gc_section">
        <div class="gc_section_head">
            <h2 class="gc_section_title">Загрузить сборку</h2>
            <span class="gc_section_note">msi, msix, zip, exe · до <?= gc_h(gc_size(gc_max_upload())) ?></span>
        </div>
        <div class="gc_card">
            <?php if ($apps === []) { ?>
            <p class="gc_hint">Сначала создайте приложение выше — сборке нужен владелец.</p>
            <?php } else { ?>
            <form method="post" enctype="multipart/form-data" class="gc_admin_form">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="release_upload">
                <div class="gc_form_grid">
                    <div class="gc_field">
                        <label class="gc_label" for="rel-app">Приложение</label>
                        <select class="gc_select" id="rel-app" name="app_id" required>
                            <?php foreach ($apps as $app) { ?>
                            <option value="<?= (int) $app['id'] ?>" <?= (int) $app['id'] === ($editId > 0 ? $editId : 0) ? 'selected' : '' ?>><?= gc_h((string) $app['name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="gc_field">
                        <label class="gc_label" for="rel-channel">Канал</label>
                        <select class="gc_select" id="rel-channel" name="channel">
                            <option value="stable">Stable</option>
                            <option value="beta">Beta</option>
                        </select>
                    </div>
                </div>
                <div class="gc_form_grid">
                    <div class="gc_field">
                        <label class="gc_label" for="rel-version">Версия</label>
                        <input class="gc_input" id="rel-version" name="version" maxlength="40" required placeholder="1.0.7">
                    </div>
                    <div class="gc_field">
                        <label class="gc_label" for="rel-file">Файл сборки</label>
                        <input class="gc_input" id="rel-file" type="file" name="release" accept=".msi,.msix,.zip,.exe" required>
                    </div>
                </div>
                <div class="gc_field">
                    <label class="gc_label" for="rel-notes">Что нового</label>
                    <textarea class="gc_textarea" id="rel-notes" name="notes" maxlength="2000" placeholder="Список изменений для пользователей"></textarea>
                </div>
                <label class="gc_check">
                    <input type="checkbox" name="is_mandatory">
                    <span>Обязательное обновление</span>
                </label>
                <div class="gc_btn-row">
                    <button class="gc_btn gc_btn--primary" type="submit">Загрузить сборку</button>
                </div>
            </form>
            <?php } ?>
        </div>
    </section>

    <section class="gc_section">
        <div class="gc_section_head">
            <h2 class="gc_section_title">Последние релизы</h2>
            <span class="gc_section_note">
                <?= $filterApp > 0 ? 'только выбранное приложение' : 'все приложения' ?>
                <?php if ($filterApp > 0) { ?>
                · <a href="admin_releases.php">сбросить фильтр</a>
                <?php } ?>
            </span>
        </div>
        <?php if ($releases === []) { ?>
        <div class="gc_empty">
            <span class="gc_ico gc_ico--upload" aria-hidden="true"></span>
            <h3>Релизов пока нет</h3>
            <p>Загрузите первую сборку — она появится в разделе «Приложения» и в API.</p>
        </div>
        <?php } else { ?>
        <div class="gc_filetable gc_reltable">
            <div class="gc_relrow gc_relrow--head">
                <span>Версия</span>
                <span>Канал</span>
                <span>Приложение</span>
                <span class="is-right">Размер</span>
                <span class="is-right">Скачиваний</span>
                <span>Дата</span>
                <span class="is-right">Действия</span>
            </div>
            <?php foreach ($releases as $release) { ?>
            <div class="gc_relrow<?= $release['is_active'] ? '' : ' is-off' ?>">
                <span class="gc_relversion">
                    v<?= gc_h((string) $release['version']) ?>
                    <?php if ($release['is_mandatory']) { ?><span class="gc_tag gc_tag--danger gc_tag--xs">обяз.</span><?php } ?>
                </span>
                <span><span class="gc_tag gc_tag--<?= $release['channel'] === 'stable' ? 'accent' : 'info' ?> gc_tag--xs"><?= gc_h((string) $release['channel']) ?></span></span>
                <span class="gc_relapp"><?= gc_h((string) ($release['app_name'] ?? $defaultAppName ?? 'legacy')) ?></span>
                <span class="gc_cell--size is-right"><?= gc_h(gc_size((int) $release['file_size'])) ?></span>
                <span class="gc_cell--size is-right"><?= (int) $release['downloads'] ?></span>
                <span class="gc_cell--date"><?= gc_h(gc_dt((string) $release['published_at'], 'd.m.Y H:i')) ?></span>
                <span class="gc_relacts">
                    <form method="post">
                        <?= gc_csrf_field() ?>
                        <input type="hidden" name="action" value="release_toggle">
                        <input type="hidden" name="release_id" value="<?= (int) $release['id'] ?>">
                        <button class="gc_iconbtn<?= $release['is_active'] ? ' is-active' : '' ?>" type="submit" title="<?= $release['is_active'] ? 'Снять с раздачи' : 'Включить в раздачу' ?>">
                            <span class="gc_ico gc_ico--shield" aria-hidden="true"></span>
                        </button>
                    </form>
                    <form method="post" data-confirm="Удалить релиз v<?= gc_h((string) $release['version']) ?> и его файл?">
                        <?= gc_csrf_field() ?>
                        <input type="hidden" name="action" value="release_delete">
                        <input type="hidden" name="release_id" value="<?= (int) $release['id'] ?>">
                        <button class="gc_iconbtn gc_iconbtn--danger" type="submit" title="Удалить релиз">
                            <span class="gc_ico gc_ico--delete" aria-hidden="true"></span>
                        </button>
                    </form>
                </span>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
    </section>

    <section class="gc_section">
        <div class="gc_section_head">
            <h2 class="gc_section_title">Все приложения</h2>
            <span class="gc_section_note">slug — часть адреса API</span>
        </div>
        <div class="gc_card gc_admin_list">
            <?php if ($apps === []) { ?>
            <p class="gc_hint">Пока ни одного приложения.</p>
            <?php } ?>
            <?php foreach ($apps as $app) { ?>
            <div class="gc_userrow">
                <span class="gc_app_mark gc_app_mark--sm" aria-hidden="true"><span class="gc_ico gc_ico--grid"></span></span>
                <div class="gc_userrow_main">
                    <div class="gc_userrow_name">
                        <?= gc_h((string) $app['name']) ?>
                        <?php if (!$app['is_active']) { ?><span class="gc_tag gc_tag--danger gc_tag--xs">скрыто</span><?php } ?>
                    </div>
                    <div class="gc_userrow_meta">
                        <?= gc_h((string) $app['slug']) ?>
                        <span class="gc_footer_dot">·</span>
                        <?= (int) $app['releases_count'] ?> релизов
                    </div>
                </div>
                <a class="gc_btn gc_btn--ghost gc_btn--sm" href="admin_releases.php?app=<?= (int) $app['id'] ?>">Изменить</a>
            </div>
            <?php } ?>
        </div>
    </section>
</div>

<?php gc_foot(); ?>
