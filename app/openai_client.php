<?php

declare(strict_types=1);

function openai_generate_text(string $input, string $model = 'gpt-4o-mini', int $timeoutSeconds = 30): string
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if ($apiKey === '') {
        throw new RuntimeException('Missing OPENAI_API_KEY.');
    }

    $payload = [
        'model' => $model,
        'input' => $input,
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
        CURLOPT_TIMEOUT => $timeoutSeconds,
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
    $text = $data['output'][0]['content'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('OpenAI response missing text.');
    }

    return trim($text);
}
