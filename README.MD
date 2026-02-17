# Пример авторизации AmoCRM

## Перед запуском:

- Переименовать example.env в .env
- Проверить параметры в .env

## Запуск через докер:

```
docker build -t amocrm-oauth-php . 
docker run -p 8000:8000 -v ${PWD}:/app amocrm-oauth-php
```

Перейти на:

http://localhost:8000/
