<?php

$app = require __DIR__ . '/bootstrap.php';
$client = $app['client'];
$isAuthorized = $client->isAuthorized();

// Функция для безопасного вывода данных в HTML
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Если пользователь авторизован
if ($isAuthorized) {
    // Получаем актуальные токены и информацию об аккаунте
    try {
        $tokens = $client->getAccessToken();
        $account = $client->getAccountInfo();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    // Получаем поля контактов и сделок
    try {
        $contactFields = $client->getContactFields();
        $leadFields = $client->getLeadFields();
    } catch (Exception $e) {
        echo "<p>Ошибка получения полей: " . e($e->getMessage()) . "</p>";
        $contactFields = [];
        $leadFields = [];
    }

    // Получаем список контактов и сделок
    try {
        $contacts = $client->getContacts();
        $leads = $client->getLeads();
    } catch (Exception $e) {
        echo "<p>Ошибка получения данных: " . e($e->getMessage()) . "</p>";
        $contacts = [];
        $leads = [];
    }
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
            <div class="list-container">
                <h3>Авторизация активна</h3>

                <?php if ($account): ?>
                    <ul>
                        <li>Имя аккаунта: <?= e($account['name'] ?? '') ?></li>
                        <li>Поддомен: <?= e($account['subdomain'] ?? '') ?></li>
                        <li>Язык: <?= e($account['language'] ?? '') ?></li>
                        <li>Текущий пользователь ID: <?= e($account['current_user_id'] ?? '') ?></li>
                    </ul>
                <?php else: ?>
                    <p>Данные аккаунта недоступны.</p>
                <?php endif; ?>
            </div>
            <br>
            <a href="index.php" class="btn">Вернуться на главную страницу</a>
            <br><br>

            <form action="auth.php" method="post">
                <input type="hidden" name="action" value="forceRefresh">
                <button type="submit" class="btn">Обновить токен</button>
            </form>

            <br>

            <!-- Показываем кнопку для выхода -->
            <?= $client->renderAuthButton() ?>

            <!-- Показываем актуальные токены -->
            <?php if ($tokens): ?>
                <br><b>Актуальные данные:</b>
                <pre class="tokenBox"><?= e(print_r($tokens, true)) ?></pre>
            <?php endif; ?>

            <!-- Показываем поля контактов -->
            <div class="list-container">
                <h3>Поля контактов</h3>

                <?php if (!empty($contactFields)): ?>
                    <ul>
                        <?php foreach ($contactFields as $field): ?>
                            <li>
                                <b><?= e($field['name']) ?></b>
                                (ID: <?= e($field['id']) ?>,
                                Тип: <?= e($field['type']) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Поля контактов не найдены</p>
                <?php endif; ?>
            </div>

            <!-- Показываем поля сделок -->
            <div class="list-container">
                <h3>Поля сделок</h3>

                <?php if (!empty($leadFields)): ?>
                    <ul>
                        <?php foreach ($leadFields as $field): ?>
                            <li>
                                <b><?= e($field['name']) ?></b>
                                (ID: <?= e($field['id']) ?>,
                                Тип: <?= e($field['type']) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Поля сделок не найдены</p>
                <?php endif; ?>
            </div>

            <!-- Показываем список контактов -->
            <div class="list-container">
                <h3>Список контактов</h3>

                <?php if (!empty($contacts)): ?>
                    <ul>
                        <?php foreach ($contacts as $contact): ?>
                            <li>
                                <b><?= e($contact['name'] ?? 'Без имени') ?></b>
                                (ID: <?= e($contact['id']) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Контакты не найдены</p>
                <?php endif; ?>
            </div>

            <!-- Показываем список сделок -->
            <div class="list-container">
                <h3>Список сделок</h3>

                <?php if (!empty($leads)): ?>
                    <ul>
                        <?php foreach ($leads as $lead): ?>
                            <li>
                                <b><?= e($lead['name'] ?? 'Без названия') ?></b>
                                (ID: <?= e($lead['id']) ?>,
                                Цена: <?= e($lead['price'] ?? 0) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Сделки не найдены</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Если пользователь не авторизован, показываем кнопку для входа и сообщение -->
            <h3>Авторизация не выполнена</h3>
            <p>Сначала выполните вход через amoCRM</p>

            <?= $client->renderAuthButton() ?>

            <br><br>
            Или перейдите на <a href="index.php">главную страницу</a>.

        <?php endif; ?>

    </div>
</body>

</html>