<?php

require_once __DIR__ . '/src/OAuthClient.php';
require_once __DIR__ . '/src/logger.php';
require_once __DIR__ . '/src/Storage/StorageInterface.php';
require_once __DIR__ . '/src/Storage/SqliteStorage.php';
// require_once __DIR__ . '/src/Storage/MysqlStorage.php';

$config = require __DIR__ . '/config/config.php';

// Генерируем уникальный ID для каждого запроса для удобства логирования
$GLOBALS['REQUEST_ID'] = bin2hex(random_bytes(4));

// Создаём экземпляр хранилища (выбираем между SQLite и MySQL)
// SQLite storage
$storage = new SqliteStorage($config['dbPath']);
// MySQL storage
// $storage = new MysqlStorage(
//     $config['dbHost'],
//     $config['dbName'],
//     $config['dbUser'],
//     $config['dbPass']
// );

// Создаём и получаем экземпляр OAuthClient / config / storage
return [
    'client'      => new OAuthClient($config, $storage),
    'config'      => $config,
    'storage'     => $storage,
];
