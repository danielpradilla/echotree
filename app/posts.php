<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/adapters/AdapterFactory.php';
require_once __DIR__ . '/publish_lock.php';

function record_share_history(
    PDO $pdo,
    int $articleId,
    int $postId,
    int $deliveryId,
    string $url,
    string $title,
    string $comment,
    string $platform,
    int $accountId,
    string $accountDisplayName,
    string $accountHandle,
    string $externalId
): void {
    $insert = $pdo->prepare(
        'INSERT INTO share_history '
        . '(url, title, comment, shared_at, status, platform, account_id, account_display_name, account_handle, article_id, post_id, delivery_id, external_id) '
        . 'VALUES (:url, :title, :comment, datetime(\'now\'), :status, :platform, :account_id, :account_display_name, :account_handle, :article_id, :post_id, :delivery_id, :external_id)'
    );

    run_sqlite_write_with_retry(static function () use (
        $insert,
        $url,
        $title,
        $comment,
        $platform,
        $accountId,
        $accountDisplayName,
        $accountHandle,
        $articleId,
        $postId,
        $deliveryId,
        $externalId
    ): void {
        $insert->execute([
            ':url' => $url,
            ':title' => $title !== '' ? $title : null,
            ':comment' => $comment,
            ':status' => 'sent',
            ':platform' => $platform,
            ':account_id' => $accountId,
            ':account_display_name' => $accountDisplayName !== '' ? $accountDisplayName : null,
            ':account_handle' => $accountHandle !== '' ? $accountHandle : null,
            ':article_id' => $articleId > 0 ? $articleId : null,
            ':post_id' => $postId > 0 ? $postId : null,
            ':delivery_id' => $deliveryId > 0 ? $deliveryId : null,
            ':external_id' => $externalId !== '' ? $externalId : null,
        ]);
    }, 'share_history_insert post=' . $postId . ' delivery=' . $deliveryId . ' platform=' . $platform);
}

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

function publishing_stale_minutes(): int
{
    $staleMinutes = (int) (getenv('ECHOTREE_PUBLISHING_STALE_MINUTES') ?: 15);
    return $staleMinutes < 1 ? 15 : $staleMinutes;
}

function create_publisher_run(PDO $pdo, string $triggerType = 'cron'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO publisher_runs (trigger_type, status, started_at) '
        . "VALUES (:trigger_type, 'running', datetime('now'))"
    );

    run_sqlite_write_with_retry(static function () use ($stmt, $triggerType): void {
        $stmt->execute([':trigger_type' => $triggerType]);
    }, 'publisher_run_create');

    return (int) $pdo->lastInsertId();
}

function complete_publisher_run(PDO $pdo, int $runId, string $status, array $stats = [], ?string $note = null, ?string $error = null): void
{
    $stmt = $pdo->prepare(
        'UPDATE publisher_runs '
        . 'SET status = :status, finished_at = datetime(\'now\'), due_post_count = :due_post_count, '
        . 'processed_delivery_count = :processed_delivery_count, sent_count = :sent_count, '
        . 'failed_count = :failed_count, recovered_delivery_count = :recovered_delivery_count, '
        . 'note = :note, error = :error '
        . 'WHERE id = :id'
    );

    run_sqlite_write_with_retry(static function () use ($stmt, $runId, $status, $stats, $note, $error): void {
        $stmt->execute([
            ':status' => $status,
            ':due_post_count' => (int) ($stats['due_post_count'] ?? 0),
            ':processed_delivery_count' => (int) ($stats['processed_delivery_count'] ?? 0),
            ':sent_count' => (int) ($stats['sent_count'] ?? 0),
            ':failed_count' => (int) ($stats['failed_count'] ?? 0),
            ':recovered_delivery_count' => (int) ($stats['recovered_delivery_count'] ?? 0),
            ':note' => $note,
            ':error' => $error,
            ':id' => $runId,
        ]);
    }, 'publisher_run_complete');
}

function record_publisher_run_snapshot(PDO $pdo, string $status, string $note, array $stats = [], ?string $error = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO publisher_runs '
        . '(trigger_type, status, started_at, finished_at, due_post_count, processed_delivery_count, sent_count, failed_count, recovered_delivery_count, note, error) '
        . 'VALUES (:trigger_type, :status, datetime(\'now\'), datetime(\'now\'), :due_post_count, :processed_delivery_count, :sent_count, :failed_count, :recovered_delivery_count, :note, :error)'
    );

    run_sqlite_write_with_retry(static function () use ($stmt, $status, $note, $stats, $error): void {
        $stmt->execute([
            ':trigger_type' => 'cron',
            ':status' => $status,
            ':due_post_count' => (int) ($stats['due_post_count'] ?? 0),
            ':processed_delivery_count' => (int) ($stats['processed_delivery_count'] ?? 0),
            ':sent_count' => (int) ($stats['sent_count'] ?? 0),
            ':failed_count' => (int) ($stats['failed_count'] ?? 0),
            ':recovered_delivery_count' => (int) ($stats['recovered_delivery_count'] ?? 0),
            ':note' => $note,
            ':error' => $error,
        ]);
    }, 'publisher_run_snapshot');
}

function recover_stale_publishing_deliveries(PDO $pdo, int $staleMinutes, ?callable $log = null): int
{
    $window = '-' . $staleMinutes . ' minutes';
    $select = $pdo->prepare(
        "SELECT id, post_id FROM deliveries "
        . "WHERE status = 'publishing' "
        . "AND COALESCE(last_attempted_at, created_at) <= datetime('now', :window)"
    );
    $select->execute([':window' => $window]);
    $rows = $select->fetchAll();
    if (!$rows) {
        return 0;
    }

    $deliveryIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $postIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['post_id'], $rows)));
    $deliveryPlaceholders = implode(', ', array_fill(0, count($deliveryIds), '?'));
    $postPlaceholders = implode(', ', array_fill(0, count($postIds), '?'));

    $resetStmt = $pdo->prepare(
        "UPDATE deliveries SET status = 'pending', error = 'Recovered after stale publishing claim' "
        . "WHERE id IN ($deliveryPlaceholders)"
    );
    run_sqlite_write_with_retry(static function () use ($resetStmt, $deliveryIds): void {
        $resetStmt->execute($deliveryIds);
    }, 'delivery_recover_stale');

    $postStmt = $pdo->prepare(
        "UPDATE posts SET status = 'scheduled' WHERE id IN ($postPlaceholders)"
    );
    run_sqlite_write_with_retry(static function () use ($postStmt, $postIds): void {
        $postStmt->execute($postIds);
    }, 'post_recover_stale');

    publish_log($log, 'Recovered stale deliveries: ' . count($deliveryIds) . "\n");
    return count($deliveryIds);
}

function publish_due_posts(?callable $log = null): void
{
    $pdo = db_connection();
    $lockHandle = acquire_publish_lock();
    if ($lockHandle === null) {
        record_publisher_run_snapshot($pdo, 'lock_skipped', 'Publisher already running; skipping.');
        publish_log($log, "Publisher already running; skipping.\n");
        return;
    }

    $rateLimitMinutes = posting_rate_limit_minutes();
    $runId = create_publisher_run($pdo);
    $stats = [
        'due_post_count' => 0,
        'processed_delivery_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'recovered_delivery_count' => 0,
    ];

    try {
        $stats['recovered_delivery_count'] = recover_stale_publishing_deliveries($pdo, publishing_stale_minutes(), $log);
        $posts = $pdo->query(
            "SELECT id, article_id, comment "
            . "FROM posts "
            . "WHERE status = 'scheduled' AND scheduled_at <= datetime('now') "
            . 'ORDER BY scheduled_at ASC'
        )->fetchAll();
        $stats['due_post_count'] = count($posts);

        if (!$posts) {
            complete_publisher_run($pdo, $runId, 'success', $stats, 'No due posts.');
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
                $log,
                $stats
            );
        }
        complete_publisher_run($pdo, $runId, 'success', $stats, 'Processed due posts.');
    } catch (Throwable $e) {
        complete_publisher_run($pdo, $runId, 'failed', $stats, 'Publisher run failed.', $e->getMessage());
        throw $e;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function publish_post_now(int $postId): bool
{
    $pdo = db_connection();
    $lockHandle = acquire_publish_lock();
    if ($lockHandle === null) {
        return false;
    }

    $rateLimitMinutes = posting_rate_limit_minutes();

    try {
        $postStmt = $pdo->prepare(
            'SELECT id, article_id, comment FROM posts WHERE id = :id LIMIT 1'
        );
        $postStmt->execute([':id' => $postId]);
        $post = $postStmt->fetch();
        if (!$post) {
            return false;
        }

        process_post_deliveries(
            $pdo,
            (int) $post['id'],
            (int) $post['article_id'],
            (string) $post['comment'],
            $rateLimitMinutes,
            null
        );
        return true;
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
    ?callable $log,
    ?array &$stats = null
): void {
    $articleStmt = $pdo->prepare('SELECT url, title FROM articles WHERE id = :id');
    $articleStmt->execute([':id' => $articleId]);
    $article = $articleStmt->fetch();
    $articleUrl = $article ? (string) $article['url'] : '';
    $articleTitle = $article ? (string) ($article['title'] ?? '') : '';

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

        $claim = $pdo->prepare(
            "UPDATE deliveries SET status = 'publishing', error = NULL, last_attempted_at = datetime('now'), "
            . "attempt_count = COALESCE(attempt_count, 0) + 1 "
            . "WHERE id = :id AND status IN ('pending', 'failed')"
        );

        try {
            run_sqlite_write_with_retry(static function () use ($claim, $deliveryId): void {
                $claim->execute([':id' => $deliveryId]);
            }, 'delivery_claim post=' . $postId . ' delivery=' . $deliveryId . ' platform=' . $platform);

            if ($claim->rowCount() === 0) {
                publish_log($log, "Skipped: post {$postId} to {$platform} already claimed\n");
                continue;
            }
            if ($stats !== null) {
                $stats['processed_delivery_count'] = (int) ($stats['processed_delivery_count'] ?? 0) + 1;
            }

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
            run_sqlite_write_with_retry(static function () use ($update, $externalId, $deliveryId): void {
                $update->execute([
                    ':external_id' => $externalId,
                    ':id' => $deliveryId,
                ]);
            }, 'delivery_sent post=' . $postId . ' delivery=' . $deliveryId . ' platform=' . $platform);

            record_share_history(
                $pdo,
                $articleId,
                $postId,
                $deliveryId,
                $articleUrl,
                $articleTitle,
                $comment,
                $platform,
                $accountId,
                (string) ($delivery['display_name'] ?? ''),
                (string) ($delivery['handle'] ?? ''),
                $externalId
            );

            publish_log($log, "Sent: post {$postId} to {$platform}\n");
            if ($stats !== null) {
                $stats['sent_count'] = (int) ($stats['sent_count'] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $isDeliveryAlreadyPublished = isset($externalId) && $externalId !== '';
            if ($isDeliveryAlreadyPublished) {
                publish_log($log, "Publish uncertain: post {$postId} to {$platform} ({$e->getMessage()})\n");
                throw $e;
            }

            $update = $pdo->prepare(
                "UPDATE deliveries SET status = 'failed', error = :error WHERE id = :id"
            );
            run_sqlite_write_with_retry(static function () use ($update, $e, $deliveryId): void {
                $update->execute([
                    ':error' => $e->getMessage(),
                    ':id' => $deliveryId,
                ]);
            }, 'delivery_failed post=' . $postId . ' delivery=' . $deliveryId . ' platform=' . $platform);

            publish_log($log, "Failed: post {$postId} to {$platform} ({$e->getMessage()})\n");
            if ($stats !== null) {
                $stats['failed_count'] = (int) ($stats['failed_count'] ?? 0) + 1;
            }
        }
    }

    $statusRows = $pdo->prepare('SELECT status, COUNT(*) AS count FROM deliveries WHERE post_id = :id GROUP BY status');
    $statusRows->execute([':id' => $postId]);
    $counts = ['pending' => 0, 'publishing' => 0, 'failed' => 0, 'sent' => 0];
    foreach ($statusRows->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['count'];
    }

    if ($counts['pending'] > 0 || $counts['publishing'] > 0) {
        return;
    }

    if ($counts['failed'] > 0) {
        $updatePost = $pdo->prepare("UPDATE posts SET status = 'failed' WHERE id = :id");
        run_sqlite_write_with_retry(static function () use ($updatePost, $postId): void {
            $updatePost->execute([':id' => $postId]);
        }, 'post_failed post=' . $postId);
    } else {
        $updatePost = $pdo->prepare("UPDATE posts SET status = 'sent' WHERE id = :id");
        run_sqlite_write_with_retry(static function () use ($updatePost, $postId): void {
            $updatePost->execute([':id' => $postId]);
        }, 'post_sent post=' . $postId);
    }
}

function run_sqlite_write_with_retry(
    callable $fn,
    string $operation = 'sqlite_write',
    int $maxAttempts = 5,
    int $baseDelayMs = 120
): void
{
    $attempt = 0;
    $start = microtime(true);
    while (true) {
        try {
            $fn();
            if ($attempt > 0) {
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                error_log(
                    '[echotree] sqlite lock recovered operation=' . $operation
                    . ' retries=' . $attempt
                    . ' elapsed_ms=' . $elapsedMs
                );
            }
            return;
        } catch (PDOException $e) {
            $attempt++;
            $locked = stripos($e->getMessage(), 'database is locked') !== false;
            if (!$locked || $attempt >= $maxAttempts) {
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                error_log(
                    '[echotree] sqlite write failed operation=' . $operation
                    . ' retries=' . $attempt
                    . ' elapsed_ms=' . $elapsedMs
                    . ' error=' . $e->getMessage()
                );
                throw $e;
            }

            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
            error_log(
                '[echotree] sqlite lock retry operation=' . $operation
                . ' attempt=' . $attempt
                . ' elapsed_ms=' . $elapsedMs
            );
            usleep($baseDelayMs * $attempt * 1000);
        }
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
