<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
    $pdo = db_connection();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

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
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    return $handler->handle($request);
}
