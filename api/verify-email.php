<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: /verify-email.html?status=error&message=Токен не указан');
    exit;
}

// Ищем пользователя с таким токеном и подтверждаем email
$stmt = $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE verification_token = ? AND email_verified = 0");
$stmt->execute([$token]);

if ($stmt->rowCount() > 0) {
    // Успешно
    header('Location: /verify-email.html?status=success');
} else {
    // Токен недействителен или уже использован
    header('Location: /verify-email.html?status=error&message=Токен недействителен или уже использован');
}
exit;
?>