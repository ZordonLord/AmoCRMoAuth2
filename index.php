<?php
$app = require __DIR__ . '/bootstrap.php';
$config = $app['config'];
$client = $app['client'];
$storageFile = $app['storageFile'];

$isAuthorized = file_exists($storageFile); // Проверяем наличие токенов для определения авторизации

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

        <?php if ($isAuthorized): // Если авторизованы, показываем кнопку выхода ?>
            <a href="callback.php?logout=1" class="btn">Выйти</a>
            <br /><br />
            <a href="callback.php" class="btn">Перейти к callback странице</a>
   
        <?php else: // Если не авторизованы, показываем кнопку авторизации ?>
            <script
                class="amocrm_oauth"
                charset="utf-8"
                data-client-id="<?= $config['clientId'] ?>"
                data-title="Авторизоваться через amoCRM"
                data-compact="false"
                data-class-name="className"
                data-color="default"
                data-state="state"
                data-error-callback="functionName"
                data-mode="popup"
                src="https://www.amocrm.ru/auth/button.min.js"></script>
            <br /><br />
            <a href='callback.php' class="btn">Перейти к callback странице</a>
        <?php endif; ?>
    </div>
</body>
</html>