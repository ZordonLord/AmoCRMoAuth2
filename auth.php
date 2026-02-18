<?php

$app = require __DIR__ . '/bootstrap.php';

$client = $app['client'];

if (($_POST['action'] ?? null) === 'logout') {
    $client->logout();
    header("Location: index.php");
    exit;
}