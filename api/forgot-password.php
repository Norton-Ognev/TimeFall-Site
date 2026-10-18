<?php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die(json_encode(['success' => false, 'error' => 'Введите корректный email']));
}

// Ищем пользователя
$stmt = $pdo->prepare("SELECT id, minecraft_nick FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

// Всегда отвечаем одинаково (безопасность)
if (!$user) {
    die(json_encode(['success' => true, 'message' => 'Если email зарегистрирован, инструкции отправлены на почту']));
}

// Генерируем токен сброса (действует 1 час)
$reset_token = bin2hex(random_bytes(32));
$reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

$stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
$stmt->execute([$reset_token, $reset_expires, $user['id']]);

// Отправляем письмо
$reset_link = SITE_URL . '/reset-password.html?token=' . $reset_token;
$email_body = "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; background: #050505; color: #fff; padding: 20px; margin: 0; }
        .container { max-width: 500px; margin: 0 auto; background: #111; border: 2px solid #ffaa22; border-radius: 16px; padding: 30px; }
        h1 { color: #ffaa22; }
        .btn { display: inline-block; background: #ffaa22; color: #000; padding: 14px 30px; border-radius: 25px; text-decoration: none; font-weight: bold; margin: 20px 0; font-size: 16px; }
        .link { color: #ffaa22; word-break: break-all; font-size: 14px; }
        p { line-height: 1.6; color: #fff; }
        .footer-text { font-size: 14px; color: #aaa; margin-top: 20px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔑 Timefall</h1>
        <p>Привет, <strong>{$user['minecraft_nick']}</strong>!</p>
        <p>Мы получили запрос на сброс пароля. Если это были не вы — просто проигнорируйте это письмо.</p>
        <center><a href='{$reset_link}' class='btn'>Сбросить пароль</a></center>
        <p class='footer-text'>Ссылка действительна 1 час.<br>Или скопируйте в браузер:<br><span class='link'>{$reset_link}</span></p>
    </div>
</body>
</html>";

sendEmail($email, 'Сброс пароля — Timefall', $email_body);

echo json_encode(['success' => true, 'message' => 'Если email зарегистрирован, инструкции отправлены на почту']);
?>