<?php

require_once __DIR__ . '/OAuthClient.php';
$config = require __DIR__ . '/config.php';

// Создаём и получаем экземпляр OAuthClient / config / storageFile
return [
    'client' => new OAuthClient($config),
    'config' => $config,
    'storageFile' => __DIR__ . '/storage/tokens.json'
];
