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

        // Таблица кэша
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );
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
    public function clearToken(string $userId, string $clientId, string $baseDomain): void
    {
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
     * Сохранение кэша
     */
    public function saveCache(string $key, array $data, int $ttl): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO cache (key, value, expires_at)
            VALUES (:key, :value, :expires_at)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                expires_at = excluded.expires_at
        ");

        $success = $stmt->execute([
            ':key' => $key,
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
            SELECT value, expires_at FROM cache WHERE key = :key
        ");

        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Проверка TTL
        if ((int)$row['expires_at'] < time()) {
            return null;
        }

        $data = json_decode($row['value'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * Очистка просроченного кэша (опционально)
     */
    public function clearExpiredCache(): void
    {
        $this->db->prepare("
            DELETE FROM cache WHERE expires_at < :time
        ")->execute([
            ':time' => time()
        ]);
    }
}
