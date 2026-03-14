<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../app/env.php';
load_env(__DIR__ . '/../.env');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/feed_fetch_lock.php';
require __DIR__ . '/../app/feed_fetcher.php';

$pdo = db_connection();

$refresh = in_array('--refresh', $argv, true);
$skipExtraction = in_array('--skip-extraction', $argv, true);

$feedId = null;
$maxFeeds = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--feed-id=')) {
        $feedId = (int) substr($arg, strlen('--feed-id='));
        continue;
    }

    if (str_starts_with($arg, '--max-feeds=')) {
        $maxFeeds = (int) substr($arg, strlen('--max-feeds='));
    }
}

$lockHandle = acquire_feed_fetch_lock();
if ($lockHandle === null) {
    fwrite(STDOUT, "Feed fetcher already running; skipping.\n");
    exit(0);
}

try {
    fetch_feeds(
        $pdo,
        [
            'refresh' => $refresh,
            'feed_id' => $feedId,
            'max_feeds' => $maxFeeds,
            'extract_full_content' => !$skipExtraction,
        ],
        function (string $line): void {
            fwrite(STDOUT, $line);
        }
    );
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
