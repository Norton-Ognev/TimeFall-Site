<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://' . $_SERVER['HTTP_HOST']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Подключение к БД
$host = 'localhost';
$dbname = 'a1263903_users';
$username = 'a1263903_users';
$password = 'Norton2007!';

define('ROBOKASSA_LOGIN', 'timefallru');      // Логин магазина
define('ROBOKASSA_PASSWORD1', 'onHB3TIxUERhARu4817l');           // Пароль №1
define('ROBOKASSA_PASSWORD2', 'Hiib24RmH31W4FRkwUXQ');           // Пароль №2
define('ROBOKASSA_TEST_MODE', 0);                          // 1 - тест, 0 - боевой

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
} catch(PDOException $e) {
    die(json_encode(['error' => 'Ошибка подключения к БД: ' . $e->getMessage()]));
}

// ========== БЛОК 1: Подключение PHPMailer ==========
require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

session_start();

// ========== БЛОК 2: Настройки почты (TLS) ==========
$mail_config = [
    'host' => ' smtp.timefall.ru',              // Хост Sprinthost/Beget
    'username' => 'support@timefall.ru',      // Полный email
    'password' => 'Norton2007!',              // Пароль
    'port' => 587,                           // TLS порт
    'encryption' => 'tls',                   // TLS шифрование
    'from_email' => 'support@timefall.ru',
    'from_name' => 'Timefall',
    'smtp_auth' => true,
    'timeout' => 30
];

// ========== БЛОК 3: Функция отправки письма (улучшенная) ==========
function sendEmail($to, $subject, $body) {
    global $mail_config;
    
    $mail = new PHPMailer(true);
    
    try {
        // Включаем подробное логирование
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;  // Уровень 2
        $mail->Debugoutput = function($str, $level) {
            file_put_contents(__DIR__ . '/smtp_debug.log', 
                date('H:i:s') . " [$level] $str", 
                FILE_APPEND);
        };
        
        // Настройки SMTP
        $mail->isSMTP();
        $mail->Host = $mail_config['host'];
        $mail->SMTPAuth = $mail_config['smtp_auth'];
        $mail->Username = $mail_config['username'];
        $mail->Password = $mail_config['password'];
        $mail->SMTPSecure = $mail_config['encryption'];
        $mail->Port = $mail_config['port'];
        
        // Дополнительные настройки для стабильности
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = $mail_config['timeout'];
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        // Отключаем проверку сертификата (только для теста!)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Отправитель и получатель
        $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
        $mail->addAddress($to);
        
        // Контент письма
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); // текстовая версия
        
        // Отправка
        $result = $mail->send();
        
        // Логирование успеха
        file_put_contents(__DIR__ . '/mail_success.log', 
            date('Y-m-d H:i:s') . " - Письмо отправлено на $to\n", 
            FILE_APPEND);
        
        return true;
        
    } catch (Exception $e) {
        // Детальное логирование ошибки
        $error_log = sprintf(
            "[%s] Ошибка: %s\nSMTP Error: %s\nTo: %s\nSubject: %s\nTrace: %s\n\n",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            $mail->ErrorInfo,
            $to,
            $subject,
            $e->getTraceAsString()
        );
        
        file_put_contents(__DIR__ . '/mail_error.log', $error_log, FILE_APPEND);
        
        // Сохраняем в глобальную переменную для отладки
        $GLOBALS['mail_debug'] = [
            'error' => $e->getMessage(),
            'smtp_error' => $mail->ErrorInfo ?? 'нет',
            'host' => $mail_config['host'],
            'port' => $mail_config['port']
        ];
        
        return false;
    }
}

// Базовый URL сайта
define('SITE_URL', 'http://' . $_SERVER['HTTP_HOST']);

// Дополнительная функция для тестирования
function testMailConnection() {
    global $mail_config;
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->isSMTP();
        $mail->Host = $mail_config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mail_config['username'];
        $mail->Password = $mail_config['password'];
        $mail->SMTPSecure = $mail_config['encryption'];
        $mail->Port = $mail_config['port'];
        $mail->Timeout = 10;
        
        // Пробуем соединиться
        $mail->smtpConnect();
        $mail->smtpClose();
        
        return ['success' => true, 'message' => 'SMTP соединение установлено'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}
?>