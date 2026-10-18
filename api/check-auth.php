<?php
require_once 'config.php';

// Проверяем сессию
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT id, minecraft_nick, email, has_pass, subscription_days FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo json_encode(['authenticated' => true, 'user' => $user]);
        exit;
    }
}

// Проверяем remember_token из cookie
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("SELECT id, minecraft_nick, email, has_pass, subscription_days FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Восстанавливаем сессию
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['minecraft_nick'] = $user['minecraft_nick'];
        
        echo json_encode(['authenticated' => true, 'user' => $user]);
        exit;
    }
}

echo json_encode(['authenticated' => false]);
?>