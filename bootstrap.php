<?php

require_once __DIR__ . '/src/OAuthClient.php';
require_once __DIR__ . '/src/logger.php';
require_once __DIR__ . '/src/Storage/StorageInterface.php';
require_once __DIR__ . '/src/Storage/SqliteStorage.php';
$config = require __DIR__ . '/config/config.php';

// Инициализируем сессию и глобальный ID запроса для логирования
session_start();

// Генерируем уникальный ID для пользователя, если его нет в сессии
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = bin2hex(random_bytes(16));
}

// Генерируем уникальный ID для каждого запроса для удобства логирования
$GLOBALS['REQUEST_ID'] = bin2hex(random_bytes(4));

// Создаём экземпляр хранилища
$storage = new SqliteStorage($config['dbPath']);

// Создаём и получаем экземпляр OAuthClient / config / storage
return [
    'client'      => new OAuthClient($config, $storage),
    'config'      => $config,
    'storage'     => $storage,
];
