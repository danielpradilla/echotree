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
