<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$item_type = $input['item_type'] ?? null;

// Получаем пользователя
$stmt = $pdo->prepare("SELECT has_pass, warn_count, is_banned FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

// ========== ТОВАРЫ ==========
$items = [
    'pass' => [
        'name' => 'Проходка на сервер Timefall',
        'price' => 300.00,
        'description' => 'Единоразовый доступ к серверу навсегда'
    ],
    'subscription_1m' => [
        'name' => 'Подписка Time+ (1 месяц)',
        'price' => 200.00,
        'description' => 'Подписка Time+ на 30 дней'
    ],
    'subscription_3m' => [
        'name' => 'Подписка Time+ (3 месяца)',
        'price' => 550.00,
        'description' => 'Подписка Time+ на 90 дней'
    ],
    'unban' => [
        'name' => 'Разбан на сервере Timefall',
        'price' => 1000.00,
        'description' => 'Снятие бана после 3 варнов'
    ]
];

if (!isset($items[$item_type])) {
    echo json_encode(['success' => false, 'error' => 'Неверный тип товара']);
    exit;
}

// ========== ПРОВЕРКИ ==========
if ($item_type === 'pass' && $user['has_pass']) {
    echo json_encode(['success' => false, 'error' => 'У вас уже есть проходка']);
    exit;
}

if ($item_type === 'unban') {
    if ($user['is_banned']) {
        echo json_encode(['success' => false, 'error' => 'У вас перманентный бан. Разбан невозможен.']);
        exit;
    }
    if ($user['warn_count'] < 3) {
        echo json_encode(['success' => false, 'error' => 'Вы не забанены на сервере. Разбан не требуется.']);
        exit;
    }
}

$item = $items[$item_type];
$inv_id = time() . $_SESSION['user_id'] . rand(100, 999);

// ========== ВАЖНО: Форматируем цену ==========
$price_formatted = number_format($item['price'], 2, '.', '');

// ========== ФОРМИРУЕМ ЧЕК (Receipt) ==========
$receipt_items = [
    [
        'name' => $item['name'],
        'quantity' => 1,
        'sum' => $item['price'],
        'payment_method' => 'full_payment',
        'payment_object' => 'service',
        'tax' => 'none'
    ]
];

// Создаём компактный JSON (без лишних пробелов)
$receipt = json_encode(['items' => $receipt_items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Кодируем для подписи (URL-encoding)
$receipt_encoded = urlencode($receipt);

// ========== ФОРМИРУЕМ ПОДПИСЬ ==========
// ВАЖНО: В подписи используем СЫРОЙ JSON, НЕ URL-кодированный!
// Порядок: MerchantLogin:OutSum:InvId:Receipt:Password1:Shp_item_type=value:Shp_user_id=value
$signature_string = ROBOKASSA_LOGIN . ':' . 
                   $price_formatted . ':' . 
                   $inv_id . ':' . 
                   $receipt . ':' .          // <-- Сырой JSON, БЕЗ urlencode()
                   ROBOKASSA_PASSWORD1 . ':' .
                   'Shp_item_type=' . $item_type . ':' .
                   'Shp_user_id=' . $_SESSION['user_id'];

$signature = md5($signature_string);

// ========== ФОРМИРУЕМ URL ==========
// Параметры в том порядке, как их ожидает Robokassa
$params = [
    'MerchantLogin' => ROBOKASSA_LOGIN,
    'OutSum' => $price_formatted,
    'InvId' => $inv_id,
    'Description' => $item['description'],
    'SignatureValue' => $signature,
    'Culture' => 'ru',
    'Encoding' => 'utf-8',
    'IsTest' => 0,
    'Shp_item_type' => $item_type,
    'Shp_user_id' => $_SESSION['user_id'],
    'SuccessURL' => 'https://timefall.ru/success.php',
    'FailURL' => 'https://timefall.ru/fail.php',
    'Receipt' => $receipt   // Передаём сырой JSON (http_build_query сам закодирует)
];

$robokassaUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx?' . http_build_query($params);

// ========== РАСШИРЕННОЕ ЛОГИРОВАНИЕ ==========
$debug_log = "===========================================\n";
$debug_log .= date('Y-m-d H:i:s') . " - СОЗДАН ПЛАТЕЖ\n";
$debug_log .= "===========================================\n";
$debug_log .= "Логин: " . ROBOKASSA_LOGIN . "\n";
$debug_log .= "Цена: " . $price_formatted . "\n";
$debug_log .= "InvId: " . $inv_id . "\n";
$debug_log .= "Пароль1: " . ROBOKASSA_PASSWORD1 . "\n";
$debug_log .= "Shp_item_type: " . $item_type . "\n";
$debug_log .= "Shp_user_id: " . $_SESSION['user_id'] . "\n";
$debug_log .= "-------------------------------------------\n";
$debug_log .= "СТРОКА ПОДПИСИ:\n";
$debug_log .= $signature_string . "\n";
$debug_log .= "-------------------------------------------\n";
$debug_log .= "ПОДПИСЬ: " . $signature . "\n";
$debug_log .= "-------------------------------------------\n";
$debug_log .= "URL: " . $robokassaUrl . "\n";
$debug_log .= "===========================================\n\n";

file_put_contents('payment_debug.log', $debug_log, FILE_APPEND);

// Проверка через сервис Robokassa (для отладки)
$check_url = "https://auth.robokassa.ru/Merchant/Index.aspx?" . 
    "MerchantLogin=" . ROBOKASSA_LOGIN . 
    "&OutSum=" . $price_formatted . 
    "&InvId=" . $inv_id . 
    "&Description=test" . 
    "&SignatureValue=" . $signature . 
    "&IsTest=1";

echo json_encode([
    'success' => true,
    'confirmation_url' => $robokassaUrl,
    'debug' => [
        'signature_string' => $signature_string,
        'signature' => $signature,
        'price' => $price_formatted
    ]
]);