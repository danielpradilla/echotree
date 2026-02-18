<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_client.php';

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

    $comment = openai_generate_text($prompt . "\n\n" . $content);
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
