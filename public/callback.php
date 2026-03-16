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

    // Получаем список контактов и сделок
    try {
        $contacts = $client->getContacts();
        $leads = $client->getLeads();
    } catch (Exception $e) {
        $error = $e->getMessage();
        $contacts = [];
        $leads = [];
    }

    // Создаём контакт при отправке формы
    if (isset($_POST['create_contact'])) {

        $contact = [
            'name' => $_POST['contact_name'] ?? 'Без имени',
            'custom_fields_values' => []
        ];

        foreach ($_POST as $key => $value) {

            if ($key === 'contact_name') {
                continue;
            }

            if (is_numeric($key) && $value !== '' && $value !== null) {

                $contact['custom_fields_values'][] = [
                    'field_id' => (int)$key,
                    'values' => [
                        [
                            'value' => (string)$value
                        ]
                    ]
                ];
            }
        }

        if (empty($contact['custom_fields_values'])) {
            unset($contact['custom_fields_values']);
        }

        try {
            $response = $client->addContact($contact);

            header("Location: callback.php");
            exit;
        } catch (Exception $e) {
            log_error("Ошибка создания контакта: " . $e->getMessage());
        }
    }

    // Обработка создания 3 тестовых контактов
    if (isset($_POST['create_test_contacts'])) {

        $testContacts = [
            [
                'name' => 'Тестовый контакт 1',
                'custom_fields_values' => [
                    [
                        'field_id' => 1410045,
                        'values' => [['value' => 'Текст из теста 1']]
                    ],
                    [
                        'field_id' => 1410047,
                        'values' => [['value' => '1 000']]
                    ],
                    [
                        'field_id' => 1410049,
                        'values' => [['value' => '15.03.2024']]
                    ]
                ]
            ],
            [
                'name' => 'Тестовый контакт 2',
                'custom_fields_values' => [
                    [
                        'field_id' => 1410045,
                        'values' => [['value' => 'Текст из теста 2']]
                    ],
                    [
                        'field_id' => 1410047,
                        'values' => [['value' => '2500 руб.']]
                    ],
                    [
                        'field_id' => 1410051,
                        'values' => [['value' => 'https://example.com']]
                    ]
                ]
            ],
            [
                'name' => 'Тестовый контакт 3',
                'custom_fields_values' => [
                    [
                        'field_id' => 1410049,
                        'values' => [['value' => '05.04.2024']]
                    ],
                    [
                        'field_id' => 1410053,
                        'values' => [['value' => 'Многострочный текст из теста']]
                    ],
                    [
                        'field_id' => 1410057,
                        'values' => [['value' => '20.04.2024 14:30']]
                    ]
                ]
            ]
        ];

        try {
            $response = $client->addContact($testContacts);
            log_error('Тестовые контакты созданы');

            header("Location: callback.php");
            exit;
        } catch (Exception $e) {
            log_error("Ошибка создания тестовых контактов: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            header("Location: callback.php");
            exit;
        }
    }

    // Создаём сделку при отправке формы
    if (isset($_POST['create_lead'])) {

        $lead = [
            'name' => $_POST['lead_name'] ?? 'Новая сделка',
            'price' => $_POST['price'] ?? null,
            'custom_fields_values' => []
        ];

        foreach ($_POST as $key => $value) {

            if ($key === 'lead_name') {
                continue;
            }

            if (is_numeric($key) && $value !== '' && $value !== null) {

                $lead['custom_fields_values'][] = [
                    'field_id' => (int)$key,
                    'values' => [
                        [
                            'value' => (string)$value
                        ]
                    ]
                ];
            }
        }

        if (empty($lead['custom_fields_values'])) {
            unset($lead['custom_fields_values']);
        }

        try {
            $response = $client->addLead($lead);

            header("Location: callback.php");
            exit;
        } catch (Exception $e) {
            log_error("Ошибка создания сделки: " . $e->getMessage());
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
                <input type="text" name="contact_name" placeholder="Имя контакта" required="">
                <br><br>
                <input type="text" name="1410045" placeholder="Текст">
                <br>
                <input type="text" name="1410047" placeholder="Число">
                <br>
                <input type="text" name="1410049" placeholder="Дата">
                <br>
                <input type="text" name="1410051" placeholder="Ссылка">
                <br>
                <input type="text" name="1410053" placeholder="Текстовая область">
                <br>
                <input type="text" name="1410057" placeholder="Дата и время">
                <br><br>
                <button type="submit" name="create_contact" class="btn">
                    Добавить контакт
                </button>
                <br><br>
                <!-- Кнопка для тестовых контактов -->
                <button type="submit" name="create_test_contacts" class="btn btn-test" style="background-color: #28a745; margin-left: 10px;">
                    Создать 3 тестовых контакта
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
                <input type="text" name="lead_name" placeholder="Название сделки" required="">
                <br><br>
                <input type="text" name="price" placeholder="Бюджет сделки">
                <br>
                <input type="text" name="1410059" placeholder="Текст">
                <br>
                <input type="text" name="1410061" placeholder="Число">
                <br>
                <input type="text" name="1410063" placeholder="Дата">
                <br>
                <input type="text" name="1410077" placeholder="Ссылка">
                <br>
                <input type="text" name="1410079" placeholder="Текстовая область">
                <br>
                <input type="text" name="1410081" placeholder="Дата и время">
                <br><br>
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