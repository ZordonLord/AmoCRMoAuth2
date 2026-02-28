<?php

require_once __DIR__ . '/src/OAuthClient.php';
require_once __DIR__ . '/src/logger.php';
$config = require __DIR__ . '/config/config.php';

// Создаём и получаем экземпляр OAuthClient / config / storageFile
return [
    'client' => new OAuthClient($config),
    'config' => $config,
    'storageFile' => __DIR__ . '/storage/tokens.json'
];
