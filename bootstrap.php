<?php

require_once __DIR__ . '/src/OAuthClient.php';
require_once __DIR__ . '/src/logger.php';
$config = require __DIR__ . '/config/config.php';

// Инициализируем сессию и глобальный ID запроса для логирования
session_start();
// Генерируем уникальный ID для каждого запроса для удобства логирования
$GLOBALS['REQUEST_ID'] = bin2hex(random_bytes(4));

// Создаём и получаем экземпляр OAuthClient / config / storageFile
return [
    'client'      => new OAuthClient($config),
    'config'      => $config,
    'storageFile' => $config['tokenStorage'],
];
