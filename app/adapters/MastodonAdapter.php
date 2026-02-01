<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';

use GuzzleHttp\Client;

class MastodonAdapter implements AdapterInterface
{
    public function publish(string $text, string $url, array $account): string
    {
        $token = $account['oauth_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('Missing Mastodon OAuth token.');
        }

        $baseUrl = getenv('ECHOTREE_MASTODON_BASE_URL') ?: '';
        if ($baseUrl === '') {
            throw new RuntimeException('Missing ECHOTREE_MASTODON_BASE_URL.');
        }

        $bodyText = $this->buildText($text, $url);

        $client = new Client([
            'timeout' => 15,
        ]);

        $resp = $client->post(rtrim($baseUrl, '/') . '/api/v1/statuses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'form_params' => [
                'status' => $bodyText,
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $id = $data['id'] ?? '';
        if ($id === '') {
            throw new RuntimeException('Mastodon response missing id.');
        }

        return (string) $id;
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
