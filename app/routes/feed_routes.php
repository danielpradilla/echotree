<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function register_feed_routes(App $app): void
{
    $app->get('/feeds', function ($request, $response) {
        $pdo = db_connection();
        $stmt = $pdo->query('SELECT * FROM feeds ORDER BY name ASC');
        $feeds = $stmt->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'feeds/index.twig', [
            'title' => 'Feeds',
            'feeds' => $feeds,
            'csrf' => csrf_token(),
        ]);
    });

    $app->map(['GET', 'POST'], '/feeds/new', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $name = trim((string) ($data['name'] ?? ''));
            $url = trim((string) ($data['url'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($name === '' || $url === '') {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'Name and URL are required.',
                    'feed' => ['name' => $name, 'url' => $url, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                ]);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'URL must be valid.',
                    'feed' => ['name' => $name, 'url' => $url, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                ]);
            }

            $pdo = db_connection();
            $stmt = $pdo->prepare(
                'INSERT INTO feeds (name, url, is_active) VALUES (:name, :url, :is_active)'
            );
            $stmt->execute([
                ':name' => $name,
                ':url' => $url,
                ':is_active' => $isActive,
            ]);

            return $response
                ->withHeader('Location', url_for($request, '/feeds'))
                ->withStatus(302);
        }

        return $view->render($response, 'feeds/form.twig', [
            'title' => 'New Feed',
            'feed' => ['name' => '', 'url' => '', 'is_active' => 1],
            'action' => '/feeds/new',
            'csrf' => csrf_token(),
        ]);
    });

    $app->map(['GET', 'POST'], '/feeds/{id}/edit', function ($request, $response, $args) {
        $feedId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT * FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);
        $feed = $stmt->fetch();

        if (!$feed) {
            return $response->withStatus(404);
        }

        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $name = trim((string) ($data['name'] ?? ''));
            $url = trim((string) ($data['url'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($name === '' || $url === '') {
                $feed['name'] = $name;
                $feed['url'] = $url;
                $feed['is_active'] = $isActive;
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'Edit Feed',
                    'error' => 'Name and URL are required.',
                    'feed' => $feed,
                    'action' => "/feeds/{$feedId}/edit",
                    'csrf' => csrf_token(),
                ]);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $feed['name'] = $name;
                $feed['url'] = $url;
                $feed['is_active'] = $isActive;
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'Edit Feed',
                    'error' => 'URL must be valid.',
                    'feed' => $feed,
                    'action' => "/feeds/{$feedId}/edit",
                    'csrf' => csrf_token(),
                ]);
            }

            $update = $pdo->prepare(
                'UPDATE feeds SET name = :name, url = :url, is_active = :is_active WHERE id = :id'
            );
            $update->execute([
                ':name' => $name,
                ':url' => $url,
                ':is_active' => $isActive,
                ':id' => $feedId,
            ]);

            return $response
                ->withHeader('Location', url_for($request, '/feeds'))
                ->withStatus(302);
        }

        return $view->render($response, 'feeds/form.twig', [
            'title' => 'Edit Feed',
            'feed' => $feed,
            'action' => "/feeds/{$feedId}/edit",
            'csrf' => csrf_token(),
        ]);
    });

    $app->post('/feeds/{id}/delete', function ($request, $response, $args) {
        $feedId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $stmt = $pdo->prepare('DELETE FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);

        return $response
            ->withHeader('Location', url_for($request, '/feeds'))
            ->withStatus(302);
    });

    $app->post('/feeds/{id}/toggle', function ($request, $response, $args) {
        $feedId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $stmt = $pdo->prepare('SELECT is_active FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);
        $feed = $stmt->fetch();

        if ($feed) {
            $next = ((int) $feed['is_active']) === 1 ? 0 : 1;
            $update = $pdo->prepare('UPDATE feeds SET is_active = :active WHERE id = :id');
            $update->execute([':active' => $next, ':id' => $feedId]);
        }

        return $response
            ->withHeader('Location', url_for($request, '/feeds'))
            ->withStatus(302);
    });

    $app->post('/feeds/{id}/fetch', function ($request, $response, $args) {
        $feedId = (int) ($args['id'] ?? 0);
        if ($feedId > 0) {
            $pdo = db_connection();
            fetch_feeds($pdo, [
                'refresh' => true,
                'feed_id' => $feedId,
            ]);
        }

        return $response
            ->withHeader('Location', url_for($request, '/feeds'))
            ->withStatus(302);
    });
}
