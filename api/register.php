<?php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);

$minecraft_nick = trim($data['minecraft_nick'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

// Валидация
if (empty($minecraft_nick) || empty($email) || empty($password)) {
    die(json_encode(['success' => false, 'error' => 'Все поля обязательны']));
}

if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $minecraft_nick)) {
    die(json_encode(['success' => false, 'error' => 'Некорректный ник Minecraft']));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die(json_encode(['success' => false, 'error' => 'Некорректный email']));
}

if (strlen($password) < 6) {
    die(json_encode(['success' => false, 'error' => 'Пароль должен быть минимум 6 символов']));
}

// Проверка на существование
$stmt = $pdo->prepare("SELECT id FROM users WHERE minecraft_nick = ? OR email = ?");
$stmt->execute([$minecraft_nick, $email]);
if ($stmt->fetch()) {
    die(json_encode(['success' => false, 'error' => 'Пользователь с таким ником или email уже существует']));
}

// Хэшируем пароль
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Генерируем токен верификации
$verification_token = bin2hex(random_bytes(32));

// СОЗДАЁМ пользователя
$stmt = $pdo->prepare("INSERT INTO users (minecraft_nick, email, password_hash, verification_token, email_verified) VALUES (?, ?, ?, ?, 0)");
$stmt->execute([$minecraft_nick, $email, $password_hash, $verification_token]);

$userId = $pdo->lastInsertId();

// Отправляем письмо
$verify_link = SITE_URL . '/verify-email.html?token=' . $verification_token;
$email_body = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background: #050505; color: #fff; padding: 20px; margin: 0; }
        .container { max-width: 500px; margin: 0 auto; background: #111; border: 2px solid #22ff88; border-radius: 16px; padding: 30px; }
        h1 { color: #22ff88; }
        .btn { display: inline-block; background: #22ff88; color: #000; padding: 14px 30px; border-radius: 25px; text-decoration: none; font-weight: bold; margin: 20px 0; }
        .link { color: #22ff88; word-break: break-all; }
        p { line-height: 1.6; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>⏳ Timefall</h1>
        <p>Привет, <strong>{$minecraft_nick}</strong>!</p>
        <p>Остался последний шаг — подтвердить email:</p>
        <center><a href='{$verify_link}' class='btn'>Подтвердить email</a></center>
        <p class='footer-text'>Ссылка: <span class='link'>{$verify_link}</span></p>
    </div>
</body>
</html>";

// === ОТПРАВЛЯЕМ ПИСЬМО ===
$email_sent = sendEmail($email, 'Подтверждение email — Timefall', $email_body);

// === ЕДИНСТВЕННАЯ ПРОВЕРКА И ОДИН ВЫВОД ===
if (!$email_sent) {
    // Письмо не отправилось - удаляем пользователя
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    
    die(json_encode([
        'success' => false,
        'error' => 'Не удалось отправить письмо. Попробуйте позже.'
    ]));
}

// УСПЕХ - только один JSON!
die(json_encode([
    'success' => true,
    'message' => 'Регистрация почти завершена! Проверьте почту для подтверждения email.',
    'require_verification' => true
]));
?>