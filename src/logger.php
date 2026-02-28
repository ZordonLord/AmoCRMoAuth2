<?php
// Функция для логирования ошибок в файл storage/error.log
function log_error(string $message, array $context = []): void
{
    $file = __DIR__ . '/../storage/error.log';

    $time = date('Y-m-d H:i:s');

    if (!empty($context)) {
        $message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    $line = "[$time] $message" . PHP_EOL;

    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

