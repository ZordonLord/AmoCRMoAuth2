<?php
$app = require __DIR__ . '/bootstrap.php';
$config = $app['config'];
$client = $app['client'];
$storageFile = $app['storageFile'];

?>

<!doctype html>
<html>

<head>
    <meta charset="UTF-8">
    <title>OAuth amoCRM</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container">
        <h2>Авторизация amoCRM</h2>

        <?= $client->renderButton() ?>
        <br><br>
        <a href="callback.php" class="btn">Перейти к callback странице</a>
    </div>
</body>
</html>