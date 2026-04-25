<?php

// Интерфейс для абстрактного хранилища токенов и кэша
interface StorageInterface
{
    // =========================
    // Токены и данные авторизации
    // =========================
    public function saveToken(array $tokenData): void;
    public function getToken(string $userId, string $clientId, string $baseDomain): ?array;
    public function clearToken(string $userId, string $clientId, string $baseDomain): void;

    // =========================
    // Пользователи
    // =========================
    public function saveUser(array $userData): void;
    public function getUser(string $id): ?array;
    public function getUserByBaseDomain(string $baseDomain): ?array;
    public function listUsers(): array;

    // =========================
    // Кеширование
    // =========================
    public function saveCache(string $key, array $data, int $ttl, ?string $userId = null): void;
    public function getCache(string $key): ?array;
    public function clearUserCache(?string $userId): void;

    // =========================
    // Поиск дубликатов
    // =========================
    public function saveDuplicateCheckFields(string $userId, array $fields): void;
    public function getDuplicateCheckFields(string $userId): array;

    public function findDuplicatesByFieldCode(string $fieldCode, string $userId): array;
    public function findDuplicatesByFieldId(int $fieldId, string $userId): array;

    // =========================
    // Контакты
    // =========================
    public function saveContactsBatch(array $contacts, string $userId): void;
    public function getAllContactsFromDb(string $userId): array;
    public function clearContacts(string $userId): void;

    public function upsertContact(array $contact, string $userId): void;
    public function deleteContact(int $contactId, string $userId): void;

    // =========================
    // Поиск дубликатов для конкретного контакта
    // =========================

    public function saveContactFields(array $contact, string $userId): void;

    public function getContactFieldValues(int $contactId, string $userId): array;

    public function findDuplicatesForValue(
        string $normalizedValue,
        ?string $fieldCode,
        ?int $fieldId,
        string $userId,
        int $excludeContactId
    ): array;
}
