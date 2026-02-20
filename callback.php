<?php

$app = require __DIR__ . '/bootstrap.php';
$client = $app['client'];
$isAuthorized = $client->isAuthorized();
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
        // Проверяем, авторизован ли пользователь, ессли да - показываем закрытую информацию
        if ($isAuthorized === true) {

            $tokens = $client->getAccessToken();

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
                echo "</ul>";
            } else {
                echo "<p>Данные аккаунта недоступны.</p>";
            }

            echo "<h3>Авторизация активна</h3>
                <a href='index.php' class='btn'>Вернуться на главную страницу</a><br /><br />
                <form action='auth.php' method='post'>
                <input type='hidden' name='action' value='forceRefresh'>
                <button type='submit' class='btn'>Обновить токен</button>
                </form><br />";

            echo $client->renderButton();
            echo "<br /><b>Актуальные данные:</b>
                <pre class='tokenBox'>";
            print_r($tokens);
            echo "</pre>";

            // Если пользователь не авторизован - показываем кнопку для входа через AmoCRM
        } else {
            echo "<h3>Авторизация не выполнена</h3>
                Сначала выполните вход через AmoCRM<br><br>";
            echo $client->renderButton();
            echo "<br><br>Или перейдите на <a href='index.php'>главную страницу</a>.";
        }
        ?>
    </div>
</body>

</html>