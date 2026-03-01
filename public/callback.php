<?php

$app = require __DIR__ . '/../bootstrap.php';
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
        $error = $e->getMessage();
        $contactFields = [];
        $leadFields = [];
    }

    // Получаем список контактов и сделок
    try {
        $contacts = $client->getContacts();
        $leads = $client->getLeads();
    } catch (Exception $e) {
        $error = $e->getMessage();
        $contacts = [];
        $leads = [];
    }

    if (isset($_POST['create_contact'])) {

        $contact = [
            'first_name' => $_POST['first_name'] ?? '',
            'last_name'  => $_POST['last_name'] ?? '',
        ];

        $fields = $client->getContactFields();
        $customFields = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'];
            $inputName = "cf_{$fieldId}";

            if (!empty($_POST[$inputName])) {
                $customFields[] = [
                    'field_id' => $fieldId,
                    'values' => [
                        ['value' => $_POST[$inputName]]
                    ]
                ];
            }
        }

        if (!empty($customFields)) {
            $contact['custom_fields_values'] = $customFields;
        }

        try {
            $result = $client->addContact($contact);

            header("Location: callback.php");
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if (isset($_POST['create_lead'])) {
        $lead = [
            'name' => $_POST['lead_name'] ?? '',
        ];

        $fields = $client->getLeadFields();
        $customFields = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'];
            $inputName = "lf_{$fieldId}";

            if (!empty($_POST[$inputName])) {
                $customFields[] = [
                    'field_id' => $fieldId,
                    'values' => [
                        ['value' => $_POST[$inputName]]
                    ]
                ];
            }
        }

        if (!empty($customFields)) {
            $lead['custom_fields_values'] = $customFields;
        }

        try {
            $result = $client->addLead($lead);
            header("Location: callback.php");
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
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

                <?php if (!empty($account)): ?>
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

            <!-- Форма для создания контакта -->
            <h2>Создать контакт</h2>

            <form method="POST" class="form-container">

                <h3>Основные поля</h3>

                <input type="text" name="first_name" placeholder="Имя" required>
                <input type="text" name="last_name" placeholder="Фамилия">

                <h3>Дополнительные поля</h3>

                <?php foreach ($contactFields as $field): ?>
                    <div>
                        <label><?= htmlspecialchars($field['name']) ?></label>
                        <input type="text" name="cf_<?= $field['id'] ?>">
                    </div>
                <?php endforeach; ?>
                <br>
                <button type="submit" name="create_contact" class="btn">
                    Добавить контакт
                </button>
            </form>

            <!-- Показываем список сделок -->
            <div class="list-container">
                <h3>Список сделок</h3>

                <?php if (!empty($leads)): ?>
                    <ul>
                        <?php foreach ($leads as $lead): ?>
                            <li>
                                <b><?= e($lead['name'] ?? 'Без имени') ?></b>
                                (ID: <?= e($lead['id']) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Сделки не найдены</p>
                <?php endif; ?>
            </div>

            <!-- Форма для создания сделки -->
            <h2>Создать сделку</h2>

            <form method="POST" class="form-container">
                <h3>Основные поля</h3>
                <input type="text" name="lead_name" placeholder="Название сделки" required>

                <h3>Дополнительные поля</h3>
                <?php foreach ($leadFields as $field): ?>
                    <div>
                        <label><?= htmlspecialchars($field['name']) ?></label>
                        <input type="text" name="lf_<?= $field['id'] ?>">
                    </div>
                <?php endforeach; ?>
                <br>
                <button type="submit" name="create_lead" class="btn">
                    Добавить сделку
                </button>
            </form>
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