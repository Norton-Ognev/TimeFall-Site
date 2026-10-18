<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config.php';

// ========== ЛОГИРОВАНИЕ ==========
$logFile = 'webhook_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Webhook вызван\n", FILE_APPEND);
file_put_contents($logFile, "POST: " . print_r($_POST, true) . "\n", FILE_APPEND);

if (!defined('ROBOKASSA_PASSWORD2')) {
    file_put_contents($logFile, "ERROR: ROBOKASSA_PASSWORD2 не определена\n", FILE_APPEND);
    http_response_code(500);
    echo "FAIL";
    exit;
}

// Получаем данные (могут быть как в POST, так и в GET)
$inv_id = $_REQUEST['InvId'] ?? null;
$out_sum = $_REQUEST['OutSum'] ?? null;
$signature = $_REQUEST['SignatureValue'] ?? null;
$user_id = $_REQUEST['Shp_user_id'] ?? null;
$item_type = $_REQUEST['Shp_item_type'] ?? null;

file_put_contents($logFile, "inv_id=$inv_id, user_id=$user_id, item_type=$item_type\n", FILE_APPEND);

// Проверяем наличие обязательных параметров (Receipt НЕ обязателен)
if (!$inv_id || !$out_sum || !$signature || !$user_id || !$item_type) {
    file_put_contents($logFile, "ERROR: Отсутствуют обязательные параметры\n", FILE_APPEND);
    http_response_code(400);
    echo "FAIL";
    exit;
}

// ========== ПРОВЕРКА ПОДПИСИ ==========
// ВАЖНО: Если Receipt не пришёл, используем пустую строку
// Пробуем оба варианта: с Receipt и без него

$receipt = $_REQUEST['Receipt'] ?? '';

// Вариант 1: Без Receipt (старый формат)
$my_signature_string_without_receipt = $out_sum . ':' . 
                                       $inv_id . ':' . 
                                       ROBOKASSA_PASSWORD2 . ':' .
                                       'Shp_item_type=' . $item_type . ':' .
                                       'Shp_user_id=' . $user_id;

$my_signature_without = md5($my_signature_string_without_receipt);

// Вариант 2: С Receipt (новый формат)
$my_signature_string_with_receipt = $out_sum . ':' . 
                                    $inv_id . ':' . 
                                    $receipt . ':' . 
                                    ROBOKASSA_PASSWORD2 . ':' .
                                    'Shp_item_type=' . $item_type . ':' .
                                    'Shp_user_id=' . $user_id;

$my_signature_with = md5($my_signature_string_with_receipt);

file_put_contents($logFile, "Строка без Receipt: $my_signature_string_without_receipt\n", FILE_APPEND);
file_put_contents($logFile, "Подпись без Receipt: $my_signature_without\n", FILE_APPEND);
file_put_contents($logFile, "Строка с Receipt: $my_signature_string_with_receipt\n", FILE_APPEND);
file_put_contents($logFile, "Подпись с Receipt: $my_signature_with\n", FILE_APPEND);
file_put_contents($logFile, "Подпись Robokassa: $signature\n", FILE_APPEND);

// Проверяем оба варианта
$signature_valid = false;
if (strtoupper($signature) === strtoupper($my_signature_without)) {
    $signature_valid = true;
    file_put_contents($logFile, "✅ Подпись совпадает (без Receipt)\n", FILE_APPEND);
} elseif (strtoupper($signature) === strtoupper($my_signature_with)) {
    $signature_valid = true;
    file_put_contents($logFile, "✅ Подпись совпадает (с Receipt)\n", FILE_APPEND);
} else {
    file_put_contents($logFile, "❌ Подпись НЕ совпадает (оба варианта)\n", FILE_APPEND);
}

if (!$signature_valid) {
    file_put_contents($logFile, "ERROR: Неверная подпись!\n", FILE_APPEND);
    http_response_code(400);
    echo "FAIL";
    exit;
}

// ========== ОБНОВЛЯЕМ БД ==========
try {
    file_put_contents($logFile, "Начинаем обновление БД для user_id=$user_id, item_type=$item_type\n", FILE_APPEND);
    
    // Проверяем существование пользователя
    $stmt = $pdo->prepare("SELECT id, has_pass FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        file_put_contents($logFile, "❌ Пользователь $user_id не найден\n", FILE_APPEND);
        http_response_code(400);
        echo "FAIL";
        exit;
    }
    
    file_put_contents($logFile, "Найден пользователь $user_id, текущий has_pass = " . $user['has_pass'] . "\n", FILE_APPEND);
    
    switch ($item_type) {
        case 'pass':
            $stmt = $pdo->prepare("UPDATE users SET has_pass = 1 WHERE id = ?");
            $stmt->execute([$user_id]);
            file_put_contents($logFile, "✅ Проходка выдана пользователю $user_id\n", FILE_APPEND);
            break;
            
        case 'subscription_1m':
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    subscription_days = subscription_days + 30,
                    subscription_end_date = CASE 
                        WHEN subscription_end_date IS NULL OR subscription_end_date < NOW() 
                        THEN DATE_ADD(NOW(), INTERVAL 30 DAY)
                        ELSE DATE_ADD(subscription_end_date, INTERVAL 30 DAY)
                    END
                WHERE id = ?
            ");
            $stmt->execute([$user_id]);
            file_put_contents($logFile, "✅ Подписка 1 месяц выдана пользователю $user_id\n", FILE_APPEND);
            break;
            
        case 'subscription_3m':
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    subscription_days = subscription_days + 90,
                    subscription_end_date = CASE 
                        WHEN subscription_end_date IS NULL OR subscription_end_date < NOW() 
                        THEN DATE_ADD(NOW(), INTERVAL 90 DAY)
                        ELSE DATE_ADD(subscription_end_date, INTERVAL 90 DAY)
                    END
                WHERE id = ?
            ");
            $stmt->execute([$user_id]);
            file_put_contents($logFile, "✅ Подписка 3 месяца выдана пользователю $user_id\n", FILE_APPEND);
            break;
            
        case 'unban':
            $stmt = $pdo->prepare("UPDATE users SET warn_count = 0, is_banned = 0 WHERE id = ?");
            $stmt->execute([$user_id]);
            file_put_contents($logFile, "✅ Разбан выполнен для пользователя $user_id\n", FILE_APPEND);
            break;
            
        default:
            file_put_contents($logFile, "ERROR: Неизвестный тип: $item_type\n", FILE_APPEND);
            http_response_code(400);
            echo "FAIL";
            exit;
    }
    
    // Проверяем, изменилась ли запись
    $stmt = $pdo->prepare("SELECT has_pass FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    file_put_contents($logFile, "После обновления has_pass = " . ($result['has_pass'] ?? 'NULL') . "\n", FILE_APPEND);
    
    file_put_contents($logFile, "✅ Успешно! OK$inv_id\n", FILE_APPEND);
    echo "OK$inv_id";
    
} catch (PDOException $e) {
    file_put_contents($logFile, "❌ Ошибка БД: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo "FAIL";
}