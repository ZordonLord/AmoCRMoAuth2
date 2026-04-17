<?php

$app = require __DIR__ . '/../bootstrap.php';

$client = $app['client'];
$storage = $app['storage'];

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
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

try {
    $payload = !empty($_POST)
        ? $_POST
        : json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        throw new Exception('Некорректный webhook payload');
    }

    // ID нового контакта
    $contactId = (int)($payload['contacts']['add'][0]['id'] ?? 0);

    if ($contactId <= 0) {
        throw new Exception('contact_id не найден');
    }

    // Домен amoCRM аккаунта
    $baseDomain = trim((string)($payload['account']['_links']['self'] ?? ''));

    if ($baseDomain === '') {
        throw new Exception('Домен amoCRM не найден');
    }

    // нормализация домена - удаляем протокол и слеши
    $baseDomain = preg_replace('#^https?://#', '', $baseDomain);
    $baseDomain = trim($baseDomain, '/');

    // ищем пользователя по домену
    $user = $storage->getUserByBaseDomain($baseDomain);

    if (!$user) {
        throw new Exception("Пользователь для домена {$baseDomain} не найден");
    }

    $cacheKey = 'webhook_processed_' . $contactId;

    $alreadyProcessed = $storage->getCache($cacheKey);

    if ($alreadyProcessed) {
        http_response_code(200);
        echo json_encode(['success' => true, 'skipped' => true]);
        exit;
    }

    // переключаем OAuth контекст на нужного пользователя
    $client->setUserContext($user['id']);

    // очищаем кэш этого amoCRM аккаунта
    $storage->clearUserCache($user['id']);

    // ищем дубли для нового контакта по выбранным полям
    $selectedFields = $storage->getDuplicateCheckFields($user['id']);

    $duplicates = $client->findDuplicatesForNewContact($contactId, $selectedFields);

    if (!empty($duplicates)) {

        $lines = [];
        $lines[] = 'Обнаружены дубли:';
        $lines[] = '';

        $uniqueLines = [];

        foreach ($duplicates as $item) {

            $fieldName = trim((string)($item['field_name'] ?? 'Поле'));
            $fieldValueRaw = trim((string)($item['field_value'] ?? ''));

            $fieldValue = formatDuplicateValueForDisplay(
                $fieldValueRaw,
                (string)($item['field_type'] ?? ''),
                (string)($item['field_name'] ?? '')
            );

            $duplicateName = trim((string)($item['name'] ?? 'Без имени'));
            $duplicateId = (int)($item['id'] ?? 0);

            $line = "• {$fieldName}";

            if ($fieldValue !== '') {
                $line .= ": {$fieldValue}";
            }

            $link = "https://{$baseDomain}/contacts/detail/{$duplicateId}";

            $line .= " → {$duplicateName} {$link}";

            $uniqueLines[$line] = true;
        }

        $lines = array_merge($lines, array_keys($uniqueLines));

        $text = implode("\n", $lines);

        $client->addContactNote($contactId, $text);

        $storage->saveCache($cacheKey, ['done' => true], 300, $user['id']);
    }

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'contact_id' => $contactId,
        'duplicates_found' => count($duplicates)
    ]);
} catch (Throwable $e) {

    log_error('Ошибка webhook дублей', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
