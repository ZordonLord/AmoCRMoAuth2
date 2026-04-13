<?php

set_time_limit(0);
ignore_user_abort(true);

ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');

$app = require __DIR__ . '/../bootstrap.php';

$client = $app['client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $count = (int)($_POST['count'] ?? 0);

    if ($count <= 0) {
        die('Введите число');
    }

    echo '<!doctype html><html><head><meta charset="UTF-8"><title>Создание контактов</title></head><body>';
    echo '<div style="font-family: Arial; padding:20px;">';
    echo '<h3>Запуск создания контактов...</h3>';

    @ob_flush();
    @flush();

    $client->generateMassTestContacts($count);

    echo '<br><b>Готово</b>';
    echo '</div></body></html>';

    exit;
}
?>

<!doctype html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Создание тестовых контактов</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
<div class="container">

    <h2>Создание тестовых контактов</h2>

    <form method="POST" action="generate-test-contacts.php" target="_blank">

        <label>
            Количество:
            <input
                type="number"
                name="count"
                value="10000"
                min="1"
                max="200000"
                required
            >
        </label>

        <br><br>

        <button type="submit">
            Создать
        </button>

    </form>

</div>
</body>
</html>