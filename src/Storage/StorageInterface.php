<?php

// Интерфейс для абстрактного хранилища токенов и кэша
interface StorageInterface
{
    public function saveToken(array $tokenData): void;
    public function getToken(): ?array;
    public function saveCache(string $key, array $data, int $ttl): void;
    public function getCache(string $key): ?array;
    public function clearToken(): void;
}