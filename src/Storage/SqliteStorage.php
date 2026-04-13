<?php

/**
 * Класс для работы с SQLite базой данных
 */
class SqliteStorage implements StorageInterface
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->init();
    }

    /**
     * Создание таблиц и индексов (если их нет)
     */
    private function init(): void
    {
        // Таблица токенов
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                client_id TEXT,
                base_domain TEXT,
                access_token TEXT,
                refresh_token TEXT,
                expires_at INTEGER,
                UNIQUE(user_id, client_id, base_domain)
            );
        ");
        // Таблица пользователей
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                name TEXT,
                email TEXT,
                client_id TEXT,
                client_secret TEXT,
                base_domain TEXT,
                updated_at INTEGER
            );
        ");

        // Таблица кэша
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                user_id TEXT,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_cache_user_id
                ON cache(user_id);
        ");
    }

    /**
     * Сохранение токена (UPSERT)
     */
    public function saveToken(array $tokenData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tokens (user_id, client_id, base_domain, access_token, refresh_token, expires_at)
            VALUES (:user_id, :client_id, :base_domain, :access_token, :refresh_token, :expires_at)
            ON CONFLICT(user_id, client_id, base_domain) DO UPDATE SET
            access_token = excluded.access_token,
            refresh_token = excluded.refresh_token,
            expires_at = excluded.expires_at
");

        $success = $stmt->execute([
            ':user_id'      => $tokenData['user_id'],
            ':client_id'    => $tokenData['client_id'],
            ':base_domain'  => $tokenData['base_domain'],
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'],
            ':expires_at'   => $tokenData['expires_at'],
        ]);

        if (!$success) {
            throw new Exception('Failed to save token');
        }
    }

    /**
     * Получение токена
     */
    public function getToken(string $userId, string $clientId, string $baseDomain): ?array
    {
        $stmt = $this->db->prepare("
        SELECT * FROM tokens
        WHERE user_id = :user_id
          AND client_id = :client_id
          AND base_domain = :base_domain
        LIMIT 1
    ");

        $stmt->execute([
            ':user_id' => $userId,
            ':client_id' => $clientId,
            ':base_domain' => $baseDomain
        ]);


        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Удаление токена
     */
    public function clearToken(
        string $userId,
        string $clientId,
        string $baseDomain
    ): void {
        $stmt = $this->db->prepare("
        DELETE FROM tokens
        WHERE user_id = :user_id
          AND client_id = :client_id
          AND base_domain = :base_domain
    ");

        $stmt->execute([
            ':user_id' => $userId,
            ':client_id' => $clientId,
            ':base_domain' => $baseDomain
        ]);
    }

    /**
     * Сохранение пользователя
     */
    public function saveUser(array $userData): void
    {
        $stmt = $this->db->prepare("
        INSERT INTO users (id, name, email, client_id, client_secret, base_domain, updated_at)
        VALUES (:id, :name, :email, :client_id, :client_secret, :base_domain, :updated_at)
        ON CONFLICT(id) DO UPDATE SET
            name = excluded.name,
            email = excluded.email,
            client_id = excluded.client_id,
            client_secret = excluded.client_secret,
            base_domain = excluded.base_domain,
            updated_at = excluded.updated_at
    ");

        $success = $stmt->execute([
            ':id'            => $userData['id'],
            ':name'          => $userData['name'] ?? null,
            ':email'         => $userData['email'] ?? null,
            ':client_id'     => $userData['client_id'] ?? null,
            ':client_secret' => $userData['client_secret'] ?? null,
            ':base_domain'   => $userData['base_domain'] ?? null,
            ':updated_at'    => $userData['updated_at'] ?? time()
        ]);

        if (!$success) {
            throw new Exception('Failed to save user');
        }
    }

    /**
     * Получение пользователя по ID
     */
    public function getUser(string $id): ?array
    {
        $stmt = $this->db->prepare("
        SELECT * FROM users WHERE id = :id LIMIT 1
    ");

        $stmt->execute([':id' => $id]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Получение пользователя по домену
     */
    public function getUserByBaseDomain(string $baseDomain): ?array
    {
        $stmt = $this->db->prepare("
        SELECT * 
        FROM users
        WHERE base_domain = :base_domain
        LIMIT 1
    ");

        $stmt->execute([
            ':base_domain' => trim($baseDomain),
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Сохранение кэша
     */
    public function saveCache(
        string $key,
        array $data,
        int $ttl,
        ?string $userId = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO cache (key, user_id, value, expires_at)
            VALUES (:key, :user_id, :value, :expires_at)
            ON CONFLICT(key) DO UPDATE SET
                user_id = excluded.user_id,
                value = excluded.value,
                expires_at = excluded.expires_at
        ");

        $success = $stmt->execute([
            ':key' => $key,
            ':user_id' => $userId,
            ':value' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':expires_at' => time() + $ttl
        ]);

        if (!$success) {
            throw new Exception('Failed to save cache');
        }
    }

    /**
     * Получение кэша
     */
    public function getCache(string $key): ?array
    {
        $stmt = $this->db->prepare("
            SELECT value, expires_at
            FROM cache
            WHERE key = :key
            LIMIT 1
        ");

        $stmt->execute([
            ':key' => $key
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ((int)$row['expires_at'] < time()) {

            $this->db->prepare("
                DELETE FROM cache
                WHERE key = :key
            ")->execute([
                ':key' => $key
            ]);

            return null;
        }

        $data = json_decode($row['value'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * Очистка просроченного кэша
     */
    public function clearExpiredCache(): void
    {
        $this->db->prepare("
            DELETE FROM cache WHERE expires_at < :time
        ")->execute([
            ':time' => time()
        ]);
    }

    /**
     * Очистка кэша пользователя
     */
    public function clearUserCache(?string $userId): void
    {
        if (!$userId) {
            return;
        }

        $stmt = $this->db->prepare("
            DELETE FROM cache
            WHERE user_id = :user_id
        ");

        $stmt->execute([
            ':user_id' => $userId
        ]);
    }
}
