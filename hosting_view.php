<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/hosting_lib.php';

$uploadDir = gc_media_upload_dir();
gc_media_ensure_schema($pdo);
gc_media_purge($pdo, $uploadDir);

$token = isset($_GET['t']) && is_string($_GET['t']) ? strtolower(trim($_GET['t'])) : '';
$row = $token !== '' ? gc_media_find($pdo, $token) : null;
$missing = false;

if ($row && $row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
    $row = null;
}
if (!$row) {
    $missing = true;
} else {
    $path = gc_media_path((string) $row['filename']);
    $mime = gc_media_mime((string) $row['mime_type'], (string) $row['original_name']);
    if ($path === null || $mime === null) {
        $missing = true;
    }
}

if ($missing) {
    http_response_code(404);
    gc_head('Ссылка недоступна', '', ['curtain' => false, 'main' => false]);
    gc_public_bar();
    ?>
<main class="gc_public_main">
    <section class="gc_public_card gc_hosting_missing">
        <span class="gc_ico gc_ico--close gc_ico--xl" aria-hidden="true"></span>
        <h1>Ссылка недоступна</h1>
        <p>Публикация удалена или срок её жизни истёк. Попросите автора создать новую ссылку.</p>
        <a class="gc_btn gc_btn--outline" href="<?= gc_user() !== null ? 'hosting.php' : 'login.php' ?>"><?= gc_user() !== null ? 'К хостингу' : 'Войти' ?></a>
    </section>
</main>
    <?php
    gc_foot();
    exit;
}

$kind = gc_preview_kind((string) $row['mime_type'], (string) $row['original_name']);
$pageUrl = gc_media_page_url((string) $row['token']);
$directUrl = gc_media_direct_url((string) $row['token'], (string) $row['original_name'], (string) $row['mime_type']);
$downloadUrl = 'hosting_file.php?t=' . rawurlencode((string) $row['token']) . '&download=1';
$expires = $row['expires_at'] !== null ? date('d.m.Y H:i', strtotime((string) $row['expires_at'])) : null;
$isOwner = gc_user() !== null && ((int) gc_user()['id'] === (int) $row['uploader_id'] || gc_is_admin());

$pdo->prepare('UPDATE hosted_media SET views = views + 1 WHERE id = ?')->execute([(int) $row['id']]);

gc_head((string) $row['original_name'], '', ['curtain' => false, 'main' => false]);
gc_public_bar();
?>
<main class="gc_public_main gc_hosting_public">
    <section class="gc_hosting_public_card">
        <header class="gc_hosting_public_head">
            <div>
                <span class="gc_eyebrow">Хостинг фото и видео</span>
                <h1><?= gc_h((string) $row['original_name']) ?></h1>
                <p class="gc_hosting_public_meta">
                    <?= gc_h(gc_size((int) $row['file_size'])) ?>
                    <span>·</span>
                    <?= gc_h(date('d.m.Y H:i', strtotime((string) $row['created_at']))) ?>
                    <span>·</span>
                    <?= (int) $row['views'] + 1 ?> просм.
                </p>
            </div>
            <span class="gc_hosting_badge"><?= $row['is_copy'] ? 'загружено' : 'из хранилища' ?></span>
        </header>

        <div class="gc_hosting_stage">
            <?php if ($kind === 'video') { ?>
            <div class="gc_player" data-gc-player>
                <video data-gc-video playsinline preload="metadata" src="<?= gc_h($directUrl) ?>"></video>
                <button type="button" class="gc_player_big" data-gc-toggle aria-label="Воспроизвести">
                    <span class="gc_player_big_icon" aria-hidden="true"></span>
                </button>
                <div class="gc_player_controls">
                    <button type="button" class="gc_player_btn" data-gc-toggle aria-label="Воспроизвести"><span class="gc_player_play" data-gc-play-icon aria-hidden="true"></span></button>
                    <span class="gc_player_time" data-gc-current>0:00</span>
                    <input class="gc_player_seek" type="range" min="0" max="1000" value="0" step="1" aria-label="Перемотка" data-gc-seek>
                    <span class="gc_player_time" data-gc-duration>0:00</span>
                    <button type="button" class="gc_player_btn" data-gc-mute aria-label="Без звука"><span class="gc_player_mute" data-gc-mute-icon aria-hidden="true"></span></button>
                    <button type="button" class="gc_player_btn" data-gc-fullscreen aria-label="На весь экран"><span class="gc_player_full" aria-hidden="true"></span></button>
                </div>
            </div>
            <?php } else { ?>
            <img class="gc_hosting_image" src="<?= gc_h($directUrl) ?>" alt="<?= gc_h((string) $row['original_name']) ?>">
            <?php } ?>
        </div>

        <div class="gc_hosting_public_actions">
            <button type="button" class="gc_btn gc_btn--outline gc_btn--sm" data-copy data-copy-value="<?= gc_h($pageUrl) ?>">
                <span class="gc_ico gc_ico--link" aria-hidden="true"></span>
                Скопировать страницу
            </button>
            <button type="button" class="gc_btn gc_btn--outline gc_btn--sm" data-copy data-copy-value="<?= gc_h($directUrl) ?>">
                <span class="gc_ico gc_ico--copy" aria-hidden="true"></span>
                Прямая ссылка
            </button>
            <a class="gc_btn gc_btn--outline gc_btn--sm" href="<?= gc_h($directUrl) ?>" target="_blank" rel="noopener">
                <span class="gc_ico gc_ico--eye" aria-hidden="true"></span>
                Открыть файл
            </a>
            <a class="gc_btn gc_btn--outline gc_btn--sm" href="<?= gc_h($downloadUrl) ?>">
                <span class="gc_ico gc_ico--download" aria-hidden="true"></span>
                Скачать
            </a>
        </div>

        <footer class="gc_hosting_public_foot">
            <span><?= $expires !== null ? 'Ссылка работает до ' . gc_h($expires) : 'Ссылка работает, пока её не удалят' ?>.</span>
            <span>Доступна всем, у кого есть адрес.</span>
        </footer>

        <?php if ($isOwner) { ?>
        <form method="post" action="hosting.php" class="gc_hosting_public_delete" onsubmit="return confirm('Удалить публикацию? Оригинал в хранилище останется.');">
            <?= gc_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <button type="submit" class="gc_textlink gc_textlink--danger">Удалить публикацию</button>
        </form>
        <?php } ?>
    </section>
</main>
<?php
gc_foot(['scripts' => ['assets/js/hosting.js']]);
