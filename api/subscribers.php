<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://' . $_SERVER['HTTP_HOST']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$host = 'localhost';
$dbname = 'a1263903_users';
$username = 'a1263903_users';
$password = 'Norton2007!';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Все игроки с активной подпиской, сортировка по id
$stmt = $pdo->query("
    SELECT id, minecraft_nick, subscription_days
    FROM users
    WHERE subscription_days > 0
    ORDER BY id ASC
");

$subscribers = $stmt->fetchAll();

echo json_encode($subscribers, JSON_UNESCAPED_UNICODE);