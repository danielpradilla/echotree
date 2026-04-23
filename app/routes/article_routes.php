<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;
use GuzzleHttp\Client;

function echotree_schedule_timezone_id(): string
{
    $tz = trim((string) (getenv('ECHOTREE_SCHEDULE_TIMEZONE') ?: 'Europe/Paris'));
    return $tz !== '' ? $tz : 'Europe/Paris';
}

function echotree_schedule_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone(echotree_schedule_timezone_id());
    } catch (Throwable $e) {
        return new DateTimeZone('Europe/Paris');
    }
}

function echotree_schedule_default_input(): string
{
    $dt = new DateTime('now', echotree_schedule_timezone());
    $dt->modify('+1 hour');
    return $dt->format('Y-m-d\\TH:i');
}

function echotree_schedule_input_to_utc(string $rawInput): ?string
{
    $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $rawInput, echotree_schedule_timezone());
    if (!$dt) {
        return null;
    }

    $errors = DateTime::getLastErrors();
    if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function echotree_schedule_utc_to_input(string $utcValue): string
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $utcValue, new DateTimeZone('UTC'));
    if (!$dt) {
        return '';
    }

    $dt->setTimezone(echotree_schedule_timezone());
    return $dt->format('Y-m-d\\TH:i');
}

function echotree_schedule_utc_to_display(string $utcValue): string
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $utcValue, new DateTimeZone('UTC'));
    if (!$dt) {
        return $utcValue;
    }

    $dt->setTimezone(echotree_schedule_timezone());
    return $dt->format('Y-m-d H:i');
}

function echotree_schedule_utc_to_display_nullable(?string $utcValue): ?string
{
    $utcValue = trim((string) $utcValue);
    if ($utcValue === '') {
        return null;
    }

    return echotree_schedule_utc_to_display($utcValue);
}

function echotree_monitor_missed_minutes(): int
{
    $minutes = (int) (getenv('ECHOTREE_MONITOR_MISSED_MINUTES') ?: 5);
    return $minutes < 1 ? 5 : $minutes;
}

function echotree_monitor_state(array $post): string
{
    $sent = (int) ($post['sent_delivery_count'] ?? 0);
    $failed = (int) ($post['failed_delivery_count'] ?? 0);
    $pending = (int) ($post['pending_delivery_count'] ?? 0);
    $publishing = (int) ($post['publishing_delivery_count'] ?? 0);
    $scheduledRaw = trim((string) ($post['scheduled_at'] ?? ''));

    $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledRaw, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $missedThreshold = $now->modify('-' . echotree_monitor_missed_minutes() . ' minutes');

    if ($publishing > 0) {
        return 'publishing';
    }

    if ($sent > 0 && ($pending > 0 || $failed > 0)) {
        return 'partial';
    }

    if ($failed > 0 && $sent === 0 && $pending === 0) {
        return 'failed';
    }

    if ($pending > 0 && $scheduledAt instanceof DateTimeImmutable) {
        if ($scheduledAt <= $missedThreshold) {
            return 'missed';
        }
        if ($scheduledAt <= $now) {
            return 'due';
        }
    }

    if ($pending > 0) {
        return 'scheduled';
    }

    if ($sent > 0) {
        return 'sent';
    }

    return (string) ($post['status'] ?? 'scheduled');
}

function echotree_monitor_state_label(string $state): string
{
    return match ($state) {
        'publishing' => 'Publishing',
        'partial' => 'Partial',
        'failed' => 'Failed',
        'missed' => 'Missed',
        'due' => 'Due now',
        'sent' => 'Sent',
        default => 'Scheduled',
    };
}

function echotree_monitor_state_tone(string $state): string
{
    return match ($state) {
        'failed', 'missed' => 'danger',
        'partial' => 'warning',
        'publishing', 'sent' => 'success',
        'due' => 'warning',
        default => 'neutral',
    };
}

function echotree_present_publisher_run(array $run): array
{
    $run['started_at_local'] = echotree_schedule_utc_to_display_nullable((string) ($run['started_at'] ?? ''));
    $run['finished_at_local'] = echotree_schedule_utc_to_display_nullable((string) ($run['finished_at'] ?? ''));
    $run['status_label'] = match ((string) ($run['status'] ?? '')) {
        'success' => 'Healthy',
        'failed' => 'Failed',
        'lock_skipped' => 'Skipped',
        'running' => 'Running',
        default => ucfirst((string) ($run['status'] ?? 'unknown')),
    };
    $run['status_tone'] = match ((string) ($run['status'] ?? '')) {
        'success' => 'success',
        'failed' => 'danger',
        'lock_skipped' => 'warning',
        'running' => 'neutral',
        default => 'neutral',
    };

    $started = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($run['started_at'] ?? ''), new DateTimeZone('UTC'));
    $finished = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($run['finished_at'] ?? ''), new DateTimeZone('UTC'));
    if ($started instanceof DateTimeImmutable && $finished instanceof DateTimeImmutable) {
        $seconds = max(0, $finished->getTimestamp() - $started->getTimestamp());
        $run['duration_label'] = $seconds < 60 ? ($seconds . 's') : (int) floor($seconds / 60) . 'm';
    } else {
        $run['duration_label'] = null;
    }

    return $run;
}

function create_or_publish_history_post(
    PDO $pdo,
    int $articleId,
    string $comment,
    string $scheduledAt,
    string $action,
    array $accountIds
): array {
    $duplicatePostId = find_recent_duplicate_post($pdo, $articleId, $comment, $accountIds);
    if ($duplicatePostId !== null) {
        return ['status' => 'duplicate_ignored', 'post_details' => null];
    }

    $postId = create_scheduled_post($articleId, $comment, $scheduledAt, $accountIds);

    if ($action === 'now') {
        publish_post_now($postId);
    }

    if ($action !== 'now') {
        return ['status' => 'scheduled', 'post_details' => null];
    }

    $statusRows = $pdo->prepare(
        'SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status'
    );
    $statusRows->execute([':id' => $postId]);
    $counts = ['pending' => 0, 'publishing' => 0, 'failed' => 0, 'sent' => 0];
    foreach ($statusRows->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['count'];
    }

    $status = 'failed';
    if ($counts['publishing'] > 0) {
        $status = 'scheduled';
    } elseif ($counts['sent'] > 0) {
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
    }

    $detailStmt = $pdo->prepare(
        'SELECT d.status, d.error, a.platform FROM deliveries d '
        . 'JOIN accounts a ON a.id = d.account_id WHERE d.post_id = :id'
    );
    $detailStmt->execute([':id' => $postId]);

    return [
        'status' => $status,
        'post_details' => $detailStmt->fetchAll(),
    ];
}

function find_recent_duplicate_post(PDO $pdo, int $articleId, string $comment, array $accountIds, int $windowMinutes = 15): ?int
{
    if ($articleId <= 0 || $comment === '' || count($accountIds) === 0) {
        return null;
    }

    sort($accountIds);
    $normalizedComment = preg_replace('/\s+/u', ' ', trim($comment)) ?? trim($comment);
    $stmt = $pdo->prepare(
        "SELECT id, comment FROM posts "
        . "WHERE article_id = :article_id "
        . "AND status IN ('scheduled', 'sent', 'failed') "
        . "AND created_at >= datetime('now', :window) "
        . 'ORDER BY id DESC'
    );
    $stmt->execute([
        ':article_id' => $articleId,
        ':window' => '-' . $windowMinutes . ' minutes',
    ]);

    foreach ($stmt->fetchAll() as $post) {
        $existingComment = preg_replace('/\s+/u', ' ', trim((string) $post['comment'])) ?? trim((string) $post['comment']);
        if ($existingComment !== $normalizedComment) {
            continue;
        }

        $deliveryStmt = $pdo->prepare('SELECT account_id FROM deliveries WHERE post_id = :post_id ORDER BY account_id ASC');
        $deliveryStmt->execute([':post_id' => (int) $post['id']]);
        $existingAccountIds = array_map('intval', array_column($deliveryStmt->fetchAll(), 'account_id'));
        sort($existingAccountIds);

        if ($existingAccountIds === $accountIds) {
            return (int) $post['id'];
        }
    }

    return null;
}

function manual_feed_id(PDO $pdo): int
{
    $feedStmt = $pdo->prepare('SELECT id FROM feeds WHERE url = :url');
    $feedStmt->execute([':url' => 'manual://local']);
    $feedRow = $feedStmt->fetch();
    if ($feedRow) {
        return (int) $feedRow['id'];
    }

    $insertFeed = $pdo->prepare(
        'INSERT INTO feeds (name, url, is_active) VALUES (:name, :url, :is_active)'
    );
    $insertFeed->execute([
        ':name' => 'Manual',
        ':url' => 'manual://local',
        ':is_active' => 0,
    ]);

    return (int) $pdo->lastInsertId();
}

function upsert_manual_article_from_url(PDO $pdo, string $url): ?int
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $exists = $pdo->prepare('SELECT id FROM articles WHERE url = :url');
    $exists->execute([':url' => $url]);
    $row = $exists->fetch();

    $extracted = extract_article_from_url($url, 15);
    $title = trim((string) ($extracted['title'] ?? ''));
    $contentHtml = (string) ($extracted['content_html'] ?? '');
    $contentText = (string) ($extracted['content_text'] ?? '');

    if ($row) {
        $articleId = (int) $row['id'];
        if ($contentHtml !== '' || $contentText !== '' || $title !== '') {
            $update = $pdo->prepare(
                'UPDATE articles SET title = :title, extracted_content_html = :extracted_content_html, '
                . 'extracted_content_text = :extracted_content_text, content_html = :content_html, content_text = :content_text '
                . 'WHERE id = :id'
            );
            $update->execute([
                ':title' => $title !== '' ? $title : $url,
                ':extracted_content_html' => $contentHtml !== '' ? $contentHtml : null,
                ':extracted_content_text' => $contentText !== '' ? $contentText : null,
                ':content_html' => $contentHtml,
                ':content_text' => $contentText,
                ':id' => $articleId,
            ]);
        }
        return $articleId;
    }

    $feedId = manual_feed_id($pdo);
    $insertArticle = $pdo->prepare(
        'INSERT INTO articles (feed_id, title, url, extracted_content_html, extracted_content_text, content_html, content_text, summary, published_at) '
        . 'VALUES (:feed_id, :title, :url, :extracted_content_html, :extracted_content_text, :content_html, :content_text, :summary, :published_at)'
    );
    $insertArticle->execute([
        ':feed_id' => $feedId,
        ':title' => $title !== '' ? $title : $url,
        ':url' => $url,
        ':extracted_content_html' => $contentHtml !== '' ? $contentHtml : null,
        ':extracted_content_text' => $contentText !== '' ? $contentText : null,
        ':content_html' => $contentHtml,
        ':content_text' => $contentText,
        ':summary' => null,
        ':published_at' => null,
    ]);

    return (int) $pdo->lastInsertId();
}

function article_reader_modes(): array
{
    return ['feed', 'extracted', 'original'];
}

function normalize_article_mode(?string $mode): string
{
    $candidate = strtolower(trim((string) $mode));
    if ($candidate === 'reader' || $candidate === '') {
        return 'auto';
    }
    return in_array($candidate, article_reader_modes(), true) ? $candidate : 'auto';
}

function article_has_substantial_source(array $article, string $source): bool
{
    $text = '';
    if ($source === 'feed') {
        $text = trim((string) ($article['feed_content_text'] ?? ''));
    } elseif ($source === 'extracted') {
        $text = trim((string) ($article['extracted_content_text'] ?? ''));
    }

    if ($text === '') {
        $html = '';
        if ($source === 'feed') {
            $html = trim((string) ($article['feed_content_html'] ?? ''));
        } elseif ($source === 'extracted') {
            $html = trim((string) ($article['extracted_content_html'] ?? ''));
        }
        $text = trim(strip_tags($html));
    }

    return mb_strlen($text) >= 280;
}

function resolve_article_mode(array $article, ?string $requestedMode): string
{
    $mode = normalize_article_mode($requestedMode);
    if ($mode === 'original') {
        return 'original';
    }
    if ($mode === 'feed' && article_has_substantial_source($article, 'feed')) {
        return 'feed';
    }
    if ($mode === 'extracted' && article_has_substantial_source($article, 'extracted')) {
        return 'extracted';
    }
    if ($mode === 'feed') {
        return article_has_substantial_source($article, 'extracted') ? 'extracted' : 'feed';
    }
    if ($mode === 'extracted') {
        return article_has_substantial_source($article, 'feed') ? 'feed' : 'extracted';
    }
    if (article_has_substantial_source($article, 'feed')) {
        return 'feed';
    }
    if (article_has_substantial_source($article, 'extracted')) {
        return 'extracted';
    }
    return 'original';
}

function article_reader_payload(array $article, string $mode, string $density, string $layout, string $basePath, ?int $feedId = null): array
{
    $safeMode = resolve_article_mode($article, $mode);
    $safeDensity = 'compact';
    $safeLayout = in_array($layout, ['split', 'magazine', 'grid'], true) ? $layout : 'split';
    $baseQuery = [
        'selected' => (int) $article['id'],
        'mode' => $safeMode,
        'density' => $safeDensity,
        'layout' => $safeLayout,
    ];
    if ($feedId !== null && $feedId > 0) {
        $baseQuery['feed_id'] = $feedId;
    }

    $modeUrls = [];
    foreach (article_reader_modes() as $readerMode) {
        $query = $baseQuery;
        $query['mode'] = $readerMode;
        $modeUrls[$readerMode] = $basePath . '/articles?' . http_build_query($query);
    }

    return [
        'id' => (int) $article['id'],
        'feed_id' => (int) ($article['feed_id'] ?? 0),
        'title' => (string) $article['title'],
        'url' => (string) $article['url'],
        'feed_name' => (string) ($article['feed_name'] ?? ''),
        'feed_host' => (string) ($article['feed_host'] ?? ''),
        'favicon_url' => (string) ($article['favicon_url'] ?? ''),
        'accent_color' => (string) ($article['accent_color'] ?? '#84b316'),
        'mode' => $safeMode,
        'density' => $safeDensity,
        'layout' => $safeLayout,
        'reader_html' => $safeMode !== 'original' ? article_reader_body_html($article, $safeMode) : '',
        'available_modes' => [
            'feed' => article_has_substantial_source($article, 'feed'),
            'extracted' => article_has_substantial_source($article, 'extracted'),
            'original' => true,
        ],
        'mode_urls' => $modeUrls,
        'original_embed_url' => $basePath . '/articles/' . (int) $article['id'] . '/original',
        'embed_url' => $basePath . '/articles/' . (int) $article['id'] . '/embed?' . http_build_query([
            'mode' => $safeMode,
            'density' => $safeDensity,
            'layout' => $safeLayout,
        ]),
        'archive_url' => $basePath . '/articles/' . (int) $article['id'] . '/archive',
        'return_to' => $basePath . '/articles?' . http_build_query($baseQuery),
    ];
}

function article_source_html(array $article, string $mode): string
{
    if ($mode === 'feed') {
        $html = trim((string) ($article['feed_content_html'] ?? ''));
        if ($html !== '') {
            return $html;
        }
    }
    if ($mode === 'extracted') {
        $html = trim((string) ($article['extracted_content_html'] ?? ''));
        if ($html !== '') {
            return $html;
        }
    }
    return trim((string) ($article['content_html'] ?? ''));
}

function article_source_text(array $article, string $mode): string
{
    if ($mode === 'feed') {
        $text = trim((string) ($article['feed_content_text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }
    if ($mode === 'extracted') {
        $text = trim((string) ($article['extracted_content_text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }
    return trim((string) ($article['content_text'] ?? ''));
}

function article_reader_body_html(array $article, ?string $requestedMode = null): string
{
    $mode = resolve_article_mode($article, $requestedMode);
    if ($mode === 'original') {
        return '';
    }

    $htmlContent = article_source_html($article, $mode);
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
        $text = article_source_text($article, $mode);
        $paragraphs = array_filter(preg_split("/\\R{2,}/", $text));
        foreach ($paragraphs as $para) {
            $safe = htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8');
            if ($safe !== '') {
                $body .= '<p>' . nl2br($safe) . '</p>';
            }
        }
    }

    return $body;
}

function article_original_proxy_html(string $url, int $timeoutSeconds = 15): string
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    try {
        $client = new Client([
            'timeout' => $timeoutSeconds,
            'headers' => [
                'User-Agent' => 'EchoTree/1.0 (+https://example.com)',
            ],
        ]);
        $resp = $client->get($url);
        $html = (string) $resp->getBody();
    } catch (Throwable $e) {
        return '';
    }

    if ($html === '') {
        return '';
    }

    $baseHref = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $baseTag = '<base href="' . $baseHref . '">';

    if (stripos($html, '<head') !== false) {
        $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $baseTag, $html, 1) ?? $html;
    } else {
        $html = '<head>' . $baseTag . '</head>' . $html;
    }

    $html = preg_replace('/<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $html) ?? $html;

    return $html;
}

function register_article_routes(App $app): void
{
    $app->get('/articles', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $feedId = isset($queryParams['feed_id']) ? (int) $queryParams['feed_id'] : null;
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'auto';
        $density = 'compact';
        $layout = isset($queryParams['layout']) ? (string) $queryParams['layout'] : 'split';

        $feeds = list_feed_navigation($pdo);
        $articles = list_articles_with_feed($pdo, $feedId);

        if ($selectedId === null && count($articles) > 0) {
            $selectedId = (int) $articles[0]['id'];
        }

        $selectedArticle = null;
        if ($selectedId) {
            $selectedArticle = find_article_with_feed($pdo, $selectedId);
            if ($selectedArticle && $feedId && (int) $selectedArticle['feed_id'] !== $feedId) {
                $selectedArticle = null;
            }
        }
        if ($selectedArticle === null && count($articles) > 0) {
            $selectedArticle = find_article_with_feed($pdo, (int) $articles[0]['id']);
        }
        if ($selectedArticle !== null) {
            $mode = resolve_article_mode($selectedArticle, $mode);
            $selectedPayload = article_reader_payload(
                $selectedArticle,
                $mode,
                $density,
                $layout,
                base_path($request),
                $feedId
            );
            $selectedArticle['reader_html'] = $selectedPayload['reader_html'];
            $selectedArticle['available_modes'] = $selectedPayload['available_modes'];
            $selectedArticle['mode_urls'] = $selectedPayload['mode_urls'];
        }

        $accounts = list_active_accounts($pdo);

        $postDetails = $_SESSION['last_post_details'] ?? null;
        unset($_SESSION['last_post_details'], $_SESSION['last_post_status']);

        $currentReaderUrl = (string) $request->getUri()->getPath();
        $currentQuery = (string) $request->getUri()->getQuery();
        if ($currentQuery !== '') {
            $currentReaderUrl .= '?' . $currentQuery;
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'articles/index.twig', [
            'title' => 'Reader',
            'articles' => $articles,
            'feeds' => $feeds,
            'active_feed_id' => $feedId,
            'selected' => $selectedArticle,
            'accounts' => $accounts,
            'mode' => $mode,
            'density' => 'compact',
            'layout' => in_array($layout, ['split', 'magazine', 'grid'], true) ? $layout : 'split',
            'status' => $queryParams['status'] ?? null,
            'error' => $queryParams['error'] ?? null,
            'post_details' => $postDetails,
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'base_path' => base_path($request),
            'current_reader_url' => $currentReaderUrl,
            'default_schedule_input' => echotree_schedule_default_input(),
            'schedule_timezone' => echotree_schedule_timezone_id(),
            'river_label' => $feedId ? 'Site' : 'All stories',
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

    $app->get('/articles/selected', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : 0;
        $feedId = isset($queryParams['feed_id']) ? (int) $queryParams['feed_id'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'auto';
        $density = 'compact';
        $layout = isset($queryParams['layout']) ? (string) $queryParams['layout'] : 'split';

        if ($selectedId <= 0) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'missing_selected']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $article = find_article_with_feed($pdo, $selectedId);
        if ($article === null) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if ($feedId && (int) ($article['feed_id'] ?? 0) !== $feedId) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'feed_mismatch']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'ok' => true,
            'article' => article_reader_payload($article, $mode, $density, $layout, base_path($request), $feedId),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->get('/articles/follow', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $url = trim((string) ($queryParams['url'] ?? ''));
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'auto';
        $density = 'compact';
        $layout = isset($queryParams['layout']) ? (string) $queryParams['layout'] : 'split';
        $format = isset($queryParams['format']) ? (string) $queryParams['format'] : '';

        $articleId = upsert_manual_article_from_url($pdo, $url);
        if ($articleId === null) {
            if ($format === 'json') {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'invalid_url']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            return $response
                ->withHeader('Location', url_for($request, '/articles?error=1'))
                ->withStatus(302);
        }

        $article = find_article_with_feed($pdo, $articleId);
        if (!$article) {
            if ($format === 'json') {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'not_found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            return $response
                ->withHeader('Location', url_for($request, '/articles?error=1'))
                ->withStatus(302);
        }

        if ($format === 'json') {
            $response->getBody()->write(json_encode([
                'ok' => true,
                'article' => article_reader_payload($article, $mode, $density, $layout, base_path($request)),
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $redirectQuery = http_build_query([
            'selected' => $articleId,
            'mode' => normalize_article_mode($mode),
            'density' => 'compact',
            'layout' => in_array($layout, ['split', 'magazine', 'grid'], true) ? $layout : 'split',
        ]);

        return $response
            ->withHeader('Location', url_for($request, '/articles?' . $redirectQuery))
            ->withStatus(302);
    });

    $app->map(['GET', 'POST'], '/share', function ($request, $response) {
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : null;
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'auto';
        $error = null;
        $urlFromQuery = trim((string) ($queryParams['url'] ?? ''));

        if ($request->getMethod() === 'POST' || $urlFromQuery !== '') {
            $data = (array) $request->getParsedBody();
            $url = $urlFromQuery !== '' ? $urlFromQuery : trim((string) ($data['url'] ?? ''));

            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                $error = 'Please enter a valid URL.';
            } else {
                $selectedId = upsert_manual_article_from_url($pdo, $url);
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
            'mode' => normalize_article_mode($mode),
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'error' => $error,
            'status' => $queryParams['status'] ?? null,
            'post_details' => $postDetails,
            'base_path' => base_path($request),
            'default_schedule_input' => echotree_schedule_default_input(),
            'schedule_timezone' => echotree_schedule_timezone_id(),
        ]);
    });

    $app->get('/history', function ($request, $response) {
        $pdo = db_connection();
        $entries = list_share_history($pdo);
        foreach ($entries as &$entry) {
            $entry['shared_at_local'] = echotree_schedule_utc_to_display((string) $entry['shared_at']);
        }
        unset($entry);

        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : 0;
        if ($selectedId === 0 && count($entries) > 0) {
            $selectedId = (int) $entries[0]['id'];
        }

        $selectedEntry = null;
        foreach ($entries as $entry) {
            if ((int) $entry['id'] === $selectedId) {
                $selectedEntry = $entry;
                break;
            }
        }

        $accounts = list_active_accounts($pdo);
        $postDetails = $_SESSION['last_post_details'] ?? null;
        unset($_SESSION['last_post_details'], $_SESSION['last_post_status']);

        $view = Twig::fromRequest($request);
        return $view->render($response, 'history.twig', [
            'title' => 'History',
            'entries' => $entries,
            'selected' => $selectedEntry,
            'accounts' => $accounts,
            'status' => $queryParams['status'] ?? null,
            'error' => $queryParams['error'] ?? null,
            'post_details' => $postDetails,
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'base_path' => base_path($request),
            'default_schedule_input' => echotree_schedule_default_input(),
            'schedule_timezone' => echotree_schedule_timezone_id(),
        ]);
    });

    $app->post('/history/{id}/repost', function ($request, $response, $args) {
        $historyId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $comment = trim((string) ($data['comment'] ?? ''));
        $scheduledAt = trim((string) ($data['scheduled_at'] ?? ''));
        $action = trim((string) ($data['action'] ?? 'schedule'));
        $submitToken = trim((string) ($data['_submit_token'] ?? ''));
        $accountIds = array_map('intval', (array) ($data['account_ids'] ?? []));
        $target = "/history?selected={$historyId}";
        $sep = '&';

        if (!consume_post_submit_token($submitToken)) {
            return $response
                ->withHeader('Location', $target . $sep . 'status=duplicate_ignored')
                ->withStatus(302);
        }

        if ($historyId === 0 || $comment === '' || count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $target . $sep . 'error=1')
                ->withStatus(302);
        }

        if ($action === 'now') {
            $scheduledAt = gmdate('Y-m-d H:i:s');
        } else {
            $parsedScheduleAt = echotree_schedule_input_to_utc($scheduledAt);
            if ($parsedScheduleAt === null) {
                return $response
                    ->withHeader('Location', $target . $sep . 'error=1')
                    ->withStatus(302);
            }
            $scheduledAt = $parsedScheduleAt;
        }

        $pdo = db_connection();
        $entry = find_share_history_entry($pdo, $historyId);
        if (!$entry) {
            return $response
                ->withHeader('Location', $target . $sep . 'status=failed')
                ->withStatus(302);
        }

        $articleId = upsert_manual_article_from_url($pdo, (string) ($entry['url'] ?? ''));
        if ($articleId === null) {
            return $response
                ->withHeader('Location', $target . $sep . 'status=failed')
                ->withStatus(302);
        }

        try {
            $result = create_or_publish_history_post($pdo, $articleId, $comment, $scheduledAt, $action, $accountIds);
            $_SESSION['last_post_details'] = $result['post_details'];
            $_SESSION['last_post_status'] = $result['status'];

            return $response
                ->withHeader('Location', $target . $sep . 'status=' . $result['status'])
                ->withStatus(302);
        } catch (Throwable $e) {
            error_log('Failed reposting history entry: ' . $e->getMessage());
            return $response
                ->withHeader('Location', $target . $sep . 'status=failed')
                ->withStatus(302);
        }
    });

    $app->get('/scheduled', function ($request, $response) {
        $pdo = db_connection();
        $posts = list_scheduled_posts($pdo);
        $accounts = list_active_accounts($pdo);
        $lastPublisherRun = find_latest_publisher_run($pdo);
        $recentPublisherRuns = array_map('echotree_present_publisher_run', list_recent_publisher_runs($pdo, 6));
        $queryParams = $request->getQueryParams();
        $selectedId = isset($queryParams['selected']) ? (int) $queryParams['selected'] : 0;
        if ($selectedId === 0 && count($posts) > 0) {
            $selectedId = (int) $posts[0]['id'];
        }

        $selectedPost = null;
        $postSummary = [
            'scheduled' => 0,
            'due' => 0,
            'missed' => 0,
            'partial' => 0,
            'failed' => 0,
            'publishing' => 0,
        ];
        foreach ($posts as &$post) {
            $post['scheduled_at_local'] = echotree_schedule_utc_to_display((string) $post['scheduled_at']);
            $post['last_attempted_at_local'] = echotree_schedule_utc_to_display_nullable((string) ($post['last_attempted_at'] ?? ''));
            $post['monitor_state'] = echotree_monitor_state($post);
            $post['monitor_label'] = echotree_monitor_state_label((string) $post['monitor_state']);
            $post['monitor_tone'] = echotree_monitor_state_tone((string) $post['monitor_state']);
            $postSummary[$post['monitor_state']] = ($postSummary[$post['monitor_state']] ?? 0) + 1;
        }
        unset($post);
        foreach ($posts as $post) {
            if ((int) $post['id'] !== $selectedId) {
                continue;
            }
            $deliveries = list_post_deliveries($pdo, (int) $post['id']);
            $selected = [];
            foreach ($deliveries as $delivery) {
                if (in_array((string) $delivery['status'], ['pending', 'failed', 'publishing'], true)) {
                    $selected[] = (int) $delivery['account_id'];
                }
            }

            $selectedPost = $post;
            foreach ($deliveries as &$delivery) {
                $delivery['last_attempted_at_local'] = echotree_schedule_utc_to_display_nullable((string) ($delivery['last_attempted_at'] ?? ''));
                $delivery['sent_at_local'] = echotree_schedule_utc_to_display_nullable((string) ($delivery['sent_at'] ?? ''));
            }
            unset($delivery);
            $selectedPost['deliveries'] = $deliveries;
            $selectedPost['scheduled_at_input'] = echotree_schedule_utc_to_input((string) $post['scheduled_at']);
            $selectedPost['selected_account_ids'] = $selected;
            break;
        }

        $view = Twig::fromRequest($request);
        return $view->render($response, 'posts/scheduled.twig', [
            'title' => 'Scheduled',
            'posts' => $posts,
            'selected_id' => $selectedId,
            'selected_post' => $selectedPost,
            'accounts' => $accounts,
            'updated' => ($queryParams['updated'] ?? '') === '1',
            'cancelled_count' => max(0, (int) ($queryParams['cancelled'] ?? '0')),
            'notice' => (string) ($queryParams['notice'] ?? ''),
            'error' => (string) ($queryParams['error'] ?? ''),
            'post_summary' => $postSummary,
            'last_publisher_run' => $lastPublisherRun ? echotree_present_publisher_run($lastPublisherRun) : null,
            'recent_publisher_runs' => $recentPublisherRuns,
            'csrf' => csrf_token(),
            'base_path' => base_path($request),
            'schedule_timezone' => echotree_schedule_timezone_id(),
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
        $selectedQuery = '&selected=' . $postId;
        if ($postId <= 0 || $comment === '' || $scheduledAtRaw === '' || count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input' . $selectedQuery)
                ->withStatus(302);
        }

        $scheduledAt = echotree_schedule_input_to_utc($scheduledAtRaw);
        if ($scheduledAt === null) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_schedule' . $selectedQuery)
                ->withStatus(302);
        }

        $pdo = db_connection();
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND status IN ('scheduled', 'failed')");
        $postStmt->execute([':id' => $postId]);
        if (!$postStmt->fetch()) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable' . $selectedQuery)
                ->withStatus(302);
        }

        $activeAccountStmt = $pdo->query('SELECT id FROM accounts WHERE is_active = 1');
        $activeAccountIds = array_map('intval', array_column($activeAccountStmt->fetchAll(), 'id'));
        $accountIds = array_values(array_intersect($accountIds, $activeAccountIds));
        if (count($accountIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=no_active_accounts' . $selectedQuery)
                ->withStatus(302);
        }

        $pdo->beginTransaction();
        try {
            $updatePost = $pdo->prepare(
                "UPDATE posts SET comment = :comment, scheduled_at = :scheduled_at, status = 'scheduled' WHERE id = :id"
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
                ->withHeader('Location', $targetBase . '?error=save_failed' . $selectedQuery)
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', url_for($request, '/scheduled') . '?updated=1&selected=' . $postId)
            ->withStatus(302);
    });

    $app->post('/scheduled/cancel', function ($request, $response) {
        $data = (array) $request->getParsedBody();
        $rawPostIds = array_map('intval', (array) ($data['post_ids'] ?? []));
        $postIds = array_values(array_unique(array_filter($rawPostIds, fn ($id) => $id > 0)));
        $targetBase = url_for($request, '/scheduled');

        if (count($postIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input')
                ->withStatus(302);
        }

        $pdo = db_connection();
        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $postStmt = $pdo->prepare(
            "SELECT id FROM posts WHERE status IN ('scheduled', 'failed') AND id IN ($placeholders)"
        );
        $postStmt->execute($postIds);
        $editableIds = array_map('intval', array_column($postStmt->fetchAll(), 'id'));
        if (count($editableIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable')
                ->withStatus(302);
        }

        $editablePlaceholders = implode(', ', array_fill(0, count($editableIds), '?'));
        $pdo->beginTransaction();
        try {
            $updatePost = $pdo->prepare(
                "UPDATE posts SET status = 'cancelled' WHERE id IN ($editablePlaceholders)"
            );
            $updatePost->execute($editableIds);

            $deleteDeliveries = $pdo->prepare(
                "DELETE FROM deliveries WHERE post_id IN ($editablePlaceholders) AND status IN ('pending', 'failed', 'publishing')"
            );
            $deleteDeliveries->execute($editableIds);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return $response
                ->withHeader('Location', $targetBase . '?error=save_failed')
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', $targetBase . '?cancelled=' . count($editableIds))
            ->withStatus(302);
    });

    $app->post('/scheduled/run', function ($request, $response) {
        $data = (array) $request->getParsedBody();
        $rawPostIds = array_map('intval', (array) ($data['post_ids'] ?? []));
        $postIds = array_values(array_unique(array_filter($rawPostIds, fn ($id) => $id > 0)));
        $targetBase = url_for($request, '/scheduled');

        if (count($postIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input')
                ->withStatus(302);
        }

        $pdo = db_connection();
        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $postStmt = $pdo->prepare(
            "SELECT id FROM posts WHERE status IN ('scheduled', 'failed') AND id IN ($placeholders)"
        );
        $postStmt->execute($postIds);
        $eligibleIds = array_map('intval', array_column($postStmt->fetchAll(), 'id'));
        if (count($eligibleIds) === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable')
                ->withStatus(302);
        }

        $ranCount = 0;
        foreach ($eligibleIds as $eligibleId) {
            if (publish_post_now($eligibleId)) {
                $ranCount++;
            }
        }

        if ($ranCount === 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=publisher_busy')
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', $targetBase . '?notice=ran_selected_' . $ranCount)
            ->withStatus(302);
    });

    $app->post('/scheduled/{id}/run', function ($request, $response, $args) {
        $postId = (int) ($args['id'] ?? 0);
        $targetBase = url_for($request, '/scheduled');
        $selectedQuery = '&selected=' . $postId;
        if ($postId <= 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input')
                ->withStatus(302);
        }

        $pdo = db_connection();
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND status IN ('scheduled', 'failed')");
        $postStmt->execute([':id' => $postId]);
        if (!$postStmt->fetch()) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable' . $selectedQuery)
                ->withStatus(302);
        }

        if (!publish_post_now($postId)) {
            return $response
                ->withHeader('Location', $targetBase . '?error=publisher_busy' . $selectedQuery)
                ->withStatus(302);
        }

        return $response
            ->withHeader('Location', $targetBase . '?selected=' . $postId . '&notice=ran_now')
            ->withStatus(302);
    });

    $app->post('/scheduled/{id}/cancel', function ($request, $response, $args) {
        $postId = (int) ($args['id'] ?? 0);
        $targetBase = url_for($request, '/scheduled');
        $selectedQuery = '&selected=' . $postId;
        if ($postId <= 0) {
            return $response
                ->withHeader('Location', $targetBase . '?error=invalid_input')
                ->withStatus(302);
        }

        $pdo = db_connection();
        $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND status IN ('scheduled', 'failed')");
        $postStmt->execute([':id' => $postId]);
        if (!$postStmt->fetch()) {
            return $response
                ->withHeader('Location', $targetBase . '?error=not_editable' . $selectedQuery)
                ->withStatus(302);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE posts SET status = 'cancelled' WHERE id = :id")
                ->execute([':id' => $postId]);
            $pdo->prepare("DELETE FROM deliveries WHERE post_id = :post_id AND status IN ('pending', 'failed', 'publishing')")
                ->execute([':post_id' => $postId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return $response
                ->withHeader('Location', $targetBase . '?error=save_failed' . $selectedQuery)
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
            'status' => $request->getQueryParams()['status'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
            'csrf' => csrf_token(),
            'submit_token' => create_post_submit_token(),
            'default_schedule_input' => echotree_schedule_default_input(),
            'schedule_timezone' => echotree_schedule_timezone_id(),
        ]);
    });

    $app->get('/articles/{id}/embed', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();
        $queryParams = $request->getQueryParams();
        $mode = isset($queryParams['mode']) ? (string) $queryParams['mode'] : 'auto';
        $density = 'compact';
        $layout = isset($queryParams['layout']) ? (string) $queryParams['layout'] : 'split';

        $stmt = $pdo->prepare(
            'SELECT title, content_html, content_text, feed_content_html, feed_content_text, '
            . 'extracted_content_html, extracted_content_text FROM articles WHERE id = :id'
        );
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        $resolvedMode = resolve_article_mode($article, $mode);
        $body = article_reader_body_html($article, $resolvedMode);

        $followBase = base_path($request) . '/articles/follow?';
        $followParams = http_build_query([
            'mode' => $resolvedMode,
            'density' => 'compact',
            'layout' => in_array($layout, ['split', 'magazine', 'grid'], true) ? $layout : 'split',
        ]);

        $html = '<!doctype html><html><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<style>body{font-family:ui-serif,Georgia,Cambria,Times New Roman,Times,serif;'
            . 'margin:0;padding:28px;line-height:1.8;color:#1e1a16;background:#fffaf2;}'
            . '.reader{max-width:720px;margin:0 auto;}'
            . 'p{margin:0 0 18px;}'
            . 'h1,h2,h3{line-height:1.3;margin:0 0 12px;}'
            . 'img,video{max-width:100%;height:auto;}</style></head><body><div class="reader">'
            . $body
            . '</div><script>'
            . 'document.addEventListener("click",function(event){'
            . 'if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey){return;}'
            . 'var link=event.target&&event.target.closest?event.target.closest("a[href]"):null;'
            . 'if(!link){return;}'
            . 'var href=link.getAttribute("href")||"";'
            . 'if(!/^https?:\\/\\//i.test(href)){return;}'
            . 'event.preventDefault();'
            . 'window.top.postMessage({type:"echotree-follow-link",url:href,followBase:' . json_encode($followBase) . ',followParams:' . json_encode($followParams) . '},"*");'
            . '});'
            . '</script></body></html>';

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    });

    $app->get('/articles/{id}/original', function ($request, $response, $args) {
        $articleId = (int) ($args['id'] ?? 0);
        $pdo = db_connection();

        $stmt = $pdo->prepare('SELECT title, url FROM articles WHERE id = :id');
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch();

        if (!$article) {
            return $response->withStatus(404);
        }

        $html = article_original_proxy_html((string) ($article['url'] ?? ''));
        if ($html === '') {
            $safeTitle = htmlspecialchars((string) ($article['title'] ?? 'Original article'), ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars((string) ($article['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html = '<!doctype html><html><head><meta charset="utf-8" />'
                . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
                . '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
                . 'margin:0;padding:32px;line-height:1.6;color:#1f2b1f;background:#f7f8f3;}'
                . '.card{max-width:720px;margin:0 auto;padding:24px;border:1px solid #d7ddd1;border-radius:16px;background:#fff;}'
                . 'a{color:#0b5d1e;}</style></head><body><div class="card"><h1>' . $safeTitle . '</h1>'
                . '<p>This site cannot be rendered inline right now.</p>'
                . '<p><a href="' . $safeUrl . '" target="_blank" rel="noopener">Open the original site</a></p>'
                . '</div></body></html>';
        }

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
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
            $scheduledAt = gmdate('Y-m-d H:i:s');
        } else {
            $parsedScheduleAt = echotree_schedule_input_to_utc($scheduledAt);
            if ($parsedScheduleAt === null) {
                return $response
                    ->withHeader('Location', $target . $sep . 'error=1')
                    ->withStatus(302);
            }
            $scheduledAt = $parsedScheduleAt;
        }

        $pdo = db_connection();
        $duplicatePostId = find_recent_duplicate_post($pdo, $articleId, $comment, $accountIds);
        if ($duplicatePostId !== null) {
            return $response
                ->withHeader('Location', $target . $sep . 'status=duplicate_ignored')
                ->withStatus(302);
        }

        try {
            $postId = create_scheduled_post($articleId, $comment, $scheduledAt, $accountIds);

            if ($action === 'now') {
                publish_post_now($postId);
            }

            $status = 'scheduled';
            if ($action === 'now') {
                $statusRows = $pdo->prepare(
                    'SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status'
                );
                $statusRows->execute([':id' => $postId]);
                $counts = ['pending' => 0, 'publishing' => 0, 'failed' => 0, 'sent' => 0];
                foreach ($statusRows->fetchAll() as $row) {
                    $counts[$row['status']] = (int) $row['count'];
                }

                if ($counts['publishing'] > 0) {
                    $status = 'scheduled';
                } elseif ($counts['sent'] > 0) {
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
        } catch (Throwable $e) {
            error_log('Failed creating/publishing post: ' . $e->getMessage());
            return $response
                ->withHeader('Location', $target . $sep . 'status=failed')
                ->withStatus(302);
        }
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
