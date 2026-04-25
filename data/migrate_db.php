<?php

$db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');

$db->exec("
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

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    name TEXT,
    email TEXT,
    client_id TEXT,
    client_secret TEXT,
    base_domain TEXT,
    updated_at INTEGER
);

CREATE TABLE IF NOT EXISTS cache (
    key TEXT PRIMARY KEY,
    value TEXT,
    expires_at INTEGER
);
");

$this->db->exec("
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY,
    user_id TEXT NOT NULL,
    name TEXT,
    data TEXT NOT NULL,
    updated_at INTEGER
);
");

$this->db->exec("
CREATE INDEX IF NOT EXISTS idx_contacts_user_id ON contacts(user_id);
");

echo "Database initialized!";
