<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/OAuthClient.php';
$client = new OAuthClient($config);
$storageFile = __DIR__ . '/storage/tokens.json';

// Обработка кнопки принудительного обновления токена
if (isset($_GET['forceRefresh'])) {

    $tokens = json_decode(file_get_contents($storageFile), true);
    $newTokens = $client->refreshToken($tokens);
    try {
        $account = $client->getAccountInfo();
    } catch (Exception $e) {
        $account = null;
        echo "Ошибка OAuth или API: " . $e->getMessage();
    }
    header("Location: callback.php");
    exit;
}

// Обработка кнопки выхода
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

            // Если пришел код авторизации, обмениваем его на токены
            if (isset($_GET['code'])) {

                $tokens = $client->exchangeCodeForTokens($_GET['code']);

                file_put_contents(
                    __DIR__ . '/storage/tokens.json',
                    json_encode($tokens, JSON_PRETTY_PRINT)
                );

                try {
                    $account = $client->getAccountInfo();
                } catch (Exception $e) {
                    $account = null;
                    echo "Ошибка OAuth или API: " . $e->getMessage();
                }

                if ($account) {
                    echo "<ul>";
                    echo "<li>Имя аккаунта: {$account['name']}</li>";
                    echo "<li>Поддомен: {$account['subdomain']}</li>";
                    echo "<li>Язык: {$account['language']}</li>";
                    echo "<li>Текущий пользователь ID: {$account['current_user_id']}</li>";
                    echo "<li>Валюта: {$account['currency']} ({$account['currency_symbol']})</li>";
                    echo "</ul>";
                } else {
                    echo "<p>Данные аккаунта недоступны.</p>";
                }

                echo "<h3>Токены получены</h3>";
                echo "<a href='index.php' class='btn'>Вернуться на главную страницу</a><br /><br />";
                echo "<a href='callback.php?forceRefresh=1' class='btn'>Обновить токен</a><br /><br />";
                echo "<a href='callback.php?logout=1' class='btn'>Выйти</a><br /><br />";
                echo "<pre class='tokenBox'>";
                print_r($tokens);
                echo "</pre>";

                // Если код не пришел, проверяем наличие токенов 
            } else {

                // Если токенов нет, предлагаем авторизоваться
                if (!file_exists($storageFile)) {
                    echo "<h3>Авторизация не выполнена</h3>";
                    echo "Сначала выполните вход через OAuth на <a href='index.php'>главной странице</a>.";

                    // Если токены есть, показываем информацию для авторизованного пользователя    
                } else {

                    $accessToken = $client->getAccessToken();
                    $tokens = json_decode(file_get_contents($storageFile), true);

                    try {
                        $account = $client->getAccountInfo();
                    } catch (Exception $e) {
                        $account = null;
                        echo "Ошибка OAuth или API: " . $e->getMessage();
                    }

                    if ($account) {
                        echo "<ul>";
                        echo "<li>Имя аккаунта: {$account['name']}</li>";
                        echo "<li>Поддомен: {$account['subdomain']}</li>";
                        echo "<li>Язык: {$account['language']}</li>";
                        echo "<li>Текущий пользователь ID: {$account['current_user_id']}</li>";
                        echo "<li>Валюта: {$account['currency']} ({$account['currency_symbol']})</li>";
                        echo "</ul>";
                    } else {
                        echo "<p>Данные аккаунта недоступны.</p>";
                    }

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