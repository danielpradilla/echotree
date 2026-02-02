<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crypto.php';

use GuzzleHttp\Client;

class BlueskyAdapter implements AdapterInterface
{
    public function publish(string $text, string $url, array $account): string
    {
        $tokenPayload = $account['oauth_token'] ?? '';
        if ($tokenPayload === '') {
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
        $tokens = $this->parseTokens($tokenPayload);
        $access = $tokens['access'];
        $refresh = $tokens['refresh'];

        $client = new Client([
            'timeout' => 15,
        ]);

        try {
            $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.repo.createRecord', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access,
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
        } catch (GuzzleHttp\Exception\RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            if ($refresh !== '' && str_contains($body, 'ExpiredToken')) {
                $newTokens = $this->refreshSession($pds, $refresh);
                $this->updateStoredTokens((int) $account['id'], $newTokens);
                $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.repo.createRecord', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $newTokens['access'],
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
            } else {
                throw $e;
            }
        }

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

    private function parseTokens(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && ($decoded['type'] ?? '') === 'bluesky') {
            return [
                'access' => (string) ($decoded['access'] ?? ''),
                'refresh' => (string) ($decoded['refresh'] ?? ''),
            ];
        }

        return [
            'access' => $payload,
            'refresh' => '',
        ];
    }

    private function refreshSession(string $pds, string $refreshJwt): array
    {
        $client = new Client(['timeout' => 15]);
        $resp = $client->post(rtrim($pds, '/') . '/xrpc/com.atproto.server.refreshSession', [
            'headers' => [
                'Authorization' => 'Bearer ' . $refreshJwt,
                'Accept' => 'application/json',
            ],
        ]);
        $data = json_decode((string) $resp->getBody(), true);
        $access = (string) ($data['accessJwt'] ?? '');
        $refresh = (string) ($data['refreshJwt'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new RuntimeException('Failed to refresh Bluesky token.');
        }
        return ['access' => $access, 'refresh' => $refresh];
    }

    private function updateStoredTokens(int $accountId, array $tokens): void
    {
        $payload = json_encode([
            'type' => 'bluesky',
            'access' => $tokens['access'],
            'refresh' => $tokens['refresh'],
        ]);
        $encrypted = encrypt_token($payload);
        $pdo = db_connection();
        $stmt = $pdo->prepare('UPDATE accounts SET oauth_token_encrypted = :token WHERE id = :id');
        $stmt->execute([
            ':token' => $encrypted,
            ':id' => $accountId,
        ]);
    }
}
