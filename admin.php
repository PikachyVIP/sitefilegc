<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// === СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $new_login = trim($_POST['new_login'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $new_tags = $_POST['new_tags'] ?? [];
    
    if ($new_login === '' || $new_password === '') {
        $error = 'Логин и пароль обязательны!';
    } else {
        // Проверяем, существует ли уже такой логин
        $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$new_login]);
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким логином уже существует!';
        } else {
            $pdo->beginTransaction();
            try {
                // Создаём пользователя
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (login, password) VALUES (?, ?)');
                $stmt->execute([$new_login, $password_hash]);
                $new_user_id = $pdo->lastInsertId();
                
                // Выдаём выбранные теги
                if (!empty($new_tags)) {
                    $stmt_tag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
                    foreach ($new_tags as $tag) {
                        $stmt_tag->execute([$new_user_id, $tag]);
                    }
                }
                $pdo->commit();
                $message = "✅ Пользователь «{$new_login}» создан!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка создания пользователя: ' . $e->getMessage();
            }
        }
    }
}

// === СОЗДАНИЕ ТЕГА ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tag') {
    $new_tag = trim($_POST['new_tag'] ?? '');
    
    if ($new_tag === '') {
        $error = 'Название тега обязательно!';
    } else {
        // Проверяем, существует ли уже такой тег
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$new_tag]);
        if ($stmt->fetch()) {
            $error = 'Тег с таким названием уже существует!';
        } else {
            $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
            $stmt->execute([$new_tag]);
            $message = "✅ Тег «{$new_tag}» создан!";
        }
    }
}

// === СОХРАНЕНИЕ ТЕГОВ ПОЛЬЗОВАТЕЛЕЙ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tags') {
    $pdo->beginTransaction();
    try {
        // Очищаем все user_tags
        $pdo->exec('DELETE FROM user_tags');
        
        // Вставляем новые
        if (isset($_POST['user_tags']) && is_array($_POST['user_tags'])) {
            $stmt = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
            foreach ($_POST['user_tags'] as $user_id => $tags) {
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $stmt->execute([(int)$user_id, $tag]);
                    }
                }
            }
        }
        $pdo->commit();
        $message = '✅ Теги сохранены!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = '❌ Ошибка сохранения тегов!';
    }
}

// === УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ ===
if (isset($_GET['delete_user'])) {
    $delete_id = (int)$_GET['delete_user'];
    if ($delete_id != 1) { // Нельзя удалить админа (id=1)
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$delete_id]);
        $message = '✅ Пользователь удалён!';
    } else {
        $error = '❌ Нельзя удалить главного админа!';
    }
}

// === УДАЛЕНИЕ ТЕГА ===
if (isset($_GET['delete_tag'])) {
    $delete_tag_id = (int)$_GET['delete_tag'];
    $stmt = $pdo->prepare('DELETE FROM tags WHERE id = ?');
    $stmt->execute([$delete_tag_id]);
    $message = '✅ Тег удалён!';
}

// === ПОЛУЧАЕМ ДАННЫЕ ===

// Все пользователи
$stmt = $pdo->query('SELECT id, login FROM users ORDER BY login');
$users = $stmt->fetchAll();

// Все теги
$stmt = $pdo->query('SELECT id, name FROM tags ORDER BY id');
$all_tags = $stmt->fetchAll();

// Теги пользователей
$user_tags_data = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('SELECT t.id, t.name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ?');
    $stmt->execute([$user['id']]);
    $user_tags_data[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:,">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-page">
    <div class="container">
        <header class="header">
            <h1>⚙ Админ-панель</h1>
            <a href="files.php" class="back-btn">← Назад к файлам</a>
        </header>
        
        <?php if ($message): ?>
            <div class="success-message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Блоки в две колонки -->
        <div class="admin-grid">
            
            <!-- ЛЕВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <!-- СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ -->
                <div class="admin-block">
                    <h2>👤 Создать пользователя</h2>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-row">
                            <input type="text" name="new_login" placeholder="Логин" required>
                            <input type="text" name="new_password" placeholder="Пароль" required>
                        </div>
                        <div class="form-row">
                            <div class="admin-tags">
                                <?php foreach ($all_tags as $tag): ?>
                                    <label>
                                        <input type="checkbox" name="new_tags[]" value="<?php echo htmlspecialchars($tag['name']); ?>">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="action-btn green-btn">Создать пользователя</button>
                    </form>
                </div>
                
                <!-- СОЗДАНИЕ ТЕГА -->
                <div class="admin-block">
                    <h2>🏷 Создать тег</h2>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="create_tag">
                        <div class="form-row">
                            <input type="text" name="new_tag" placeholder="Название тега" required>
                            <button type="submit" class="action-btn gold-btn">Создать тег</button>
                        </div>
                    </form>
                </div>
                
                <!-- СПИСОК ТЕГОВ -->
                <div class="admin-block">
                    <h2>📋 Все теги (<?php echo count($all_tags); ?>)</h2>
                    <div class="tag-list">
                        <?php foreach ($all_tags as $tag): ?>
                            <span class="tag-item">
                                <?php echo htmlspecialchars($tag['name']); ?>
                                <a href="?delete_tag=<?php echo $tag['id']; ?>" 
                                   class="tag-delete" 
                                   onclick="return confirm('Удалить тег «<?php echo htmlspecialchars($tag['name']); ?>»?')">✕</a>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
            </div>
            
            <!-- ПРАВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <!-- УПРАВЛЕНИЕ ТЕГАМИ ПОЛЬЗОВАТЕЛЕЙ -->
                <div class="admin-block">
                    <h2>🔑 Теги пользователей</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_tags">
                        
                        <?php foreach ($users as $user): ?>
                            <div class="user-edit-card">
                                <div class="user-card-header">
                                    <h3>
                                        👤 <?php echo htmlspecialchars($user['login']); ?>
                                        <?php if ($user['id'] == 1): ?>
                                            <span class="admin-mark">(админ)</span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($user['id'] != 1): ?>
                                        <a href="?delete_user=<?php echo $user['id']; ?>" 
                                           class="delete-user-btn"
                                           onclick="return confirm('Удалить пользователя «<?php echo htmlspecialchars($user['login']); ?>»?')">Удалить</a>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="admin-tags">
                                    <?php foreach ($all_tags as $tag): ?>
                                        <label>
                                            <input type="checkbox" 
                                                   name="user_tags[<?php echo $user['id']; ?>][]" 
                                                   value="<?php echo htmlspecialchars($tag['name']); ?>"
                                                   <?php echo in_array($tag['name'], $user_tags_data[$user['id']] ?? []) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="save-btn">💾 Сохранить теги</button>
                    </form>
                </div>
                
            </div>
            
        </div>
    </div>
</body>
</html>