<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../app/env.php';
load_env(__DIR__ . '/../.env');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/feed_fetcher.php';

$pdo = db_connection();

$refresh = in_array('--refresh', $argv, true);

$feedId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--feed-id=')) {
        $feedId = (int) substr($arg, strlen('--feed-id='));
        break;
    }
}

fetch_feeds(
    $pdo,
    [
        'refresh' => $refresh,
        'feed_id' => $feedId,
    ],
    function (string $line): void {
        fwrite(STDOUT, $line);
    }
);
