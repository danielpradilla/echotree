<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
