<?php

$app = require __DIR__ . '/bootstrap.php';
$client = $app['client'];

$action = $_POST['action'] ?? null; // Получаем действие из POST-запроса

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