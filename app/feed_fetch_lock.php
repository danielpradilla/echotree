<?php

declare(strict_types=1);

/**
 * Returns an exclusive lock handle when feed fetching can proceed, otherwise null.
 */
function acquire_feed_fetch_lock()
{
    $lockPath = __DIR__ . '/../data/feed_fetcher.lock';
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        return null;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    return $handle;
}
