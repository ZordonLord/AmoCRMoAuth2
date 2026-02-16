<?php

class OAuthClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // Функция обмена кода авторизации на токены доступа
    public function exchangeCodeForTokens(string $code): array
    {
        $url = "https://{$this->config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $this->config['clientId'],
            'client_secret' => $this->config['clientSecret'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirectUri']
        ];

        $response = $this->sendRequest($url, $payload);

        $response['createdAt'] = time();

        return $response;
    }

    // Функция отправки POST-запроса и обработки ответа
    private function sendRequest(string $url, array $data): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($http !== 200) {
            throw new Exception("OAuth error: $raw");
        }

        return json_decode($raw, true);
    }

    // Функция обновления токена доступа
    public function refreshToken(array $tokens): array
    {
        $url = "https://{$this->config['baseDomain']}/oauth2/access_token";

        $payload = [
            'client_id'     => $this->config['clientId'],
            'client_secret' => $this->config['clientSecret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'redirect_uri'  => $this->config['redirectUri']
        ];

        $response = $this->sendRequest($url, $payload);

        $response['createdAt'] = time();

        file_put_contents(__DIR__ . '/storage/tokens.json', json_encode($response, JSON_PRETTY_PRINT));

        return $response;
    }

    // Функция получения актуального токена доступа (с проверкой срока действия)
    public function getAccessToken(): string
    {
        $file = __DIR__ . '/storage/tokens.json';
        if (!file_exists($file)) {
            throw new Exception("Токены не найдены, нужна авторизация");
        }

        $tokens = json_decode(file_get_contents($file), true);

        if (time() > ($tokens['createdAt'] + $tokens['expires_in'] - 300)) {
            $tokens = $this->refreshToken($tokens);
        }

        return $tokens['access_token'];
    }

    // Функция загрузки токенов из файла и проверки их актуальности
    private function loadTokens(): array
    {
        $file = __DIR__ . '/storage/tokens.json';

        if (!file_exists($file)) {
            throw new Exception("Токены не найдены. Авторизуйтесь.");
        }

        return json_decode(file_get_contents($file), true);
    }

    // Функция проверки срока действия токена
    private function isTokenExpired(array $tokens): bool
    {
        return time() >= ($tokens['createdAt'] + $tokens['expires_in'] - 60);
    }

    // Функция получения валидных токенов (обновляет при необходимости)
    private function getValidTokens(): array
    {
        $tokens = $this->loadTokens();

        if ($this->isTokenExpired($tokens)) {
            $tokens = $this->refreshToken($tokens);

            file_put_contents(
                __DIR__ . '/storage/tokens.json',
                json_encode($tokens, JSON_PRETTY_PRINT)
            );
        }

        return $tokens;
    }

    // Функция получения информации об аккаунте с помощью API и токена доступа
    public function getAccountInfo(): array
    {
        $tokens = $this->getValidTokens();

        $accessToken = $tokens['access_token'];
        $apiDomain = $this->config['baseDomain'];

        $url = "https://{$apiDomain}/api/v4/account";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ]
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Ошибка API: $raw");
        }

        return json_decode($raw, true);
    }
}
