<?php
session_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка оплаты</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
        }
        .fail-icon {
            font-size: 80px;
            color: #f44336;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #f44336;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            margin: 0 10px;
        }
        .btn.retry {
            background: #2196F3;
        }
        .btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="fail-icon">✗</div>
        <h1>Ошибка оплаты</h1>
        <p>К сожалению, произошла ошибка при оплате. Пожалуйста, попробуйте еще раз.</p>
        <a href="/" class="btn retry">Попробовать снова</a>
        <a href="/" class="btn">На главную</a>
    </div>
</body>
</html>