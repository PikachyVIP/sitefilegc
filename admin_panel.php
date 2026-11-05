<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();
if (!gc_can_admin()) {
    gc_flash('error', 'Админ-меню доступно только администраторам.');
    gc_redirect('changelog.php');
}

$canChangelog = gc_is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gc_csrf_check();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if (!$canChangelog) {
        gc_flash('error', 'Редактирование changelog доступно только с правом «Админ меню».');
        gc_redirect('admin_panel.php');
    }

    if ($action === 'changelog_save') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $version = isset($_POST['version']) && is_string($_POST['version']) ? trim($_POST['version']) : '';
        $body = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
        $category = isset($_POST['category']) && is_string($_POST['category']) ? $_POST['category'] : 'site';
        $isPublished = isset($_POST['is_published']);

        if ($title === '' || gc_strlen($title) > 160) {
            gc_flash('error', 'Заголовок должен быть от 1 до 160 символов.');
            gc_redirect('admin_panel.php' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (gc_strlen($version) > 40) {
            gc_flash('error', 'Версия должна быть короче 40 символов.');
            gc_redirect('admin_panel.php' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (gc_strlen($body) > 8000) {
            gc_flash('error', 'Текст записи не должен превышать 8000 символов.');
            gc_redirect('admin_panel.php' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (!isset(GC_CATEGORIES[$category])) {
            $category = 'site';
        }

        if ($id > 0) {
            $statement = $pdo->prepare(
                'UPDATE changelog SET version = ?, title = ?, body = ?, category = ?, is_published = ? WHERE id = ?'
            );
            $statement->execute([$version, $title, $body, $category, $isPublished ? 'true' : 'false', $id]);
            gc_flash('ok', 'Запись обновлена.');
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO changelog (version, title, body, category, is_published, published_at, published_by)
                 VALUES (?, ?, ?, ?, ?, now(), ?)'
            );
            $statement->execute([$version, $title, $body, $category, $isPublished ? 'true' : 'false', (int) $user['id']]);
            gc_flash('ok', 'Запись опубликована.');
        }

        gc_redirect('admin_panel.php');
    }

    if ($action === 'changelog_delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $pdo->prepare('DELETE FROM changelog WHERE id = ?')->execute([$id]);
        gc_flash('ok', 'Запись удалена.');
        gc_redirect('admin_panel.php');
    }

    gc_redirect('admin_panel.php');
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editEntry = [
    'id' => 0,
    'version' => '',
    'title' => '',
    'body' => '',
    'category' => 'site',
    'is_published' => 1,
];
if ($canChangelog && $editId > 0) {
    $statement = $pdo->prepare(
        'SELECT id, version, title, body, category, CASE WHEN is_published THEN 1 ELSE 0 END AS is_published
         FROM changelog WHERE id = ?'
    );
    $statement->execute([$editId]);
    $row = $statement->fetch();
    if ($row) {
        $editEntry = [
            'id' => (int) $row['id'],
            'version' => (string) $row['version'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'category' => (string) $row['category'],
            'is_published' => (int) $row['is_published'],
        ];
    } else {
        gc_flash('error', 'Запись не найдена.');
        gc_redirect('admin_panel.php');
    }
}

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'files' => (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
    'folders' => (int) $pdo->query('SELECT COUNT(*) FROM folders')->fetchColumn(),
    'apps' => (int) $pdo->query('SELECT COUNT(*) FROM apps')->fetchColumn(),
    'releases' => (int) $pdo->query('SELECT COUNT(*) FROM app_releases')->fetchColumn(),
    'changelog' => (int) $pdo->query('SELECT COUNT(*) FROM changelog')->fetchColumn(),
];

$entries = $pdo->query(
    'SELECT id, version, title, category, CASE WHEN is_published THEN 1 ELSE 0 END AS is_published,
            published_at
     FROM changelog ORDER BY published_at DESC, id DESC LIMIT 20'
)->fetchAll();

$planned = [
    ['Права на папки', 'Роли и доступы для основного хранилища: папка наследует права родителя, файл — папки.', 'accent'],
    ['Журнал действий', 'Кто скачал, удалил или закрепил файл. Сейчас учитываются только счётчики скачиваний.', 'violet'],
    ['Редактирование профиля', 'Смена своего пароля и аватара без администратора.', 'info'],
];

gc_head('Админ-меню', 'admin');
gc_flashes();
?>

<div class="gc_wrap">
    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Управление</div>
            <h1 class="gc_hero_title">Админ-меню<span class="gc_accent">.</span></h1>
            <p class="gc_hero_lead">
                Сводка по сайту и разделы управления. Пользователи, теги и права —
                в «Пользователях», приложения и сборки — в «Приложениях».
            </p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat"><b><?= $stats['users'] ?></b><span>аккаунтов</span></div>
            <div class="gc_hero_stat"><b><?= $stats['files'] ?></b><span>файлов</span></div>
            <div class="gc_hero_stat"><b><?= $stats['apps'] ?></b><span>приложений</span></div>
        </div>
    </section>

    <?php gc_admin_nav('summary'); ?>

    <?php if ($canChangelog) { ?>
    <div class="gc_transfer_grid">
        <section class="gc_panel" id="editor">
            <div class="gc_panel_head">
                <h2><?= $editEntry['id'] > 0 ? 'Редактировать запись' : 'Новая запись changelog' ?></h2>
                <p>Запись появится на главной странице сразу после публикации. Порядок — от новых к старым.</p>
            </div>

            <form method="post" action="admin_panel.php">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="changelog_save">
                <input type="hidden" name="id" value="<?= (int) $editEntry['id'] ?>">

                <div class="gc_form_grid">
                    <div class="gc_field">
                        <label class="gc_label" for="version">Версия</label>
                        <input class="gc_input" id="version" type="text" name="version" maxlength="40" value="<?= gc_h($editEntry['version']) ?>" placeholder="2.0.3">
                    </div>
                    <div class="gc_field">
                        <label class="gc_label" for="category">Категория</label>
                        <select class="gc_select" id="category" name="category">
                            <?php foreach (GC_CATEGORIES as $key => $label) { ?>
                            <option value="<?= gc_h($key) ?>"<?= $editEntry['category'] === $key ? ' selected' : '' ?>><?= gc_h($label) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="gc_field">
                    <label class="gc_label" for="title">Заголовок</label>
                    <input class="gc_input" id="title" type="text" name="title" maxlength="160" required value="<?= gc_h($editEntry['title']) ?>" placeholder="Что изменилось">
                </div>

                <div class="gc_field">
                    <label class="gc_label" for="body">Текст <span class="gc_muted">(пустая строка — новый абзац)</span></label>
                    <textarea class="gc_textarea" id="body" name="body" rows="9" maxlength="8000" placeholder="Коротко опишите изменение"><?= gc_h($editEntry['body']) ?></textarea>
                </div>

                <label class="gc_check">
                    <input type="checkbox" name="is_published"<?= $editEntry['is_published'] ? ' checked' : '' ?>>
                    <span>Опубликовать на главной</span>
                </label>

                <div class="gc_btn-row">
                    <button class="gc_btn gc_btn--primary" type="submit"><?= $editEntry['id'] > 0 ? 'Сохранить' : 'Опубликовать' ?></button>
                    <?php if ($editEntry['id'] > 0) { ?>
                    <a class="gc_btn gc_btn--ghost" href="admin_panel.php">Отменить редактирование</a>
                    <?php } ?>
                </div>
            </form>
        </section>

        <aside class="gc_panel">
            <div class="gc_panel_head">
                <h2>Последние записи</h2>
                <p><?= $stats['changelog'] ?> всего. Показаны 20 последних.</p>
            </div>

            <?php if ($entries === []) { ?>
            <div class="gc_empty">
                <span class="gc_ico gc_ico--news"></span>
                <h3>Записей пока нет</h3>
                <p>Создайте первую запись в форме слева.</p>
            </div>
            <?php } else { ?>
            <div class="gc_links">
                <?php foreach ($entries as $entry) { ?>
                <div class="gc_linkrow">
                    <span class="gc_filetile"><span class="gc_ico gc_ico--news"></span></span>
                    <div class="gc_linkrow_main">
                        <a class="gc_linkrow_name" href="admin_panel.php?edit=<?= (int) $entry['id'] ?>#editor"><?= gc_h($entry['title']) ?></a>
                        <div class="gc_linkrow_meta">
                            <?php if ((string) $entry['version'] !== '') { ?><span>v<?= gc_h($entry['version']) ?></span><?php } ?>
                            <span><?= gc_h(gc_category_label((string) $entry['category'])) ?></span>
                            <span><?= gc_h(gc_dt($entry['published_at'], 'd.m.Y H:i')) ?></span>
                            <span><?= (int) $entry['is_published'] === 1 ? 'опубликовано' : 'черновик' ?></span>
                        </div>
                    </div>
                    <div class="gc_linkrow_actions">
                        <form method="post" action="admin_panel.php" data-confirm="Удалить запись «<?= gc_h($entry['title']) ?>»?">
                            <?= gc_csrf_field() ?>
                            <input type="hidden" name="action" value="changelog_delete">
                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                            <button type="submit" class="gc_iconbtn gc_iconbtn--danger" title="Удалить" aria-label="Удалить">
                                <span class="gc_ico gc_ico--delete"></span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php } ?>
        </aside>
    </div>
    <?php } ?>

    <section class="gc_section">
        <div class="gc_card gc_card--glass gc_release">
            <div class="gc_release_main">
                <div class="gc_release_version">
                    <b>v2</b>
                    <span>сводка</span>
                </div>
                <p>
                    В базе <?= $stats['folders'] ?> папок и <?= $stats['releases'] ?> релизов
                    в <?= $stats['apps'] ?> приложениях.
                </p>
                <div class="gc_release_meta">API обновлений: /update_api.php?channel=stable&amp;app=&lt;slug&gt;</div>
            </div>
        </div>
    </section>

    <section class="gc_section">
        <div class="gc_section_head">
            <div>
                <h2 class="gc_section_title">Дальше</h2>
                <p class="gc_section_note">Что ещё не переехало в новый интерфейс.</p>
            </div>
        </div>
        <div class="gc_soon_grid">
            <?php foreach ($planned as [$title, $text, $tone]) { ?>
            <article class="gc_card gc_card--glass gc_soon">
                <span class="gc_tag gc_tag--<?= gc_h($tone) ?>">Скоро</span>
                <h3><?= gc_h($title) ?></h3>
                <p><?= gc_h($text) ?></p>
            </article>
            <?php } ?>
        </div>
    </section>
</div>
<?php gc_foot(); ?>
