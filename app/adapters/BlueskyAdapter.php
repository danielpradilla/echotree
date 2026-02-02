<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';

use GuzzleHttp\Client;

class BlueskyAdapter implements AdapterInterface
{
    public function publish(string $text, string $url, array $account): string
    {
        $token = $account['oauth_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('Missing Bluesky OAuth token.');
        }

        $repo = $account['handle'] ?? '';
        if ($repo === '') {
            throw new RuntimeException('Missing Bluesky handle for repo.');
        }

        $pds = getenv('ECHOTREE_BLUESKY_PDS') ?: 'https://bsky.social';
        $bodyText = $this->buildText($text, $url);
        $facets = $this->buildLinkFacet($bodyText);
        $embed = $this->buildExternalEmbed($url);

        $client = new Client([
            'timeout' => 15,
        ]);

        $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.repo.createRecord', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'json' => [
                'repo' => $repo,
                'collection' => 'app.bsky.feed.post',
                'record' => [
                    'text' => $bodyText,
                    'createdAt' => gmdate('c'),
                    'facets' => $facets,
                    'embed' => $embed,
                ],
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $uri = $data['uri'] ?? '';
        if ($uri === '') {
            throw new RuntimeException('Bluesky response missing uri.');
        }

        return (string) $uri;
    }

    private function buildText(string $text, string $url): string
    {
        $text = trim($text);
        if ($url !== '') {
            return trim($text . "\n" . $url);
        }

        return $text;
    }

    private function buildLinkFacet(string $text): array
    {
        if (!preg_match('/https?:\\/\\/\\S+/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $url = $match[0][0];
        $start = $match[0][1];
        $end = $start + strlen($url);

        return [[
            'index' => [
                'byteStart' => $start,
                'byteEnd' => $end,
            ],
            'features' => [[
                '$type' => 'app.bsky.richtext.facet#link',
                'uri' => $url,
            ]],
        ]];
    }

    private function buildExternalEmbed(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $resp = $client->get($url, [
                'headers' => ['User-Agent' => 'EchoTree/1.0'],
            ]);
            $html = (string) $resp->getBody();
        } catch (Throwable $e) {
            return null;
        }

        $title = $this->extractMeta($html, 'og:title');
        if ($title === '') {
            $title = $this->extractTitle($html);
        }
        $description = $this->extractMeta($html, 'og:description');
        if ($description === '') {
            $description = $this->extractMeta($html, 'description');
        }

        if ($title === '' && $description === '') {
            return null;
        }

        return [
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => $url,
                'title' => $title !== '' ? $title : $url,
                'description' => $description,
            ],
        ];
    }

    private function extractMeta(string $html, string $name): string
    {
        if ($html === '') {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $query = "//meta[@property='{$name}']|//meta[@name='{$name}']";
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            $content = $nodes->item(0)->getAttribute('content');
            return trim($content);
        }

        return '';
    }

    private function extractTitle(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (preg_match('/<title>(.*?)<\\/title>/si', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        return '';
    }
}
