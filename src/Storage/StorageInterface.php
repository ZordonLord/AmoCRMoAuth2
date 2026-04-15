<?php

// Интерфейс для абстрактного хранилища токенов и кэша
interface StorageInterface
{
    public function saveToken(array $tokenData): void;
    public function getToken(string $userId, string $clientId, string $baseDomain): ?array;
    public function clearToken(string $userId, string $clientId, string $baseDomain): void;
    
    public function saveUser(array $userData): void;
    public function getUser(string $id): ?array;
    public function getUserByBaseDomain(string $baseDomain): ?array;

    public function saveCache(string $key, array $data, int $ttl, ?string $userId = null): void;
    public function getCache(string $key): ?array;
    public function clearUserCache(?string $userId): void;
}