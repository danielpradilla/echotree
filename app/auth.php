<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensure_login_attempts_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'username TEXT NOT NULL UNIQUE,'
        . 'failed_count INTEGER NOT NULL DEFAULT 0,'
        . 'last_failed_at TEXT NULL'
        . ')'
    );
}

function login_rate_limit_minutes(): int
{
    $minutes = (int) (getenv('ECHOTREE_RATE_LIMIT_MINUTES') ?: 10);
    return $minutes < 1 ? 10 : $minutes;
}

function is_login_throttled(string $username): bool
{
    $pdo = db_connection();
    ensure_login_attempts_table($pdo);

    $stmt = $pdo->prepare(
        "SELECT failed_count, last_failed_at FROM login_attempts WHERE username = :username"
    );
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $failed = (int) $row['failed_count'];
    if ($failed < 3 || !$row['last_failed_at']) {
        return false;
    }

    $minutes = login_rate_limit_minutes();
    $check = $pdo->prepare(
        "SELECT 1 WHERE datetime('now') < datetime(:last_failed_at, '+' || :minutes || ' minutes')"
    );
    $check->execute([
        ':last_failed_at' => $row['last_failed_at'],
        ':minutes' => $minutes,
    ]);

    return (bool) $check->fetch();
}

function record_login_failure(string $username): void
{
    $pdo = db_connection();
    ensure_login_attempts_table($pdo);

    $stmt = $pdo->prepare('SELECT failed_count FROM login_attempts WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();

    if ($row) {
        $failed = (int) $row['failed_count'] + 1;
        $update = $pdo->prepare(
            "UPDATE login_attempts SET failed_count = :count, last_failed_at = datetime('now') "
            . "WHERE username = :username"
        );
        $update->execute([':count' => $failed, ':username' => $username]);
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO login_attempts (username, failed_count, last_failed_at) "
            . "VALUES (:username, 1, datetime('now'))"
        );
        $insert->execute([':username' => $username]);
    }
}

function clear_login_failures(string $username): void
{
    $pdo = db_connection();
    ensure_login_attempts_table($pdo);
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
    $stmt->execute([':username' => $username]);
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $pdo = db_connection();
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function authenticate(string $username, string $password): ?array
{
    if (is_login_throttled($username)) {
        return null;
    }

    $pdo = db_connection();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        record_login_failure($username);
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        record_login_failure($username);
        return null;
    }

    clear_login_failures($username);
    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
    ];
}

function require_login($request, $handler)
{
    $path = $request->getUri()->getPath();
    if ($path === '/login' || $path === '/logout') {
        return $handler->handle($request);
    }

    if (!isset($_SESSION['user_id'])) {
        $response = new Slim\Psr7\Response();
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(str_replace('/index.php', '', $script), '/');
    $path = $request->getUri()->getPath();
    if (str_ends_with($path, '/login')) {
        return $handler->handle($request);
    }
        return $response
            ->withHeader('Location', $base . '/login')
            ->withStatus(302);
    }

    return $handler->handle($request);
}
