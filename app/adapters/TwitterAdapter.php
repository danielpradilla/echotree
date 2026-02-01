<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';

use GuzzleHttp\Client;

class TwitterAdapter implements AdapterInterface
{
    public function publish(string $text, string $url, array $account): string
    {
        $token = $account['oauth_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('Missing Twitter OAuth token.');
        }

        $bodyText = $this->buildText($text, $url);

        $client = new Client([
            'timeout' => 15,
        ]);

        $resp = $client->post('https://api.twitter.com/2/tweets', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'json' => [
                'text' => $bodyText,
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $id = $data['data']['id'] ?? '';
        if ($id === '') {
            throw new RuntimeException('Twitter response missing id.');
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
