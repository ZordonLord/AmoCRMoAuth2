<?php
$app = require __DIR__ . '/../bootstrap.php';
$client = $app['client'];
$storage = $app['storage'];

// Безопасный редирект
function safeRedirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * =========================================
 * 1. WEBHOOK amoCRM (POST)
 * =========================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = !empty($_POST)
        ? $_POST
        : json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        $payload = [];
    }

    // webhook установки интеграции
    if (
        !empty($payload['client_id']) &&
        !empty($payload['state'])
    ) {
        $userId = trim($payload['state']);

        $existingUser = $storage->getUser($userId);

        $clientSecret = $payload['client_secret']
            ?? ($existingUser['client_secret'] ?? null);

        $baseDomain = !empty($payload['referer'])
            ? trim($payload['referer'])
            : ($existingUser['base_domain'] ?? null);

        $storage->saveUser([
            'id'            => $userId,
            'client_id'     => $payload['client_id'],
            'client_secret' => $clientSecret,
            'base_domain'   => $baseDomain,
            'updated_at'    => time(),
        ]);

        http_response_code(200);
        echo 'OK';
        exit;
    }

    // если POST, но не webhook
    http_response_code(400);
    echo 'Invalid webhook';
    exit;
}

/**
 * =========================================
 * 2. OAuth redirect (?code=...)
 * =========================================
 */
if (isset($_GET['code'])) {

    try {
        $userId = $_GET['state'] ?? null;

        if (!$userId) {
            throw new Exception('Нет state');
        }

        $user = $storage->getUser($userId);

        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        // проверка client_id
        if (
            !empty($_GET['client_id']) &&
            $user['client_id'] !== $_GET['client_id']
        ) {
            throw new Exception('client_id mismatch');
        }

        // обновить домен если пусто
        if (
            empty($user['base_domain']) &&
            !empty($_GET['referer'])
        ) {
            $user['base_domain'] = trim($_GET['referer']);

            $storage->saveUser([
                'id'            => $user['id'],
                'client_id'     => $user['client_id'],
                'client_secret' => $user['client_secret'],
                'base_domain'   => $user['base_domain'],
                'updated_at'    => time(),
            ]);
        }

        $tokens = $client->exchangeCodeForTokens($_GET['code']);
        $client->saveTokens($tokens);

        safeRedirect('index.php');
    } catch (Exception $e) {

        log_error('OAuth error', [
            'message' => $e->getMessage(),
            'get' => $_GET
        ]);

        safeRedirect('index.php?status=error');
    }
}

/**
 * =========================================
 * 3. logout / refresh
 * =========================================
 */

$action = $_GET['action'] ?? null;

if ($action === 'logout') {
    $client->logout();
    safeRedirect('index.php');
}

if ($action === 'forceRefresh') {
    try {
        $client->forceRefreshToken();
        safeRedirect('index.php?status=refreshed');
    } catch (Exception $e) {
        log_error('Refresh error', [
            'message' => $e->getMessage()
        ]);

        safeRedirect('index.php?status=refresh_error');
    }
}

/**
 * =========================================
 * 4. fallback
 * =========================================
 */

safeRedirect('index.php');
