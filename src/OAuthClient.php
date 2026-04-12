<?php
require_once __DIR__ . '/HttpException.php';

class OAuthClient
{
    private $config;
    private $storage;
    private $requestsInCurrentSecond = 0; // счётчик запросов для троттлинга
    private $currentSecond = 0; // текущая секунда для троттлинга
    private $forcedUserId = null;

    public function __construct(array $config, StorageInterface $storage)
    {
        $this->config = $config;
        $this->storage = $storage;
    }

    // Получаем конфигурацию для текущего пользователя (с учётом возможных переопределений в БД)
    private function getUserConfig(): array
    {
        $userId = $this->forcedUserId ?? ($_SESSION['user_id'] ?? null);

        $user = null;
        if ($userId) {
            $user = $this->storage->getUser($userId);
        }

        return [
            'clientId'     => $user['client_id'] ?? $this->config['clientId'],
            'clientSecret' => $user['client_secret'] ?? $this->config['clientSecret'],
            'baseDomain'   => $user['base_domain'] ?? $this->config['baseDomain'],
            'redirectUri'  => $this->config['redirectUri'],
        ];
    }

    // Функция для троттлинга запросов (не более 7 запросов в секунду)
    private function throttle(): void
    {
        while (true) {
            $sec = time();

            if ($this->currentSecond !== $sec) {
                $this->currentSecond = $sec;
                $this->requestsInCurrentSecond = 0;
            }

            if ($this->requestsInCurrentSecond < 7) {
                $this->requestsInCurrentSecond++;
                break;
            }

            sleep(1);
        }
    }

    /**
     * Функция отправки HTTP-запроса с помощью cURL
     *
     * @param string $method - HTTP метод (GET, POST, PATCH, DELETE)
     * @param string $url - полный URL запроса
     * @param array $data - тело запроса (будет преобразовано в JSON)
     * @param array $headers - дополнительные заголовки для запроса
     * @param bool $withAuth добавлять ли Authorization
     * @param int $retry количество повторов при ошибках
     *
     * @return array декодированный JSON-ответ
     * @throws Exception при ошибке сети, HTTP или JSON
     */
    private function sendRequest(string $method, string $url, array $data = [], array $headers = [], bool $withAuth = true, int $retry = 1): array
    {
        $this->throttle();

        $defaultHeaders = ['Content-Type: application/json'];

        if ($withAuth) {
            $defaultHeaders[] = "Authorization: Bearer {$this->getAccessToken()}";
        }

        $originalHeaders = $headers;
        $headers = array_merge($defaultHeaders, $headers);

        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10
        ];

        if (!empty($data)) {
            $jsonData = json_encode($data);

            if ($jsonData === false) {
                log_error('JSON encode error: ' . json_last_error_msg(), ['data' => $data]);
                throw new Exception('JSON encode error: ' . json_last_error_msg());
            }

            $options[CURLOPT_POSTFIELDS] = $jsonData;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            log_error('Network error', [
                'error' => $error,
                'url' => $url
            ]);

            throw new Exception("Network error: $error");
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 204) {
            return [];
        }

        // автообновление токена при 401
        if ($http === 401 && $withAuth && $retry > 0) {
            try {
                $this->forceRefreshToken(); // Пробуем обновить токен
                return $this->sendRequest($method, $url, $data, $originalHeaders, true, $retry - 1);
            } catch (Exception $e) {
                // Если ошибка критическая
                if ($e->getCode() === 401 && strpos($e->getMessage(), 'AUTH_REQUIRED') !== false) {

                    log_error('Сессия истекла — требуется авторизация пользователя', [
                        'url' => $url,
                        'original_error' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null
                    ]);

                    throw $e;
                }

                log_error('Unauthorized after token refresh', [
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);

                throw new Exception('Требуется повторная авторизация', 401, $e);
            }
        }

        // retry при лимите или ошибке сервера
        if (($http === 429 || $http >= 500) && $retry > 0) {
            sleep(1);
            return $this->sendRequest($method, $url, $data, $originalHeaders, $withAuth, $retry - 1);
        }

        if ($http < 200 || $http >= 300) {
            logHttpError(
                'HTTP error',
                $http,
                $raw,
                ['url' => $url]
            );

            throw new HttpException($http, $raw);
        }

        if ($raw === '') {
            log_error('Empty response from server', [
                'http_code' => $http,
                'url' => $url,
                'method' => $method
            ]);
            throw new Exception('Empty response from server');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            log_error('Invalid JSON response', [
                'response' => substr($raw, 0, 300),
                'http_code' => $http,
                'json_error' => json_last_error_msg()
            ]);
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Проверяет, является ли ошибка критической для авторизации
     * (требует полной переавторизации, а не повтора)
     *
     * @param int $httpCode - HTTP-код ответа
     * @param $response - тело ответа от сервера
     * @return bool - true если ошибка критическая
     */
    private function isCriticalAuthError(int $httpCode, $response): bool
    {
        if ($httpCode !== 400) {
            return false;
        }

        $body = is_string($response) ? json_decode($response, true) ?? [] : $response;

        // Ключевые слова, указывающие на критические ошибки, требующие переавторизации
        $criticalPatterns = [
            'refresh_token',
            'Check the',
            'некорректный запрос',
            'параметры невалидны'
        ];

        $haystack = strtolower(json_encode($body, JSON_UNESCAPED_UNICODE));

        foreach ($criticalPatterns as $pattern) {
            if (strpos($haystack, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Функция проверки валидности ответа с токенами
     *
     * @param array $data - массив данных, полученный от сервера при запросе токенов
     * @return boolean - true, если ответ содержит все необходимые поля и они валидны, иначе false
     */
    private function isValidTokenResponse(array $data): bool
    {
        return
            isset($data['access_token']) &&
            isset($data['refresh_token']) &&
            isset($data['expires_in']) &&
            isset($data['token_type']) &&

            is_string($data['access_token']) &&
            is_string($data['refresh_token']) &&
            is_numeric($data['expires_in']) &&
            is_string($data['token_type']) &&

            $data['token_type'] === 'Bearer' &&
            (int)$data['expires_in'] > 0;
    }

    /**
     * Функция обмена кода авторизации на токены доступа
     *
     * @param string $code - код авторизации, полученный после успешной авторизации пользователя
     * @param integer $attempts - количество попыток при неудаче (по умолчанию 2)
     * @return array - массив с токенами доступа и другой информацией, полученной от сервера
     * @throws Exception - при некорректном ответе от сервера или превышении количества попыток
     */
    public function exchangeCodeForTokens(string $code, int $attempts = 2): array
    {
        $config = $this->getUserConfig();

        if (empty($config['clientId']) || empty($config['clientSecret'])) {
            throw new Exception('OAuth не настроен');
        }

        $url = "https://{$config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $config['clientId'],
            'client_secret' => $config['clientSecret'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $config['redirectUri']
        ];

        $response = $this->sendRequest('POST', $url, $payload, [], false);

        if (!$this->isValidTokenResponse($response)) {

            log_error('Invalid token response', [
                'attempts_left' => $attempts,
                'response' => $response
            ]);

            if ($attempts > 0) {
                sleep(1);
                return $this->exchangeCodeForTokens($code, $attempts - 1);
            }

            throw new Exception('Некорректный ответ OAuth при авторизации');
        }

        $response['createdAt'] = time();

        return $response;
    }

    /**
     * Функция для загрузки токенов из хранилища
     *
     * @return array - массив с токенами доступа, полученными из хранилища, или пустой массив, если токены не найдены или недействительны
     */
    public function loadTokens(): array
    {
        $userId = $this->forcedUserId ?? ($_SESSION['user_id'] ?? null);

        if (!$userId) {
            return [];
        }

        $config = $this->getUserConfig();

        $token = $this->storage->getToken(
            $userId,
            $config['clientId'],
            $config['baseDomain']
        );

        return $token ?: [];
    }

    /**
     * Функция проверки срока действия токена доступа
     *
     * @param array $tokens - массив с токенами доступа, который нужно проверить на истечение срока действия
     * @return boolean - true, если токен истёк или скоро истечёт (менее 60 секунд до истечения), иначе false
     */
    private function isTokenExpired(array $tokens): bool
    {
        if (empty($tokens['expires_at'])) {
            return true;
        }
        return time() >= ($tokens['expires_at'] - 60);
    }

    /**
     * Функция для сохранения токенов
     *
     * @param array $tokens - массив с токенами доступа, который нужно сохранить
     * @return void
     */
    public function saveTokens(array $tokens): void
    {
        $config = $this->getUserConfig();

        $created = $tokens['server_time'] ?? $tokens['createdAt'] ?? time();

        $this->storage->saveToken([
            'user_id' => $this->forcedUserId ?? ($_SESSION['user_id'] ?? null),
            'client_id'     => $config['clientId'],
            'base_domain'   => $config['baseDomain'],
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at'    => $created + $tokens['expires_in'],
        ]);
    }

    /**
     * Функция обновления токена доступа (запрос нового)
     *
     * @param array $tokens - массив с текущими токенами доступа, содержащий поле 'refresh_token' для обновления
     * @param integer $attempts - количество попыток при неудаче (по умолчанию 2)
     * @return array - массив с новыми токенами доступа, полученными от сервера
     * @throws Exception - при некорректном ответе от сервера или превышении количества попыток
     */
    public function refreshToken(array $tokens, int $attempts = 2): array
    {
        $config = $this->getUserConfig();

        $url = "https://{$config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $config['clientId'],
            'client_secret' => $config['clientSecret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'redirect_uri'  => $config['redirectUri']
        ];

        try {
            $response = $this->sendRequest('POST', $url, $payload, [], false);
        } catch (HttpException $e) {

            if ($this->isCriticalAuthError($e->getCode(), $e->getResponse())) {

                log_error('Критическая ошибка refresh_token — требуется переавторизация', [
                    'http_code' => $e->getCode(),
                    'response' => $e->getResponse(),
                    'domain' => $config['baseDomain']
                ]);

                $this->logout();

                throw new Exception('AUTH_REQUIRED: Требуется повторная авторизация', 401, $e);
            }

            throw $e;
        }

        if (!$this->isValidTokenResponse($response)) {

            log_error('Invalid refresh token response', [
                'attempts_left' => $attempts,
                'response' => $response
            ]);

            if ($attempts > 0) {
                sleep(1);
                return $this->refreshToken($tokens, $attempts - 1);
            }

            throw new Exception('Некорректный ответ OAuth при обновлении токена');
        }

        $response['createdAt'] = time();

        return $response;
    }

    /**
     * Функция получения валидных токенов (обновляет при необходимости)
     *
     * @return array - массив с валидными токенами доступа, обновлёнными при необходимости
     */
    private function getValidTokens(): array
    {
        $tokens = $this->loadTokens();

        if ($this->isTokenExpired($tokens)) {
            $tokens = $this->refreshToken($tokens);
            $this->saveTokens($tokens);
        }

        return $tokens;
    }

    /**
     * Функция получения актуального токена доступа
     *
     * @return string - валидный токен доступа для использования в API-запросах
     */
    public function getAccessToken(): string
    {
        return $this->getValidTokens()['access_token'];
    }

    /**
     * Функция для принудительного обновления токена доступа (без проверки срока действия)
     *
     * @return array - массив с новыми токенами доступа, полученными от сервера после принудительного обновления
     * @throws Exception - если обновление не удалось
     */
    public function forceRefreshToken(): array
    {
        $tokens = $this->loadTokens();

        if (empty($tokens['refresh_token'])) {
            throw new Exception('Нет refresh_token для обновления');
        }

        $tokens = $this->refreshToken($tokens);
        $this->saveTokens($tokens);

        return $tokens;
    }

    /**
     * Функция получения информации об аккаунте с помощью API
     *
     * @return array - массив с информацией об аккаунте, полученной от сервера
     */
    public function getAccountInfo(): array
    {
        $config = $this->getUserConfig();
        $url = "https://{$config['baseDomain']}/api/v4/account";

        return $this->sendRequest('GET', $url);
    }

    /**
     * Функция проверки, авторизован ли пользователь
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        $tokens = $this->loadTokens();

        if (empty($tokens['access_token'])) {
            return false;
        }

        if (empty($tokens['expires_at'])) {
            return false;
        }

        return $tokens['expires_at'] > time();
    }

    /**
     * Функция для рендеринга кнопки авторизации/выхода
     *
     * @return string - HTML-код кнопки авторизации/выхода, который можно вставить на страницу
     */
    public function renderAuthButton(): string
    {
        $isAuthorized = $this->isAuthorized();
        $config = $this->getUserConfig();
        $clientId = $config['clientId'];
        $state = $_SESSION['user_id'];

        ob_start();
        require __DIR__ . '/../views/auth_button.php';
        return ob_get_clean();
    }

    /**
     * Функция для удаления токенов при выходе из аккаунта
     *
     * @return void
     */
    public function logout(): void
    {
        $userId = $this->forcedUserId ?? ($_SESSION['user_id'] ?? null);

        if (!$userId) {
            return;
        }

        $config = $this->getUserConfig();

        $this->storage->clearToken(
            $userId,
            $config['clientId'],
            $config['baseDomain']
        );
    }

    /**
     * Функция получения пользовательских полей контактов
     *
     * @return array - массив с пользовательскими полями контактов, полученными от сервера
     */
    public function getContactFields(): array
    {
        $config = $this->getUserConfig();
        $domain = $config['baseDomain'];

        $url = "https://{$domain}/api/v4/contacts/custom_fields";

        $response = $this->sendRequest('GET', $url);

        return $response['_embedded']['custom_fields'] ?? [];
    }

    /**
     * Функция получения пользовательских полей сделок
     *
     * @return array - массив с пользовательскими полями сделок, полученными от сервера
     */
    public function getLeadFields(): array
    {
        $config = $this->getUserConfig();
        $domain = $config['baseDomain'];

        $url = "https://{$domain}/api/v4/leads/custom_fields";

        $response = $this->sendRequest('GET', $url);

        return $response['_embedded']['custom_fields'] ?? [];
    }

    /**
     * Функция получения списка контактов
     *
     * @param integer $limit - количество контактов для получения (по умолчанию 50)
     * @param integer $page - номер страницы для получения (по умолчанию 1)
     * @return array - массив с контактами, полученными от сервера, или пустой массив, если контактов нет
     */
    public function getContacts(int $limit = 50, int $page = 1): array
    {
        $config = $this->getUserConfig();
        $domain = $config['baseDomain'];

        $url = "https://{$domain}/api/v4/contacts?page={$page}&limit={$limit}";

        $response = $this->sendRequest('GET', $url);

        return $response['_embedded']['contacts'] ?? [];
    }

    /**
     * Загружает все контакты постранично.
     *
     * @param int $limit количество контактов на страницу (макс. 250)
     * @return array
     */
    public function getAllContacts(int $limit = 250): array
    {
        $allContacts = [];
        $page = 1;

        while (true) {
            $contacts = $this->getContacts($limit, $page);

            if (empty($contacts)) {
                break;
            }

            $allContacts = array_merge($allContacts, $contacts);

            if (count($contacts) < $limit) {
                break;
            }

            $page++;
        }

        return $allContacts;
    }

    /**
     * Поиск дубликатов контактов по телефону/email
     *
     * @param string $type phone|email
     * @return array
     */
    public function findDuplicateContacts(string $type = 'phone'): array
    {
        $fieldCode = strtolower($type) === 'email' ? 'EMAIL' : 'PHONE';

        return $this->findDuplicateContactsByFieldCode($fieldCode);
    }

    /**
     * Ищет дубликаты контактов по значению произвольного поля (field_code).
     *
     * @param string $fieldCode
     * @return array
     */
    public function findDuplicateContactsByFieldCode(string $fieldCode): array
    {
        $targetCode = strtoupper(trim($fieldCode));
        if ($targetCode === '') {
            return [];
        }

        $contacts = $this->getAllContacts();

        $map = [];
        $seenByContact = [];

        foreach ($contacts as $contact) {
            $contactId = (int)($contact['id'] ?? 0);
            $contactName = trim((string)($contact['name'] ?? '')) ?: 'Без имени';

            if ($contactId <= 0) {
                continue;
            }

            $customFields = $contact['custom_fields_values'] ?? [];
            if (!is_array($customFields)) {
                continue;
            }

            foreach ($customFields as $field) {
                $fieldCodeFromContact = strtoupper((string)($field['field_code'] ?? ''));
                if ($fieldCodeFromContact !== $targetCode) {
                    continue;
                }

                $values = $field['values'] ?? [];
                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $valueItem) {
                    $rawValue = trim((string)($valueItem['value'] ?? ''));
                    if ($rawValue === '') {
                        continue;
                    }

                    $normalizedValue = $this->normalizeDuplicateValue($rawValue, $targetCode);
                    if ($normalizedValue === '' || $normalizedValue === null) {
                        continue;
                    }

                    $dedupeKey = $contactId . '|' . $normalizedValue;
                    if (isset($seenByContact[$dedupeKey])) {
                        continue;
                    }

                    $seenByContact[$dedupeKey] = true;

                    if (!isset($map[$normalizedValue])) {
                        $map[$normalizedValue] = [];
                    }

                    $map[$normalizedValue][] = [
                        'id' => $contactId,
                        'name' => $contactName,
                        'raw_value' => $rawValue
                    ];
                }
            }
        }

        $duplicates = [];
        foreach ($map as $value => $items) {
            if (count($items) <= 1) {
                continue;
            }

            $duplicates[] = [
                'value' => $value,
                'contacts' => $items
            ];
        }

        usort($duplicates, function (array $a, array $b): int {
            return count($b['contacts']) <=> count($a['contacts']);
        });

        return $duplicates;
    }

    /**
     * Ищет дубликаты контактов по field_id пользовательского поля.
     *
     * @param int $fieldId
     * @return array
     */
    public function findDuplicateContactsByCustomFieldId(int $fieldId): array
    {
        if ($fieldId <= 0) {
            return [];
        }

        $contacts = $this->getAllContacts();

        $map = [];
        $seenByContact = [];

        foreach ($contacts as $contact) {
            $contactId = (int)($contact['id'] ?? 0);
            $contactName = trim((string)($contact['name'] ?? '')) ?: 'Без имени';

            if ($contactId <= 0) {
                continue;
            }

            $customFields = $contact['custom_fields_values'] ?? [];
            if (!is_array($customFields)) {
                continue;
            }

            foreach ($customFields as $field) {
                $fieldIdFromContact = (int)($field['field_id'] ?? 0);
                if ($fieldIdFromContact !== $fieldId) {
                    continue;
                }

                $values = $field['values'] ?? [];
                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $valueItem) {
                    $rawValue = trim((string)($valueItem['value'] ?? ''));
                    if ($rawValue === '') {
                        continue;
                    }

                    $normalizedValue = $this->normalizeDuplicateCustomValue($rawValue);
                    if ($normalizedValue === '' || $normalizedValue === null) {
                        continue;
                    }

                    $dedupeKey = $contactId . '|' . $normalizedValue;
                    if (isset($seenByContact[$dedupeKey])) {
                        continue;
                    }

                    $seenByContact[$dedupeKey] = true;

                    if (!isset($map[$normalizedValue])) {
                        $map[$normalizedValue] = [];
                    }

                    $map[$normalizedValue][] = [
                        'id' => $contactId,
                        'name' => $contactName,
                        'raw_value' => $rawValue
                    ];
                }
            }
        }

        $duplicates = [];
        foreach ($map as $value => $items) {
            if (count($items) <= 1) {
                continue;
            }

            $duplicates[] = [
                'value' => $value,
                'contacts' => $items
            ];
        }

        usort($duplicates, function (array $a, array $b): int {
            return count($b['contacts']) <=> count($a['contacts']);
        });

        return $duplicates;
    }

    /**
     * Нормализует значение для дедупликации.
     *
     * @param string $rawValue
     * @param string $targetCode
     * @return string
     */
    private function normalizeDuplicateValue(string $rawValue, string $targetCode): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return '';
        }

        if ($targetCode === 'EMAIL') {
            return strtolower($rawValue);
        }

        if ($targetCode === 'PHONE') {
            return preg_replace('/\D+/', '', $rawValue);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($rawValue, 'UTF-8');
        }

        return strtolower($rawValue);
    }

    /**
     * Нормализация для кастомных полей
     */
    private function normalizeDuplicateCustomValue(string $rawValue): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return '';
        }

        // Нормализуем пробелы
        $rawValue = preg_replace('/\s+/u', ' ', $rawValue);

        // Пытаемся привести к числу (numeric) если похоже на число
        $numCandidate = str_replace(' ', '', $rawValue);
        $numCandidate = str_replace(',', '.', $numCandidate);

        if (preg_match('/^-?\d+(\.\d+)?$/', $numCandidate)) {
            // убираем лишние нули после запятой
            if (strpos($numCandidate, '.') !== false) {
                $numCandidate = rtrim($numCandidate, '0');
                $numCandidate = rtrim($numCandidate, '.');
            }

            return strtolower($numCandidate);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($rawValue, 'UTF-8');
        }

        return strtolower($rawValue);
    }

    /**
     * Функция получения списка сделок
     *
     * @param integer $limit - количество сделок для получения (по умолчанию 50)
     * @param integer $page - номер страницы для получения (по умолчанию 1)
     * @return array - массив со сделками, полученными от сервера, или пустой массив, если сделок нет
     */
    public function getLeads(int $limit = 50, int $page = 1): array
    {
        $config = $this->getUserConfig();

        $domain = $config['baseDomain'];

        $url = "https://{$domain}/api/v4/leads?page={$page}&limit={$limit}";

        $response = $this->sendRequest('GET', $url);

        return $response['_embedded']['leads'] ?? [];
    }

    /**
     * Функция для добавления нового контакта
     *
     * @param array $contact - данные контакта для добавления
     * @param int $attempts - количество попыток исправления типов (по умолчанию 4)
     * @return array - ответ сервера с добавленным контактом
     */
    public function addContact(array $contact, int $attempts = 4): array
    {
        $config = $this->getUserConfig();

        return $this->addEntityWithTypeRetry(
            $contact,
            "https://{$config['baseDomain']}/api/v4/contacts",
            'contact',
            $attempts
        );
    }

    /**
     * Функция для добавления новой сделки
     *
     * @param array $lead - данные сделки для добавления
     * @param int $attempts - количество попыток исправления типов (по умолчанию 4)
     * @return array - ответ сервера с добавленной сделкой
     */
    public function addLead(array $lead, int $attempts = 4): array
    {
        $config = $this->getUserConfig();

        return $this->addEntityWithTypeRetry(
            $lead,
            "https://{$config['baseDomain']}/api/v4/leads",
            'lead',
            $attempts
        );
    }

    /**
     * Функция добавляет сущность с авто-повтором при ошибках валидации 400
     *
     * @param array $entityData - данные сущности для добавления (контакт, сделка и т.д.)
     * @param string $url - URL для добавления сущности (например, "https://{domain}/api/v4/contacts")
     * @param string $entityLabel - название сущности (например, "contact" или "lead")
     * @param int $maxRetries - максимальное количество попыток повтора
     * @return array - ответ сервера с добавленной сущностью
     * @throws Exception - если не удаётся добавить сущность после всех попыток
     */
    public function addEntityWithTypeRetry(
        array $entityData,
        string $url,
        string $entityLabel = 'entity',
        int $maxRetries = 4
    ): array {
        // если пришёл один объект, оборачиваем в массив
        $currentData = (isset($entityData[0]) && is_array($entityData[0]))
            ? $entityData
            : [$entityData];

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                return $this->sendRequest('POST', $url, $currentData);
            } catch (HttpException $e) {
                if ($e->getCode() !== 400) {
                    throw $e;
                }

                $response = $e->getResponse();
                if (is_string($response)) {
                    $response = json_decode($response, true) ?? [];
                }

                $rawErrors = $response['validation-errors']
                    ?? $response['_embedded']['validation-errors']
                    ?? [];

                if (empty($rawErrors)) {
                    throw $e;
                }

                $fixed = $this->fixEntityDataByErrors($currentData, $rawErrors);

                if ($fixed === false) {
                    log_error("Не удалось исправить {$entityLabel}", [
                        'errors' => $rawErrors,
                        'data' => $currentData
                    ]);
                    throw $e;
                }

                $currentData = $fixed;
                log_error("Попытка #" . ($attempt + 1) . " для {$entityLabel}: данные исправлены");
            }
        }

        throw new Exception("Превышено количество попыток ({$maxRetries}) для {$entityLabel}");
    }

    /**
     * Функция для исправления данных сущности на основе ошибок валидации, возвращаемых API
     *
     * @param array $entityData - массив с данными сущности, который нужно исправить
     * @param array $errors - массив с ошибками валидации, каждая ошибка содержит 'field' (путь к полю) и 'message' (текст ошибки)
     * @return array|false - исправленный массив данных сущности, если были внесены изменения, или false, если не удалось исправить
     */
    private function fixEntityDataByErrors(array $entityData, array $errors)
    {
        $wasFixed = false;

        foreach ($errors as $errorGroup) {

            if (!is_array($errorGroup)) {
                continue;
            }

            // request_id — индекс объекта в массиве
            $requestId = isset($errorGroup['request_id'])
                ? (int)$errorGroup['request_id']
                : null;

            if ($requestId === null || !isset($entityData[$requestId])) {
                continue;
            }

            $entity = &$entityData[$requestId];

            if (empty($errorGroup['errors']) || !is_array($errorGroup['errors'])) {
                unset($entity);
                continue;
            }

            foreach ($errorGroup['errors'] as $error) {

                if (!is_array($error)) {
                    continue;
                }

                $field = $error['path'] ?? $error['field'] ?? null;
                $message = $error['detail'] ?? $error['message'] ?? $error['error'] ?? null;

                if (!$field || !$message) {
                    continue;
                }

                // Парсинг пути: "a.0.b" или "a[0][b]" → ['a',0,'b']
                $path = array_map(
                    function ($p) {
                        return is_numeric($p) ? (int)$p : $p;
                    },
                    explode('.', str_replace(['][', '[', ']'], ['.', '.', ''], (string)$field))
                );

                if (empty($path)) {
                    continue;
                }

                // Пытаемся исправить значение
                $fixed = $this->applyFieldFix($entity, $path, strtolower($message));

                if ($fixed) {
                    $wasFixed = true;
                    continue;
                }

                // Если исправить не удалось — пробуем удалить custom field
                $fieldId = null;

                if (
                    isset($path[0], $path[1]) &&
                    $path[0] === 'custom_fields_values' &&
                    isset($entity['custom_fields_values'][$path[1]]['field_id'])
                ) {
                    $fieldId = (int)$entity['custom_fields_values'][$path[1]]['field_id'];
                }

                if ($this->removeCustomFieldByPath($entity, $path, $fieldId)) {

                    $wasFixed = true;

                    log_error('🗑️ Removed unfixable field', [
                        'path' => implode('.', $path),
                        'field_id' => $fieldId,
                        'reason' => 'auto-fix failed'
                    ]);
                }
            }

            unset($entity);
        }

        return $wasFixed ? $entityData : false;
    }

    /**
     * Применяет исправление к данным на основе сообщения об ошибке и пути к полю.
     *
     * @param array $data - данные сущности (контакт, сделка и т.д.), которые нужно исправить
     * @param array $path - массив сегментов пути к полю, которое нужно исправить (например, ['custom_fields_values', 0, 'values', 0, 'value'])
     * @param string $errorMessage - текст сообщения об ошибке, который может содержать подсказки о том, как исправить значение
     * @return bool - true, если было применено исправление, иначе false
     */
    private function applyFieldFix(array &$data, array $path, string $message): bool
    {
        if (empty($path)) {
            return false;
        }

        $current = &$data;
        $lastIndex = count($path) - 1;

        foreach ($path as $i => $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            if ($i === $lastIndex) {
                $value = $current[$segment];
                $fixed = false;

                // Проверка на наличие ключевых слов в сообщении об ошибке
                $contains = function (string $haystack, string $needle): bool {
                    return strpos($haystack, $needle) !== false;
                };

                // Целое число
                if ($contains($message, 'int') || $contains($message, 'integer')) {
                    if ($value !== '') {
                        $normalized = str_replace(',', '.', $value);
                        if (is_numeric($normalized)) {
                            $current[$segment] = (int) round((float)$normalized);
                        } else {
                            $current[$segment] = 0;
                        }
                    } else {
                        $current[$segment] = 0;
                    }
                    $fixed = true;
                }

                // Число (целое или с плавающей точкой)
                elseif ($contains($message, 'numeric')) {
                    $str = (string)$value;
                    $cleaned = preg_replace('/[^\d\-.,]/u', '', $str);
                    $cleaned = str_replace(',', '.', $cleaned);
                    $parts = explode('.', $cleaned);

                    if (count($parts) > 2) {
                        $cleaned = $parts[0] . '.' . implode('', array_slice($parts, 1));
                    }

                    if (is_numeric($cleaned) && $cleaned !== '') {
                        $current[$segment] = $cleaned + 0;
                        $fixed = true;
                    }
                }

                // Дата
                elseif ($contains($message, 'date') || $contains($message, 'y-m-d')) {
                    $formats = [
                        'd.m.Y H:i',
                        'd.m.Y H:i:s',
                        'd.m.Y'
                    ];
                    $tz = new DateTimeZone('Europe/Moscow');

                    foreach ($formats as $fmt) {
                        $dt = DateTimeImmutable::createFromFormat($fmt, (string)$value, $tz);

                        if ($dt !== false) {
                            if ($contains($message, 'h:i:s') || $contains($message, 't')) {
                                $newValue = $dt->format('Y-m-d\TH:i:sP');
                            } else {
                                $newValue = $dt->format('Y-m-d');
                            }

                            $current[$segment] = $newValue;
                            $fixed = true;
                        }
                    }
                }

                if ($fixed) {
                    log_error('Auto fix', [
                        'path' => implode('.', $path),
                        'old'  => $value,
                        'new'  => $current[$segment],
                        'rule' => $message
                    ]);
                    return true;
                }
                return false;
            }

            $current = &$current[$segment];
        }

        return false;
    }

    /**
     * Пытается удалить пользовательское поле по пути из ошибки, если исправить его не удалось.
     *
     * @param array $entity - данные сущности, из которой нужно удалить пользовательское поле
     * @param array $path - массив сегментов пути к полю, которое нужно удалить (например, ['custom_fields_values', 0])
     * @return boolean - true, если поле было успешно удалено, иначе false
     */
    private function removeCustomFieldByPath(array &$entity, array $path, ?int $targetFieldId = null): bool
    {
        // Должен начинаться с custom_fields_values
        if (!isset($path[0]) || $path[0] !== 'custom_fields_values') {
            return false;
        }

        // Если знаем field_id — ищем и удаляем по нему (точнее!)
        if ($targetFieldId !== null && !empty($entity['custom_fields_values'])) {
            foreach ($entity['custom_fields_values'] as $index => $field) {
                if (($field['field_id'] ?? null) === $targetFieldId) {
                    unset($entity['custom_fields_values'][$index]);
                    $entity['custom_fields_values'] = array_values($entity['custom_fields_values']);

                    log_error('Removed custom field by field_id', [
                        'field_id' => $targetFieldId,
                        'index' => $index
                    ]);
                    return true;
                }
            }
            return false;
        }

        // Фоллбэк: удаляем по индексу (старая логика)
        $index = $path[1] ?? null;
        if (!is_int($index) || !isset($entity['custom_fields_values'][$index])) {
            return false;
        }

        unset($entity['custom_fields_values'][$index]);
        $entity['custom_fields_values'] = array_values($entity['custom_fields_values']);

        log_error('Removed custom field by index', [
            'index' => $index
        ]);
        return true;
    }

    public function setUserContext(string $userId): void
    {
        $this->forcedUserId = trim($userId);
    }

    /**
     * Добавление заметок
     *
     * @param integer $contactId - ID контакта, к которому нужно добавить заметку
     * @param string $text - текст заметки
     * @return array - массив с ответом от сервера после добавления заметки
     */
    public function addContactNote(int $contactId, string $text): array
    {
        $config = $this->getUserConfig();

        $url = "https://{$config['baseDomain']}/api/v4/contacts/notes";

        return $this->sendRequest('POST', $url, [[
            'entity_id' => $contactId,
            'note_type' => 'common',
            'params' => [
                'text' => $text
            ]
        ]]);
    }

    public function findDuplicatesForNewContact(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }

        $contacts = $this->getAllContacts();

        $newContact = null;

        foreach ($contacts as $contact) {
            if ((int)($contact['id'] ?? 0) === $contactId) {
                $newContact = $contact;
                break;
            }
        }

        if (!$newContact) {
            return [];
        }

        $duplicates = [];

        foreach (($newContact['custom_fields_values'] ?? []) as $field) {

            $fieldId = (int)($field['field_id'] ?? 0);
            $fieldName = trim((string)($field['field_name'] ?? 'Поле #' . $fieldId));

            if ($fieldId <= 0) {
                continue;
            }

            foreach (($field['values'] ?? []) as $valueItem) {

                $rawValue = trim((string)($valueItem['value'] ?? ''));

                if ($rawValue === '') {
                    continue;
                }

                $normalized = $this->normalizeDuplicateCustomValue($rawValue);

                if ($normalized === '') {
                    continue;
                }

                foreach ($contacts as $contact) {

                    $otherId = (int)($contact['id'] ?? 0);

                    if ($otherId <= 0 || $otherId === $contactId) {
                        continue;
                    }

                    foreach (($contact['custom_fields_values'] ?? []) as $otherField) {

                        if ((int)($otherField['field_id'] ?? 0) !== $fieldId) {
                            continue;
                        }

                        foreach (($otherField['values'] ?? []) as $otherValueItem) {

                            $otherRaw = trim((string)($otherValueItem['value'] ?? ''));

                            if ($otherRaw === '') {
                                continue;
                            }

                            $otherNormalized = $this->normalizeDuplicateCustomValue($otherRaw);

                            if ($otherNormalized === $normalized) {

                                $duplicates[] = [
                                    'field_id' => $fieldId,
                                    'field_name' => $fieldName,
                                    'field_value' => $rawValue,
                                    'id' => $otherId,
                                    'name' => $contact['name'] ?? 'Без имени',
                                ];

                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return $duplicates;
    }
}
