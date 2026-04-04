<?php

/**
 * Класс для работы с SQLite базой данных, реализующий интерфейс StorageInterface
 */
class SqliteStorage implements StorageInterface
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function saveToken(array $tokenData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tokens (client_id, access_token, refresh_token, expires_at)
            VALUES (:client_id, :access_token, :refresh_token, :expires_at)
        ");

        $stmt->execute([
            ':client_id' => $tokenData['client_id'],
            ':access_token' => $tokenData['access_token'],
            ':refresh_token' => $tokenData['refresh_token'],
            ':expires_at' => $tokenData['expires_at'],
        ]);
    }

    public function getToken(): ?array
    {
        $stmt = $this->db->query("SELECT * FROM tokens ORDER BY id DESC LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function saveCache(string $key, array $data, int $ttl): void
    {
        $stmt = $this->db->prepare("
            REPLACE INTO cache (key, value, expires_at)
            VALUES (:key, :value, :expires_at)
        ");

        $stmt->execute([
            ':key' => $key,
            ':value' => json_encode($data),
            ':expires_at' => time() + $ttl
        ]);
    }

    public function getCache(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cache WHERE key = :key");
        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        if ($row['expires_at'] < time()) {
            return null;
        }

        return json_decode($row['value'], true);
    }

    public function clearToken(): void
    {
        $this->db->exec("DELETE FROM tokens");
    }
}
