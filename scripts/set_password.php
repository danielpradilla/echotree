<?php

declare(strict_types=1);

require __DIR__ . '/../app/db.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/set_password.php <username>\n");
    exit(1);
}

$username = trim((string) $argv[1]);
if ($username === '') {
    fwrite(STDERR, "Username cannot be empty.\n");
    exit(1);
}

fwrite(STDOUT, "Enter password: ");
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\nConfirm password: ");
system('stty -echo');
$confirm = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");

if ($password === '' || $password !== $confirm) {
    fwrite(STDERR, "Passwords do not match or are empty.\n");
    exit(1);
}

$pdo = db_connection();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (\n"
    . "id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
    . "username TEXT NOT NULL UNIQUE,\n"
    . "password_hash TEXT NOT NULL,\n"
    . "created_at TEXT NOT NULL DEFAULT (datetime('now'))\n"
    . ")"
);

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
$stmt->execute([':username' => $username]);
$existing = $stmt->fetch();

if ($existing) {
    $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE username = :username');
    $update->execute([':hash' => $hash, ':username' => $username]);
    fwrite(STDOUT, "Password updated for {$username}.\n");
    exit(0);
}

$insert = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :hash)');
$insert->execute([':username' => $username, ':hash' => $hash]);

fwrite(STDOUT, "User created: {$username}.\n");
