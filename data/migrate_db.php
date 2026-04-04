<?php

$db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');

$db->exec("
CREATE TABLE IF NOT EXISTS tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id TEXT,
    access_token TEXT,
    refresh_token TEXT,
    expires_at INTEGER
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    name TEXT,
    email TEXT,
    updated_at INTEGER
);

CREATE TABLE IF NOT EXISTS cache (
    key TEXT PRIMARY KEY,
    value TEXT,
    expires_at INTEGER
);
");

echo "Database initialized!";