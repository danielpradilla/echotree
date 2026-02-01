<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';

use GuzzleHttp\Client;

class LinkedInAdapter implements AdapterInterface
{
    public function publish(string $text, string $url, array $account): string
    {
        $token = $account['oauth_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('Missing LinkedIn OAuth token.');
        }

        $authorUrn = getenv('ECHOTREE_LINKEDIN_AUTHOR_URN') ?: '';
        if ($authorUrn === '') {
            throw new RuntimeException('Missing ECHOTREE_LINKEDIN_AUTHOR_URN.');
        }

        $bodyText = $this->buildText($text, $url);

        $client = new Client([
            'timeout' => 15,
        ]);

        $resp = $client->post('https://api.linkedin.com/v2/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'X-Restli-Protocol-Version' => '2.0.0',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'author' => $authorUrn,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $bodyText],
                        'shareMediaCategory' => 'NONE',
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $id = $data['id'] ?? '';
        if ($id === '') {
            throw new RuntimeException('LinkedIn response missing id.');
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
