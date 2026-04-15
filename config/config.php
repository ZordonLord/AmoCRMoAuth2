<?php

require_once __DIR__ . '/../env.php';

loadEnv(__DIR__ . '/../.env');

return [
    'clientId'     => $_ENV['AMO_CLIENT_ID'],
    'clientSecret' => $_ENV['AMO_CLIENT_SECRET'],
    'redirectUri'  => $_ENV['AMO_REDIRECT_URI'],
    'baseDomain'   => $_ENV['AMO_BASE_DOMAIN'],

    // SQLite
    'dbPath'       => __DIR__ . '/../data/database.sqlite',

    // MySQL
    'dbHost'       => $_ENV['DB_HOST'] ?? 'localhost',
    'dbName'       => $_ENV['DB_NAME'] ?? '',
    'dbUser'       => $_ENV['DB_USER'] ?? '',
    'dbPass'       => $_ENV['DB_PASS'] ?? '',
];