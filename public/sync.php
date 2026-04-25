<?php

$app = require __DIR__ . '/../bootstrap.php';

$client = $app['client'];

$userId = $_GET['user'] ?? null;

if (!$userId) {
    http_response_code(400);
    exit('No user');
}

$client->setActiveUserId($userId);

try {
    $client->syncContactsToDb(250);
    echo "SYNC OK";
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}