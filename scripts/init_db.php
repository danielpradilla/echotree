<?php

declare(strict_types=1);

require __DIR__ . '/../app/db.php';

$schemaPath = __DIR__ . '/schema.sql';
if (!file_exists($schemaPath)) {
    fwrite(STDERR, "Missing schema.sql at {$schemaPath}\n");
    exit(1);
}

$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, "Failed to read schema.sql\n");
    exit(1);
}

$pdo = db_connection();
$pdo->exec($schema);

fwrite(STDOUT, "Database initialized.\n");
