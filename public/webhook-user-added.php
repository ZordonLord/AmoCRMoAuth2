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

try {
    $payload = !empty($_POST)
        ? $_POST
        : json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        throw new Exception('Некорректный webhook payload');
    }

    log_error('Webhook payload', $payload);

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

    // нормализация домена: https://zordonlord6.amocrm.ru -> zordonlord6.amocrm.ru
    $baseDomain = preg_replace('#^https?://#', '', $baseDomain);
    $baseDomain = trim($baseDomain, '/');

    // ищем пользователя по домену
    $user = $storage->getUserByBaseDomain($baseDomain);

    if (!$user) {
        throw new Exception("Пользователь для домена {$baseDomain} не найден");
    }

    // переключаем OAuth контекст на нужного пользователя
    $client->setUserContext($user['id']);

    // ищем дубли по всем заполненным полям нового контакта
    $duplicates = $client->findDuplicatesForNewContact($contactId);

    if (!empty($duplicates)) {

        $lines = [];
        $lines[] = 'Обнаружены возможные дубли:';
        $lines[] = '';

        $uniqueLines = [];

        foreach ($duplicates as $item) {

            $fieldName = trim((string)($item['field_name'] ?? 'Поле'));
            $fieldValue = trim((string)($item['field_value'] ?? ''));
            $duplicateName = trim((string)($item['name'] ?? 'Без имени'));
            $duplicateId = (int)($item['id'] ?? 0);

            $line = "• {$fieldName}";

            if ($fieldValue !== '') {
                $line .= ": {$fieldValue}";
            }

            $line .= " → {$duplicateName} (ID: {$duplicateId})";

            $uniqueLines[$line] = true;
        }

        $lines = array_merge($lines, array_keys($uniqueLines));

        $text = implode("\n", $lines);

        $client->addContactNote($contactId, $text);

        log_error('Найдены дубли', [
            'contact_id' => $contactId,
            'duplicates' => $duplicates
        ]);
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
