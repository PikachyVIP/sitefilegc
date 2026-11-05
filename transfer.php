<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_user();
$uploadDir = __DIR__ . '/uploads';

/**
 * Removes expired transfer rows together with their files.
 * Called on every transfer page load: the site has no cron.
 */
function gc_transfer_purge(PDO $pdo, string $dir): void
{
    $statement = $pdo->query('DELETE FROM transfers WHERE expires_at <= now() RETURNING filename');
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        $stored = (string) $stored;
        if ($stored === '' || str_contains($stored, '/') || str_contains($stored, '\\')) {
            continue;
        }
        $path = $dir . '/' . $stored;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

gc_transfer_purge($pdo, $uploadDir);

$token = isset($_GET['t']) && is_string($_GET['t']) ? strtolower(trim($_GET['t'])) : '';
$isNew = isset($_GET['new']) && $_GET['new'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user === null) {
        gc_require_login();
    }
    gc_csrf_check();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'upload') {
        $file = $_FILES['file'] ?? null;
        $ttl = isset($_POST['ttl']) ? (int) $_POST['ttl'] : 24;
        if (!in_array($ttl, [1, 24, 168], true)) {
            $ttl = 24;
        }
        $note = isset($_POST['note']) && is_string($_POST['note']) ? trim($_POST['note']) : '';
        if (gc_strlen($note) > 500) {
            $note = gc_cut($note, 0, 500);
        }

        if (!is_array($file) || !isset($file['error'])) {
            gc_flash('error', 'Файл не выбран.');
            gc_redirect('transfer.php');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            gc_flash('error', gc_upload_error((int) $file['error']));
            gc_redirect('transfer.php');
        }

        $max = gc_max_upload();
        if ($max > 0 && (int) $file['size'] > $max) {
            gc_flash('error', 'Файл больше допустимого размера ' . gc_size($max) . '.');
            gc_redirect('transfer.php');
        }

        if (!is_uploaded_file((string) $file['tmp_name'])) {
            gc_flash('error', 'Загрузка не подтверждена сервером.');
            gc_redirect('transfer.php');
        }

        if (!gc_ensure_data_dir($uploadDir)) {
            gc_flash('error', 'Папка uploads недоступна для записи.');
            gc_redirect('transfer.php');
        }

        $original = gc_safe_upload_name((string) $file['name']);
        $stored = bin2hex(random_bytes(16));
        $path = $uploadDir . '/' . $stored;

        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            gc_flash('error', 'Не удалось сохранить файл на сервере.');
            gc_redirect('transfer.php');
        }

        $shareToken = bin2hex(random_bytes(16));
        $mime = gc_mime_type($path);

        try {
            $statement = $pdo->prepare(
                'INSERT INTO transfers (token, filename, original_name, mime_type, file_size, uploader_id, note, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, now() + (CAST(? AS integer) * interval \'1 hour\'))'
            );
            $statement->execute([
                $shareToken,
                $stored,
                $original,
                $mime,
                (int) $file['size'],
                (int) $user['id'],
                $note,
                $ttl,
            ]);
        } catch (Throwable $exception) {
            @unlink($path);
            gc_flash('error', 'Не удалось создать передачу. Попробуйте ещё раз.');
            gc_redirect('transfer.php');
        }

        gc_redirect('transfer.php?t=' . $shareToken . '&new=1');
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $statement = $pdo->prepare('SELECT id, filename, uploader_id FROM transfers WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if (!$row) {
            gc_flash('error', 'Передача не найдена или уже истекла.');
            gc_redirect('transfer.php');
        }

        if ((int) $row['uploader_id'] !== (int) $user['id'] && !gc_is_admin()) {
            gc_flash('error', 'Удалить передачу может только её автор.');
            gc_redirect('transfer.php');
        }

        $pdo->prepare('DELETE FROM transfers WHERE id = ?')->execute([$id]);
        $stored = (string) $row['filename'];
        if ($stored !== '' && !str_contains($stored, '/') && !str_contains($stored, '\\')) {
            $path = $uploadDir . '/' . $stored;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        gc_flash('ok', 'Передача удалена.');
        gc_redirect('transfer.php');
    }

    gc_redirect('transfer.php');
}

$landing = null;
$landingMissing = false;

if ($token !== '') {
    if (preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
        $statement = $pdo->prepare(
            'SELECT id, token, original_name, mime_type, file_size, uploader_id, note, created_at, expires_at, downloads
             FROM transfers WHERE token = ?'
        );
        $statement->execute([$token]);
        $landing = $statement->fetch() ?: null;
        if ($landing && strtotime((string) $landing['expires_at']) <= time()) {
            $landing = null;
        }
    }
    if (!$landing) {
        $landingMissing = true;
    }
}

$links = [];
if (!$landing && !$landingMissing) {
    if ($user === null) {
        gc_require_login();
    }
    $statement = $pdo->prepare(
        'SELECT id, token, original_name, file_size, downloads, created_at, expires_at
         FROM transfers WHERE uploader_id = ? AND expires_at > now()
         ORDER BY created_at DESC'
    );
    $statement->execute([(int) $user['id']]);
    $links = $statement->fetchAll();
}

if ($landingMissing) {
    gc_head('Ссылка недоступна', 'transfer', ['curtain' => false, 'main' => false]);
    gc_public_bar();
    ?>
<main id="main" class="gc_public_page">
<div class="gc_wrap">
    <div class="gc_landing_wrap">
        <div class="gc_card gc_card--glass gc_landing">
            <span class="gc_landing_icon"><span class="gc_ico gc_ico--close"></span></span>
            <h1>Ссылка не найдена или истекла</h1>
            <div class="gc_landing_meta">Временные передачи живут ограниченное время и удаляются автоматически.</div>
            <div class="gc_btn-row">
                <?php if ($user !== null) { ?>
                <a class="gc_btn gc_btn--primary" href="transfer.php">Создать новую передачу</a>
                <a class="gc_btn gc_btn--outline" href="changelog.php">На главную</a>
                <?php } else { ?>
                <a class="gc_btn gc_btn--primary" href="login.php">Войти на сайт</a>
                <a class="gc_btn gc_btn--outline" href="login.php">На главную</a>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
</main>
    <?php
    gc_foot();
    exit;
}

if ($landing) {
    $shareUrl = gc_share_url((string) $landing['token']);
    $isOwner = $user !== null
        && ((int) $landing['uploader_id'] === (int) $user['id'] || gc_is_admin());
    gc_head('Передача файла', 'transfer', ['curtain' => false, 'main' => false]);
    gc_public_bar();
    ?>
<main id="main" class="gc_public_page">
<div class="gc_wrap">
    <?php gc_flashes(); ?>
    <div class="gc_landing_wrap">
        <div class="gc_card gc_card--glass gc_landing">
            <span class="gc_landing_icon"><span class="gc_ico gc_ico--download"></span></span>
            <?php if ($isNew) { ?>
            <span class="gc_tag gc_tag--accent">Ссылка создана</span>
            <?php } else { ?>
            <span class="gc_tag gc_tag--info">Временная передача</span>
            <?php } ?>
            <h1><?= gc_h($landing['original_name']) ?></h1>
            <div class="gc_landing_meta">
                <span><?= gc_h(gc_size((int) $landing['file_size'])) ?></span>
                <span>до <?= gc_h(gc_dt($landing['expires_at'], 'd.m.Y H:i')) ?></span>
                <span><?= (int) $landing['downloads'] ?> скачиваний</span>
            </div>

            <?php if ($landing['note'] !== null && trim((string) $landing['note']) !== '') { ?>
            <p class="gc_lead"><?= nl2br(gc_h($landing['note'])) ?></p>
            <?php } ?>

            <div class="gc_btn-row">
                <a class="gc_btn gc_btn--primary" href="transfer_download.php?t=<?= gc_h($landing['token']) ?>">
                    <span class="gc_ico gc_ico--download"></span> Скачать файл
                </a>
                <button type="button" class="gc_btn gc_btn--outline" data-copy data-copy-value="<?= gc_h($shareUrl) ?>" data-copy-label="Ссылка скопирована">
                    <span class="gc_ico gc_ico--copy"></span> Скопировать ссылку
                </button>
            </div>

            <div class="gc_landing_note">Скачать файл может любой, у кого есть ссылка.</div>

            <?php if ($isOwner) { ?>
            <form method="post" action="transfer.php" class="gc_landing_delete" data-confirm="Удалить передачу и файл? Ссылка перестанет работать.">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $landing['id'] ?>">
                <button type="submit" class="gc_btn gc_btn--ghost gc_btn--sm">Удалить передачу</button>
            </form>
            <?php } ?>
        </div>
    </div>
</div>
</main>
    <?php
    gc_foot();
    exit;
}

gc_head('Трансфер', 'transfer');
?>
<div class="gc_wrap">
    <?php gc_flashes(); ?>

    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Временные ссылки</div>
            <h1 class="gc_h1 gc_hero_title">Трансфер</h1>
            <p class="gc_hero_lead">Временная передача файла по ссылке. Файл живёт от часа до недели и удаляется сам. Получателю не нужен аккаунт: достаточно ссылки.</p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat">
                <b><?= count($links) ?></b>
                <span>активных</span>
            </div>
        </div>
    </section>

    <div class="gc_transfer_grid">
        <section class="gc_panel">
            <div class="gc_panel_head">
                <h2>Новая передача</h2>
                <p>Загрузите файл и получите ссылку. Открыть её и скачать файл сможет любой — вход на сайт не требуется.</p>
            </div>

            <form method="post" action="transfer.php" enctype="multipart/form-data">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="upload">

                <label class="gc_drop" for="file">
                    <input type="file" id="file" name="file" required>
                    <span class="gc_drop_icon gc_ico gc_ico--upload" aria-hidden="true"></span>
                    <strong>Перетащите файл или выберите</strong>
                    <span class="gc_drop_file" data-drop-name></span>
                    <small class="gc_drop_hint" data-drop-hint>Любой файл до <?= gc_h(gc_size(gc_max_upload())) ?></small>
                </label>

                <div class="gc_field gc_field--space">
                    <span class="gc_label">Сколько хранить</span>
                    <div class="gc_seg">
                        <label><input type="radio" name="ttl" value="1"><span>1 час</span></label>
                        <label><input type="radio" name="ttl" value="24" checked><span>24 часа</span></label>
                        <label><input type="radio" name="ttl" value="168"><span>7 дней</span></label>
                    </div>
                    <p class="gc_ttl_note"><span class="gc_ico gc_ico--clock"></span> По истечении срока файл и запись удаляются при следующем открытии страницы.</p>
                </div>

                <div class="gc_field">
                    <label class="gc_label" for="note">Заметка <span class="gc_muted">(необязательно)</span></label>
                    <textarea class="gc_textarea" id="note" name="note" maxlength="500" rows="3" placeholder="Что внутри и для кого файл"></textarea>
                </div>

                <button type="submit" class="gc_btn gc_btn--primary gc_btn--block">
                    <span class="gc_ico gc_ico--upload"></span> Загрузить и получить ссылку
                </button>
            </form>
        </section>

        <aside class="gc_panel">
            <div class="gc_panel_head">
                <h2>Как это работает</h2>
                <p>Три шага, без тегов и без доступа к основному хранилищу.</p>
            </div>
            <div class="gc_steps">
                <div class="gc_step">
                    <span class="gc_step_num">01</span>
                    <div>
                        <h3>Загрузите файл</h3>
                        <p>Файл сохраняется под случайным именем. Оригинальное имя видно только на странице передачи.</p>
                    </div>
                </div>
                <div class="gc_step">
                    <span class="gc_step_num">02</span>
                    <div>
                        <h3>Отправьте ссылку</h3>
                        <p>Ссылка вида transfer.php?t=… открывает страницу с файлом. Аккаунт получателю не нужен.</p>
                    </div>
                </div>
                <div class="gc_step">
                    <span class="gc_step_num">03</span>
                    <div>
                        <h3>Дождитесь удаления</h3>
                        <p>Через 1 час, 24 часа или 7 дней файл удаляется. Ссылка после этого отвечает «истекла».</p>
                    </div>
                </div>
            </div>
            <div class="gc_ttl_note"><span class="gc_ico gc_ico--shield"></span> Ссылка — это и есть доступ. Не публикуйте её в открытых каналах: скачать файл сможет любой, у кого она есть.</div>
        </aside>
    </div>

    <?php if ($links !== []) { ?>
    <section class="gc_links_section">
        <div class="gc_section_head">
            <div>
                <h2 class="gc_section_title">Мои передачи</h2>
                <p class="gc_section_note">Активные ссылки, созданные вами. Список очищается сам, когда истекает срок.</p>
            </div>
            <span class="gc_badge"><?= count($links) ?></span>
        </div>
        <div class="gc_links">
            <?php foreach ($links as $link) {
                $url = gc_share_url((string) $link['token']);
                ?>
            <div class="gc_linkrow">
                <span class="gc_filetile"><span class="gc_ico gc_ico--file"></span></span>
                <div class="gc_linkrow_main">
                    <a class="gc_linkrow_name" href="transfer.php?t=<?= gc_h($link['token']) ?>"><?= gc_h($link['original_name']) ?></a>
                    <div class="gc_linkrow_meta">
                        <span><span class="gc_ico gc_ico--file"></span> <?= gc_h(gc_size((int) $link['file_size'])) ?></span>
                        <span><span class="gc_ico gc_ico--download"></span> <?= (int) $link['downloads'] ?></span>
                        <span><span class="gc_ico gc_ico--clock"></span> <?= gc_h(gc_dt($link['created_at'], 'd.m.Y H:i')) ?></span>
                        <span class="gc_expiry" data-expires="<?= gc_h(gc_iso($link['expires_at'])) ?>">
                            <span class="gc_ico gc_ico--clock"></span>
                            <span data-countdown>до <?= gc_h(gc_dt($link['expires_at'], 'd.m H:i')) ?></span>
                        </span>
                    </div>
                </div>
                <div class="gc_linkrow_actions">
                    <button type="button" class="gc_iconbtn" data-copy data-copy-value="<?= gc_h($url) ?>" title="Скопировать ссылку" aria-label="Скопировать ссылку">
                        <span class="gc_ico gc_ico--copy"></span>
                    </button>
                    <a class="gc_iconbtn" href="transfer_download.php?t=<?= gc_h($link['token']) ?>" title="Скачать" aria-label="Скачать">
                        <span class="gc_ico gc_ico--download"></span>
                    </a>
                    <form method="post" action="transfer.php" data-confirm="Удалить передачу и файл?">
                        <?= gc_csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
                        <button type="submit" class="gc_iconbtn gc_iconbtn--danger" title="Удалить" aria-label="Удалить">
                            <span class="gc_ico gc_ico--delete"></span>
                        </button>
                    </form>
                </div>
            </div>
            <?php } ?>
        </div>
    </section>
    <?php } ?>
</div>
<?php gc_foot(); ?>
