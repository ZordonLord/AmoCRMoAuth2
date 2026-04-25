<?php

/**
 * Класс для работы с SQLite базой данных
 */
class SqliteStorage implements StorageInterface
{
    /**
     * @var PDO
     */
    private $db;

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

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER,
                user_id TEXT,
                name TEXT,
                data TEXT,
                updated_at INTEGER,
                PRIMARY KEY (id, user_id)
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS contact_field_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER,
                user_id TEXT,
                field_code TEXT,
                field_id INTEGER,
                value TEXT,
                normalized_value TEXT
            );
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_field_search 
            ON contact_field_values(user_id, field_code, normalized_value);
        ");

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->addColumnIfNotExists('users', 'duplicate_check_fields', 'TEXT');
    }

    private function addColumnIfNotExists(string $table, string $column, string $type): void
    {
        $stmt = $this->db->query("PRAGMA table_info({$table})");
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[] = $col['name'];
        }

        if (!in_array($column, $columns, true)) {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
        }
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

    public function listUsers(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM users
            ORDER BY updated_at DESC, id DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
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
            ':expires_at' => $ttl > 0 ? (time() + $ttl) : 0
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

        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] < time()) {

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
            DELETE FROM cache WHERE expires_at > 0 AND expires_at < :time
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

    /**
     * Сохранение полей для проверки дублей
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
     * Получение полей для проверки дублей
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

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['duplicate_check_fields'])) {
            return [];
        }

        $data = json_decode($row['duplicate_check_fields'], true);

        return is_array($data) ? $data : [];
    }

    /**
     * Сохранение контактов пачкой
     */
    public function saveContactsBatch(array $contacts, string $userId): void
    {
        $this->db->beginTransaction();

        $stmt = $this->db->prepare("
        INSERT OR REPLACE INTO contacts (id, user_id, name, data, updated_at)
        VALUES (:id, :user_id, :name, :data, :updated_at)
    ");

        foreach ($contacts as $contact) {

            $contactId = (int)($contact['id'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $stmt->execute([
                ':id' => $contactId,
                ':user_id' => $userId,
                ':name' => $contact['name'] ?? '',
                ':data' => json_encode($contact, JSON_UNESCAPED_UNICODE),
                ':updated_at' => time()
            ]);

            $this->saveContactFields($contact, $userId);
        }

        $this->db->commit();
    }

    /**
     * Получение всех контактов из БД
     */
    public function getAllContactsFromDb(string $userId): array
    {
        $stmt = $this->db->prepare("
        SELECT data FROM contacts WHERE user_id = :user_id
    ");

        $stmt->execute([':user_id' => $userId]);

        $result = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = json_decode($row['data'], true);
        }

        return $result;
    }

    /**
     * Очистка контактов пользователя
     */
    public function clearContacts(string $userId): void
    {
        $stmt = $this->db->prepare("
        DELETE FROM contacts WHERE user_id = :user_id
    ");

        $stmt->execute([':user_id' => $userId]);
    }

    /**
     * Сохранение полей контакта для проверки дублей
     */
    public function saveContactFields(array $contact, string $userId): void
    {
        $contactId = (int)($contact['id'] ?? 0);

        if ($contactId <= 0) {
            return;
        }

        $this->db->prepare("
        DELETE FROM contact_field_values
        WHERE contact_id = :contact_id AND user_id = :user_id
    ")->execute([
            ':contact_id' => $contactId,
            ':user_id' => $userId
        ]);

        $stmt = $this->db->prepare("
        INSERT INTO contact_field_values
        (contact_id, user_id, field_code, field_id, value, normalized_value)
        VALUES (:contact_id, :user_id, :field_code, :field_id, :value, :normalized)
    ");

        foreach ($contact['custom_fields_values'] ?? [] as $field) {

            $fieldCode = strtoupper(trim($field['field_code'] ?? ''));
            $fieldId   = (int)($field['field_id'] ?? 0);

            foreach ($field['values'] ?? [] as $v) {

                $value = trim((string)($v['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $normalized = $this->normalizeValue($value, $fieldCode);
                if ($normalized === '') {
                    continue;
                }

                $stmt->execute([
                    ':contact_id' => $contactId,
                    ':user_id' => $userId,
                    ':field_code' => $fieldCode,
                    ':field_id' => $fieldId,
                    ':value' => $value,
                    ':normalized' => $normalized
                ]);
            }
        }
    }

    /**
     * Нормализация значения для сравнения (например, для телефонов и email)
     */
    private function normalizeValue(string $value, string $fieldCode): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // EMAIL
        if ($fieldCode === 'EMAIL') {
            return strtolower($value);
        }

        // PHONE
        if ($fieldCode === 'PHONE') {

            $digits = preg_replace('/\D+/', '', $value);

            // нормализация РФ номера
            if (strlen($digits) === 11 && $digits[0] === '8') {
                $digits[0] = '7';
            }

            return $digits;
        }

        // generic text
        $value = preg_replace('/\s+/u', ' ', $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Добавление или обновление контакта
     */
    public function upsertContact(array $contact, string $userId): void
    {
        $stmt = $this->db->prepare("
        INSERT OR REPLACE INTO contacts (id, user_id, name, data, updated_at)
        VALUES (:id, :user_id, :name, :data, :updated_at)
    ");

        $stmt->execute([
            ':id' => $contact['id'],
            ':user_id' => $userId,
            ':name' => $contact['name'] ?? '',
            ':data' => json_encode($contact),
            ':updated_at' => time()
        ]);

        $this->saveContactFields($contact, $userId);
    }

    /**
     * Удаление контакта
     */
    public function deleteContact(int $contactId, string $userId): void
    {
        $this->db->prepare("
        DELETE FROM contacts WHERE id = :id AND user_id = :user_id
    ")->execute([
            ':id' => $contactId,
            ':user_id' => $userId
        ]);

        $this->db->prepare("
        DELETE FROM contact_field_values 
        WHERE contact_id = :id AND user_id = :user_id
    ")->execute([
            ':id' => $contactId,
            ':user_id' => $userId
        ]);
    }

    /**
     * Поиск дубликатов по коду поля
     */
    public function findDuplicatesByFieldCode(string $fieldCode, string $userId): array
    {
        $fieldCode = strtoupper(trim($fieldCode));

        if ($fieldCode === '') {
            return [];
        }

        $stmt = $this->db->prepare("
        SELECT 
            cfv.normalized_value,
            c.id,
            c.name
        FROM contact_field_values cfv
        JOIN contacts c 
          ON c.id = cfv.contact_id 
         AND c.user_id = cfv.user_id
        WHERE cfv.user_id = :user_id
          AND cfv.field_code = :field_code
          AND cfv.normalized_value IN (
              SELECT normalized_value
              FROM contact_field_values
              WHERE user_id = :user_id
                AND field_code = :field_code
              GROUP BY normalized_value
              HAVING COUNT(DISTINCT contact_id) > 1
          )
        ORDER BY cfv.normalized_value
    ");

        $stmt->execute([
            ':user_id' => $userId,
            ':field_code' => $fieldCode
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->groupDuplicates($rows);
    }

    /**
     * Поиск дубликатов по ID поля
     */
    public function findDuplicatesByFieldId(int $fieldId, string $userId): array
    {
        if ($fieldId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
        SELECT 
            cfv.normalized_value,
            c.id,
            c.name
        FROM contact_field_values cfv
        JOIN contacts c 
          ON c.id = cfv.contact_id 
         AND c.user_id = cfv.user_id
        WHERE cfv.user_id = :user_id
          AND cfv.field_id = :field_id
          AND cfv.normalized_value IN (
              SELECT normalized_value
              FROM contact_field_values
              WHERE user_id = :user_id
                AND field_id = :field_id
              GROUP BY normalized_value
              HAVING COUNT(DISTINCT contact_id) > 1
          )
        ORDER BY cfv.normalized_value
    ");

        $stmt->execute([
            ':user_id' => $userId,
            ':field_id' => $fieldId
        ]);

        return $this->groupDuplicates($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Группировка результатов поиска дублей по нормализованному значению
     */
    private function groupDuplicates(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {

            $value = $row['normalized_value'];

            if (!isset($map[$value])) {
                $map[$value] = [
                    'value' => $value,
                    'contacts' => []
                ];
            }

            $map[$value]['contacts'][] = [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }

        return array_values($map);
    }

    /**
     * Получение всех значений полей контакта
     */
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

    /**
     * Поиск контактов с таким же значением поля (для проверки при сохранении)
     */
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
            JOIN contacts c ON c.id = v.contact_id
            WHERE v.user_id = :user_id
              AND v.field_code = :field_code
              AND v.normalized_value = :value
              AND v.contact_id != :exclude_id
        ");

            $stmt->execute([
                ':user_id' => $userId,
                ':field_code' => $fieldCode,
                ':value' => $normalizedValue,
                ':exclude_id' => $excludeContactId
            ]);
        } else {
            $stmt = $this->db->prepare("
            SELECT c.id, c.name
            FROM contact_field_values v
            JOIN contacts c ON c.id = v.contact_id
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
