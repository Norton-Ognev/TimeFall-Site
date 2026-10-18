<?php
require_once 'config.php';

// Удаляем remember_token из БД
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// Удаляем сессию
$_SESSION = [];
session_destroy();

// Удаляем cookie
setcookie('remember_token', '', time() - 3600, '/');

echo json_encode(['success' => true]);
?>