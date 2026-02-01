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
            return trim($text . " " . $url);
        }

        return $text;
    }
}
