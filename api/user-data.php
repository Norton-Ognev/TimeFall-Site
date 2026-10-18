<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $pdo->prepare("SELECT id FROM users WHERE remember_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
        }
    }
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, minecraft_nick, email, has_pass, subscription_days, 
           warn_count, is_banned, subscription_end_date, created_at, nick_colour
    FROM users 
    WHERE id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

// Берём subscription_days напрямую из БД, без вычислений
$subscription_valid_until = $user['subscription_end_date'] 
    ? date('d.m.Y', strtotime($user['subscription_end_date'])) 
    : null;

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $user['id'],
        'minecraft_nick' => $user['minecraft_nick'],
        'email' => $user['email'],
        'has_pass' => (bool)$user['has_pass'],
        'subscription_days' => (int)$user['subscription_days'],
        'remaining_days' => (int)$user['subscription_days'], // одно и то же значение
        'subscription_valid_until' => $subscription_valid_until,
        'warn_count' => (int)$user['warn_count'],
        'is_banned' => (bool)$user['is_banned'],
        'nick_colour' => $user['nick_colour'],
        'created_at' => date('d.m.Y', strtotime($user['created_at']))
    ]
]);