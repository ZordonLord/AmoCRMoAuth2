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
$customFieldsCount = 0;
$customFieldTypesById = [];
$settingsSaved = false;
$autoCheckError = null;

if ($isAuthorized) {
    try {
        $customFields = $client->getContactFields();

        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldId = $field['id'] ?? ($field['field_id'] ?? null);
                $fieldId = is_numeric($fieldId) ? (int)$fieldId : 0;
                if ($fieldId <= 0) {
                    continue;
                }

                $fieldCode = strtoupper(trim((string)($field['field_code'] ?? '')));
                $fieldTypeRaw = (string)($field['type'] ?? $field['field_type'] ?? $field['data_type'] ?? '');
                $fieldType = strtolower(trim($fieldTypeRaw));

                $isPhoneEmailCustom =
                    $fieldCode === 'PHONE' ||
                    $fieldCode === 'EMAIL' ||
                    ($fieldType !== '' && (strpos($fieldType, 'phone') !== false || strpos($fieldType, 'email') !== false));

                if ($isPhoneEmailCustom) {
                    continue;
                }

                $label = (string)($field['name'] ?? $field['label'] ?? $field['field_code'] ?? $field['code'] ?? '');
                $label = trim($label);
                if ($label === '') {
                    $label = 'Поле #' . $fieldId;
                }

                $fieldTypeRaw = (string)($field['type'] ?? $field['field_type'] ?? $field['data_type'] ?? '');
                $fieldTypeRaw = trim($fieldTypeRaw);
                $customFieldTypesById[$fieldId] = $fieldTypeRaw;

                $labelNorm = function (string $s): string {
                    $s = trim($s);
                    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
                    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
                };

                $labelNormValue = $labelNorm($label);
                if (
                    $labelNormValue === 'телефон' ||
                    $labelNormValue === 'phone' ||
                    $labelNormValue === 'email' ||
                    $labelNormValue === 'e-mail' ||
                    $labelNormValue === 'e_mail'
                ) {
                    continue;
                }

                $customFieldsById[$fieldId] = $label;
            }
        }

        $customFieldsCount = count($customFieldsById);

        $savedAutoDuplicateFields = [];

        try {
            if ($isAuthorized) {
                $userId = $client->getCurrentAuthorizedUserId();

                if ($userId) {
                    $savedAutoDuplicateFields = $storage->getDuplicateCheckFields($userId);
                }
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
        $fieldsError = $e->getMessage();
    }
}

// сохранение выбранных полей для авто-проверки при добавлении контакта
if ($isAuthorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_duplicate_fields'])) {
    try {
        $userId = $client->getCurrentAuthorizedUserId();

        if (!$userId) {
            throw new Exception('Не удалось определить пользователя');
        }

        $selectedAutoFields = $_POST['auto_duplicate_fields'] ?? [];

        if (!is_array($selectedAutoFields)) {
            $selectedAutoFields = [];
        }

        $validFields = [];

        foreach ($selectedAutoFields as $fieldKey) {
            $fieldKey = trim((string)$fieldKey);

            if ($fieldKey === '') {
                continue;
            }

            [$kind, $value] = array_pad(explode(':', $fieldKey, 2), 2, null);

            $kind = strtoupper(trim((string)$kind));
            $value = trim((string)$value);

            if ($kind === 'SYSTEM' && isset($systemFields[strtoupper($value)])) {
                $validFields[] = 'SYSTEM:' . strtoupper($value);
            }

            if ($kind === 'CUSTOM' && is_numeric($value)) {
                $fieldId = (int)$value;

                if ($fieldId > 0 && isset($customFieldsById[$fieldId])) {
                    $validFields[] = 'CUSTOM:' . $fieldId;
                }
            }
        }

        $storage->saveDuplicateCheckFields($userId, array_unique($validFields));

        $settingsSaved = true;
    } catch (Throwable $e) {
        $autoCheckError = $e->getMessage();
    }
}

$selectedFieldKey = (string)($_GET['field_key'] ?? 'SYSTEM:PHONE');
$selectedFieldKey = trim($selectedFieldKey);
if ($selectedFieldKey === '') {
    $selectedFieldKey = 'SYSTEM:PHONE';
}

[$selectedKind, $selectedValue] = array_pad(explode(':', $selectedFieldKey, 2), 2, null);
$selectedKind = strtoupper(trim((string)$selectedKind));
$selectedValue = $selectedValue !== null ? trim((string)$selectedValue) : null;

$selectedFieldLabel = 'Телефон';

if ($selectedKind === 'SYSTEM' && $selectedValue) {
    $code = strtoupper($selectedValue);
    if (isset($systemFields[$code])) {
        $selectedFieldLabel = $systemFields[$code];
    }
}

if ($selectedKind === 'CUSTOM' && $selectedValue) {
    $fieldId = is_numeric($selectedValue) ? (int)$selectedValue : 0;
    if ($fieldId > 0 && isset($customFieldsById[$fieldId])) {
        $selectedFieldLabel = $customFieldsById[$fieldId];
    }
}

$selectedFieldType = '';
if ($selectedKind === 'CUSTOM' && isset($selectedValue) && is_numeric((string)$selectedValue)) {
    $fid = (int)$selectedValue;
    $selectedFieldType = $customFieldTypesById[$fid] ?? '';
}

function formatDuplicateValueForDisplay(string $value, string $fieldTypeRaw, string $fieldLabel): string
{
    $valueTrim = trim($value);
    if ($valueTrim === '') {
        return $valueTrim;
    }

    $fieldType = strtolower(trim($fieldTypeRaw));
    $labelLower = function_exists('mb_strtolower') ? mb_strtolower(trim($fieldLabel), 'UTF-8') : strtolower(trim($fieldLabel));
    $looksLikeTimestamp = preg_match('/^\d+$/', $valueTrim) === 1;

    $isDate =
        $fieldType !== '' && (strpos($fieldType, 'date') !== false || strpos($fieldType, 'datetime') !== false || strpos($fieldType, 'time') !== false);

    if (!$isDate && ($labelLower !== '' && (strpos($labelLower, 'дата') !== false || strpos($labelLower, 'date') !== false))) {
        $isDate = true;
    }

    if (!$isDate || !$looksLikeTimestamp) {
        return $valueTrim;
    }

    try {
        $dt = new DateTime('@' . $valueTrim);
        $dt->setTimezone(new DateTimeZone('Europe/Moscow'));

        if (strpos($fieldType, 'datetime') !== false || strpos($fieldType, 'time') !== false || strpos($labelLower, 'врем') !== false) {
            return $dt->format('d.m.Y H:i');
        }

        return $dt->format('d.m.Y');
    } catch (Throwable $e) {
        return $valueTrim;
    }
}

if ($selectedKind !== 'SYSTEM' && $selectedKind !== 'CUSTOM') {
    $selectedKind = 'SYSTEM';
    $selectedValue = 'PHONE';
    $selectedFieldLabel = $systemFields['PHONE'];
}

if ($isAuthorized && isset($_GET['search'])) {
    try {
        if ($selectedKind === 'SYSTEM') {
            $code = strtoupper((string)$selectedValue);
            $duplicates = $client->findDuplicateContactsByFieldCode($code);
        } elseif ($selectedKind === 'CUSTOM') {
            $fieldId = is_numeric($selectedValue) ? (int)$selectedValue : 0;
            $duplicates = $client->findDuplicateContactsByCustomFieldId($fieldId);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
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

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Автопоиск дублей при добавлении контакта</h5>
                    <p class="text-muted">
                        Выберите поля, по которым webhook будет автоматически искать дубликаты у нового контакта.
                    </p>

                    <?php if ($settingsSaved): ?>
                        <div class="alert alert-success">
                            Настройки сохранены.
                        </div>
                    <?php endif; ?>

                    <?php if ($autoCheckError): ?>
                        <div class="alert alert-danger">
                            <?= e($autoCheckError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="row">
                            <?php foreach ($systemFields as $code => $label): ?>
                                <?php $key = 'SYSTEM:' . $code; ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
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

                            <?php foreach ($customFieldsById as $fieldId => $label): ?>
                                <?php $key = 'CUSTOM:' . $fieldId; ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
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

                        <button type="submit" name="save_auto_duplicate_fields" value="1" class="btn btn-success mt-3">
                            Сохранить настройки
                        </button>
                    </form>
                </div>
            </div>

            <form method="GET" class="mt-5">

                <label for="field_key" class="form-label">
                    Выберите поле контакта для поиска дубликатов
                </label>

                <select class="form-select" name="field_key" id="field_key" aria-label="field_key">
                    <?php
                    $systemOrder = ['PHONE', 'EMAIL'];
                    foreach ($systemOrder as $code) {
                        if (!isset($systemFields[$code])) {
                            continue;
                        }

                        $optKey = 'SYSTEM:' . $code;
                    ?>
                        <option value="<?= e($optKey) ?>" <?= $selectedFieldKey === $optKey ? 'selected' : '' ?>>
                            <?= e($systemFields[$code]) ?>
                        </option>
                    <?php
                    }
                    foreach ($customFieldsById as $fieldId => $label) {
                        $optKey = 'CUSTOM:' . (string)$fieldId;
                    ?>
                        <option value="<?= e($optKey) ?>" <?= $selectedFieldKey === $optKey ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php
                    }
                    ?>
                </select>

                <button type="submit" name="search" value="1" class="btn btn-primary mt-3">
                    Найти дубликаты
                </button>

            </form>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <b>Ошибка:</b> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['search']) && !$error): ?>
                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">
                            Результаты: дубли по полю `<?= e($selectedFieldLabel) ?>`
                            (<?= count($duplicates) ?>)
                        </h5>

                        <?php if (!empty($duplicates)): ?>
                            <ul class="list-group">
                                <?php foreach ($duplicates as $group): ?>
                                    <li class="list-group-item">
                                        <b><?= e(formatDuplicateValueForDisplay((string)$group['value'], $selectedFieldType, (string)$selectedFieldLabel)) ?></b>
                                        <ul class="mt-2 mb-0">
                                            <?php foreach ($group['contacts'] as $contact): ?>
                                                <li>
                                                    <?= e($contact['name']) ?> (ID: <?= e($contact['id']) ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Дубликаты не найдены.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>