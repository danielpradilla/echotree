<?php

declare(strict_types=1);

const ECHOTREE_DEFAULT_SESSION_LIFETIME_SECONDS = 31536000;

function session_lifetime_seconds(): int
{
    $raw = getenv('ECHOTREE_SESSION_LIFETIME_SECONDS');
    if ($raw === false || trim((string) $raw) === '') {
        return ECHOTREE_DEFAULT_SESSION_LIFETIME_SECONDS;
    }

    $seconds = (int) $raw;
    return $seconds < 0 ? 0 : $seconds;
}

function session_gc_lifetime_seconds(): int
{
    $lifetime = session_lifetime_seconds();
    return $lifetime > 0 ? $lifetime : ECHOTREE_DEFAULT_SESSION_LIFETIME_SECONDS;
}
