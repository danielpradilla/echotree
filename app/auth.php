<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function remember_cookie_name(): string
{
    return 'echotree_remember';
}

function is_request_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
}

function session_lifetime_seconds(): int
{
    $seconds = (int) (getenv('ECHOTREE_SESSION_LIFETIME_SECONDS') ?: 0);
    return $seconds < 0 ? 0 : $seconds;
}

function remember_me_lifetime_seconds(): int
{
    $seconds = (int) (getenv('ECHOTREE_REMEMBER_ME_SECONDS') ?: 2592000);
    return $seconds < 0 ? 0 : $seconds;
}

function remember_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function set_remember_cookie(string $token, int $expiresTs): void
{
    setcookie(remember_cookie_name(), $token, [
        'expires' => $expiresTs,
        'path' => '/',
        'httponly' => true,
        'secure' => is_request_secure(),
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void
{
    setcookie(remember_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => is_request_secure(),
        'samesite' => 'Lax',
    ]);
}

function refresh_session_cookie(): void
{
    $lifetime = session_lifetime_seconds();
    if ($lifetime <= 0 || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $sid = session_id();
    if ($sid === '') {
        return;
    }

    setcookie(session_name(), $sid, [
        'expires' => time() + $lifetime,
        'path' => '/',
        'httponly' => true,
        'secure' => is_request_secure(),
        'samesite' => 'Lax',
    ]);
}

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

function ensure_remember_tokens_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS remember_tokens ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'user_id INTEGER NOT NULL,'
        . 'token_hash TEXT NOT NULL UNIQUE,'
        . 'expires_at TEXT NOT NULL,'
        . 'last_used_at TEXT NULL,'
        . 'created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),'
        . 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
        . ')'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remember_tokens_user_id ON remember_tokens(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires_at ON remember_tokens(expires_at)');
}

function ensure_auth_tables(PDO $pdo): void
{
    ensure_login_attempts_table($pdo);
    ensure_remember_tokens_table($pdo);
}

function login_rate_limit_minutes(): int
{
    $minutes = (int) (
        getenv('ECHOTREE_LOGIN_THROTTLE_MINUTES')
        ?: (getenv('ECHOTREE_RATE_LIMIT_MINUTES') ?: 10)
    );
    return $minutes < 1 ? 10 : $minutes;
}

function is_login_throttled(string $username): bool
{
    $pdo = db_connection();
    ensure_auth_tables($pdo);

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
    retry_db_write(function () use ($username) {
        $pdo = db_connection();
        ensure_auth_tables($pdo);

        $stmt = $pdo->prepare('SELECT failed_count FROM login_attempts WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if ($row) {
            $failed = (int) $row['failed_count'] + 1;
            $update = $pdo->prepare(
                "UPDATE login_attempts SET failed_count = :count, last_failed_at = datetime('now') "
                . 'WHERE username = :username'
            );
            $update->execute([':count' => $failed, ':username' => $username]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO login_attempts (username, failed_count, last_failed_at) "
                . "VALUES (:username, 1, datetime('now'))"
            );
            $insert->execute([':username' => $username]);
        }
    });
}

function clear_login_failures(string $username): void
{
    retry_db_write(function () use ($username) {
        $pdo = db_connection();
        ensure_auth_tables($pdo);
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
        $stmt->execute([':username' => $username]);
    });
}

function retry_db_write(callable $fn): void
{
    $attempts = 0;
    $max = 3;
    $delayUs = 200000;

    while (true) {
        try {
            $fn();
            return;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'database is locked') === false) {
                throw $e;
            }
            $attempts++;
            if ($attempts >= $max) {
                throw $e;
            }
            usleep($delayUs);
            $delayUs *= 2;
        }
    }
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
    ensure_auth_tables($pdo);

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

function issue_remember_me_token(int $userId): void
{
    $lifetime = remember_me_lifetime_seconds();
    if ($lifetime <= 0) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hash = remember_token_hash($token);
    $expiresTs = time() + $lifetime;
    $expiresAt = gmdate('Y-m-d H:i:s', $expiresTs);

    retry_db_write(function () use ($userId, $hash, $expiresAt) {
        $pdo = db_connection();
        ensure_auth_tables($pdo);
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id')
            ->execute([':user_id' => $userId]);
        $pdo->prepare('DELETE FROM remember_tokens WHERE expires_at <= datetime(\'now\')')->execute();
        $insert = $pdo->prepare(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at, last_used_at) '
            . 'VALUES (:user_id, :token_hash, :expires_at, datetime(\'now\'))'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':token_hash' => $hash,
            ':expires_at' => $expiresAt,
        ]);
    });

    set_remember_cookie($token, $expiresTs);
}

function maybe_restore_user_from_remember_cookie(): bool
{
    if (isset($_SESSION['user_id'])) {
        return true;
    }

    $token = (string) ($_COOKIE[remember_cookie_name()] ?? '');
    if ($token === '') {
        return false;
    }

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        clear_remember_cookie();
        return false;
    }

    $hash = remember_token_hash($token);
    $pdo = db_connection();
    ensure_auth_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, u.username '
        . 'FROM remember_tokens t '
        . 'JOIN users u ON u.id = t.user_id '
        . 'WHERE t.token_hash = :token_hash AND t.expires_at > datetime(\'now\') '
        . 'LIMIT 1'
    );
    $stmt->execute([':token_hash' => $hash]);
    $row = $stmt->fetch();

    if (!$row) {
        clear_remember_cookie();
        return false;
    }

    $_SESSION['user_id'] = (int) $row['user_id'];

    // Rotate remember token after successful auto-login.
    issue_remember_me_token((int) $row['user_id']);
    retry_db_write(function () use ($row) {
        $pdo = db_connection();
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = :id')
            ->execute([':id' => (int) $row['token_id']]);
    });

    refresh_session_cookie();
    return true;
}

function logout_current_user(): void
{
    $token = (string) ($_COOKIE[remember_cookie_name()] ?? '');
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $hash = remember_token_hash($token);
        retry_db_write(function () use ($hash) {
            $pdo = db_connection();
            ensure_remember_tokens_table($pdo);
            $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = :token_hash')
                ->execute([':token_hash' => $hash]);
        });
    }

    clear_remember_cookie();

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => is_request_secure(),
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

function require_login($request, $handler)
{
    $path = $request->getUri()->getPath();
    if (str_ends_with($path, '/login') || str_ends_with($path, '/logout')) {
        return $handler->handle($request);
    }

    if (!isset($_SESSION['user_id'])) {
        maybe_restore_user_from_remember_cookie();
    }

    if (!isset($_SESSION['user_id'])) {
        $response = new Slim\Psr7\Response();
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('/index.php', '', $script), '/');
        return $response
            ->withHeader('Location', $base . '/login')
            ->withStatus(302);
    }

    refresh_session_cookie();
    return $handler->handle($request);
}
