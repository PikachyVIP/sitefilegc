<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Page furniture: document head, quick-access curtain, footer.
 * Pages call gc_head(), print their content, then gc_foot().
 */

function gc_head(string $title, string $active = '', array $options = []): void
{
    $user = gc_user();
    $bodyClass = (string) ($options['body_class'] ?? 'gc_body');
    $showCurtain = (bool) ($options['curtain'] ?? ($user !== null));
    $openMain = (bool) ($options['main'] ?? true);
    $mainClass = (string) ($options['main_class'] ?? 'gc_main');
    $fullTitle = $title === '' ? GC_APP_NAME : $title . ' — ' . GC_APP_NAME;
    $version = rawurlencode(GC_ASSET_VERSION);
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#070421">
    <meta name="description" content="Закрытое хранилище файлов команды GC.">
    <title><?= gc_h($fullTitle) ?></title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link rel="preload" href="assets/font/Ubuntu-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/tokens.css?v=<?= $version ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?= $version ?>">
    <link rel="stylesheet" href="assets/css/base.css?v=<?= $version ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $version ?>">
    <link rel="stylesheet" href="assets/css/nocturne.css?v=<?= $version ?>">
</head>
<body class="<?= gc_h($bodyClass) ?>">
<a class="skip-link" href="#main">К содержимому</a>
<?php if ($showCurtain) { gc_curtain($active); } ?>
<?php if ($openMain) { ?>
<main id="main" class="<?= gc_h($mainClass) ?>">
<?php }
    $GLOBALS['gc_main_open'] = $openMain;
}

function gc_public_bar(): void
{
    $user = gc_user();
    $home = $user !== null ? 'changelog.php' : 'login.php';
    ?>
<header class="gc_public_bar">
    <div class="gc_public_in">
        <a class="gc_public_brand" href="<?= gc_h($home) ?>">
            <img src="assets/img/logo.png" alt="" width="30" height="30">
            <span>GC Files</span>
        </a>
        <div class="gc_public_actions">
            <?php if ($user !== null) { ?>
            <a class="gc_btn gc_btn--outline gc_btn--sm" href="changelog.php">В хранилище</a>
            <?php } else { ?>
            <a class="gc_btn gc_btn--primary gc_btn--sm" href="login.php">Войти</a>
            <?php } ?>
        </div>
    </div>
</header>
    <?php
}

function gc_curtain(string $active = ''): void
{
    $user = gc_user();
    if ($user === null) {
        return;
    }

    $links = [
        ['info.php', 'info', 'info', 'Инфо', 'О проекте и приложении'],
        ['apps.php', 'apps', 'grid', 'Приложения', 'Обновления приложений команды'],
        ['storage.php', 'storage', 'folder', 'Хранилище', 'Основное хранилище'],
        ['transfer.php', 'transfer', 'swap', 'Трансфер', 'Временный трансфер'],
        ['profile.php', 'profile', 'user', 'Профиль', 'Профиль и доступы'],
    ];
    if (gc_can_admin()) {
        $links[] = ['admin_panel.php', 'admin', 'gear', 'Админ', 'Админ-меню'];
    }

    $role = $user['tags'][0] ?? ($user['is_admin'] ? 'Администратор' : 'Участник');
    ?>
<header class="gc_curtain" id="gcCurtain" inert>
    <form class="gc_curtain_out_form" method="post" action="logout.php">
        <?= gc_csrf_field() ?>
        <button class="gc_curtain_out" type="submit">
            <span class="gc_ico gc_ico--logout" aria-hidden="true"></span>
            Выход
        </button>
    </form>

    <nav class="gc_curtain_nav" aria-label="Быстрый доступ">
        <?php foreach ($links as [$href, $key, $icon, $label, $hint]) { ?>
        <a class="gc_curtain_link<?= $active === $key ? ' is-active' : '' ?>" href="<?= gc_h($href) ?>" title="<?= gc_h($hint) ?>">
            <span class="gc_ico gc_ico--<?= gc_h($icon) ?>" aria-hidden="true"></span>
            <span><?= gc_h($label) ?></span>
        </a>
        <?php } ?>
    </nav>

    <div class="gc_curtain_user">
        <div class="gc_curtain_user_info">
            <span class="gc_curtain_user_name"><?= gc_h($user['login']) ?></span>
            <span class="gc_curtain_user_role"><?= gc_h($role) ?></span>
        </div>
        <span class="gc_avatar" aria-hidden="true"><?= gc_h(gc_initials($user['login'])) ?></span>
    </div>
</header>

<button class="gc_trigger" id="gcTrigger" type="button" aria-controls="gcCurtain" aria-expanded="false" aria-label="Панель быстрого доступа">
    <span class="gc_trigger_pill" aria-hidden="true">
        <span class="gc_ico gc_ico--chevron"></span>
    </span>
</button>
<?php
}

function gc_flashes(): void
{
    $flashes = gc_take_flashes();
    if ($flashes === []) {
        return;
    }

    $icons = ['error' => 'close', 'ok' => 'shield', 'info' => 'info'];
    ?>
<div class="gc_flashes" role="status">
    <?php foreach ($flashes as $flash) {
        $type = (string) ($flash['type'] ?? 'info');
        if (!isset($icons[$type])) {
            $type = 'info';
        }
        ?>
    <div class="gc_alert gc_alert--<?= gc_h($type) ?>">
        <span class="gc_ico gc_ico--<?= gc_h($icons[$type]) ?>" aria-hidden="true"></span>
        <span><?= gc_h((string) ($flash['message'] ?? '')) ?></span>
    </div>
    <?php } ?>
</div>
<?php
}

function gc_admin_nav(string $active = ''): void
{
    if (!gc_can_admin()) {
        return;
    }

    $tabs = [['admin_panel.php', 'summary', 'Обзор']];
    if (gc_has_right('create_users') || gc_has_right('manage_rights')
        || gc_has_right('edit_user_tags') || gc_has_right('create_delete_tags')) {
        $tabs[] = ['admin_users.php', 'users', 'Пользователи'];
    }
    if (gc_has_right('manage_updates')) {
        $tabs[] = ['admin_releases.php', 'releases', 'Приложения'];
    }
    ?>
<nav class="gc_admin_nav" aria-label="Разделы админ-меню">
    <?php foreach ($tabs as [$href, $key, $label]) { ?>
    <a class="gc_admin_tab<?= $active === $key ? ' is-active' : '' ?>" href="<?= gc_h($href) ?>"><?= gc_h($label) ?></a>
    <?php } ?>
</nav>
<?php
}

function gc_footer(): void
{
    ?>
<footer class="gc_footer">
    <div class="gc_footer_in">
        <span>GC Files <span class="gc_footer_dot">·</span> закрытый контур</span>
        <span>© <?= date('Y') ?> GC Team</span>
        <span><a href="info.php">Инфо</a> <span class="gc_footer_dot">·</span> <a href="storage.php">Хранилище</a></span>
    </div>
</footer>
<?php
}

function gc_foot(array $options = []): void
{
    if (!empty($GLOBALS['gc_main_open'])) {
        echo '</main>';
    }
    if (($options['footer'] ?? true) === true) {
        gc_footer();
    }
    ?>
<?php if (gc_user() !== null) { ?>
<div class="gc_upload_widget" id="gcUploadWidget" data-upload-widget hidden>
    <button type="button" class="gc_upload_widget_toggle" data-upload-toggle aria-expanded="false" aria-label="Очередь загрузки">
        <span class="loader" aria-hidden="true"></span>
        <span class="gc_upload_widget_badge" data-upload-badge hidden>0</span>
    </button>
    <section class="gc_upload_widget_panel" aria-label="Очередь загрузки">
        <header class="gc_upload_widget_head">
            <div>
                <span class="gc_upload_widget_eyebrow">Передача</span>
                <strong data-upload-title>Загрузка</strong>
            </div>
            <button type="button" class="gc_iconbtn" data-upload-close aria-label="Свернуть">
                <span class="gc_ico gc_ico--close"></span>
            </button>
        </header>
        <div class="gc_upload_widget_current">
            <span data-upload-current>Подготовка…</span>
            <span data-upload-file-percent>0%</span>
        </div>
        <div class="gc_upload_widget_bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-upload-widget-bar>
            <span data-upload-widget-fill></span>
        </div>
        <div class="gc_upload_widget_meta">
            <span data-upload-widget-percent>0%</span>
            <span data-upload-widget-size></span>
        </div>
        <p class="gc_upload_widget_note" data-upload-widget-note>Можно свернуть: загрузка продолжится в фоне.</p>
        <ul class="gc_upload_widget_list" data-upload-widget-list></ul>
        <div class="gc_upload_widget_actions">
            <button type="button" class="gc_btn gc_btn--outline gc_btn--sm" data-upload-retry hidden>Повторить</button>
            <a class="gc_btn gc_btn--primary gc_btn--sm" data-upload-refresh hidden href="#">Обновить</a>
        </div>
    </section>
</div>
<?php } ?>
<script src="assets/js/app.js?v=<?= rawurlencode(GC_ASSET_VERSION) ?>" defer></script>
</body>
</html>
<?php
}
