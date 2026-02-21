<?php

$app = require __DIR__ . '/bootstrap.php';
$client = $app['client'];
$isAuthorized = $client->isAuthorized();

// Если пользователь авторизован, пробуем получить данные аккаунта и токены
if ($isAuthorized) {
    try {
        $tokens = $client->getAccessToken();
        $account = $client->getAccountInfo();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Функция для безопасного вывода данных в HTML
function escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

        <?php // Проверяем, авторизован ли пользователь, если да - показываем закрытую информацию
        if ($isAuthorized): ?>

            <h3>Авторизация активна</h3>

            <?php if ($account): ?>
                <ul>
                    <li>Имя аккаунта: <?= escape($account['name'] ?? '') ?></li>
                    <li>Поддомен: <?= escape($account['subdomain'] ?? '') ?></li>
                    <li>Язык: <?= escape($account['language'] ?? '') ?></li>
                    <li>Текущий пользователь ID: <?= escape($account['current_user_id'] ?? '') ?></li>
                </ul>
            <?php else: ?>
                <p>Данные аккаунта недоступны.</p>
            <?php endif; ?>

            <a href="index.php" class="btn">Вернуться на главную страницу</a>
            <br><br>

            <form action="auth.php" method="post">
                <input type="hidden" name="action" value="forceRefresh">
                <button type="submit" class="btn">Обновить токен</button>
            </form>

            <br>

            <?= $client->renderButton() ?>

            <?php if ($tokens): ?>
                <br><b>Актуальные данные:</b>
                <pre class="tokenBox"><?= escape(print_r($tokens, true)) ?></pre>
            <?php endif; ?>

        <?php // Если пользователь не авторизован - показываем кнопку для входа через AmoCRM
        else: ?>

            <h3>Авторизация не выполнена</h3>
            <p>Сначала выполните вход через amoCRM</p>

            <?= $client->renderButton() ?>

            <br><br>
            Или перейдите на <a href="index.php">главную страницу</a>.

        <?php endif; ?>

    </div>
</body>

</html>