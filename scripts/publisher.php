<?php

declare(strict_types=1);

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/crypto.php';
require __DIR__ . '/../app/adapters/AdapterFactory.php';

$pdo = db_connection();
$pdo->exec('PRAGMA foreign_keys = ON');

$posts = $pdo->query(
    "SELECT id, article_id, comment, scheduled_at "
    . "FROM posts "
    . "WHERE status = 'scheduled' AND scheduled_at <= datetime('now') "
    . "ORDER BY scheduled_at ASC"
)->fetchAll();

if (!$posts) {
    fwrite(STDOUT, "No due posts.\n");
    exit(0);
}

foreach ($posts as $post) {
    $postId = (int) $post['id'];
    $articleId = (int) $post['article_id'];

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
        continue;
    }

    foreach ($deliveries as $delivery) {
        $deliveryId = (int) $delivery['id'];
        $accountId = (int) $delivery['account_id'];
        $platform = (string) $delivery['platform'];

        $recent = $pdo->prepare(
            "SELECT 1 FROM deliveries "
            . "WHERE account_id = :account_id AND status = 'sent' "
            . "AND sent_at >= datetime('now', '-10 minutes') "
            . "LIMIT 1"
        );
        $recent->execute([':account_id' => $accountId]);
        if ($recent->fetch()) {
            fwrite(STDOUT, "Rate limit: account {$accountId} (post {$postId})\n");
            continue;
        }

        try {
            $token = decrypt_token((string) $delivery['oauth_token_encrypted']);
            $externalId = publish_via_adapter($platform, $post['comment'], $articleUrl, [
                'id' => $accountId,
                'platform' => $platform,
                'display_name' => $delivery['display_name'],
                'handle' => $delivery['handle'],
                'oauth_token' => $token,
            ]);

            $update = $pdo->prepare(
                "UPDATE deliveries SET status = 'sent', sent_at = datetime('now'), external_id = :external_id, error = NULL "
                . "WHERE id = :id"
            );
            $update->execute([
                ':external_id' => $externalId,
                ':id' => $deliveryId,
            ]);

            fwrite(STDOUT, "Sent: post {$postId} to {$platform}\n");
        } catch (Throwable $e) {
            $update = $pdo->prepare(
                "UPDATE deliveries SET status = 'failed', error = :error WHERE id = :id"
            );
            $update->execute([
                ':error' => $e->getMessage(),
                ':id' => $deliveryId,
            ]);

            fwrite(STDOUT, "Failed: post {$postId} to {$platform} ({$e->getMessage()})\n");
        }
    }

    $statusRows = $pdo->prepare('SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status');
    $statusRows->execute([':id' => $postId]);
    $statusCounts = $statusRows->fetchAll();

    $counts = ['pending' => 0, 'failed' => 0, 'sent' => 0];
    foreach ($statusCounts as $row) {
        $counts[$row['status']] = (int) $row['count'];
    }

    if ($counts['pending'] > 0) {
        continue;
    }

    if ($counts['failed'] > 0) {
        $pdo->prepare("UPDATE posts SET status = 'failed' WHERE id = :id")
            ->execute([':id' => $postId]);
    } else {
        $pdo->prepare("UPDATE posts SET status = 'sent' WHERE id = :id")
            ->execute([':id' => $postId]);
    }
}

function publish_via_adapter(string $platform, string $comment, string $url, array $account): string
{
    $adapter = AdapterFactory::forPlatform($platform);
    return $adapter->publish($comment, $url, $account);
}
