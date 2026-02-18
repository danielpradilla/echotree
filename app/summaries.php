<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_client.php';

function get_article_summary(int $articleId): ?string
{
    $pdo = db_connection();
    $stmt = $pdo->prepare('SELECT summary FROM articles WHERE id = :id');
    $stmt->execute([':id' => $articleId]);
    $row = $stmt->fetch();

    return $row ? (string) $row['summary'] : null;
}

function save_article_summary(int $articleId, string $summary): void
{
    $pdo = db_connection();
    $stmt = $pdo->prepare('UPDATE articles SET summary = :summary WHERE id = :id');
    $stmt->execute([
        ':summary' => $summary,
        ':id' => $articleId,
    ]);
}

function generate_summary(string $content): string
{
    return openai_generate_text("Summarize the article in concise bullet points.\n\n" . $content);
}
