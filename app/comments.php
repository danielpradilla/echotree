<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function generate_comment(string $content, string $mode): string
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if ($apiKey === '') {
        throw new RuntimeException('Missing OPENAI_API_KEY.');
    }

    $prompt = '';
    if ($mode === 'summary') {
        $prompt = "Summarize the article in 2-3 concise sentences.";
    } elseif ($mode === 'phrase') {
        $prompt = "Extract one impactful sentence from the article. Return only that sentence.";
    } else {
        $prompt = "Write a concise, thoughtful 1-2 sentence comment about this article. Avoid hashtags.";
    }

    $payload = [
        'model' => 'gpt-4o-mini',
        'input' => $prompt . "\n\n" . $content,
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
    $comment = $data['output'][0]['content'][0]['text'] ?? '';

    if ($comment === '') {
        throw new RuntimeException('OpenAI response missing comment.');
    }

    return trim($comment);
}
