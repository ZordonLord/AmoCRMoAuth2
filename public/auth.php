<?php

$app = require __DIR__ . '/../bootstrap.php';
$client = $app['client'];

$action = $_POST['action'] ?? null; // Получаем действие из POST-запроса

// Обработка получения кода авторизации
if (isset($_GET['code'])) {
    try {
        $tokens = $client->exchangeCodeForTokens($_GET['code']);
        $client->saveTokens($tokens);
        header("Location: callback.php");
        exit;

    } catch (Exception $e) {
        error_log("OAuth error: " . $e->getMessage());
        header("Location: callback.php?status=error");
        exit;
    }
}

// Обработка кнопки выхода
if ($action === 'logout') {
    $client->logout();
    header("Location: index.php");
    exit;
}

// Обработка кнопки принудительного обновления токена
if ($action === 'forceRefresh') {
    $client->forceRefreshToken();
    header("Location: callback.php");
    exit;
}

// Если ничего не передано, перенаправляем на главную страницу
header("Location: index.php");
exit;
