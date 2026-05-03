<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user'])) {
    header('Location: files.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    $stmt = $pdo->prepare('SELECT id, login, password FROM users WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        // Получаем теги пользователя
        $stmt = $pdo->prepare('
            SELECT t.name 
            FROM tags t 
            JOIN user_tags ut ON t.id = ut.tag_id 
            WHERE ut.user_id = ?
        ');
        $stmt->execute([$user['id']]);
        $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $_SESSION['user'] = $user['login'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['tags'] = $tags;
        header('Location: files.php');
        exit;
    }
    
    $error = 'Неверный логин или пароль!';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:,">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — Наш Файлообменник</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>GCFiles</h1>
                <p>Grande Confidence</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="login">Логин</label>
                    <input type="text" id="login" name="login" placeholder="Введи логин" required autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" placeholder="Введи пароль" required>
                </div>
                
                <button type="submit" class="login-btn">ВОЙТИ</button>
            </form>
        </div>
    </div>
</body>
</html>