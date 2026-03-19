<?php

declare(strict_types=1);

function list_non_manual_feeds(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name FROM feeds WHERE url != 'manual://local' ORDER BY name ASC"
    )->fetchAll();
}

function feed_host_from_url(string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    return strtolower(trim($host));
}

function favicon_url_for_host(string $host): string
{
    if ($host === '') {
        return '';
    }

    return 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=64';
}

function accent_color_for_host(string $host): string
{
    if ($host === '') {
        return '#84b316';
    }

    $hash = abs(crc32($host));
    $hue = $hash % 360;
    return sprintf('hsl(%d 55%% 48%%)', $hue);
}

function extract_first_image_url(string $html): ?string
{
    if (trim($html) === '') {
        return null;
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $matches)
        || preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i', $html, $matches)) {
        $metaSrc = trim((string) ($matches[1] ?? ''));
        if ($metaSrc !== '' && preg_match('#^https?://#i', $metaSrc)) {
            return $metaSrc;
        }
    }

    if (!preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        return null;
    }

    $src = trim((string) ($matches[1] ?? ''));
    if ($src === '' || !preg_match('#^https?://#i', $src)) {
        return null;
    }

    return $src;
}

function list_feed_navigation(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT f.id, f.name, f.url, f.is_active, '
        . 'COUNT(a.id) AS article_count, '
        . 'SUM(CASE WHEN a.is_read = 0 THEN 1 ELSE 0 END) AS unread_count '
        . 'FROM feeds f '
        . 'LEFT JOIN articles a ON a.feed_id = f.id '
        . 'WHERE f.url != :manual_url '
        . 'GROUP BY f.id, f.name, f.url, f.is_active '
        . 'ORDER BY LOWER(f.name) ASC'
    );
    $stmt->execute([':manual_url' => 'manual://local']);

    return array_map(static function (array $row): array {
        $host = feed_host_from_url((string) ($row['url'] ?? ''));
        $row['article_count'] = (int) ($row['article_count'] ?? 0);
        $row['unread_count'] = (int) ($row['unread_count'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 0);
        $row['host'] = $host;
        $row['favicon_url'] = favicon_url_for_host($host);
        $row['accent_color'] = accent_color_for_host($host);
        return $row;
    }, $stmt->fetchAll());
}

function list_articles_with_feed(PDO $pdo, ?int $feedId = null): array
{
    if ($feedId) {
        $stmt = $pdo->prepare(
            'SELECT a.*, f.name AS feed_name, f.url AS feed_url '
            . 'FROM articles a '
            . 'JOIN feeds f ON f.id = a.feed_id '
            . 'WHERE a.feed_id = :feed_id AND f.url != :manual_url '
            . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
        );
        $stmt->execute([':feed_id' => $feedId, ':manual_url' => 'manual://local']);
        $rows = $stmt->fetchAll();
        return array_map('hydrate_article_presenter_fields', $rows);
    }

    $stmt = $pdo->prepare(
        'SELECT a.*, f.name AS feed_name, f.url AS feed_url '
        . 'FROM articles a '
        . 'JOIN feeds f ON f.id = a.feed_id '
        . 'WHERE f.url != :manual_url '
        . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
    );
    $stmt->execute([':manual_url' => 'manual://local']);
    $rows = $stmt->fetchAll();
    return array_map('hydrate_article_presenter_fields', $rows);
}

function find_article_with_feed(PDO $pdo, int $articleId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, f.name AS feed_name, f.url AS feed_url '
        . 'FROM articles a '
        . 'JOIN feeds f ON f.id = a.feed_id '
        . 'WHERE a.id = :id'
    );
    $stmt->execute([':id' => $articleId]);
    $row = $stmt->fetch();
    return $row ? hydrate_article_presenter_fields($row) : null;
}

function hydrate_article_presenter_fields(array $row): array
{
    $host = feed_host_from_url((string) ($row['feed_url'] ?? $row['url'] ?? ''));
    $row['feed_host'] = $host;
    $row['favicon_url'] = favicon_url_for_host($host);
    $row['thumbnail_url'] = extract_first_image_url((string) ($row['content_html'] ?? ''));
    $row['accent_color'] = accent_color_for_host($host);
    return $row;
}

function list_active_accounts(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, platform, display_name, handle '
        . 'FROM accounts WHERE is_active = 1 ORDER BY platform, display_name'
    )->fetchAll();
}

function list_scheduled_posts(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT p.id, p.article_id, p.comment, p.scheduled_at, p.status, p.created_at, "
        . "a.title AS article_title, a.url AS article_url "
        . "FROM posts p "
        . "JOIN articles a ON a.id = p.article_id "
        . "WHERE p.status = 'scheduled' "
        . "ORDER BY p.scheduled_at ASC, p.id ASC"
    );
    return $stmt->fetchAll();
}

function list_post_deliveries(PDO $pdo, int $postId): array
{
    $stmt = $pdo->prepare(
        'SELECT d.id, d.account_id, d.status, d.error, a.platform, a.display_name, a.handle '
        . 'FROM deliveries d '
        . 'JOIN accounts a ON a.id = d.account_id '
        . 'WHERE d.post_id = :post_id '
        . 'ORDER BY d.id ASC'
    );
    $stmt->execute([':post_id' => $postId]);
    return $stmt->fetchAll();
}

function list_share_history(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, url, title, comment, shared_at, created_at, status, platform, '
        . 'account_id, account_display_name, account_handle, article_id, post_id, delivery_id, external_id, error '
        . 'FROM share_history '
        . "WHERE status = 'sent' "
        . 'ORDER BY shared_at DESC, id DESC'
    );

    return $stmt->fetchAll();
}

function find_share_history_entry(PDO $pdo, int $historyId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, url, title, comment, shared_at, created_at, status, platform, '
        . 'account_id, account_display_name, account_handle, article_id, post_id, delivery_id, external_id, error '
        . 'FROM share_history WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $historyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
