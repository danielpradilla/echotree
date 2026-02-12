<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\Readability;

function extract_article_from_url(string $url, int $timeoutSeconds = 15): array
{
    $title = $url;
    $contentHtml = '';
    $contentText = '';
    $rawHtml = '';

    try {
        $client = new Client([
            'timeout' => $timeoutSeconds,
            'headers' => [
                'User-Agent' => 'EchoTree/1.0 (+https://example.com)',
            ],
        ]);
        $resp = $client->get($url);
        $rawHtml = (string) $resp->getBody();
    } catch (Throwable $e) {
        return [
            'title' => $title,
            'content_html' => $contentHtml,
            'content_text' => $contentText,
        ];
    }

    if ($rawHtml === '') {
        return [
            'title' => $title,
            'content_html' => $contentHtml,
            'content_text' => $contentText,
        ];
    }

    $parsedTitle = extract_title_from_html($rawHtml);
    if ($parsedTitle !== '') {
        $title = $parsedTitle;
    }

    try {
        $config = new Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL(true);
        $readability = new Readability($config);
        $readability->parse($rawHtml);
        $contentNode = $readability->getContent();
        if ($contentNode) {
            $candidateHtml = trim((string) $contentNode->C14N());
            $candidateText = normalize_extracted_text(strip_tags($candidateHtml));
            if ($candidateHtml !== '') {
                $contentHtml = $candidateHtml;
            }
            if ($candidateText !== '') {
                $contentText = $candidateText;
            }
        }
    } catch (Throwable $e) {
        // Continue with DOM and raw fallbacks.
    }

    $domText = extract_text_from_dom($rawHtml);
    if (is_better_text_candidate($domText, $contentText)) {
        $contentText = $domText;
    }

    if ($contentHtml === '') {
        $contentHtml = $rawHtml;
    }

    if ($contentText === '') {
        $contentText = normalize_extracted_text(strip_tags($rawHtml));
    }

    return [
        'title' => $title,
        'content_html' => $contentHtml,
        'content_text' => $contentText,
    ];
}

function extract_title_from_html(string $html): string
{
    if (preg_match('/<meta\\s+property=["\']og:title["\']\\s+content=["\'](.*?)["\']/si', $html, $m)) {
        return normalize_extracted_text(html_entity_decode((string) $m[1], ENT_QUOTES, 'UTF-8'));
    }

    if (preg_match('/<title>(.*?)<\\/title>/si', $html, $m)) {
        return normalize_extracted_text(html_entity_decode((string) $m[1], ENT_QUOTES, 'UTF-8'));
    }

    if (preg_match('/<h1[^>]*>(.*?)<\\/h1>/si', $html, $m)) {
        return normalize_extracted_text(strip_tags((string) $m[1]));
    }

    return '';
}

function extract_text_from_dom(string $html): string
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($dom);
    $removals = $xpath->query('//script|//style|//noscript|//svg|//form|//nav|//footer|//header|//aside');
    if ($removals instanceof DOMNodeList) {
        foreach ($removals as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $nodeQueries = [
        '//article//p',
        '//main//p',
        '//p',
        '//article//li',
        '//main//li',
        '//blockquote',
    ];

    $chunks = [];
    foreach ($nodeQueries as $query) {
        $nodes = $xpath->query($query);
        if (!($nodes instanceof DOMNodeList)) {
            continue;
        }
        foreach ($nodes as $node) {
            $text = normalize_extracted_text((string) $node->textContent);
            if ($text === '' || mb_strlen($text) < 40) {
                continue;
            }
            if (!preg_match('/[A-Za-z]/', $text)) {
                continue;
            }
            $chunks[] = $text;
            if (count($chunks) >= 120) {
                break 2;
            }
        }
        if (count($chunks) >= 20) {
            break;
        }
    }

    return trim(implode("\n\n", array_unique($chunks)));
}

function normalize_extracted_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function is_better_text_candidate(string $candidate, string $current): bool
{
    if ($candidate === '') {
        return false;
    }
    if ($current === '') {
        return true;
    }

    $candidateScore = text_candidate_score($candidate);
    $currentScore = text_candidate_score($current);
    return $candidateScore > $currentScore;
}

function text_candidate_score(string $text): int
{
    $lengthScore = min(5000, mb_strlen($text));
    $sentenceScore = preg_match_all('/[.!?](?:\\s|$)/', $text) ?: 0;
    return $lengthScore + ($sentenceScore * 120);
}
