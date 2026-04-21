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

    $baseDomain = trim((string)($payload['account']['_links']['self'] ?? ''));

    if ($baseDomain === '') {
        throw new Exception('Домен amoCRM не найден');
    }

    $baseDomain = preg_replace('#^https?://#', '', $baseDomain);
    $baseDomain = trim($baseDomain, '/');

    $user = $storage->getUserByBaseDomain($baseDomain);

    if (!$user) {
        throw new Exception("Пользователь для домена {$baseDomain} не найден");
    }

    // Быстрый ответ amoCRM, чтобы не держать его в ожидании
    http_response_code(200);
    echo json_encode(['success' => true]);
    @ob_flush();
    @flush();

    if (function_exists('fastcgi_finish_request')) {
        set_time_limit(0);
        ignore_user_abort(true);
        fastcgi_finish_request();
    }

    // Устанавливаем контекст пользователя для работы с API amoCRM
    $client->setUserContext($user['id']);

    $addedContacts = $payload['contacts']['add'] ?? [];
    $updatedContacts = $payload['contacts']['update'] ?? [];
    $deletedContacts = $payload['contacts']['delete'] ?? [];

    foreach ($addedContacts as $item) {
        $contactId = (int)($item['id'] ?? 0);

        if ($contactId <= 0) {
            continue;
        }

        $eventKey = 'webhook_processed_contact_add_' . $contactId;
        if ($storage->getCache($eventKey)) {
            continue;
        }

        $client->upsertContactInAllContactsCache($contactId);

        $selectedFields = $storage->getDuplicateCheckFields($user['id']);

        // Ищем дубликаты для нового контакта
        $duplicates = $client->findDuplicatesForNewContact($contactId, $selectedFields);

        // Если найдены дубликаты, добавляем заметку в контакт
        if (!empty($duplicates)) {
            $lines = [];
            $lines[] = 'Обнаружены дубли:';
            $lines[] = '';

            $uniqueLines = [];

            foreach ($duplicates as $duplicate) {
                $fieldName = trim((string)($duplicate['field_name'] ?? 'Поле'));
                $fieldValueRaw = trim((string)($duplicate['field_value'] ?? ''));

                $fieldValue = formatDuplicateValueForDisplay(
                    $fieldValueRaw,
                    (string)($duplicate['field_type'] ?? ''),
                    (string)($duplicate['field_name'] ?? '')
                );

                $duplicateName = trim((string)($duplicate['name'] ?? 'Без имени'));
                $duplicateId = (int)($duplicate['id'] ?? 0);

                $line = "• {$fieldName}";

                if ($fieldValue !== '') {
                    $line .= ": {$fieldValue}";
                }

                $link = "https://{$baseDomain}/contacts/detail/{$duplicateId}";
                $line .= " → {$duplicateName} {$link}";

                $uniqueLines[$line] = true;
            }

            $lines = array_merge($lines, array_keys($uniqueLines));
            $client->addContactNote($contactId, implode("\n", $lines));
        }

        $storage->saveCache($eventKey, ['done' => true], 300, $user['id']);
    }

    // Обработка обновленных контактов
    foreach ($updatedContacts as $item) {
        $contactId = (int)($item['id'] ?? 0);
        if ($contactId <= 0) {
            continue;
        }

        $client->upsertContactInAllContactsCache($contactId);
        log_error('Webhook: контакт изменен', ['contact_id' => $contactId, 'domain' => $baseDomain]);
    }

    // Обработка удаленных контактов
    foreach ($deletedContacts as $item) {
        $contactId = (int)($item['id'] ?? 0);
        if ($contactId <= 0) {
            continue;
        }

        $client->removeContactFromAllContactsCache($contactId);
        log_error('Webhook: контакт удален', ['contact_id' => $contactId, 'domain' => $baseDomain]);
    }
} catch (Throwable $e) {
    log_error('Ошибка webhook контактов', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
