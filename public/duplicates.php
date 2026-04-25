<?php

$app = require __DIR__ . '/../bootstrap.php';
$client = $app['client'];
$storage = $app['storage'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$isAuthorized = $client->isAuthorized();

$systemFields = [
    'PHONE' => 'Телефон',
    'EMAIL' => 'Email',
];

$duplicates = [];
$error = null;
$fieldsError = null;

$customFieldsById = [];
$customFieldTypesById = [];
$settingsSaved = false;
$autoCheckError = null;
$savedAutoDuplicateFields = [];

/**
 * =========================
 * ЗАГРУЗКА ПОЛЕЙ AMOCRM
 * =========================
 */
if ($isAuthorized) {
    try {
        $customFields = $client->getContactFields();

        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldId = (int)($field['id'] ?? $field['field_id'] ?? 0);
                if ($fieldId <= 0) {
                    continue;
                }

                $fieldCode = strtoupper(trim((string)($field['field_code'] ?? '')));
                $fieldTypeRaw = strtolower(trim((string)($field['type'] ?? $field['field_type'] ?? '')));

                // исключаем системные PHONE/EMAIL
                if (
                    $fieldCode === 'PHONE' ||
                    $fieldCode === 'EMAIL' ||
                    strpos($fieldTypeRaw, 'phone') !== false ||
                    strpos($fieldTypeRaw, 'email') !== false
                ) {
                    continue;
                }

                $label = trim((string)($field['name'] ?? 'Поле #' . $fieldId));

                $customFieldsById[$fieldId] = $label;
                $customFieldTypesById[$fieldId] = $fieldTypeRaw;
            }
        }

        $userId = $client->getCurrentAuthorizedUserId();
        if ($userId) {
            $savedAutoDuplicateFields = $storage->getDuplicateCheckFields($userId);
        }
    } catch (Throwable $e) {
        $fieldsError = $e->getMessage();
    }
}

/**
 * =========================
 * СОХРАНЕНИЕ НАСТРОЕК
 * =========================
 */
if ($isAuthorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_duplicate_fields'])) {
    try {
        $userId = $client->getCurrentAuthorizedUserId();

        if (!$userId) {
            throw new Exception('Не удалось определить пользователя');
        }

        $selected = $_POST['auto_duplicate_fields'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $valid = [];

        foreach ($selected as $key) {
            $key = trim((string)$key);
            if ($key === '') continue;

            [$kind, $value] = array_pad(explode(':', $key, 2), 2, null);

            $kind = strtoupper(trim((string)$kind));
            $value = trim((string)$value);

            if ($kind === 'SYSTEM' && isset($systemFields[strtoupper($value)])) {
                $valid[] = "SYSTEM:" . strtoupper($value);
            }

            if ($kind === 'CUSTOM' && is_numeric($value)) {
                $fid = (int)$value;
                if (isset($customFieldsById[$fid])) {
                    $valid[] = "CUSTOM:" . $fid;
                }
            }
        }

        $storage->saveDuplicateCheckFields($userId, array_values(array_unique($valid)));
        $settingsSaved = true;
    } catch (Throwable $e) {
        $autoCheckError = $e->getMessage();
    }
}

/**
 * =========================
 * ВЫБОР ПОЛЯ ДЛЯ ПОИСКА
 * =========================
 */
$selectedFieldKey = trim($_GET['field_key'] ?? 'SYSTEM:PHONE');

[$kind, $value] = array_pad(explode(':', $selectedFieldKey, 2), 2, null);
$kind = strtoupper(trim((string)$kind));
$value = trim((string)$value);

$selectedFieldLabel = 'Телефон';

if ($kind === 'SYSTEM') {
    $selectedFieldLabel = $systemFields[$value] ?? $selectedFieldLabel;
}

if ($kind === 'CUSTOM') {
    $fid = (int)$value;
    if (isset($customFieldsById[$fid])) {
        $selectedFieldLabel = $customFieldsById[$fid];
    }
}

/**
 * =========================
 * ПОИСК ДУБЛИКАТОВ
 * =========================
 */
if ($isAuthorized && isset($_GET['search'])) {
    try {
        // ЕДИНАЯ точка входа
        $duplicates = $client->findDuplicates($selectedFieldKey);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/**
 * =========================
 * FORMAT VALUE
 * =========================
 */
function formatDuplicateValueForDisplay(string $value): string
{
    return trim($value);
}
?>
<!doctype html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск дублей контактов</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">

        <h2 class="mb-4">Поиск дубликатов контактов</h2>

        <?php if (!$isAuthorized): ?>
            <div class="alert alert-warning">
                <h5>Авторизация не выполнена</h5>
                <p class="mb-2">Сначала выполните вход через amoCRM.</p>
                <?= $client->renderAuthButton() ?>
            </div>
        <?php else: ?>

            <!-- НАСТРОЙКИ -->
            <div class="card mb-4">
                <div class="card-body">

                    <h5 class="card-title">Автопоиск дублей при добавлении контакта</h5>

                    <p class="text-muted">
                        Выберите поля, по которым webhook будет автоматически искать дубликаты.
                    </p>

                    <?php if ($settingsSaved): ?>
                        <div class="alert alert-success">Настройки сохранены</div>
                    <?php endif; ?>

                    <?php if ($autoCheckError): ?>
                        <div class="alert alert-danger"><?= e($autoCheckError) ?></div>
                    <?php endif; ?>

                    <form method="POST">

                        <div class="row">

                            <!-- SYSTEM FIELDS -->
                            <?php foreach ($systemFields as $code => $label): ?>
                                <?php $key = 'SYSTEM:' . $code; ?>

                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input"
                                            type="checkbox"
                                            name="auto_duplicate_fields[]"
                                            value="<?= e($key) ?>"
                                            id="<?= e($key) ?>"
                                            <?= in_array($key, $savedAutoDuplicateFields, true) ? 'checked' : '' ?>>

                                        <label class="form-check-label" for="<?= e($key) ?>">
                                            <?= e($label) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- CUSTOM FIELDS -->
                            <?php foreach ($customFieldsById as $id => $label): ?>
                                <?php $key = 'CUSTOM:' . $id; ?>

                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input"
                                            type="checkbox"
                                            name="auto_duplicate_fields[]"
                                            value="<?= e($key) ?>"
                                            id="<?= e($key) ?>"
                                            <?= in_array($key, $savedAutoDuplicateFields, true) ? 'checked' : '' ?>>

                                        <label class="form-check-label" for="<?= e($key) ?>">
                                            <?= e($label) ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>

                        <button type="submit" name="save_auto_duplicate_fields" value="1"
                            class="btn btn-success mt-3">
                            Сохранить настройки
                        </button>

                    </form>

                </div>
            </div>

            <!-- ПОИСК -->
            <form method="GET" class="mt-5">

                <label class="form-label">
                    Выберите поле контакта для поиска дубликатов
                </label>

                <select class="form-select" name="field_key">

                    <?php foreach ($systemFields as $code => $label): ?>
                        <?php $key = "SYSTEM:$code"; ?>
                        <option value="<?= e($key) ?>"
                            <?= $selectedFieldKey === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>

                    <?php foreach ($customFieldsById as $id => $label): ?>
                        <?php $key = "CUSTOM:$id"; ?>
                        <option value="<?= e($key) ?>"
                            <?= $selectedFieldKey === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button type="submit" name="search" value="1"
                    class="btn btn-primary mt-3">
                    Найти дубликаты
                </button>

            </form>

            <!-- ОШИБКА -->
            <?php if ($error): ?>
                <div class="alert alert-danger mt-3">
                    <b>Ошибка:</b> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <!-- РЕЗУЛЬТАТ -->
            <?php if (isset($_GET['search']) && !$error): ?>

                <div class="card mt-4">
                    <div class="card-body">

                        <h5 class="card-title">
                            Результаты: <?= e($selectedFieldLabel) ?>
                            (<?= count($duplicates) ?>)
                        </h5>

                        <?php if (!empty($duplicates)): ?>

                            <ul class="list-group">

                                <?php foreach ($duplicates as $group): ?>
                                    <li class="list-group-item">

                                        <b>
                                            <?= e($group['value']) ?>
                                        </b>

                                        <ul class="mt-2 mb-0">

                                            <?php foreach ($group['contacts'] as $contact): ?>
                                                <li>
                                                    <?= e($contact['name']) ?>
                                                    (ID: <?= e($contact['id']) ?>)
                                                </li>
                                            <?php endforeach; ?>

                                        </ul>

                                    </li>
                                <?php endforeach; ?>

                            </ul>

                        <?php else: ?>
                            <p class="mb-0">Дубликаты не найдены</p>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>