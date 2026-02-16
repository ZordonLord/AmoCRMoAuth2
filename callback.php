<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/OAuthClient.php';
$client = new OAuthClient($config);
$storageFile = __DIR__ . '/storage/tokens.json';

if (isset($_GET['forceRefresh'])) {

    $tokens = json_decode(file_get_contents($storageFile), true);
    $newTokens = $client->refreshToken($tokens);
    header("Location: callback.php");
    exit;
}

if (isset($_GET['logout'])) {
    if (file_exists($storageFile)) {
        unlink($storageFile);
    }
    header("Location: index.php");
    exit;
}

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
        <h2>Callback страница amoCRM</h2>

        <?
        try {
            if (isset($_GET['code'])) {

                $tokens = $client->exchangeCodeForTokens($_GET['code']);

                file_put_contents(
                    __DIR__ . '/storage/tokens.json',
                    json_encode($tokens, JSON_PRETTY_PRINT)
                );

                echo "<h3>Токены получены</h3>";
                echo "<a href='index.php' class='btn'>Вернуться на главную страницу</a><br /><br />";
                echo "<a href='callback.php?forceRefresh=1' class='btn'>Обновить токен</a><br /><br />";
                echo "<a href='callback.php?logout=1' class='btn'>Выйти</a><br /><br />";
                echo "<pre class='tokenBox'>";
                print_r($tokens);
                echo "</pre>";
            } else {

                if (!file_exists($storageFile)) {
                    echo "<h3>Авторизация не выполнена</h3>";
                    echo "Сначала выполните вход через OAuth на <a href='index.php'>главной странице</a>.";
                } else {

                    $accessToken = $client->getAccessToken();
                    $tokens = json_decode(file_get_contents($storageFile), true);

                    echo "<h3>Авторизация активна</h3>";
                    echo "<a href='index.php' class='btn'>Вернуться на главную страницу</a><br /><br />";
                    echo "<a href='callback.php?forceRefresh=1' class='btn'>Обновить токен</a><br /><br />";
                    echo "<a href='callback.php?logout=1' class='btn'>Выйти</a><br /><br />";
                    echo "<b>Актуальные данные:</b>";
                    echo "<pre class='tokenBox'>";
                    print_r($tokens);
                    echo "</pre>";
                }
            }
        } catch (Exception $e) {
            echo "Ошибка OAuth: " . $e->getMessage();
        }

        ?>
    </div>
</body>
</html>