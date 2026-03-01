<?php

class OAuthClient
{
    private array $config;
    private string $tokenFile;
    private int $requestsInCurrentSecond = 0; // счётчик запросов для троттлинга
    private int $currentSecond = 0; // текущая секунда для троттлинга
    private array $lastErrorResponse = []; // для хранения последнего ответа с ошибкой
    private ?array $cachedContactFields = null; // кэш метаданных полей контактов
    private ?array $cachedLeadFields = null; // кэш метаданных полей сделок

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
     * Функция сохранения последнего ответа с ошибкой для анализа в случае исключений
     *
     * @param string $raw - сырой ответ от сервера, который вызвал ошибку (обычно JSON с описанием ошибки)
     * @return void
     */
    private function setLastErrorResponse(string $raw): void
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->lastErrorResponse = $decoded;
        }
    }

    /**
     * Функция получения последнего ответа с ошибкой для анализа
     *
     * @return array - массив с данными последнего ответа с ошибкой, который может содержать информацию о причинах ошибки (например, validation-errors)
     */
    public function getLastErrorResponse(): array
    {
        return $this->lastErrorResponse;
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

        // автообновление токена при 401
        if ($http === 401 && $withAuth && $retry > 0) {
            try {
                $this->forceRefreshToken(); // пробуем обновить
                return $this->sendRequest($method, $url, $data, $originalHeaders, true, $retry - 1);
            } catch (Exception $e) {
                // если refresh не удался, удаляем токены и просим авторизоваться заново
                $this->logout();
                log_error('Unauthorized after token refresh', [
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);
                throw new Exception('Требуется повторная авторизация');
            }
        }

        // retry при лимите или ошибке сервера
        if (($http === 429 || $http >= 500) && $retry > 0) {
            sleep(1);
            return $this->sendRequest($method, $url, $data, $originalHeaders, $withAuth, $retry - 1);
        }

        if ($http !== 200) {
            log_error('HTTP error', [
                'status' => $http,
                'response' => $raw,
                'url' => $url
            ]);
            $this->setLastErrorResponse($raw);
            throw new Exception("HTTP error ($http)");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            log_error('Invalid JSON response', ['response' => $raw]);
            throw new Exception('Invalid JSON response');
        }

        return $decoded;
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
     * @return array - массив с токенами доступа, загруженными из файла
     * @throws Exception - при отсутствии файла
     */
    private function loadTokens(): array
    {
        if (!file_exists($this->tokenFile)) {
            throw new Exception("Токены не найдены. Авторизуйтесь.");
        }

        return json_decode(file_get_contents($this->tokenFile), true);
    }

    /**
     * Функция проверки срока действия токена доступа
     *
     * @param array $tokens - массив с токенами доступа, содержащий поле 'createdAt' (время создания) и 'expires_in' (время жизни в секундах)
     * @return boolean - true, если токен истёк или скоро истечёт (менее 60 секунд до истечения), иначе false
     */
    private function isTokenExpired(array $tokens): bool
    {
        return time() >= ($tokens['createdAt'] + $tokens['expires_in'] - 60);
    }

    /**
     * Функция для сохранения токенов
     *
     * @param array $tokens - массив с токенами доступа, который нужно сохранить
     * @return void
     */
    function saveTokens(array $tokens): void
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

        $response = $this->sendRequest('POST', $url, $payload, [], false);

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
     */
    public function forceRefreshToken(): array
    {
        $tokens = $this->loadTokens();
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
     * Функция проверки, авторизован ли пользователь (наличие и валидность токенов)
     *
     * @return boolean - true, если пользователь авторизован (токены есть), иначе false
     */
    public function isAuthorized(): bool
    {
        try {
            $this->loadTokens();
            return true;
        } catch (Exception $e) {
            return false;
        }
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
     * Функция получения метаданных полей контактов с кэшированием (для retry при ошибках типов)
     */
    private function getCachedContactFields(): array
    {
        if ($this->cachedContactFields === null) {
            $this->cachedContactFields = $this->getContactFields();
        }
        return $this->cachedContactFields;
    }

    /**
     * Функция получения метаданных полей сделок с кэшированием (для retry при ошибках типов)
     */
    private function getCachedLeadFields(): array
    {
        if ($this->cachedLeadFields === null) {
            $this->cachedLeadFields = $this->getLeadFields();
        }
        return $this->cachedLeadFields;
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
     * Функция для добавления нового контакта с автоматическим исправлением типов полей
     *
     * @param array $contact - данные контакта для добавления
     * @param int $attempts - количество попыток исправления типов (по умолчанию 3)
     *
     * @return array - ответ сервера с добавленным контактом
     * @throws Exception - если контакт не удаётся добавить
     */
    public function addContact(array $contact, int $attempts = 3): array
    {
        return $this->addEntityWithTypeRetry(
            $contact,
            "https://{$this->config['baseDomain']}/api/v4/contacts",
            'contact',
            'contacts',
            $attempts
        );
    }

    /**
     * Функция для добавления новой сделки с автоматическим исправлением типов полей
     *
     * @param array $lead - данные сделки для добавления
     * @param int $attempts - количество попыток исправления типов (по умолчанию 3)
     *
     * @return array - ответ сервера с добавленной сделкой
     * @throws Exception - если сделку не удаётся добавить
     */
    public function addLead(array $lead, int $attempts = 3): array
    {
        return $this->addEntityWithTypeRetry(
            $lead,
            "https://{$this->config['baseDomain']}/api/v4/leads",
            'lead',
            'leads',
            $attempts
        );
    }

    /**
     * Добавление сущности (контакт/сделка) с retry при ошибках типов полей
     */
    private function addEntityWithTypeRetry(array $entity, string $url, string $entityName, string $embeddedKey, int $attempts): array
    {
        $attemptNum = 4 - $attempts;

        try {
            $response = $this->sendRequest('POST', $url, [$entity]);

            if ($attemptNum > 1) {
                log_error("{$entityName} успешно добавлен при попытке {$attemptNum}", [
                    'id' => $response['_embedded'][$embeddedKey][0]['id'] ?? null
                ]);
            }

            return $response;
        } catch (Exception $e) {
            $error = $this->getLastErrorResponse();

            if ($attempts > 0 && $this->hasTypeValidationError($error)) {
                try {
                    $fieldsMeta = $entityName === 'contact' ? $this->getCachedContactFields() : $this->getCachedLeadFields();
                } catch (Exception $metaEx) {
                    log_error('Не удалось получить метаданные полей для исправления типов', ['error' => $metaEx->getMessage()]);
                    throw $e;
                }

                log_error("Ошибка проверки типа при попытке {$attemptNum}, пробуем исправить...", [
                    'attempts_left' => $attempts,
                    'error_details' => $error['validation-errors'] ?? []
                ]);

                $fixed = $this->normalizeCustomFields($entity, $fieldsMeta, $entityName);
                return $this->addEntityWithTypeRetry($fixed, $url, $entityName, $embeddedKey, $attempts - 1);
            }

            $logData = [
                'error' => $e->getMessage(),
                'validation_errors' => $error['validation-errors'] ?? null
            ];
            if (empty($error['validation-errors']) && !empty($error)) {
                $logData['full_error_response'] = $error;
            }
            log_error("Не удалось добавить {$entityName} после {$attemptNum} попыток", $logData);

            throw $e;
        }
    }

    // Коды ошибок валидации типов полей, которые можно исправить автоматически
    private const TYPE_VALIDATION_CODES = ['InvalidType', 'BadValue', 'InvalidValueList', 'JsonInvalidValue', 'InvalidDateFormat'];

    /**
     * Функция проверяет, содержит ли ответ ошибки валидации типов полей (исправляемые автоматически)
     *
     * @param array $error - массив с данными ошибки, полученный от сервера при неудачной попытке добавить контакт/сделку
     * @return boolean
     */
    private function hasTypeValidationError(array $error): bool
    {
        if (empty($error['validation-errors'])) {
            return false;
        }

        foreach ($error['validation-errors'] as $block) {
            foreach ($block['errors'] ?? [] as $e) {
                if (isset($e['code']) && in_array($e['code'], self::TYPE_VALIDATION_CODES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Функция приводит значения custom_fields_values к типам из метаданных
     *
     * @param array $entity - массив с данными контакта или сделки, который нужно исправить (должен содержать ключ 'custom_fields_values' с массивом полей)
     * @param array $fieldsMeta - массив с метаданными полей (должен содержать массив полей с ключами 'id' и 'type')
     * @param string $logPrefix - префикс для логов (например, 'contact' или 'lead'), чтобы было понятно, к какому типу сущности относится лог
     * @return array - массив с данными контакта или сделки, в котором значения в 'custom_fields_values' приведены к типам из метаданных, если это было необходимо
     */
    private function normalizeCustomFields(array $entity, array $fieldsMeta, string $logPrefix = 'entity'): array
    {
        if (empty($entity['custom_fields_values'])) {
            return $entity;
        }

        $types = [];
        foreach ($fieldsMeta as $field) {
            $types[$field['id']] = $field['type'];
        }

        $fixedCount = 0;
        foreach ($entity['custom_fields_values'] as &$field) {
            $type = $types[$field['field_id']] ?? 'text';
            foreach ($field['values'] as &$value) {
                $original = $value['value'];
                $value['value'] = $this->castValueByType($original, $type);
                if ($value['value'] !== $original) {
                    $fixedCount++;
                }
            }
        }

        if ($fixedCount > 0) {
            log_error("Исправлено {$fixedCount} полей типа {$logPrefix}", []);
        }

        return $entity;
    }

    /**
     * Функция для приведения значения к нужному типу в зависимости от типа поля в AmoCRM
     *
     * @param [type] $value - исходное значение, которое нужно привести к нужному типу (может быть строкой, числом, массивом и т.д., в зависимости от того, что пришло в запросе на добавление контакта/сделки)
     * @param string $type - тип поля в AmoCRM (например, 'numeric', 'checkbox', 'date', 'select' и т.д.), который определяет, к какому типу нужно привести значение
     */
    private function castValueByType($value, string $type)
    {
        // Если значение пустое, возвращаем типизированное значение по умолчанию
        if ($value === null || $value === '') {
            switch ($type) {
                case 'numeric':
                case 'price':
                case 'checkbox':
                case 'select':
                case 'radiobutton':
                    return 0;
                case 'multiselect':
                    return [];
                case 'date':
                case 'date_time':
                    return (new \DateTime())->format('Y-m-d\TH:i:sP');
                default:
                    return '';
            }
        }
        // Приводим значение к нужному типу в зависимости от типа поля
        switch ($type) {

            case 'numeric':
            case 'price':
                if (is_numeric($value)) {
                    return floatval($value);
                }
                preg_match('/-?\d+[\.\,]?\d*/', (string)$value, $matches);
                return !empty($matches) ? (float)str_replace(',', '.', $matches[0]) : 0;

            case 'checkbox':
                if (is_bool($value)) return $value;
                if (is_numeric($value)) return (int)$value !== 0;
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'date':
            case 'date_time':
                try {
                    if (is_numeric($value)) {
                        $dt = (new \DateTime())->setTimestamp((int)$value);
                    } else {
                        $dt = new \DateTime((string)$value);
                    }
                    return $dt->format('Y-m-d\TH:i:sP');
                } catch (\Exception $e) {
                    return (new \DateTime())->format('Y-m-d\TH:i:sP');
                }

            case 'select':
            case 'radiobutton':
                return (int)$value;

            case 'multiselect':
                if (is_array($value)) {
                    return array_map(fn($v) => (int)$v, $value);
                }
                return [(int)$value];

            case 'text':
            case 'textarea':
            case 'url':
            default:
                return (string)$value;
        }
    }
}
