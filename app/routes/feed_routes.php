<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function find_feed_by_url(PDO $pdo, string $url, ?int $excludeId = null): ?array
{
    $sql = 'SELECT id, name, url FROM feeds WHERE url = :url';
    $params = [':url' => $url];

    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $feed = $stmt->fetch();
    return $feed ?: null;
}

function suggest_feed_title_from_url(string $feedUrl): ?string
{
    $feedUrl = trim($feedUrl);
    if ($feedUrl === '' || !filter_var($feedUrl, FILTER_VALIDATE_URL)) {
        return null;
    }

    $sp = new SimplePie();
    $sp->set_feed_url($feedUrl);
    $sp->enable_cache(false);
    $sp->set_timeout(10);

    if (!$sp->init()) {
        return null;
    }

    $title = trim((string) $sp->get_title());
    return $title !== '' ? $title : null;
}

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
    $bodyNodes = $xpath->query('/opml/body');
    $body = ($bodyNodes instanceof DOMNodeList && $bodyNodes->length > 0) ? $bodyNodes->item(0) : null;
    if (!($body instanceof DOMElement)) {
        throw new RuntimeException('No feeds were found in the OPML file.');
    }

    $feeds = [];
    $walk = static function (DOMElement $node, ?string $folderName) use (&$walk, &$feeds): void {
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement) || strtolower($child->tagName) !== 'outline') {
                continue;
            }

            $url = trim((string) $child->getAttribute('xmlUrl'));
            $title = trim((string) $child->getAttribute('title'));
            $text = trim((string) $child->getAttribute('text'));
            $label = $title !== '' ? $title : $text;

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $feeds[$url] = [
                    'name' => $label !== '' ? $label : $url,
                    'url' => $url,
                    'folder_name' => $folderName,
                ];
                continue;
            }

            $nextFolder = $label !== '' ? $label : $folderName;
            $walk($child, $nextFolder);
        }
    };
    $walk($body, null);

    if (count($feeds) === 0) {
        throw new RuntimeException('The OPML file did not contain any valid feed URLs.');
    }

    return array_values($feeds);
}

function import_opml_feeds(PDO $pdo, array $feeds): array
{
    $insert = $pdo->prepare(
        'INSERT INTO feeds (name, url, folder_name, is_active) VALUES (:name, :url, :folder_name, :is_active)'
    );
    $exists = $pdo->prepare('SELECT id FROM feeds WHERE url = :url');

    $imported = 0;
    $skipped = 0;

    foreach ($feeds as $feed) {
        $url = (string) ($feed['url'] ?? '');
        $name = trim((string) ($feed['name'] ?? ''));
        $folderName = trim((string) ($feed['folder_name'] ?? ''));
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
            ':folder_name' => $folderName !== '' ? $folderName : null,
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
    $resolveRedirectTarget = static function ($request, string $fallback, string $status = ''): string {
        $data = (array) $request->getParsedBody();
        $target = trim((string) ($data['return_to'] ?? ''));
        if ($target === '') {
            $target = url_for($request, $fallback);
        }

        if ($status === '') {
            return $target;
        }

        $sep = str_contains($target, '?') ? '&' : '?';
        return $target . $sep . 'status=' . rawurlencode($status);
    };

    $app->get('/feeds', function ($request, $response) {
        $pdo = db_connection();
        $stmt = $pdo->query(
            "SELECT * FROM feeds "
            . "ORDER BY CASE WHEN TRIM(COALESCE(folder_name, '')) = '' THEN 1 ELSE 0 END ASC, "
            . "LOWER(COALESCE(folder_name, '')) ASC, LOWER(name) ASC"
        );
        $feeds = $stmt->fetchAll();
        $feeds = array_map(static function (array $feed): array {
            $feed['is_stale'] = is_feed_stale((string) ($feed['last_fetched_at'] ?? ''));
            return $feed;
        }, $feeds);

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

    $app->post('/feeds/bulk-folder', function ($request, $response) {
        $data = (array) $request->getParsedBody();
        $rawIds = $data['feed_ids'] ?? [];
        $folderName = trim((string) ($data['folder_name'] ?? ''));
        $folderAction = trim((string) ($data['folder_action'] ?? 'assign'));

        $feedIds = array_values(array_filter(array_map('intval', is_array($rawIds) ? $rawIds : []), static fn (int $id): bool => $id > 0));
        if ($feedIds === []) {
            return $response
                ->withHeader('Location', url_for($request, '/feeds?error=no_feeds_selected'))
                ->withStatus(302);
        }

        $targetFolder = $folderAction === 'clear' ? null : ($folderName !== '' ? $folderName : null);
        if ($folderAction !== 'clear' && $targetFolder === null) {
            return $response
                ->withHeader('Location', url_for($request, '/feeds?error=folder_required'))
                ->withStatus(302);
        }

        $pdo = db_connection();
        $placeholders = implode(', ', array_fill(0, count($feedIds), '?'));
        $params = array_merge([$targetFolder], $feedIds);
        $update = $pdo->prepare("UPDATE feeds SET folder_name = ? WHERE id IN ({$placeholders})");
        $update->execute($params);

        return $response
            ->withHeader('Location', url_for($request, '/feeds?status=bulk_folder_updated'))
            ->withStatus(302);
    });

    $app->get('/feeds/title-suggest', function ($request, $response) {
        $url = trim((string) ($request->getQueryParams()['url'] ?? ''));
        $title = suggest_feed_title_from_url($url);

        $payload = [
            'ok' => $title !== null,
            'title' => $title,
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
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

    $app->post('/feeds/fetch-all', function ($request, $response) use ($resolveRedirectTarget) {
        $status = launch_feed_fetcher_in_background() ? 'fetch_started' : 'fetch_failed';
        $target = $resolveRedirectTarget($request, '/feeds', $status);

        return $response
            ->withHeader('Location', $target)
            ->withStatus(302);
    });

    $app->map(['GET', 'POST'], '/feeds/new', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $name = trim((string) ($data['name'] ?? ''));
            $url = trim((string) ($data['url'] ?? ''));
            $folderName = trim((string) ($data['folder_name'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($url === '') {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'URL is required.',
                    'feed' => ['name' => $name, 'url' => $url, 'folder_name' => $folderName, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
                ]);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'URL must be valid.',
                    'feed' => ['name' => $name, 'url' => $url, 'folder_name' => $folderName, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
                ]);
            }

            if ($name === '') {
                $name = suggest_feed_title_from_url($url) ?? $url;
            }

            $pdo = db_connection();
            $existingFeed = find_feed_by_url($pdo, $url);
            if ($existingFeed !== null) {
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'New Feed',
                    'error' => 'This feed already exists: ' . (string) ($existingFeed['name'] ?? $url),
                    'feed' => ['name' => $name, 'url' => $url, 'folder_name' => $folderName, 'is_active' => $isActive],
                    'action' => '/feeds/new',
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
                ]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO feeds (name, url, folder_name, is_active) VALUES (:name, :url, :folder_name, :is_active)'
            );
            $stmt->execute([
                ':name' => $name,
                ':url' => $url,
                ':folder_name' => $folderName !== '' ? $folderName : null,
                ':is_active' => $isActive,
            ]);

            return $response
                ->withHeader('Location', url_for($request, '/feeds'))
                ->withStatus(302);
        }

        return $view->render($response, 'feeds/form.twig', [
            'title' => 'New Feed',
            'feed' => ['name' => '', 'url' => '', 'folder_name' => '', 'is_active' => 1],
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
            $folderName = trim((string) ($data['folder_name'] ?? ''));
            $isActive = isset($data['is_active']) ? 1 : 0;

            if ($url === '') {
                $feed['name'] = $name;
                $feed['url'] = $url;
                $feed['folder_name'] = $folderName;
                $feed['is_active'] = $isActive;
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'Edit Feed',
                    'error' => 'URL is required.',
                    'feed' => $feed,
                    'action' => "/feeds/{$feedId}/edit",
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
                ]);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $feed['name'] = $name;
                $feed['url'] = $url;
                $feed['folder_name'] = $folderName;
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

            if ($name === '') {
                $name = suggest_feed_title_from_url($url) ?? $url;
            }

            $existingFeed = find_feed_by_url($pdo, $url, $feedId);
            if ($existingFeed !== null) {
                $feed['name'] = $name;
                $feed['url'] = $url;
                $feed['folder_name'] = $folderName;
                $feed['is_active'] = $isActive;
                return $view->render($response, 'feeds/form.twig', [
                    'title' => 'Edit Feed',
                    'error' => 'This feed already exists: ' . (string) ($existingFeed['name'] ?? $url),
                    'feed' => $feed,
                    'action' => "/feeds/{$feedId}/edit",
                    'csrf' => csrf_token(),
                    'base_path' => base_path($request),
                ]);
            }

            $update = $pdo->prepare(
                'UPDATE feeds SET name = :name, url = :url, folder_name = :folder_name, is_active = :is_active WHERE id = :id'
            );
            $update->execute([
                ':name' => $name,
                ':url' => $url,
                ':folder_name' => $folderName !== '' ? $folderName : null,
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

    $app->post('/feeds/{id}/fetch', function ($request, $response, $args) use ($resolveRedirectTarget) {
        $feedId = (int) ($args['id'] ?? 0);
        $status = 'fetch_failed';
        if ($feedId > 0) {
            $pdo = db_connection();
            $result = fetch_feeds($pdo, [
                'refresh' => true,
                'feed_id' => $feedId,
            ]);
            $feedResult = $result['feeds'][0] ?? null;
            if ($feedResult) {
                $feedStatus = (string) ($feedResult['status'] ?? '');
                if (in_array($feedStatus, ['added', 'refreshed'], true)) {
                    $status = 'fetch_complete';
                } elseif (in_array($feedStatus, ['checked', 'empty'], true)) {
                    $status = 'fetch_checked';
                }
            }
        }

        $target = $resolveRedirectTarget($request, '/feeds', $status);

        return $response
            ->withHeader('Location', $target)
            ->withStatus(302);
    });
}
