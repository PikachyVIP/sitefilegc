<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

if (gc_user() !== null) {
    gc_redirect('changelog.php');
}

$nextRaw = $_GET['next'] ?? $_POST['next'] ?? null;
$next = gc_safe_next(is_string($nextRaw) ? $nextRaw : null);
$login = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    gc_csrf_check();
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
        $ip = gc_client_ip();
        $attempts = $pdo->prepare(
            "SELECT count(*) FROM login_attempts
              WHERE ip = ? AND attempted_at > now() - interval '15 minutes'"
        );
        $attempts->execute([$ip]);
        if ((int) $attempts->fetchColumn() >= 10) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = 'Слишком много попыток входа. Попробуйте через 15 минут.';
        } else {
        $statement = $pdo->prepare('SELECT id, login, password FROM users WHERE login = ?');
        $statement->execute([$login]);
        $account = $statement->fetch();

        if (is_array($account) && password_verify($password, (string) $account['password'])) {
            session_regenerate_id(true);

            $tagStatement = $pdo->prepare(
                'SELECT t.name FROM tags t JOIN user_tags ut ON ut.tag_id = t.id WHERE ut.user_id = ? ORDER BY t.name'
            );
            $tagStatement->execute([(int) $account['id']]);
            $tags = $tagStatement->fetchAll(PDO::FETCH_COLUMN);

            $rightStatement = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ? ORDER BY right_name');
            $rightStatement->execute([(int) $account['id']]);
            $rights = $rightStatement->fetchAll(PDO::FETCH_COLUMN);

            $_SESSION['user'] = (string) $account['login'];
            $_SESSION['user_id'] = (int) $account['id'];
            $_SESSION['tags'] = array_values(array_map('strval', $tags));
            $_SESSION['admin_rights'] = array_values(array_map('strval', $rights));
            $_SESSION['is_admin'] = in_array('admin_panel', $_SESSION['admin_rights'], true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            gc_redirect($next);
        }

        $pdo->prepare('INSERT INTO login_attempts (ip, login) VALUES (?, ?)')->execute([$ip, $login]);
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < now() - interval '2 days'");
        usleep(320000);
        $error = 'Неверный логин или пароль.';
        }
    }
}

gc_head('Вход', '', [
    'body_class' => 'gc_login_body',
    'curtain' => false,
    'main' => false,
]);
?>
<main class="gc_login" id="main">
    <section class="gc_login_brand">
        <div class="gc_login_mark">
            <img class="gc_login_logo" src="assets/img/logo.png" width="58" height="58" alt="">
            <div class="gc_login_word">
                <strong>GC Files</strong>
                <span>закрытый контур</span>
            </div>
        </div>

        <h1 class="gc_login_title">Файлы<br>команды <em>GC</em></h1>
        <p class="gc_login_tag">Одно место для релизов, рабочих материалов и временных передач. Доступ — только по приглашению, видимость — по тегам.</p>

        <ul class="gc_login_facts">
            <li><span class="gc_ico gc_ico--shield" aria-hidden="true"></span>Закрытый сайт без регистрации</li>
            <li><span class="gc_ico gc_ico--folder" aria-hidden="true"></span>Файлы видны по тегам и правам</li>
            <li><span class="gc_ico gc_ico--swap" aria-hidden="true"></span>Временные ссылки с истечением</li>
        </ul>
    </section>

    <section class="gc_card gc_card--glass gc_login_card">
        <span class="gc_badge"><span class="gc_point" aria-hidden="true"></span>Вход в систему</span>
        <h2>Авторизация</h2>
        <p class="gc_login_sub">Введите данные, которые выдал администратор команды.</p>

        <?php if ($error !== '') { ?>
        <div class="gc_alert gc_alert--error" role="alert">
            <span class="gc_ico gc_ico--close" aria-hidden="true"></span>
            <span><?= gc_h($error) ?></span>
        </div>
        <?php } ?>

        <form method="post" action="login.php">
            <?= gc_csrf_field() ?>
            <input type="hidden" name="next" value="<?= gc_h($next) ?>">

            <div class="gc_field">
                <label class="gc_label" for="login">Логин</label>
                <div class="gc_input_wrap">
                    <span class="gc_ico gc_ico--user gc_input_ico" aria-hidden="true"></span>
                    <input class="gc_input" type="text" id="login" name="login" value="<?= gc_h($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                </div>
            </div>

            <div class="gc_field">
                <label class="gc_label" for="password">Пароль</label>
                <div class="gc_input_wrap gc_input_wrap--action">
                    <span class="gc_ico gc_ico--shield gc_input_ico" aria-hidden="true"></span>
                    <input class="gc_input" type="password" id="password" name="password" autocomplete="current-password" required>
                    <button class="gc_input_btn" type="button" data-password-toggle="#password" aria-label="Показать пароль">
                        <span class="gc_ico gc_ico--eye" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <button class="gc_btn gc_btn--primary gc_btn--block gc_btn--lg" type="submit">Войти</button>
        </form>

        <p class="gc_login_foot">Регистрации нет. Если доступа нет — напишите главе GC: аккаунт и теги выдаёт он.</p>
    </section>

    <div class="gc_login_version">GC Files <?= gc_h(GC_APP_VERSION) ?></div>
</main>
<?php gc_foot(['footer' => false]); ?>
