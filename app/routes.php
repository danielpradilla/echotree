<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/summaries.php';
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/feed_fetcher.php';

function base_path($request): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return rtrim(str_replace('/index.php', '', $script), '/');
}

function url_for($request, string $path): string
{
    return base_path($request) . $path;
}

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

    $app->get('/articles', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $feedId = isset($queryParams['feed_id']) ? (int) $queryParams['feed_id'] : null;
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'reader';

        $feeds = $pdo->query("SELECT id, name FROM feeds WHERE url != 'manual://local' ORDER BY name ASC")->fetchAll();

        if ($feedId) {
            $stmt = $pdo->prepare(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'WHERE a.feed_id = :feed_id AND f.url != :manual_url '
                . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
            );
            $stmt->execute([':feed_id' => $feedId, ':manual_url' => 'manual://local']);
        } else {
            $stmt = $pdo->prepare(
                'SELECT a.*, f.name AS feed_name '
                . 'FROM articles a '
                . 'JOIN feeds f ON f.id = a.feed_id '
                . 'WHERE f.url != :manual_url '
                . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
            );
            $stmt->execute([':manual_url' => 'manual://local']);
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

        $postDetails = $_SESSION['last_post_details'] ?? null;
        unset($_SESSION['last_post_details'], $_SESSION['last_post_status']);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'articles/index.twig', [
            'title' => 'Articles',
            'articles' => $articles,
            'feeds' => $feeds,
            'active_feed_id' => $feedId,
            'selected' => $selectedArticle,
            'accounts' => $accounts,
            'mode' => $mode === 'original' ? 'original' : 'reader',
            'status' => $queryParams['status'] ?? null,
            'error' => $queryParams['error'] ?? null,
            'post_details' => $postDetails,
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'base_path' => base_path($request),
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
            if ($next === 1) {
                $delete = $pdo->prepare('DELETE FROM articles WHERE id = :id');
                $delete->execute([':id' => $articleId]);
            } else {
                $update = $pdo->prepare('UPDATE articles SET is_read = :is_read WHERE id = :id');
                $update->execute([':is_read' => $next, ':id' => $articleId]);
            }
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
            $stmt = $pdo->prepare('DELETE FROM articles WHERE feed_id = :feed_id');
            $stmt->execute([':feed_id' => $feedId]);
        } else {
            $pdo->exec('DELETE FROM articles');
        }

        $query = $feedId ? ('?feed_id=' . $feedId) : '';
        return $response
            ->withHeader('Location', url_for($request, '/articles' . $query))
            ->withStatus(302);
    });

    $app->map(['GET', 'POST'], '/share', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'reader';
        $error = null;
        $urlFromQuery = trim((string) ($queryParams['url'] ?? ''));

        if ($request->getMethod() === 'POST' || $urlFromQuery !== '') {
            $data = (array) $request->getParsedBody();
            $url = $urlFromQuery !== '' ? $urlFromQuery : trim((string) ($data['url'] ?? ''));

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

        $postDetails = $_SESSION['last_post_details'] ?? null;
        unset($_SESSION['last_post_details'], $_SESSION['last_post_status']);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'share.twig', [
            'title' => 'Share',
            'selected' => $selectedArticle,
            'accounts' => $accounts,
            'mode' => $mode === 'original' ? 'original' : 'reader',
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'error' => $error,
            'status' => $queryParams['status'] ?? null,
            'post_details' => $postDetails,
            'base_path' => base_path($request),
        ]);
    });

    $app->get('/oauth/{platform}/start', function ($request, $response, $args) {
        $platform = strtolower((string) ($args['platform'] ?? ''));
        $state = oauth_random_string(24);
        $redirectUri = getenv('ECHOTREE_OAUTH_CALLBACK') ?: 'https://danielpradilla.info/oauth/callback';
        $view = Twig::fromRequest($request);

        if ($platform === 'mastodon') {
            $baseUrl = getenv('ECHOTREE_MASTODON_BASE_URL') ?: '';
            $clientId = getenv('ECHOTREE_MASTODON_CLIENT_ID') ?: '';
            if ($baseUrl === '' || $clientId === '') {
        return $view->render($response, 'oauth/error.twig', [
            'title' => 'Mastodon',
            'message' => 'Missing ECHOTREE_MASTODON_BASE_URL or ECHOTREE_MASTODON_CLIENT_ID.',
            'base_path' => base_path($request),
        ])->withStatus(400);
            }

            oauth_save_state('mastodon', ['state' => $state]);
            $url = rtrim($baseUrl, '/') . '/oauth/authorize'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode('read write')
                . '&state=' . rawurlencode($state);

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if ($platform === 'x') {
            $clientId = getenv('ECHOTREE_X_CLIENT_ID') ?: '';
            if ($clientId === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Missing ECHOTREE_X_CLIENT_ID.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $codeVerifier = oauth_random_string(64);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            oauth_save_state('x', [
                'state' => $state,
                'code_verifier' => $codeVerifier,
            ]);

            $scope = 'tweet.write users.read offline.access';
            $url = 'https://twitter.com/i/oauth2/authorize'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode($scope)
                . '&state=' . rawurlencode($state)
                . '&code_challenge=' . rawurlencode($codeChallenge)
                . '&code_challenge_method=S256';

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        if ($platform === 'linkedin') {
            $clientId = getenv('ECHOTREE_LINKEDIN_CLIENT_ID') ?: '';
            if ($clientId === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Missing ECHOTREE_LINKEDIN_CLIENT_ID.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            oauth_save_state('linkedin', ['state' => $state]);
            $scope = 'r_liteprofile w_member_social';
            $url = 'https://www.linkedin.com/oauth/v2/authorization'
                . '?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode($scope)
                . '&state=' . rawurlencode($state);

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        return $view->render($response, 'oauth/error.twig', [
            'title' => 'OAuth',
            'message' => 'Unknown platform.',
            'base_path' => base_path($request),
        ])->withStatus(404);
    });

    $app->map(['GET', 'POST'], '/oauth/bluesky', function ($request, $response) {
        $error = null;

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $handle = trim((string) ($data['handle'] ?? ''));
            $password = trim((string) ($data['app_password'] ?? ''));
            $password = str_replace(' ', '', $password);
            $pds = trim((string) ($data['pds'] ?? ''));

            if ($handle === '' || $password === '') {
                $error = 'Handle and app password are required.';
            } else {
                try {
                    $client = new GuzzleHttp\Client(['timeout' => 15]);
                    if ($pds === '') {
                        $resolve = $client->get('https://bsky.social/xrpc/com.atproto.identity.resolveHandle', [
                            'query' => ['handle' => $handle],
                        ]);
                        $resolveData = json_decode((string) $resolve->getBody(), true);
                        $did = (string) ($resolveData['did'] ?? '');
                        if ($did !== '') {
                            $doc = $client->get('https://plc.directory/' . rawurlencode($did));
                            $docData = json_decode((string) $doc->getBody(), true);
                            $services = $docData['service'] ?? [];
                            foreach ($services as $service) {
                                if (($service['type'] ?? '') === 'AtprotoPersonalDataServer') {
                                    $pds = (string) ($service['serviceEndpoint'] ?? '');
                                    break;
                                }
                            }
                        }
                    }

                    if ($pds === '') {
                        $pds = getenv('ECHOTREE_BLUESKY_PDS') ?: 'https://bsky.social';
                    }

                    $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.server.createSession', [
                        'json' => [
                            'identifier' => $handle,
                            'password' => $password,
                        ],
                    ]);
                    $data = json_decode((string) $resp->getBody(), true);
                    $token = (string) ($data['accessJwt'] ?? '');
                    $refresh = (string) ($data['refreshJwt'] ?? '');
                    $displayName = (string) ($data['handle'] ?? $handle);
                    if ($token === '' || $refresh === '') {
                        $error = 'Failed to create Bluesky session.';
                    } else {
                        $payload = json_encode([
                            'type' => 'bluesky',
                            'access' => $token,
                            'refresh' => $refresh,
                        ]);
                        oauth_upsert_account('bluesky', $displayName, $handle, $payload);
                        return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
                    }
                } catch (GuzzleHttp\Exception\RequestException $e) {
                    $body = '';
                    if ($e->hasResponse()) {
                        $body = (string) $e->getResponse()->getBody();
                    }
                    $details = $body !== '' ? $body : $e->getMessage();
                    $error = 'Bluesky auth failed (' . $pds . '): ' . $details;
                } catch (GuzzleHttp\Exception\GuzzleException $e) {
                    $error = 'Bluesky auth failed (' . $pds . '): ' . $e->getMessage();
                } catch (Throwable $e) {
                    $error = 'Bluesky auth failed: ' . $e->getMessage();
                }
            }
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'oauth/bluesky.twig', [
            'title' => 'Bluesky',
            'csrf' => csrf_token(),
            'error' => $error,
            'pds' => getenv('ECHOTREE_BLUESKY_PDS') ?: 'https://bsky.social',
            'base_path' => base_path($request),
        ]);
    });

    $app->get('/oauth/callback', function ($request, $response) {
        $query = $request->getQueryParams();
        $code = (string) ($query['code'] ?? '');
        $state = (string) ($query['state'] ?? '');
        $redirectUri = getenv('ECHOTREE_OAUTH_CALLBACK') ?: 'https://danielpradilla.info/oauth/callback';
        $view = Twig::fromRequest($request);

        if ($code === '' || $state === '') {
            return $view->render($response, 'oauth/error.twig', [
                'title' => 'OAuth',
                'message' => 'Missing authorization code or state.',
                'base_path' => base_path($request),
            ])->withStatus(400);
        }

        if (oauth_get_state('x') && ($state === oauth_get_state('x')['state'])) {
            $clientId = getenv('ECHOTREE_X_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_X_CLIENT_SECRET') ?: '';
            $verifier = oauth_get_state('x')['code_verifier'] ?? '';
            oauth_clear_state('x');

            if ($clientId === '' || $verifier === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Missing client ID or PKCE verifier.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ];
            if ($clientSecret !== '') {
                $basic = base64_encode($clientId . ':' . $clientSecret);
                $headers['Authorization'] = 'Basic ' . $basic;
            }
            $resp = $client->post('https://api.twitter.com/2/oauth2/token', [
                'headers' => $headers,
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                    'code_verifier' => $verifier,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'X',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $username = '';
            $name = '';
            try {
                $me = $client->get('https://api.twitter.com/2/users/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);
                $meData = json_decode((string) $me->getBody(), true);
                $username = (string) ($meData['data']['username'] ?? '');
                $name = (string) ($meData['data']['name'] ?? '');
            } catch (Throwable $e) {
                $username = 'x-user';
                $name = 'X account';
            }
            if ($username === '') {
                $username = 'x-user';
            }

            oauth_upsert_account('twitter', $name !== '' ? $name : $username, $username, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        if (oauth_get_state('linkedin') && ($state === oauth_get_state('linkedin')['state'])) {
            $clientId = getenv('ECHOTREE_LINKEDIN_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_LINKEDIN_CLIENT_SECRET') ?: '';
            oauth_clear_state('linkedin');

            if ($clientId === '' || $clientSecret === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Missing client ID or client secret.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $resp = $client->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'LinkedIn',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $me = $client->get('https://api.linkedin.com/v2/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $meData = json_decode((string) $me->getBody(), true);
            $id = (string) ($meData['id'] ?? '');
            $localized = $meData['localizedFirstName'] ?? '';
            $localizedLast = $meData['localizedLastName'] ?? '';
            $display = trim($localized . ' ' . $localizedLast);
            $handle = $id !== '' ? $id : 'linkedin-user';

            oauth_upsert_account('linkedin', $display !== '' ? $display : $handle, $handle, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        if (oauth_get_state('mastodon') && ($state === oauth_get_state('mastodon')['state'])) {
            $baseUrl = getenv('ECHOTREE_MASTODON_BASE_URL') ?: '';
            $clientId = getenv('ECHOTREE_MASTODON_CLIENT_ID') ?: '';
            $clientSecret = getenv('ECHOTREE_MASTODON_CLIENT_SECRET') ?: '';
            oauth_clear_state('mastodon');

            if ($baseUrl === '' || $clientId === '' || $clientSecret === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'Mastodon',
                    'message' => 'Missing base URL or client credentials.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $client = new GuzzleHttp\Client(['timeout' => 15]);
            $resp = $client->post(rtrim($baseUrl, '/') . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'read write',
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return $view->render($response, 'oauth/error.twig', [
                    'title' => 'Mastodon',
                    'message' => 'Token exchange failed.',
                    'base_path' => base_path($request),
                ])->withStatus(400);
            }

            $me = $client->get(rtrim($baseUrl, '/') . '/api/v1/accounts/verify_credentials', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            $meData = json_decode((string) $me->getBody(), true);
            $handle = (string) ($meData['acct'] ?? 'mastodon-user');
            $display = (string) ($meData['display_name'] ?? $handle);
            oauth_upsert_account('mastodon', $display, $handle, $token);
            return $response->withHeader('Location', url_for($request, '/accounts'))->withStatus(302);
        }

        return $view->render($response, 'oauth/error.twig', [
            'title' => 'OAuth',
            'message' => 'Invalid or expired state.',
            'base_path' => base_path($request),
        ])->withStatus(400);
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
            'submit_token' => create_post_submit_token(),
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
        $action = trim((string) ($data['action'] ?? 'schedule'));
        $returnTo = trim((string) ($data['return_to'] ?? ''));
        $submitToken = trim((string) ($data['_submit_token'] ?? ''));
        $accountIds = array_map('intval', (array) ($data['account_ids'] ?? []));
        $target = $returnTo !== '' ? $returnTo : "/articles/{$articleId}";
        $sep = str_contains($target, '?') ? '&' : '?';

        if (!consume_post_submit_token($submitToken)) {
            return $response
                ->withHeader('Location', $target . $sep . 'status=duplicate_ignored')
                ->withStatus(302);
        }

        if ($articleId === 0 || $comment === '' || count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $target . $sep . 'error=1')
                ->withStatus(302);
        }

        if ($action === 'now') {
            $scheduledAt = date('Y-m-d H:i:s');
        } else {
            $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $scheduledAt);
            if (!$dt) {
                return $response
                    ->withHeader('Location', $target . $sep . 'error=1')
                    ->withStatus(302);
            }
            $scheduledAt = $dt->format('Y-m-d H:i:s');
        }

        $postId = create_scheduled_post($articleId, $comment, $scheduledAt, $accountIds);

        if ($action === 'now') {
            publish_post_now($postId);
        }

        $status = 'scheduled';
        $pdo = db_connection();

        if ($action === 'now') {
            $statusRows = $pdo->prepare(
                'SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status'
            );
            $statusRows->execute([':id' => $postId]);
            $counts = ['pending' => 0, 'failed' => 0, 'sent' => 0];
            foreach ($statusRows->fetchAll() as $row) {
                $counts[$row['status']] = (int) $row['count'];
            }

            if ($counts['sent'] > 0) {
                $status = 'shared';
            } elseif ($counts['pending'] > 0) {
                $rateLimitMinutes = (int) (getenv('ECHOTREE_RATE_LIMIT_MINUTES') ?: 10);
                if ($rateLimitMinutes < 1) {
                    $rateLimitMinutes = 10;
                }

                $rateLimitedStmt = $pdo->prepare(
                    'SELECT COUNT(*) '
                    . 'FROM deliveries d '
                    . 'WHERE d.post_id = :post_id '
                    . "AND d.status = 'pending' "
                    . 'AND EXISTS ('
                    . '  SELECT 1 FROM deliveries prev '
                    . "  WHERE prev.account_id = d.account_id AND prev.status = 'sent' "
                    . "  AND prev.sent_at >= datetime('now', :window)"
                    . ')'
                );
                $rateLimitedStmt->execute([
                    ':post_id' => $postId,
                    ':window' => '-' . $rateLimitMinutes . ' minutes',
                ]);
                $rateLimitedCount = (int) $rateLimitedStmt->fetchColumn();
                $status = $rateLimitedCount > 0 ? 'rate_limited' : 'scheduled';
            } else {
                $status = 'failed';
            }
        }

        $detailStmt = $pdo->prepare(
            'SELECT d.status, d.error, a.platform FROM deliveries d '
            . 'JOIN accounts a ON a.id = d.account_id WHERE d.post_id = :id'
        );
        $detailStmt->execute([':id' => $postId]);
        $_SESSION['last_post_details'] = $detailStmt->fetchAll();
        $_SESSION['last_post_status'] = $status;

        return $response
            ->withHeader('Location', $target . $sep . 'status=' . $status)
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

    $app->post('/articles/{id}/comment', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $data = (array) $request->getParsedBody();
        $mode = trim((string) ($data['mode'] ?? 'comment'));
        if (!in_array($mode, ['comment', 'summary', 'phrase'], true)) {
            $mode = 'comment';
        }

        $stmt = $pdo->prepare('SELECT content_text FROM articles WHERE id = :id');
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        $content = (string) $article['content_text'];
        $content = mb_substr($content, 0, 12000);

        try {
            $comment = generate_comment($content, $mode);
        } catch (Throwable $e) {
            $response->getBody()->write('Comment unavailable: ' . $e->getMessage());
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain');
        }

        $response->getBody()->write($comment);
        return $response->withHeader('Content-Type', 'text/plain');
    });

    $app->map(['GET', 'POST'], '/login', function ($request, $response) {
        $view = Twig::fromRequest($request);

        if ($request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');

            if ($username !== '' && is_login_throttled($username)) {
                return $view->render($response, 'login.twig', [
                    'title' => 'Login',
                    'error' => 'Too many failed attempts. Please wait and try again.',
                    'csrf' => csrf_token(),
                ]);
            }

            $user = authenticate($username, $password);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                return $response
                    ->withHeader('Location', url_for($request, '/'))
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
            ->withHeader('Location', url_for($request, '/login'))
            ->withStatus(302);
    });
};
