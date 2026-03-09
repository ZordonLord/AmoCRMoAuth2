<?php
// Функция для логирования ошибок в файл storage/error.log
function log_error(string $message, array $context = []): void
{
    $file = __DIR__ . '/../storage/error.log';

    $time = date('Y-m-d H:i:s');

    if (!empty($context)) {
        $message .= ' | ' . json_encode(
            $context,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    $line = "[$time] $message" . PHP_EOL;

    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function logHttpError(
    string $message,
    int $httpCode,
    array|string|null $responseBody = null,
    array $extraContext = []
): void {

    $context = [
        'http_code' => $httpCode,
    ];

    if (is_string($responseBody)) {

        $decoded = json_decode($responseBody, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $context['response'] = $decoded;
        } else {
            $context['response_raw'] = $responseBody;
        }
    } elseif (is_array($responseBody)) {
        $context['response'] = $responseBody;
    }

    if (!empty($extraContext)) {
        $context = array_merge($context, $extraContext);
    }

    log_error($message, $context);
}
