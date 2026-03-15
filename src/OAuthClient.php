<?php
require_once __DIR__ . '/HttpException.php';

class OAuthClient
{
    private array $config;
    private string $tokenFile;
    private int $requestsInCurrentSecond = 0; // счётчик запросов для троттлинга
    private int $currentSecond = 0; // текущая секунда для троттлинга

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->tokenFile = $config['tokenStorage'];
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
                if ($e->getCode() === 401 && str_contains($e->getMessage(), 'AUTH_REQUIRED')) {

                    log_error('🔐 Сессия истекла — требуется авторизация пользователя', [
                        'url' => $url,
                        'original_error' => $e->getPrevious()?->getMessage()
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
     * @param array|string $response - тело ответа от сервера
     * @return bool - true если ошибка критическая
     */
    private function isCriticalAuthError(int $httpCode, array|string $response): bool
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
            if (str_contains($haystack, strtolower($pattern))) {
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
        $url = "https://{$this->config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $this->config['clientId'],
            'client_secret' => $this->config['clientSecret'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirectUri']
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
     * Функция загрузки токенов из файла
     *
     * @return array - массив с токенами доступа, загруженными из файла, или пустой массив, если файл не существует или содержит некорректные данные
     */
    public function loadTokens(): array
    {
        if (!file_exists($this->tokenFile)) {
            return [];
        }

        $content = file_get_contents($this->tokenFile);

        if (!$content) {
            return [];
        }

        $tokens = json_decode($content, true);

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Функция проверки срока действия токена доступа
     *
     * @param array $tokens - массив с токенами доступа, содержащий поле 'createdAt' (время создания) и 'expires_in' (время жизни в секундах)
     * @return boolean - true, если токен истёк или скоро истечёт (менее 60 секунд до истечения), иначе false
     */
    private function isTokenExpired(array $tokens): bool
    {
        if (empty($tokens['createdAt']) || empty($tokens['expires_in'])) {
            return true;
        }

        return time() >= ($tokens['createdAt'] + $tokens['expires_in'] - 60);
    }

    /**
     * Функция для сохранения токенов
     *
     * @param array $tokens - массив с токенами доступа, который нужно сохранить
     * @return void
     */
    public function saveTokens(array $tokens): void
    {
        file_put_contents(
            $this->tokenFile,
            json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
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
        $url = "https://{$this->config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $this->config['clientId'],
            'client_secret' => $this->config['clientSecret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'redirect_uri'  => $this->config['redirectUri']
        ];

        try {
            $response = $this->sendRequest('POST', $url, $payload, [], false);
        } catch (HttpException $e) {

            if ($this->isCriticalAuthError($e->getCode(), $e->getResponse())) {

                log_error('Критическая ошибка refresh_token — требуется переавторизация', [
                    'http_code' => $e->getCode(),
                    'response' => $e->getResponse(),
                    'domain' => $this->config['baseDomain']
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
        $url = "https://{$this->config['baseDomain']}/api/v4/account";

        return $this->sendRequest('GET', $url);
    }

    /**
     * Функция проверки, авторизован ли пользователь (есть ли валидные токены доступа)
     *
     * @return boolean - true, если пользователь авторизован и токены доступа валидны, иначе false
     */
    public function isAuthorized(): bool
    {
        $tokens = $this->loadTokens();

        if (empty($tokens['access_token'])) {
            return false;
        }

        if (!empty($tokens['createdAt']) && !empty($tokens['expires_in'])) {
            $expires = $tokens['createdAt'] + $tokens['expires_in'];

            if ($expires <= time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Функция для рендеринга кнопки авторизации/выхода
     *
     * @return string - HTML-код кнопки авторизации/выхода, который можно вставить на страницу
     */
    public function renderAuthButton(): string
    {
        $isAuthorized = $this->isAuthorized();
        $clientId = $this->config['clientId'];

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
        if (file_exists($this->tokenFile)) {
            unlink($this->tokenFile);
        }
    }

    /**
     * Функция получения пользовательских полей контактов
     *
     * @return array - массив с пользовательскими полями контактов, полученными от сервера
     */
    public function getContactFields(): array
    {
        $domain = $this->config['baseDomain'];

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
        $domain = $this->config['baseDomain'];

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
        $domain = $this->config['baseDomain'];

        $url = "https://{$domain}/api/v4/contacts?page={$page}&limit={$limit}";

        $response = $this->sendRequest('GET', $url);

        return $response['_embedded']['contacts'] ?? [];
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
        $domain = $this->config['baseDomain'];

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
        return $this->addEntityWithTypeRetry(
            $contact,
            "https://{$this->config['baseDomain']}/api/v4/contacts",
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
        return $this->addEntityWithTypeRetry(
            $lead,
            "https://{$this->config['baseDomain']}/api/v4/leads",
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
    private function fixEntityDataByErrors(array $entityData, array $errors): array|false
    {
        $wasFixed = false;

        foreach ($entityData as &$entity) {

            // 🔹 Вспомогательная функция для обработки одной ошибки (инлайн)
            $processError = function ($error) use (&$entity, &$wasFixed) {
                $field = $error['path'] ?? $error['field'] ?? null;
                $message = $error['detail'] ?? $error['message'] ?? $error['error'] ?? null;

                if (!$field || !$message) {
                    return;
                }

                // Парсинг пути: "a.0.b" или "a[0][b]" → ['a', 0, 'b']
                $path = array_map(
                    fn($p) => is_numeric($p) ? (int)$p : $p,
                    explode('.', str_replace(['][', '[', ']'], ['.', '.', ''], (string)$field))
                );

                if (!empty($path) && $this->applyFieldFix($entity, $path, strtolower($message))) {
                    $wasFixed = true;
                }
            };

            // 🔹 Уровень 1: прямые ошибки
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                // Обрабатываем ошибку, если в ней есть path/field + message
                $processError($error);

                // 🔹 Уровень 2: вложенные ошибки в ключе 'errors' (структура amoCRM)
                if (!empty($error['errors']) && is_array($error['errors'])) {
                    foreach ($error['errors'] as $nested) {
                        if (is_array($nested)) {
                            $processError($nested);
                        }
                    }
                }
            }
        }

        unset($entity); // сброс ссылки после цикла
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
                    $cleaned = preg_replace('/[\s\x{00A0}\$€₽£,]/u', '', (string)$value);
                    $current[$segment] = (is_numeric($cleaned) && $cleaned !== '') ? $cleaned + 0 : 0;
                    $fixed = true;
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
}
