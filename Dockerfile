FROM php:8.3-cli

WORKDIR /app

COPY . .

RUN mkdir -p storage && chmod -R 777 storage

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000"]