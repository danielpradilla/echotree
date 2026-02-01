<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';
require_once __DIR__ . '/TwitterAdapter.php';
require_once __DIR__ . '/MastodonAdapter.php';
require_once __DIR__ . '/BlueskyAdapter.php';
require_once __DIR__ . '/LinkedInAdapter.php';

class AdapterFactory
{
    public static function forPlatform(string $platform): AdapterInterface
    {
        $key = strtolower(trim($platform));
        return match ($key) {
            'twitter', 'x' => new TwitterAdapter(),
            'mastodon' => new MastodonAdapter(),
            'bluesky' => new BlueskyAdapter(),
            'linkedin' => new LinkedInAdapter(),
            default => throw new RuntimeException("Unknown platform: {$platform}"),
        };
    }
}
