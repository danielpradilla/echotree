<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function register_account_routes(App $app): void
{
    $app->get('/accounts', function ($request, $response) {
        $pdo = db_connection();
        $accounts = $pdo->query('SELECT * FROM accounts ORDER BY platform, display_name')->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'accounts/index.twig', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'csrf' => csrf_token(),
            'oauth_callback' => getenv('ECHOTREE_OAUTH_CALLBACK') ?: 'https://danielpradilla.info/oauth/callback',
        ]);
    });

    $app->map(['GET', 'POST'], '/accounts/new', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $platform = trim((string) ($data['platform'] ?? ''));
            $displayName = trim((string) ($data['display_name'] ?? ''));
            $handle = trim((string) ($data['handle'] ?? ''));
            $token = trim((string) ($data['oauth_token'] ?? ''));
            $tokenSecret = trim((string) ($data['oauth_token_secret'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($platform === '' || $displayName === '' || $handle === '' || $token === '') {
                return $view->render($response, 'accounts/form.twig', [
                    'title' => 'New Account',
                    'error' => 'All fields are required.',
                    'account' => [
                        'platform' => $platform,
                        'display_name' => $displayName,
                        'handle' => $handle,
                        'is_active' => $isActive,
                    ],
                    'action' => '/accounts/new',
                    'csrf' => csrf_token(),
                ]);
            }

            $storedToken = $token;
            if ($tokenSecret !== '') {
                $storedToken = json_encode([
                    'token' => $token,
                    'secret' => $tokenSecret,
                    'type' => 'oauth1',
                ]);
            }
            $encrypted = verify_token_encryption($storedToken);
            $pdo = db_connection();
            $stmt = $pdo->prepare(
                'INSERT INTO accounts (platform, display_name, handle, oauth_token_encrypted, is_active) '
                . 'VALUES (:platform, :display_name, :handle, :token, :is_active)'
            );
            $stmt->execute([
                ':platform' => $platform,
                ':display_name' => $displayName,
                ':handle' => $handle,
                ':token' => $encrypted,
                ':is_active' => $isActive,
            ]);

            return $response
                ->withHeader('Location', url_for($request, '/accounts'))
                ->withStatus(302);
        }

        return $view->render($response, 'accounts/form.twig', [
            'title' => 'New Account',
            'account' => [
                'platform' => '',
                'display_name' => '',
                'handle' => '',
                'is_active' => 1,
            ],
            'action' => '/accounts/new',
            'csrf' => csrf_token(),
        ]);
    });

    $app->map(['GET', 'POST'], '/accounts/{id}/edit', function ($request, $response, $args) {
        $accountId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();

        if (!$account) {
            return $response->withStatus(404);
        }

        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $platform = trim((string) ($data['platform'] ?? ''));
            $displayName = trim((string) ($data['display_name'] ?? ''));
            $handle = trim((string) ($data['handle'] ?? ''));
            $token = trim((string) ($data['oauth_token'] ?? ''));
            $tokenSecret = trim((string) ($data['oauth_token_secret'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($platform === '' || $displayName === '' || $handle === '') {
                $account['platform'] = $platform;
                $account['display_name'] = $displayName;
                $account['handle'] = $handle;
                $account['is_active'] = $isActive;
                return $view->render($response, 'accounts/form.twig', [
                    'title' => 'Edit Account',
                    'error' => 'Platform, display name, and handle are required.',
                    'account' => $account,
                    'action' => "/accounts/{$accountId}/edit",
                    'csrf' => csrf_token(),
                ]);
            }

            $fields = [
                ':platform' => $platform,
                ':display_name' => $displayName,
                ':handle' => $handle,
                ':is_active' => $isActive,
                ':id' => $accountId,
            ];

            $sql = 'UPDATE accounts SET platform = :platform, display_name = :display_name, '
                . 'handle = :handle, is_active = :is_active';

            if ($token !== '' || $tokenSecret !== '') {
                $sql .= ', oauth_token_encrypted = :token';
                $storedToken = $token;
                if ($tokenSecret !== '') {
                    $storedToken = json_encode([
                        'token' => $token,
                        'secret' => $tokenSecret,
                        'type' => 'oauth1',
                    ]);
                }
                $fields[':token'] = verify_token_encryption($storedToken);
            }

            $sql .= ' WHERE id = :id';
            $update = $pdo->prepare($sql);
            $update->execute($fields);

            return $response
                ->withHeader('Location', url_for($request, '/accounts'))
                ->withStatus(302);
        }

        return $view->render($response, 'accounts/form.twig', [
            'title' => 'Edit Account',
            'account' => $account,
            'action' => "/accounts/{$accountId}/edit",
            'token_optional' => true,
            'csrf' => csrf_token(),
            'base_path' => base_path($request),
        ]);
    });

    $app->post('/accounts/{id}/delete', function ($request, $response, $args) {
        $accountId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $stmt = $pdo->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $accountId]);

        return $response
            ->withHeader('Location', url_for($request, '/accounts'))
            ->withStatus(302);
    });

    $app->post('/accounts/{id}/toggle', function ($request, $response, $args) {
        $accountId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $stmt = $pdo->prepare('SELECT is_active FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch();

        if ($account) {
            $next = ((int) $account['is_active']) === 1 ? 0 : 1;
            $update = $pdo->prepare('UPDATE accounts SET is_active = :active WHERE id = :id');
            $update->execute([':active' => $next, ':id' => $accountId]);
        }

        return $response
            ->withHeader('Location', url_for($request, '/accounts'))
            ->withStatus(302);
    });
}
