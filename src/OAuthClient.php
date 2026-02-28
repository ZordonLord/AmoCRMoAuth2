<?php

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
     * Функция отправки HTTP-запросов с помощью cURL
     *
     * @param string $method - HTTP-метод (GET, POST и т.д.)
     * @param string $url - URL для запроса
     * @param array $data - данные для отправки
     * @param array $headers - дополнительные заголовки для запроса
     * @param integer $retry - количество попыток при неудаче (по умолчанию 1)
     * @return array - декодированный JSON-ответ от сервера
     * @throws Exception - при сетевых ошибках, HTTP-ошибках или некорректных ответах
     */
    private function sendRequest(string $method, string $url, array $data = [], array $headers = [], int $retry = 1): array
    {
        $this->throttle();

        $ch = curl_init($url);

        $defaultHeaders = ['Content-Type: application/json'];
        $headers = array_merge($defaultHeaders, $headers);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10
        ];

        if (!empty($data)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            log_error('Network error', ['error' => $error]);
            throw new Exception("Network error: $error");
        }

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // retry при лимите или ошибке сервера
        if (($http === 429 || $http >= 500) && $retry > 0) {
            sleep(1);
            return $this->sendRequest($method, $url, $data, $headers, $retry - 1);
        }

        if ($http !== 200) {
            log_error('HTTP error', [
                'status' => $http,
                'response' => $raw,
                'url' => $url
            ]);
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

        $response = $this->sendRequest('POST', $url, $payload);

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

        $response = $this->sendRequest('POST', $url, $payload);

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
        $accessToken = $this->getAccessToken();
        $url = "https://{$this->config['baseDomain']}/api/v4/account";

        return $this->sendRequest('GET', $url, [], ["Authorization: Bearer {$accessToken}"]);
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
        $token = $this->getAccessToken();

        $url = "https://{$domain}/api/v4/contacts/custom_fields";

        $response = $this->sendRequest('GET', $url, [], ["Authorization: Bearer {$token}"]);

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
        $token = $this->getAccessToken();

        $url = "https://{$domain}/api/v4/leads/custom_fields";

        $response = $this->sendRequest('GET', $url, [], ["Authorization: Bearer {$token}"]);

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
        $token  = $this->getAccessToken();

        $url = "https://{$domain}/api/v4/contacts?page={$page}&limit={$limit}";

        $response = $this->sendRequest('GET', $url, [], ["Authorization: Bearer {$token}"]);

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
        $token  = $this->getAccessToken();

        $url = "https://{$domain}/api/v4/leads?page={$page}&limit={$limit}";

        $response = $this->sendRequest('GET', $url, [], ["Authorization: Bearer {$token}"]);

        return $response['_embedded']['leads'] ?? [];
    }

    /**
     * Функция для добавления нового контакта
     *
     * @param array $contact - массив с данными нового контакта, который нужно добавить
     * @return array - массив с данными добавленного контакта, полученными от сервера, или пустой массив при ошибке
     */
    public function addContact(array $contact): array
    {
        $domain = $this->config['baseDomain'];
        $token  = $this->getAccessToken();

        $url = "https://{$domain}/api/v4/contacts";

        return $this->sendRequest('POST', $url, [$contact], ["Authorization: Bearer {$token}"]);
    }
}
