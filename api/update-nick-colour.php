<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$colour = $input['nick_colour'] ?? null;

// Разрешённые цвета
$allowed = ['голубой', 'синий', 'фиолетовый', 'розовый', 'оранжевый', 'жёлтый', 'зелёный', null];

if (!in_array($colour, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Недопустимый цвет']);
    exit;
}

try {
    if ($colour === null) {
        $stmt = $pdo->prepare("UPDATE users SET nick_colour = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET nick_colour = ? WHERE id = ?");
        $stmt->execute([$colour, $_SESSION['user_id']]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}