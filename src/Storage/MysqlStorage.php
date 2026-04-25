<?php

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
        string $pass
    ) {
        $this->db = new PDO(
            "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );

        $this->init();
    }

    private function init(): void
    {
        // TOKENS
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(64),
                client_id VARCHAR(128),
                base_domain VARCHAR(255),
                access_token TEXT,
                refresh_token TEXT,
                expires_at INT,
                UNIQUE KEY uniq_token (user_id, client_id, base_domain)
            ) ENGINE=InnoDB;
        ");

        // USERS
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(64) PRIMARY KEY,
                name TEXT,
                email TEXT,
                client_id TEXT,
                client_secret TEXT,
                base_domain TEXT,
                duplicate_check_fields TEXT,
                updated_at INT
            ) ENGINE=InnoDB;
        ");

        // CACHE
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache (
                `key` VARCHAR(255) PRIMARY KEY,
                user_id VARCHAR(64),
                value TEXT,
                expires_at INT
            ) ENGINE=InnoDB;
        ");

        $this->db->exec("
            CREATE INDEX idx_cache_user_id ON cache(user_id);
        ");

        // CONTACTS
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id INT,
                user_id VARCHAR(64),
                name TEXT,
                data LONGTEXT,
                updated_at INT,
                PRIMARY KEY (id, user_id)
            ) ENGINE=InnoDB;
        ");

        // FIELD VALUES
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS contact_field_values (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contact_id INT,
                user_id VARCHAR(64),
                field_code VARCHAR(64),
                field_id INT,
                value TEXT,
                normalized_value TEXT
            ) ENGINE=InnoDB;
        ");

        $this->db->exec("
            CREATE INDEX idx_field_search 
            ON contact_field_values(user_id, field_code, normalized_value(100));
        ");
    }

    // ================= TOKENS =================

    public function saveToken(array $t): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tokens 
            (user_id, client_id, base_domain, access_token, refresh_token, expires_at)
            VALUES (:user_id, :client_id, :base_domain, :access_token, :refresh_token, :expires_at)
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at = VALUES(expires_at)
        ");

        $stmt->execute($t);
    }

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

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

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

    // ================= USERS =================

    public function saveUser(array $u): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (id, name, email, client_id, client_secret, base_domain, updated_at)
            VALUES (:id, :name, :email, :client_id, :client_secret, :base_domain, :updated_at)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                email = VALUES(email),
                client_id = VALUES(client_id),
                client_secret = VALUES(client_secret),
                base_domain = VALUES(base_domain),
                updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':id' => $u['id'],
            ':name' => $u['name'] ?? null,
            ':email' => $u['email'] ?? null,
            ':client_id' => $u['client_id'] ?? null,
            ':client_secret' => $u['client_secret'] ?? null,
            ':base_domain' => $u['base_domain'] ?? null,
            ':updated_at' => $u['updated_at'] ?? time()
        ]);
    }

    public function getUser(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUserByBaseDomain(string $baseDomain): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM users
            WHERE base_domain = :base_domain
            LIMIT 1
        ");

        $stmt->execute([':base_domain' => trim($baseDomain)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listUsers(): array
    {
        return $this->db->query("
            SELECT * FROM users
            ORDER BY updated_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ================= CACHE =================

    public function saveCache(string $key, array $data, int $ttl, ?string $userId = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO cache (`key`, user_id, value, expires_at)
            VALUES (:key, :user_id, :value, :expires_at)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                value = VALUES(value),
                expires_at = VALUES(expires_at)
        ");

        $stmt->execute([
            ':key' => $key,
            ':user_id' => $userId,
            ':value' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':expires_at' => $ttl > 0 ? time() + $ttl : 0
        ]);
    }

    public function getCache(string $key): ?array
    {
        $stmt = $this->db->prepare("
            SELECT value, expires_at FROM cache WHERE `key` = :key LIMIT 1
        ");

        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->db->prepare("DELETE FROM cache WHERE `key` = :key")
                ->execute([':key' => $key]);
            return null;
        }

        return json_decode($row['value'], true);
    }

    public function clearUserCache(?string $userId): void
    {
        if (!$userId) return;

        $this->db->prepare("
            DELETE FROM cache WHERE user_id = :user_id
        ")->execute([':user_id' => $userId]);
    }

    // ================= DUPLICATE SETTINGS =================

    public function saveDuplicateCheckFields(string $userId, array $fields): void
    {
        $this->db->prepare("
            UPDATE users
            SET duplicate_check_fields = :fields
            WHERE id = :id
        ")->execute([
            ':fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':id' => $userId
        ]);
    }

    public function getDuplicateCheckFields(string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT duplicate_check_fields FROM users WHERE id = :id
        ");

        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row && $row['duplicate_check_fields']
            ? json_decode($row['duplicate_check_fields'], true)
            : [];
    }

    // ================= CONTACTS =================

    public function saveContactsBatch(array $contacts, string $userId): void
    {
        $this->db->beginTransaction();

        foreach ($contacts as $c) {
            $this->upsertContact($c, $userId);
        }

        $this->db->commit();
    }

    public function upsertContact(array $c, string $userId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO contacts (id, user_id, name, data, updated_at)
            VALUES (:id, :user_id, :name, :data, :updated_at)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                data = VALUES(data),
                updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            ':id' => $c['id'],
            ':user_id' => $userId,
            ':name' => $c['name'] ?? '',
            ':data' => json_encode($c, JSON_UNESCAPED_UNICODE),
            ':updated_at' => time()
        ]);

        $this->saveContactFields($c, $userId);
    }

    public function clearContacts(string $userId): void
    {
        $this->db->prepare("DELETE FROM contacts WHERE user_id = :u")
            ->execute([':u' => $userId]);
    }

    public function deleteContact(int $id, string $userId): void
    {
        $this->db->prepare("
            DELETE FROM contacts WHERE id = :id AND user_id = :u
        ")->execute([':id' => $id, ':u' => $userId]);

        $this->db->prepare("
            DELETE FROM contact_field_values WHERE contact_id = :id AND user_id = :u
        ")->execute([':id' => $id, ':u' => $userId]);
    }

    public function getAllContactsFromDb(string $userId): array
    {
        $stmt = $this->db->prepare("SELECT data FROM contacts WHERE user_id = :u");
        $stmt->execute([':u' => $userId]);

        $result = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = json_decode($row['data'], true);
        }

        return $result;
    }

    // ================= FIELDS =================

    public function saveContactFields(array $contact, string $userId): void
    {
        $contactId = (int)$contact['id'];

        $this->db->prepare("
            DELETE FROM contact_field_values
            WHERE contact_id = :id AND user_id = :u
        ")->execute([':id' => $contactId, ':u' => $userId]);

        $stmt = $this->db->prepare("
            INSERT INTO contact_field_values
            (contact_id, user_id, field_code, field_id, value, normalized_value)
            VALUES (:cid, :uid, :code, :fid, :val, :norm)
        ");

        foreach ($contact['custom_fields_values'] ?? [] as $f) {
            $code = strtoupper(trim($f['field_code'] ?? ''));
            $fid = (int)($f['field_id'] ?? 0);

            foreach ($f['values'] ?? [] as $v) {

                $value = trim((string)$v['value']);
                if ($value === '') continue;

                $norm = $this->normalizeValue($value, $code);
                if ($norm === '') continue;

                $stmt->execute([
                    ':cid' => $contactId,
                    ':uid' => $userId,
                    ':code' => $code,
                    ':fid' => $fid,
                    ':val' => $value,
                    ':norm' => $norm
                ]);
            }
        }
    }

    private function normalizeValue(string $value, string $fieldCode): string
    {
        if ($fieldCode === 'EMAIL') return strtolower($value);

        if ($fieldCode === 'PHONE') {
            $digits = preg_replace('/\D+/', '', $value);
            if (strlen($digits) === 11 && $digits[0] === '8') {
                $digits[0] = '7';
            }
            return $digits;
        }

        return mb_strtolower(trim($value));
    }

    public function getContactFieldValues(int $contactId, string $userId): array
    {
        $stmt = $this->db->prepare("
        SELECT *
        FROM contact_field_values
        WHERE contact_id = :contact_id
          AND user_id = :user_id
    ");

        $stmt->execute([
            ':contact_id' => $contactId,
            ':user_id' => $userId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ================= DUPLICATES =================

    public function findDuplicatesByFieldCode(string $fieldCode, string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT cfv.normalized_value, c.id, c.name
            FROM contact_field_values cfv
            JOIN contacts c ON c.id = cfv.contact_id AND c.user_id = cfv.user_id
            WHERE cfv.user_id = :u
              AND cfv.field_code = :code
              AND cfv.normalized_value IN (
                  SELECT normalized_value
                  FROM contact_field_values
                  WHERE user_id = :u AND field_code = :code
                  GROUP BY normalized_value
                  HAVING COUNT(DISTINCT contact_id) > 1
              )
        ");

        $stmt->execute([':u' => $userId, ':code' => $fieldCode]);

        return $this->groupDuplicates($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findDuplicatesByFieldId(int $fieldId, string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT cfv.normalized_value, c.id, c.name
            FROM contact_field_values cfv
            JOIN contacts c ON c.id = cfv.contact_id AND c.user_id = cfv.user_id
            WHERE cfv.user_id = :u
              AND cfv.field_id = :fid
              AND cfv.normalized_value IN (
                  SELECT normalized_value
                  FROM contact_field_values
                  WHERE user_id = :u AND field_id = :fid
                  GROUP BY normalized_value
                  HAVING COUNT(DISTINCT contact_id) > 1
              )
        ");

        $stmt->execute([':u' => $userId, ':fid' => $fieldId]);

        return $this->groupDuplicates($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function groupDuplicates(array $rows): array
    {
        $map = [];

        foreach ($rows as $r) {
            $v = $r['normalized_value'];

            if (!isset($map[$v])) {
                $map[$v] = ['value' => $v, 'contacts' => []];
            }

            $map[$v]['contacts'][] = [
                'id' => $r['id'],
                'name' => $r['name']
            ];
        }

        return array_values($map);
    }

    public function findDuplicatesForValue(
        string $normalizedValue,
        ?string $fieldCode,
        ?int $fieldId,
        string $userId,
        int $excludeContactId
    ): array {

        if ($normalizedValue === '') {
            return [];
        }

        if ($fieldCode) {

            $stmt = $this->db->prepare("
            SELECT c.id, c.name
            FROM contact_field_values v
            JOIN contacts c 
              ON c.id = v.contact_id 
             AND c.user_id = v.user_id
            WHERE v.user_id = :user_id
              AND v.field_code = :field_code
              AND v.normalized_value = :value
              AND v.contact_id != :exclude_id
        ");

            $stmt->execute([
                ':user_id' => $userId,
                ':field_code' => strtoupper($fieldCode),
                ':value' => $normalizedValue,
                ':exclude_id' => $excludeContactId
            ]);
        } else {

            $stmt = $this->db->prepare("
            SELECT c.id, c.name
            FROM contact_field_values v
            JOIN contacts c 
              ON c.id = v.contact_id 
             AND c.user_id = v.user_id
            WHERE v.user_id = :user_id
              AND v.field_id = :field_id
              AND v.normalized_value = :value
              AND v.contact_id != :exclude_id
        ");

            $stmt->execute([
                ':user_id' => $userId,
                ':field_id' => $fieldId,
                ':value' => $normalizedValue,
                ':exclude_id' => $excludeContactId
            ]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
