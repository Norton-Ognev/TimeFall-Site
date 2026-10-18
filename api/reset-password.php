<?php
/**
 * Сброс пароля по токену — Timefall
 * Метод: POST
 * Body: { token: string, password: string }
 */

require_once 'config.php';

header('Content-Type: application/json');

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$token    = trim($data['token'] ?? '');
$password = $data['password'] ?? '';

// === ВАЛИДАЦИЯ ===

if (empty($token) || strlen($token) < 32) {
    echo json_encode(['success' => false, 'error' => 'Недействительная ссылка для сброса пароля']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'Пароль должен быть минимум 6 символов']);
    exit;
}

// === ИЩЕМ ТОКЕН В БД ===

$stmt = $pdo->prepare(
    "SELECT id, reset_token_expires FROM users WHERE reset_token = ? LIMIT 1"
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Токен не найден или уже использован']);
    exit;
}

// Проверяем срок действия токена
if (!empty($user['reset_token_expires']) && strtotime($user['reset_token_expires']) < time()) {
    echo json_encode(['success' => false, 'error' => 'Срок действия ссылки истёк. Запросите сброс заново']);
    exit;
}

// === МЕНЯЕМ ПАРОЛЬ ===

$passwordHash = password_hash($password, PASSWORD_BCRYPT);

$updateStmt = $pdo->prepare(
    "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?"
);
$updateStmt->execute([$passwordHash, $user['id']]);

echo json_encode([
    'success' => true,
    'message' => 'Пароль успешно изменён! Сейчас вы будете перенаправлены на страницу входа.',
]);