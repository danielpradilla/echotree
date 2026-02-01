<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/summaries.php';

return function (App $app): void {
    $app->add('require_login');
    $app->add('require_csrf');

    $app->get('/', function ($request, $response) {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'home.twig', [
            'title' => 'EchoTree',
            'csrf' => csrf_token(),
        ]);
    });

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
                ->withHeader('Location', '/feeds')
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
                ->withHeader('Location', '/feeds')
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
            ->withHeader('Location', '/feeds')
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
            ->withHeader('Location', '/feeds')
            ->withStatus(302);
    });

    $app->post('/feeds/{id}/fetch', function ($request, $response, $args) {
        $feedId = (int) ($args['id'] ?? 0);
        if ($feedId > 0) {
            $cmd = 'php ' . escapeshellarg(__DIR__ . '/../scripts/fetch_feeds.php')
                . ' --refresh --feed-id=' . (int) $feedId;
            shell_exec($cmd);
        }

        return $response
            ->withHeader('Location', '/feeds')
            ->withStatus(302);
    });

    $app->get('/accounts', function ($request, $response) {
        $pdo = db_connection();
        $accounts = $pdo->query('SELECT * FROM accounts ORDER BY platform, display_name')->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'accounts/index.twig', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'csrf' => csrf_token(),
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

            $encrypted = verify_token_encryption($token);
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
                ->withHeader('Location', '/accounts')
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

            if ($token !== '') {
                $sql .= ', oauth_token_encrypted = :token';
                $fields[':token'] = verify_token_encryption($token);
            }

            $sql .= ' WHERE id = :id';
            $update = $pdo->prepare($sql);
            $update->execute($fields);

            return $response
                ->withHeader('Location', '/accounts')
                ->withStatus(302);
        }

        return $view->render($response, 'accounts/form.twig', [
            'title' => 'Edit Account',
            'account' => $account,
            'action' => "/accounts/{$accountId}/edit",
            'token_optional' => true,
            'csrf' => csrf_token(),
        ]);
    });

    $app->post('/accounts/{id}/delete', function ($request, $response, $args) {
        $accountId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $stmt = $pdo->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $accountId]);

        return $response
            ->withHeader('Location', '/accounts')
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
            ->withHeader('Location', '/accounts')
            ->withStatus(302);
    });

    $app->get('/articles', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $feedId = isset($queryParams['feed_id']) ? (int) $queryParams['feed_id'] : null;
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'reader';

        $feeds = $pdo->query('SELECT id, name FROM feeds ORDER BY name ASC')->fetchAll();

        if ($feedId) {
            $stmt = $pdo->prepare(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'WHERE a.feed_id = :feed_id '
                . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
            );
            $stmt->execute([':feed_id' => $feedId]);
        } else {
            $stmt = $pdo->query(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
            );
        }

        $articles = $stmt->fetchAll();

        $selectedArticle = null;
        if ($selectedId) {
            $sel = $pdo->prepare(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'WHERE a.id = :id'
            );
            $sel->execute([':id' => $selectedId]);
            $selectedArticle = $sel->fetch() ?: null;
        }

        $accounts = $pdo->query(
            'SELECT id, platform, display_name, handle '
            . 'FROM accounts WHERE is_active = 1 ORDER BY platform, display_name'
        )->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'articles/index.twig', [
            'title' => 'Articles',
            'articles' => $articles,
            'feeds' => $feeds,
            'active_feed_id' => $feedId,
            'selected' => $selectedArticle,
            'accounts' => $accounts,
            'mode' => $mode === 'original' ? 'original' : 'reader',
            'csrf' => csrf_token(),
        ]);
    });

    $app->post('/articles/{id}/toggle-read', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT is_read FROM articles WHERE id = :id');
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if ($article) {
            $next = ((int) $article['is_read']) === 1 ? 0 : 1;
            $update = $pdo->prepare('UPDATE articles SET is_read = :is_read WHERE id = :id');
            $update->execute([':is_read' => $next, ':id' => $articleId]);
        }

        $referer = $request->getHeaderLine('Referer');
        $redirect = $referer !== '' ? $referer : '/articles';
        return $response
            ->withHeader('Location', $redirect)
            ->withStatus(302);
    });

    $app->post('/articles/mark-all-read', function ($request, $response) {
        $data = (array) $request->getParsedBody();
        $feedId = isset($data['feed_id']) ? (int) $data['feed_id'] : null;
        $pdo = db_connection();

        if ($feedId) {
            $stmt = $pdo->prepare('UPDATE articles SET is_read = 1 WHERE feed_id = :feed_id');
            $stmt->execute([':feed_id' => $feedId]);
        } else {
            $pdo->exec('UPDATE articles SET is_read = 1');
        }

        $query = $feedId ? ('?feed_id=' . $feedId) : '';
        return $response
            ->withHeader('Location', '/articles' . $query)
            ->withStatus(302);
    });

    $app->map(['GET', 'POST'], '/share', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'reader';
        $error = null;

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $url = trim((string) ($data['url'] ?? ''));

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                $error = 'Please enter a valid URL.';
            } else {
                $exists = $pdo->prepare('SELECT id FROM articles WHERE url = :url');
                $exists->execute([':url' => $url]);
                $row = $exists->fetch();
                if ($row) {
                    $selectedId = (int) $row['id'];
                } else {
                    $feedId = null;
                    $feedStmt = $pdo->prepare('SELECT id FROM feeds WHERE url = :url');
                    $feedStmt->execute([':url' => 'manual://local']);
                    $feedRow = $feedStmt->fetch();
                    if ($feedRow) {
                        $feedId = (int) $feedRow['id'];
                    } else {
                        $insertFeed = $pdo->prepare(
                            'INSERT INTO feeds (name, url, is_active) VALUES (:name, :url, :is_active)'
                        );
                        $insertFeed->execute([
                            ':name' => 'Manual',
                            ':url' => 'manual://local',
                            ':is_active' => 0,
                        ]);
                        $feedId = (int) $pdo->lastInsertId();
                    }

                    try {
                        $client = new GuzzleHttp\Client(['timeout' => 15]);
                        $resp = $client->get($url);
                        $html = (string) $resp->getBody();
                    } catch (Throwable $e) {
                        $html = '';
                    }

                    $contentHtml = $html;
                    $contentText = trim(strip_tags($html));

                    if ($html !== '') {
                        try {
                            $config = new andreskrey\Readability\Configuration();
                            $config->setFixRelativeURLs(true);
                            $config->setOriginalURL(true);
                            $readability = new andreskrey\Readability\Readability($config);
                            $readability->parse($html);
                            $contentNode = $readability->getContent();
                            if ($contentNode) {
                                $contentHtml = $contentNode->C14N();
                                $contentText = trim(strip_tags($contentHtml));
                            }
                        } catch (Throwable $e) {
                            // Fallback to raw HTML.
                        }
                    }

                    $title = $url;
                    if ($html !== '') {
                        if (preg_match('/<title>(.*?)<\\/title>/si', $html, $matches)) {
                            $title = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
                        }
                    }

                    $insertArticle = $pdo->prepare(
                        'INSERT INTO articles (feed_id, title, url, content_html, content_text, summary, published_at) '
                        . 'VALUES (:feed_id, :title, :url, :content_html, :content_text, :summary, :published_at)'
                    );
                    $insertArticle->execute([
                        ':feed_id' => $feedId,
                        ':title' => $title !== '' ? $title : $url,
                        ':url' => $url,
                        ':content_html' => $contentHtml,
                        ':content_text' => $contentText,
                        ':summary' => null,
                        ':published_at' => null,
                    ]);

                    $selectedId = (int) $pdo->lastInsertId();
                }
            }
        }

        $selectedArticle = null;
        if ($selectedId) {
            $sel = $pdo->prepare(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'WHERE a.id = :id'
            );
            $sel->execute([':id' => $selectedId]);
            $selectedArticle = $sel->fetch() ?: null;
        }

        $accounts = $pdo->query(
            'SELECT id, platform, display_name, handle '
            . 'FROM accounts WHERE is_active = 1 ORDER BY platform, display_name'
        )->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'share.twig', [
            'title' => 'Share',
            'selected' => $selectedArticle,
            'accounts' => $accounts,
            'mode' => $mode === 'original' ? 'original' : 'reader',
            'csrf' => csrf_token(),
            'error' => $error,
        ]);
    });

    $app->get('/articles/{id}', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare(
            'SELECT a.*, f.name AS feed_name '
            . 'FROM articles a '
            . 'JOIN feeds f ON f.id = a.feed_id '
            . 'WHERE a.id = :id'
        );
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        $accounts = $pdo->query(
            'SELECT id, platform, display_name, handle '
            . 'FROM accounts WHERE is_active = 1 ORDER BY platform, display_name'
        )->fetchAll();

        $view = Twig::fromRequest($request);
        return $view->render($response, 'articles/reader.twig', [
            'title' => $article['title'],
            'article' => $article,
            'accounts' => $accounts,
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
            'csrf' => csrf_token(),
        ]);
    });

    $app->get('/articles/{id}/embed', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT title, content_html, content_text FROM articles WHERE id = :id');
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        $htmlContent = (string) $article['content_html'];
        $contentHtml = '';

        if ($htmlContent !== '') {
            try {
                $config = new andreskrey\Readability\Configuration();
                $config->setFixRelativeURLs(true);
                $config->setOriginalURL(true);
                $readability = new andreskrey\Readability\Readability($config);
                $readability->parse($htmlContent);
                $node = $readability->getContent();
                if ($node) {
                    $contentHtml = $node->C14N();
                }
            } catch (Throwable $e) {
                $contentHtml = '';
            }
        }

        if ($contentHtml === '') {
            $contentHtml = $htmlContent;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $contentHtml);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//header|//footer|//nav|//aside|//form|//button') as $node) {
            $node->parentNode->removeChild($node);
        }

        $body = '';
        foreach ($dom->getElementsByTagName('body') as $bodyNode) {
            $body = $dom->saveHTML($bodyNode);
            $body = preg_replace('/^<body>|<\\/body>$/', '', $body);
            break;
        }

        if (trim($body) === '') {
            $text = trim((string) $article['content_text']);
            $paragraphs = array_filter(preg_split("/\\R{2,}/", $text));
            foreach ($paragraphs as $para) {
                $safe = htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8');
                if ($safe !== '') {
                    $body .= '<p>' . nl2br($safe) . '</p>';
                }
            }
        }

        $html = '<!doctype html><html><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<style>body{font-family:ui-serif,Georgia,Cambria,Times New Roman,Times,serif;'
            . 'margin:0;padding:28px;line-height:1.8;color:#1e1a16;background:#fffaf2;}'
            . '.reader{max-width:720px;margin:0 auto;}'
            . 'p{margin:0 0 18px;}'
            . 'h1,h2,h3{line-height:1.3;margin:0 0 12px;}'
            . 'img,video{max-width:100%;height:auto;}</style></head><body><div class="reader">'
            . $body
            . '</div></body></html>';

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    });

    $app->post('/posts', function ($request, $response) {
        $data = (array) $request->getParsedBody();
        $articleId = (int) ($data['article_id'] ?? 0);
        $comment = trim((string) ($data['comment'] ?? ''));
        $scheduledAt = trim((string) ($data['scheduled_at'] ?? ''));
        $accountIds = array_map('intval', (array) ($data['account_ids'] ?? []));

        if ($articleId === 0 || $comment === '' || $scheduledAt === '' || count($accountIds) === 0) {
            return $response
                ->withHeader('Location', "/articles/{$articleId}?error=1")
                ->withStatus(302);
        }

        $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $scheduledAt);
        if (!$dt) {
            return $response
                ->withHeader('Location', "/articles/{$articleId}?error=1")
                ->withStatus(302);
        }

        create_scheduled_post($articleId, $comment, $scheduledAt, $accountIds);

        return $response
            ->withHeader('Location', "/articles/{$articleId}?saved=1")
            ->withStatus(302);
    });

    $app->post('/articles/{id}/summary', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT content_text, summary FROM articles WHERE id = :id');
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        if (!empty($article['summary'])) {
            $response->getBody()->write($article['summary']);
            return $response->withHeader('Content-Type', 'text/plain');
        }

        $content = (string) $article['content_text'];
        $content = mb_substr($content, 0, 12000);

        try {
            $summary = generate_summary($content);
            save_article_summary($articleId, $summary);
        } catch (Throwable $e) {
            $response->getBody()->write('Summary unavailable: ' . $e->getMessage());
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain');
        }

        $response->getBody()->write($summary);
        return $response->withHeader('Content-Type', 'text/plain');
    });

    $app->map(['GET', 'POST'], '/login', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');

            $user = authenticate($username, $password);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                return $response
                    ->withHeader('Location', '/')
                    ->withStatus(302);
            }

            return $view->render($response, 'login.twig', [
                'title' => 'Login',
                'error' => 'Invalid username or password.',
                'csrf' => csrf_token(),
            ]);
        }

        return $view->render($response, 'login.twig', [
            'title' => 'Login',
            'csrf' => csrf_token(),
        ]);
    });

    $app->get('/logout', function ($request, $response) {
        session_destroy();
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    });
};
