<?php

declare(strict_types=1);

function db_connection(): PDO
{
    $baseDir = dirname(__DIR__);
    $dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'echotree.sqlite';
    if (!file_exists($dbPath)) {
        touch($dbPath);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    auto_init_schema($pdo, $baseDir);
    ensure_runtime_schema($pdo);

    return $pdo;
}

function auto_init_schema(PDO $pdo, string $baseDir): void
{
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='feeds'")->fetch();
    if ($check) {
        return;
    }

    $schemaPath = $baseDir . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'schema.sql';
    if (!file_exists($schemaPath)) {
        return;
    }

    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        return;
    }

    $pdo->exec($schema);
}

function ensure_runtime_schema(PDO $pdo): void
{
    ensure_feeds_schema($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS share_history ("
        . "id INTEGER PRIMARY KEY AUTOINCREMENT, "
        . "url TEXT NOT NULL, "
        . "title TEXT NULL, "
        . "comment TEXT NOT NULL, "
        . "shared_at TEXT NOT NULL DEFAULT (datetime('now')), "
        . "created_at TEXT NOT NULL DEFAULT (datetime('now')), "
        . "status TEXT NOT NULL DEFAULT 'sent', "
        . "platform TEXT NULL, "
        . "account_id INTEGER NULL, "
        . "account_display_name TEXT NULL, "
        . "account_handle TEXT NULL, "
        . "article_id INTEGER NULL, "
        . "post_id INTEGER NULL, "
        . "delivery_id INTEGER NULL, "
        . "external_id TEXT NULL, "
        . "error TEXT NULL"
        . ")"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_history_shared_at ON share_history(shared_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_history_url ON share_history(url)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_history_post_id ON share_history(post_id)');
}

function ensure_feeds_schema(PDO $pdo): void
{
    $columns = $pdo->query("PRAGMA table_info(feeds)")->fetchAll();
    $columnNames = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);

    if (!in_array('folder_name', $columnNames, true)) {
        $pdo->exec('ALTER TABLE feeds ADD COLUMN folder_name TEXT NULL');
    }

    if (!in_array('last_fetch_error', $columnNames, true)) {
        $pdo->exec('ALTER TABLE feeds ADD COLUMN last_fetch_error TEXT NULL');
    }
}
