<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_check($request): void
{
    $method = strtoupper($request->getMethod());
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $parsed = (array) $request->getParsedBody();
    $token = (string) ($parsed['_csrf'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function require_csrf($request, $handler)
{
    try {
        csrf_check($request);
    } catch (Throwable $e) {
        $response = new Slim\Psr7\Response();
        $response->getBody()->write('Bad Request');
        return $response->withStatus(400);
    }

    return $handler->handle($request);
}
