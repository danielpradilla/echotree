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

        $authHeader = $this->buildAuthHeader($token, 'POST', 'https://api.twitter.com/2/tweets');

        $resp = $client->post('https://api.twitter.com/2/tweets', [
            'headers' => [
                'Authorization' => $authHeader,
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

    private function buildAuthHeader(string $tokenPayload, string $method, string $url): string
    {
        $decoded = json_decode($tokenPayload, true);
        if (is_array($decoded) && ($decoded['type'] ?? '') === 'oauth1') {
            $consumerKey = getenv('ECHOTREE_X_API_KEY') ?: '';
            $consumerSecret = getenv('ECHOTREE_X_API_SECRET') ?: '';
            if ($consumerKey === '' || $consumerSecret === '') {
                throw new RuntimeException('Missing ECHOTREE_X_API_KEY or ECHOTREE_X_API_SECRET.');
            }

            $token = (string) ($decoded['token'] ?? '');
            $secret = (string) ($decoded['secret'] ?? '');
            if ($token === '' || $secret === '') {
                throw new RuntimeException('Missing OAuth 1.0a token or secret.');
            }

            return $this->oauth1Header($method, $url, $consumerKey, $consumerSecret, $token, $secret);
        }

        return 'Bearer ' . $tokenPayload;
    }

    private function oauth1Header(string $method, string $url, string $consumerKey, string $consumerSecret, string $token, string $tokenSecret): string
    {
        $params = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        $base = $this->signatureBaseString($method, $url, $params);
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $signingKey, true));

        $headerParams = [];
        foreach ($params as $key => $value) {
            $headerParams[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParams);
    }

    private function signatureBaseString(string $method, string $url, array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $paramString = implode('&', $pairs);

        return strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
    }
}
