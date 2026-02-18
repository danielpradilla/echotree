<?php

declare(strict_types=1);

function list_non_manual_feeds(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name FROM feeds WHERE url != 'manual://local' ORDER BY name ASC"
    )->fetchAll();
}

function list_articles_with_feed(PDO $pdo, ?int $feedId = null): array
{
    if ($feedId) {
        $stmt = $pdo->prepare(
            'SELECT a.*, f.name AS feed_name '
            . 'FROM articles a '
            . 'JOIN feeds f ON f.id = a.feed_id '
            . 'WHERE a.feed_id = :feed_id AND f.url != :manual_url '
            . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
        );
        $stmt->execute([':feed_id' => $feedId, ':manual_url' => 'manual://local']);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        'SELECT a.*, f.name AS feed_name '
        . 'FROM articles a '
        . 'JOIN feeds f ON f.id = a.feed_id '
        . 'WHERE f.url != :manual_url '
        . 'ORDER BY a.is_read ASC, a.published_at DESC, a.created_at DESC'
    );
    $stmt->execute([':manual_url' => 'manual://local']);
    return $stmt->fetchAll();
}

function find_article_with_feed(PDO $pdo, int $articleId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, f.name AS feed_name '
        . 'FROM articles a '
        . 'JOIN feeds f ON f.id = a.feed_id '
        . 'WHERE a.id = :id'
    );
    $stmt->execute([':id' => $articleId]);
    $row = $stmt->fetch();
    return $row ?: null;
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
