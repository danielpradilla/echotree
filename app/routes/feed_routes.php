<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function launch_feed_fetcher_in_background(): bool
{
    $baseDir = dirname(__DIR__, 2);
    $logsDir = $baseDir . '/logs';
    if (!is_dir($logsDir) && !mkdir($logsDir, 0755, true) && !is_dir($logsDir)) {
        return false;
    }

    $phpBin = getenv('ECHOTREE_PHP_BIN') ?: PHP_BINARY;
    if (!is_string($phpBin) || $phpBin === '') {
        $phpBin = '/usr/bin/php';
    }

    $maxFeeds = (int) (getenv('ECHOTREE_MANUAL_FETCH_ALL_MAX_FEEDS') ?: 12);
    if ($maxFeeds < 1) {
        $maxFeeds = 12;
    }

    $command = sprintf(
        'cd %s && nohup %s scripts/fetch_feeds.php --max-feeds=%d --skip-extraction >> %s 2>&1 &',
        escapeshellarg($baseDir),
        escapeshellarg($phpBin),
        $maxFeeds,
        escapeshellarg($logsDir . '/fetch_feeds.log')
    );

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        exec($command);
        return true;
    }

    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        shell_exec($command);
        return true;
    }

    return false;
}

function parse_opml_feeds(string $xml): array
{
    if (trim($xml) === '') {
        throw new RuntimeException('The uploaded file is empty.');
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $message = isset($errors[0]) ? trim((string) $errors[0]->message) : 'Invalid XML document.';
        throw new RuntimeException('Could not parse OPML: ' . $message);
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//outline[@xmlUrl]');
    if (!($nodes instanceof DOMNodeList) || $nodes->length === 0) {
        throw new RuntimeException('No feeds were found in the OPML file.');
    }

    $feeds = [];
    foreach ($nodes as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }

        $url = trim((string) $node->getAttribute('xmlUrl'));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }

        $title = trim((string) $node->getAttribute('title'));
        $text = trim((string) $node->getAttribute('text'));
        $name = $title !== '' ? $title : ($text !== '' ? $text : $url);

        $feeds[$url] = [
            'name' => $name,
            'url' => $url,
        ];
    }

    if (count($feeds) === 0) {
        throw new RuntimeException('The OPML file did not contain any valid feed URLs.');
    }

    return array_values($feeds);
}

function import_opml_feeds(PDO $pdo, array $feeds): array
{
    $insert = $pdo->prepare(
        'INSERT INTO feeds (name, url, is_active) VALUES (:name, :url, :is_active)'
    );
    $exists = $pdo->prepare('SELECT id FROM feeds WHERE url = :url');

    $imported = 0;
    $skipped = 0;

    foreach ($feeds as $feed) {
        $url = (string) ($feed['url'] ?? '');
        $name = trim((string) ($feed['name'] ?? ''));
        if ($url === '') {
            $skipped++;
            continue;
        }

        $exists->execute([':url' => $url]);
        if ($exists->fetch()) {
            $skipped++;
            continue;
        }

        $insert->execute([
            ':name' => $name !== '' ? $name : $url,
            ':url' => $url,
            ':is_active' => 1,
        ]);
        $imported++;
    }

    return [
        'imported' => $imported,
        'skipped' => $skipped,
    ];
}

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
            'base_path' => base_path($request),
            'status' => (string) ($request->getQueryParams()['status'] ?? ''),
            'imported' => (int) ($request->getQueryParams()['imported'] ?? 0),
            'skipped' => (int) ($request->getQueryParams()['skipped'] ?? 0),
            'error' => (string) ($request->getQueryParams()['error'] ?? ''),
        ]);
    });

    $app->post('/feeds/import', function ($request, $response) {
        $files = $request->getUploadedFiles();
        $upload = $files['opml_file'] ?? null;

        if ($upload === null || $upload->getError() !== UPLOAD_ERR_OK) {
            return $response
                ->withHeader('Location', url_for($request, '/feeds?error=upload_failed'))
                ->withStatus(302);
        }

        $stream = $upload->getStream();
        $stream->rewind();
        $contents = $stream->getContents();

        try {
            $feeds = parse_opml_feeds($contents);
            $result = import_opml_feeds(db_connection(), $feeds);
        } catch (Throwable $e) {
            return $response
                ->withHeader('Location', url_for($request, '/feeds?error=invalid_opml'))
                ->withStatus(302);
        }

        return $response
            ->withHeader(
                'Location',
                url_for($request, '/feeds?status=imported&imported=' . $result['imported'] . '&skipped=' . $result['skipped'])
            )
            ->withStatus(302);
    });

    $app->post('/feeds/fetch-all', function ($request, $response) {
        $status = launch_feed_fetcher_in_background() ? 'fetch_started' : 'fetch_failed';

        return $response
            ->withHeader('Location', url_for($request, '/feeds?status=' . $status))
            ->withStatus(302);
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
                    'base_path' => base_path($request),
                ]);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'URL must be valid.',
                    'feed' => ['name' => $name, 'url' => $url, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
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
            'base_path' => base_path($request),
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
                    'base_path' => base_path($request),
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
                    'base_path' => base_path($request),
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
            'base_path' => base_path($request),
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
