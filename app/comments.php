<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function is_unusable_phrase_response(string $text): bool
{
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '') {
        return true;
    }

    $blockedFragments = [
        'unable to extract',
        'please provide',
        'if you have a specific',
        'technical jargon',
        'without coherent narrative',
        'as an ai',
        'i cannot',
        'i can\'t',
    ];

    foreach ($blockedFragments as $fragment) {
        if (str_contains($normalized, $fragment)) {
            return true;
        }
    }

    if (preg_match('/\b(sorry|unable|cannot|can\'t|script rather than|coherent sentences|let me know|assist)\b/u', $normalized) === 1) {
        return true;
    }

    return false;
}

function normalize_phrase_response(string $text): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
    if ($normalized === '') {
        return '';
    }

    $normalized = trim($normalized, " \t\n\r\0\x0B\"'`");
    $parts = preg_split('/(?<=[\.\!\?])\s+/u', $normalized) ?: [];
    foreach ($parts as $part) {
        $candidate = trim($part);
        if ($candidate === '') {
            continue;
        }

        $length = mb_strlen($candidate);
        if ($length >= 25 && $length <= 260) {
            return $candidate;
        }
    }

    return '';
}

function extract_impactful_fallback_sentence(string $content): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($content)) ?? '';
    if ($normalized === '') {
        return 'Key takeaway: this article describes meaningful technical changes and their impact.';
    }

    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $normalized) ?: [];
    $keywords = [
        'must', 'critical', 'important', 'impact', 'risk', 'security', 'performance',
        'faster', 'improve', 'fix', 'failure', 'downtime', 'cost', 'reliable',
    ];

    $best = '';
    $bestScore = -1;
    foreach ($sentences as $sentence) {
        $candidate = trim($sentence);
        $length = mb_strlen($candidate);
        if ($length < 40 || $length > 260) {
            continue;
        }

        $score = 0;
        $lower = mb_strtolower($candidate);
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                $score++;
            }
        }
        if (preg_match('/\d/', $candidate) === 1) {
            $score++;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    if ($best !== '') {
        return $best;
    }

    foreach ($sentences as $sentence) {
        $candidate = trim($sentence);
        $length = mb_strlen($candidate);
        if ($length >= 25 && $length <= 260) {
            return $candidate;
        }
    }

    $snippet = mb_substr($normalized, 0, 220);
    if (!preg_match('/[\.!\?]$/u', $snippet)) {
        $snippet .= '.';
    }
    return $snippet;
}

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
        $prompt = "Select exactly one sentence from the text that feels most impactful. "
            . "Do not add explanations, caveats, or meta commentary. "
            . "If the text is technical, choose the sentence with the clearest consequence, change, or risk. "
            . "Return only the selected sentence.";
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

    $comment = trim($comment);
    if ($mode === 'phrase') {
        if (is_unusable_phrase_response($comment)) {
            return extract_impactful_fallback_sentence($content);
        }

        $normalizedPhrase = normalize_phrase_response($comment);
        if ($normalizedPhrase === '' || is_unusable_phrase_response($normalizedPhrase)) {
            return extract_impactful_fallback_sentence($content);
        }

        return $normalizedPhrase;
    }

    return $comment;
}
