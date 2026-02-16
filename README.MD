# Пример авторизации AmoCRM

## Перед запуском:

- Переименовать example.env в .env
- Проверить параметры в .env

## Запуск через докер:

```
docker build -t my-php-app .  
docker run -p 8000:8000 -v ${PWD}:/app my-php-app
```

Перейти на:

http://localhost:8000/
