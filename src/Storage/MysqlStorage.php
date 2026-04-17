<?php

/**
 * Класс для работы с MySQL базой данных
 */
class MysqlStorage implements StorageInterface
{
    /**
     * @var PDO
     */
    private $db;

    public function __construct(
        string $host,
        string $dbName,
        string $user,
        string $password
    ) {
        $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

        $this->db = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

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
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                client_id VARCHAR(255) NOT NULL,
                base_domain VARCHAR(255) NOT NULL,
                access_token TEXT,
                refresh_token TEXT,
                expires_at INT,
                UNIQUE KEY uniq_token (user_id, client_id, base_domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Таблица пользователей
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(255) PRIMARY KEY,
                name VARCHAR(255),
                email VARCHAR(255),
                client_id VARCHAR(255),
                client_secret VARCHAR(255),
                base_domain VARCHAR(255),
                updated_at INT,
                KEY idx_base_domain (base_domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Таблица кэша
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache (
                `key` VARCHAR(255) PRIMARY KEY,
                user_id VARCHAR(255),
                value LONGTEXT NOT NULL,
                expires_at INT NOT NULL,
                KEY idx_cache_user_id (user_id),
                KEY idx_cache_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->addColumnIfNotExists('users', 'duplicate_check_fields', 'TEXT');
    }

    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
    ");

        $stmt->execute([
            ':table' => $table,
            ':column' => $column
        ]);

        $exists = (int)$stmt->fetchColumn() > 0;

        if (!$exists) {
            $this->db->exec("
            ALTER TABLE {$table}
            ADD COLUMN {$column} {$definition}
        ");
        }
    }

    /**
     * Сохранение токена (UPSERT)
     */
    public function saveToken(array $tokenData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tokens (
                user_id, client_id, base_domain,
                access_token, refresh_token, expires_at
            )
            VALUES (
                :user_id, :client_id, :base_domain,
                :access_token, :refresh_token, :expires_at
            )
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at = VALUES(expires_at)
        ");

        $success = $stmt->execute([
            ':user_id'       => $tokenData['user_id'],
            ':client_id'     => $tokenData['client_id'],
            ':base_domain'   => $tokenData['base_domain'],
            ':access_token'  => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'],
            ':expires_at'    => $tokenData['expires_at'],
        ]);

        if (!$success) {
            throw new Exception('Failed to save token');
        }
    }

    /**
     * Получение токена
     */
    public function getToken(
        string $userId,
        string $clientId,
        string $baseDomain
    ): ?array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM tokens
            WHERE user_id = :user_id
              AND client_id = :client_id
              AND base_domain = :base_domain
            LIMIT 1
        ");

        $stmt->execute([
            ':user_id'     => $userId,
            ':client_id'   => $clientId,
            ':base_domain' => $baseDomain,
        ]);

        $result = $stmt->fetch();

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
            ':user_id'     => $userId,
            ':client_id'   => $clientId,
            ':base_domain' => $baseDomain,
        ]);
    }

    /**
     * Сохранение пользователя
     */
    public function saveUser(array $userData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (
                id, name, email,
                client_id, client_secret,
                base_domain, updated_at
            )
            VALUES (
                :id, :name, :email,
                :client_id, :client_secret,
                :base_domain, :updated_at
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                email = VALUES(email),
                client_id = VALUES(client_id),
                client_secret = VALUES(client_secret),
                base_domain = VALUES(base_domain),
                updated_at = VALUES(updated_at)
        ");

        $success = $stmt->execute([
            ':id'            => $userData['id'],
            ':name'          => $userData['name'] ?? null,
            ':email'         => $userData['email'] ?? null,
            ':client_id'     => $userData['client_id'] ?? null,
            ':client_secret' => $userData['client_secret'] ?? null,
            ':base_domain'   => $userData['base_domain'] ?? null,
            ':updated_at'    => $userData['updated_at'] ?? time(),
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
            SELECT *
            FROM users
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id,
        ]);

        $user = $stmt->fetch();

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

        $user = $stmt->fetch();

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
            INSERT INTO cache (`key`, user_id, value, expires_at)
            VALUES (:key, :user_id, :value, :expires_at)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                value = VALUES(value),
                expires_at = VALUES(expires_at)
        ");

        $success = $stmt->execute([
            ':key'        => $key,
            ':user_id'    => $userId,
            ':value'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':expires_at' => time() + $ttl,
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
            WHERE `key` = :key
            LIMIT 1
        ");

        $stmt->execute([
            ':key' => $key,
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ((int)$row['expires_at'] < time()) {
            $this->db->prepare("
                DELETE FROM cache
                WHERE `key` = :key
            ")->execute([
                ':key' => $key,
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
            DELETE FROM cache
            WHERE expires_at < :time
        ")->execute([
            ':time' => time(),
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
            ':user_id' => $userId,
        ]);
    }

    /**
     * Сохранение полей для проверки дубликатов
     */
    public function saveDuplicateCheckFields(string $userId, array $fields): void
    {
        $fields = array_values(array_unique(array_filter($fields)));

        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("
        UPDATE users
        SET duplicate_check_fields = :fields
        WHERE id = :id
    ");

        $stmt->execute([
            ':fields' => $json,
            ':id' => $userId
        ]);
    }

    /**
     * Получение полей для проверки дубликатов
     */
    public function getDuplicateCheckFields(string $userId): array
    {
        $stmt = $this->db->prepare("
        SELECT duplicate_check_fields
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

        $stmt->execute([
            ':id' => $userId
        ]);

        $row = $stmt->fetch();

        if (!$row || empty($row['duplicate_check_fields'])) {
            return [];
        }

        $data = json_decode($row['duplicate_check_fields'], true);

        return is_array($data) ? $data : [];
    }
}
