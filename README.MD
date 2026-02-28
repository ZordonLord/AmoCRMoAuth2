# Пример авторизации AmoCRM (PHP)

Небольшой пример для организации OAuth2 авторизации с amoCRM и хранения токенов в файле.

**Структура**: проект содержит клиент `OAuthClient` для обмена/обновления токенов.

## Быстрый старт

1. Скопируйте `example.env` в `.env` и заполните значения:

```env
AMO_CLIENT_ID=your_client_id
AMO_CLIENT_SECRET=your_client_secret
AMO_REDIRECT_URI=https://your.domain/callback.php
AMO_BASE_DOMAIN=your-amo-domain.amocrm.ru
```

2. Запустите приложение (через Docker или локально):

Через Docker:

```bash
docker build -t amocrm-oauth-php .
docker run -p 8000:8000 -v ${PWD}:/app amocrm-oauth-php
```

Локально (PHP встроенный сервер):

```bash
php -S 0.0.0.0:8000 -t public
```

Откройте в браузере: http://localhost:8000

## Конфигурация

Основной конфиг в `config/config.php`. Параметры берутся из `.env`.

- `clientId`, `clientSecret`, `redirectUri`, `baseDomain` — обязательны для OAuth.
- `tokenStorage` — путь к файлу, где сохраняются токены. По умолчанию:

```php
'tokenStorage' => __DIR__ . '/../storage/tokens.json',
```
