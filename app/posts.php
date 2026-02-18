<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/adapters/AdapterFactory.php';
require_once __DIR__ . '/publish_lock.php';

function create_post_submit_token(): string
{
    $tokens = (array) ($_SESSION['post_submit_tokens'] ?? []);
    $now = time();

    // Keep only recent tokens so session state stays small.
    foreach ($tokens as $token => $createdAt) {
        if (!is_int($createdAt) || $createdAt < ($now - 7200)) {
            unset($tokens[$token]);
        }
    }

    $token = bin2hex(random_bytes(16));
    $tokens[$token] = $now;
    $_SESSION['post_submit_tokens'] = $tokens;

    return $token;
}

function consume_post_submit_token(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $tokens = (array) ($_SESSION['post_submit_tokens'] ?? []);
    $now = time();

    foreach ($tokens as $key => $createdAt) {
        if (!is_int($createdAt) || $createdAt < ($now - 7200)) {
            unset($tokens[$key]);
        }
    }

    if (!isset($tokens[$token])) {
        $_SESSION['post_submit_tokens'] = $tokens;
        return false;
    }

    unset($tokens[$token]);
    $_SESSION['post_submit_tokens'] = $tokens;
    return true;
}

function create_scheduled_post(int $articleId, string $comment, string $scheduledAt, array $accountIds): int
{
    $pdo = db_connection();
    $pdo->beginTransaction();

    try {
        $insertPost = $pdo->prepare(
            'INSERT INTO posts (article_id, comment, scheduled_at, status) '
            . 'VALUES (:article_id, :comment, :scheduled_at, :status)'
        );
        $insertPost->execute([
            ':article_id' => $articleId,
            ':comment' => $comment,
            ':scheduled_at' => $scheduledAt,
            ':status' => 'scheduled',
        ]);

        $postId = (int) $pdo->lastInsertId();

        $insertDelivery = $pdo->prepare(
            'INSERT INTO deliveries (post_id, account_id, status) '
            . 'VALUES (:post_id, :account_id, :status)'
        );

        foreach ($accountIds as $accountId) {
            $insertDelivery->execute([
                ':post_id' => $postId,
                ':account_id' => $accountId,
                ':status' => 'pending',
            ]);
        }

        $pdo->commit();
        return $postId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function posting_rate_limit_minutes(): int
{
    $rateLimitMinutes = (int) (
        getenv('ECHOTREE_POST_RATE_LIMIT_MINUTES')
        ?: (getenv('ECHOTREE_RATE_LIMIT_MINUTES') ?: 10)
    );
    return $rateLimitMinutes < 1 ? 10 : $rateLimitMinutes;
}

function publish_due_posts(?callable $log = null): void
{
    $pdo = db_connection();
    $lockHandle = acquire_publish_lock();
    if ($lockHandle === null) {
        publish_log($log, "Publisher already running; skipping.\n");
        return;
    }

    $rateLimitMinutes = posting_rate_limit_minutes();

    try {
        $posts = $pdo->query(
            "SELECT id, article_id, comment "
            . "FROM posts "
            . "WHERE status = 'scheduled' AND scheduled_at <= datetime('now') "
            . 'ORDER BY scheduled_at ASC'
        )->fetchAll();

        if (!$posts) {
            publish_log($log, "No due posts.\n");
            return;
        }

        foreach ($posts as $post) {
            process_post_deliveries(
                $pdo,
                (int) $post['id'],
                (int) $post['article_id'],
                (string) $post['comment'],
                $rateLimitMinutes,
                $log
            );
        }
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function publish_post_now(int $postId): void
{
    $pdo = db_connection();
    $lockHandle = acquire_publish_lock();
    if ($lockHandle === null) {
        return;
    }

    $rateLimitMinutes = posting_rate_limit_minutes();

    try {
        $postStmt = $pdo->prepare(
            'SELECT id, article_id, comment FROM posts WHERE id = :id LIMIT 1'
        );
        $postStmt->execute([':id' => $postId]);
        $post = $postStmt->fetch();
        if (!$post) {
            return;
        }

        process_post_deliveries(
            $pdo,
            (int) $post['id'],
            (int) $post['article_id'],
            (string) $post['comment'],
            $rateLimitMinutes,
            null
        );
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function process_post_deliveries(
    PDO $pdo,
    int $postId,
    int $articleId,
    string $comment,
    int $rateLimitMinutes,
    ?callable $log
): void {
    $articleStmt = $pdo->prepare('SELECT url FROM articles WHERE id = :id');
    $articleStmt->execute([':id' => $articleId]);
    $article = $articleStmt->fetch();
    $articleUrl = $article ? (string) $article['url'] : '';

    $deliveriesStmt = $pdo->prepare(
        'SELECT d.id, d.account_id, d.status, a.platform, a.display_name, a.handle, a.oauth_token_encrypted '
        . 'FROM deliveries d '
        . 'JOIN accounts a ON a.id = d.account_id '
        . 'WHERE d.post_id = :post_id AND d.status IN (\'pending\', \'failed\') AND a.is_active = 1 '
        . 'ORDER BY d.id'
    );
    $deliveriesStmt->execute([':post_id' => $postId]);
    $deliveries = $deliveriesStmt->fetchAll();

    if (!$deliveries) {
        return;
    }

    foreach ($deliveries as $delivery) {
        $deliveryId = (int) $delivery['id'];
        $accountId = (int) $delivery['account_id'];
        $platform = (string) $delivery['platform'];

        $recent = $pdo->prepare(
            "SELECT 1 FROM deliveries "
            . "WHERE account_id = :account_id AND status = 'sent' "
            . "AND sent_at >= datetime('now', :window) "
            . 'LIMIT 1'
        );
        $recent->execute([
            ':account_id' => $accountId,
            ':window' => '-' . $rateLimitMinutes . ' minutes',
        ]);
        if ($recent->fetch()) {
            publish_log($log, "Rate limit: account {$accountId} (post {$postId})\n");
            continue;
        }

        try {
            $token = decrypt_token((string) $delivery['oauth_token_encrypted']);
            $externalId = publish_via_adapter_for_post($platform, $comment, $articleUrl, [
                'id' => $accountId,
                'platform' => $platform,
                'display_name' => $delivery['display_name'],
                'handle' => $delivery['handle'],
                'oauth_token' => $token,
            ]);

            $update = $pdo->prepare(
                "UPDATE deliveries SET status = 'sent', sent_at = datetime('now'), external_id = :external_id, error = NULL "
                . 'WHERE id = :id'
            );
            $update->execute([
                ':external_id' => $externalId,
                ':id' => $deliveryId,
            ]);

            publish_log($log, "Sent: post {$postId} to {$platform}\n");
        } catch (Throwable $e) {
            $update = $pdo->prepare(
                "UPDATE deliveries SET status = 'failed', error = :error WHERE id = :id"
            );
            $update->execute([
                ':error' => $e->getMessage(),
                ':id' => $deliveryId,
            ]);

            publish_log($log, "Failed: post {$postId} to {$platform} ({$e->getMessage()})\n");
        }
    }

    $statusRows = $pdo->prepare('SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status');
    $statusRows->execute([':id' => $postId]);
    $counts = ['pending' => 0, 'failed' => 0, 'sent' => 0];
    foreach ($statusRows->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['count'];
    }

    if ($counts['pending'] > 0) {
        return;
    }

    if ($counts['failed'] > 0) {
        $pdo->prepare("UPDATE posts SET status = 'failed' WHERE id = :id")
            ->execute([':id' => $postId]);
    } else {
        $pdo->prepare("UPDATE posts SET status = 'sent' WHERE id = :id")
            ->execute([':id' => $postId]);
    }
}

function publish_log(?callable $log, string $line): void
{
    if ($log !== null) {
        $log($line);
    }
}

function publish_via_adapter_for_post(string $platform, string $comment, string $url, array $account): string
{
    $adapter = AdapterFactory::forPlatform($platform);
    return $adapter->publish($comment, $url, $account);
}
