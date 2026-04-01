<?php

// Функция загрузки параметров из .env файла
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}