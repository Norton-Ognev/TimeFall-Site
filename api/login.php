<?php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$login = trim($data['login'] ?? '');      // может быть email или ник
$password = $data['password'] ?? '';
$remember = $data['remember'] ?? true;    // "запомнить меня"

if (empty($login) || empty($password)) {
    die(json_encode(['success' => false, 'error' => 'Заполните все поля']));
}

// Ищем пользователя по email или нику
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR minecraft_nick = ?");
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

if (!$user) {
    die(json_encode(['success' => false, 'error' => 'Неверный логин или пароль']));
}

// Проверка пароля
if (!password_verify($password, $user['password_hash'])) {
    die(json_encode(['success' => false, 'error' => 'Неверный логин или пароль']));
}

// ★★★ ГЛАВНОЕ ДОБАВЛЕНИЕ: проверяем верификацию почты ★★★
if ($user['email_verified'] != 1) {
    die(json_encode([
        'success' => false, 
        'error' => 'Подтвердите email. Проверьте вашу почту и перейдите по ссылке для верификации'
    ]));
}

// Обновляем remember_token если нужно
if ($remember) {
    $remember_token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
    $stmt->execute([$remember_token, $user['id']]);
    setcookie('remember_token', $remember_token, time() + 86400 * 30, '/', '', false, true);
} else {
    // Если не запоминать — удаляем старый токен
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['minecraft_nick'] = $user['minecraft_nick'];

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $user['id'],
        'minecraft_nick' => $user['minecraft_nick'],
        'email' => $user['email'],
        'has_pass' => (bool)$user['has_pass'],
        'subscription_days' => (int)$user['subscription_days']
    ]
]);
?>