<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;

function register_article_routes(App $app): void
{
    $app->get('/articles', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $feedId = isset($queryParams['feed_id']) ? (int) $queryParams['feed_id'] : null;
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'reader';

        $feeds = list_non_manual_feeds($pdo);
        $articles = list_articles_with_feed($pdo, $feedId);

        $selectedArticle = null;
        if ($selectedId) {
            $selectedArticle = find_article_with_feed($pdo, $selectedId);
        }

        $accounts = list_active_accounts($pdo);

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

    $archiveArticle = function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $delete = $pdo->prepare('DELETE FROM articles WHERE id = :id');
        $delete->execute([':id' => $articleId]);

        $referer = $request->getHeaderLine('Referer');
        $redirect = $referer !== '' ? $referer : '/articles';
        return $response
            ->withHeader('Location', $redirect)
            ->withStatus(302);
    };
    $app->post('/articles/{id}/archive', $archiveArticle);
    $app->post('/articles/{id}/toggle-read', $archiveArticle);

    $archiveAll = function ($request, $response) {
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
    };
    $app->post('/articles/archive-all', $archiveAll);
    $app->post('/articles/mark-all-read', $archiveAll);

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
                    $extracted = extract_article_from_url($url, 15);
                    $title = trim((string) ($extracted['title'] ?? ''));
                    $contentHtml = (string) ($extracted['content_html'] ?? '');
                    $contentText = (string) ($extracted['content_text'] ?? '');

                    if ($contentHtml !== '' || $contentText !== '' || $title !== '') {
                        $update = $pdo->prepare(
                            'UPDATE articles SET title = :title, content_html = :content_html, content_text = :content_text '
                            . 'WHERE id = :id'
                        );
                        $update->execute([
                            ':title' => $title !== '' ? $title : $url,
                            ':content_html' => $contentHtml,
                            ':content_text' => $contentText,
                            ':id' => $selectedId,
                        ]);
                    }
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

                    $extracted = extract_article_from_url($url, 15);
                    $title = trim((string) ($extracted['title'] ?? ''));
                    $contentHtml = (string) ($extracted['content_html'] ?? '');
                    $contentText = (string) ($extracted['content_text'] ?? '');

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
            $selectedArticle = find_article_with_feed($pdo, $selectedId);
        }

        $accounts = list_active_accounts($pdo);

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

    $app->get('/scheduled', function ($request, $response) {
        $pdo = db_connection();
        $posts = list_scheduled_posts($pdo);
        $accounts = list_active_accounts($pdo);

        foreach ($posts as &$post) {
            $deliveries = list_post_deliveries($pdo, (int) $post['id']);
            $post['deliveries'] = $deliveries;
            $post['scheduled_at_input'] = substr(str_replace(' ', 'T', (string) $post['scheduled_at']), 0, 16);
            $selected = [];
            foreach ($deliveries as $delivery) {
                if (in_array((string) $delivery['status'], ['pending', 'failed'], true)) {
                    $selected[] = (int) $delivery['account_id'];
                }
            }
            $post['selected_account_ids'] = $selected;
        }
        unset($post);

        $queryParams = $request->getQueryParams();
        $view = Twig::fromRequest($request);
        return $view->render($response, 'posts/scheduled.twig', [
            'title' => 'Scheduled',
            'posts' => $posts,
            'accounts' => $accounts,
            'updated' => ($queryParams['updated'] ?? '') === '1',
            'cancelled' => ($queryParams['cancelled'] ?? '') === '1',
            'error' => (string) ($queryParams['error'] ?? ''),
            'csrf' => csrf_token(),
            'base_path' => base_path($request),
        ]);
    });

    $app->post('/scheduled/{id}/update', function ($request, $response, $args) {
        $postId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $comment = trim((string) ($data['comment'] ?? ''));
        $scheduledAtRaw = trim((string) ($data['scheduled_at'] ?? ''));
        $rawAccountIds = array_map('intval', (array) ($data['account_ids'] ?? []));
        $accountIds = array_values(array_unique(array_filter($rawAccountIds, fn ($id) => $id > 0)));

        $targetBase = url_for($request, '/scheduled');
        $targetAnchor = '#post-' . $postId;
        if ($postId <= 0 || $comment === '' || $scheduledAtRaw === '' || count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input' . $targetAnchor)
                ->withStatus(302);
        }

        $scheduledAtDt = DateTime::createFromFormat('Y-m-d\\TH:i', $scheduledAtRaw);
        if (!$scheduledAtDt) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_schedule' . $targetAnchor)
                ->withStatus(302);
        }
        $scheduledAt = $scheduledAtDt->format('Y-m-d H:i:s');

        $pdo = db_connection();
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND status = 'scheduled'");
        $postStmt->execute([':id' => $postId]);
        if (!$postStmt->fetch()) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable' . $targetAnchor)
                ->withStatus(302);
        }

        $activeAccountStmt = $pdo->query('SELECT id FROM accounts WHERE is_active = 1');
        $activeAccountIds = array_map('intval', array_column($activeAccountStmt->fetchAll(), 'id'));
        $accountIds = array_values(array_intersect($accountIds, $activeAccountIds));
        if (count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=no_active_accounts' . $targetAnchor)
                ->withStatus(302);
        }

        $pdo->beginTransaction();
        try {
            $updatePost = $pdo->prepare(
                'UPDATE posts SET comment = :comment, scheduled_at = :scheduled_at WHERE id = :id'
            );
            $updatePost->execute([
                ':comment' => $comment,
                ':scheduled_at' => $scheduledAt,
                ':id' => $postId,
            ]);

            $unsentDeliveries = $pdo->prepare(
                "SELECT id, account_id FROM deliveries WHERE post_id = :post_id AND status IN ('pending', 'failed')"
            );
            $unsentDeliveries->execute([':post_id' => $postId]);
            $existingUnsent = $unsentDeliveries->fetchAll();
            $existingByAccount = [];
            foreach ($existingUnsent as $delivery) {
                $existingByAccount[(int) $delivery['account_id']] = (int) $delivery['id'];
            }

            foreach ($existingByAccount as $accountId => $deliveryId) {
                if (!in_array($accountId, $accountIds, true)) {
                    $delete = $pdo->prepare('DELETE FROM deliveries WHERE id = :id');
                    $delete->execute([':id' => $deliveryId]);
                }
            }

            $existingAnyStmt = $pdo->prepare('SELECT account_id FROM deliveries WHERE post_id = :post_id');
            $existingAnyStmt->execute([':post_id' => $postId]);
            $existingAny = array_map('intval', array_column($existingAnyStmt->fetchAll(), 'account_id'));

            $insert = $pdo->prepare(
                "INSERT INTO deliveries (post_id, account_id, status) VALUES (:post_id, :account_id, 'pending')"
            );
            foreach ($accountIds as $accountId) {
                if (!in_array($accountId, $existingAny, true)) {
                    $insert->execute([
                        ':post_id' => $postId,
                        ':account_id' => $accountId,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return $response
                ->withHeader('Location', $targetBase . '?error=save_failed' . $targetAnchor)
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', url_for($request, '/scheduled') . '?updated=1#post-' . $postId)
            ->withStatus(302);
    });

    $app->post('/scheduled/{id}/cancel', function ($request, $response, $args) {
        $postId = (int) ($args['id'] ?? 0);
        $targetBase = url_for($request, '/scheduled');
        $targetAnchor = '#post-' . $postId;
        if ($postId <= 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input')
                ->withStatus(302);
        }

        $pdo = db_connection();
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND status = 'scheduled'");
        $postStmt->execute([':id' => $postId]);
        if (!$postStmt->fetch()) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable' . $targetAnchor)
                ->withStatus(302);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE posts SET status = 'cancelled' WHERE id = :id")
                ->execute([':id' => $postId]);
            $pdo->prepare("DELETE FROM deliveries WHERE post_id = :post_id AND status IN ('pending', 'failed')")
                ->execute([':post_id' => $postId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return $response
                ->withHeader('Location', $targetBase . '?error=save_failed' . $targetAnchor)
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', $targetBase . '?cancelled=1')
            ->withStatus(302);
    });

    $app->get('/articles/{id}', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $article = find_article_with_feed($pdo, $articleId);

        if (!$article) {
            return $response->withStatus(404);
        }

        $accounts = list_active_accounts($pdo);

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
                $rateLimitMinutes = posting_rate_limit_minutes();

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
}
