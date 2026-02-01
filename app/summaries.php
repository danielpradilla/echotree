<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if ($apiKey === '') {
        throw new RuntimeException('Missing OPENAI_API_KEY.');
    }

    $payload = [
        'model' => 'gpt-4o-mini',
        'input' => "Summarize the article in concise bullet points.\n\n" . $content,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('OpenAI request failed.');
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('OpenAI request returned status ' . $status);
    }

    $data = json_decode($raw, true);
    $summary = $data['output'][0]['content'][0]['text'] ?? '';

    if ($summary === '') {
        throw new RuntimeException('OpenAI response missing summary.');
    }

    return $summary;
}
