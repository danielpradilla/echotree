<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/db.php';

use GuzzleHttp\Client;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\Readability;

$pdo = db_connection();
$pdo->exec('PRAGMA foreign_keys = ON');

if (in_array('--refresh', $argv, true)) {
    $refresh = true;
} else {
    $refresh = false;
}

$feedId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--feed-id=')) {
        $feedId = (int) substr($arg, strlen('--feed-id='));
        break;
    }
}

if ($feedId) {
    $stmt = $pdo->prepare('SELECT id, name, url FROM feeds WHERE is_active = 1 AND id = :id');
    $stmt->execute([':id' => $feedId]);
    $feeds = $stmt->fetchAll();
} else {
    $feeds = $pdo->query('SELECT id, name, url FROM feeds WHERE is_active = 1 ORDER BY id ASC')
        ->fetchAll();
}

if (!$feeds) {
    fwrite(STDOUT, "No active feeds.\n");
    exit(0);
}

$client = new Client([
    'timeout' => 15,
    'headers' => [
        'User-Agent' => 'EchoTree/1.0 (+https://example.com)',
    ],
]);

$config = new Configuration();
$config->setFixRelativeURLs(true);
$config->setOriginalURL(true);

foreach ($feeds as $feed) {
    $feedId = (int) $feed['id'];
    $feedUrl = (string) $feed['url'];
    $feedName = (string) $feed['name'];

    fwrite(STDOUT, "Fetching: {$feedName} ({$feedUrl})\n");

    $sp = new SimplePie();
    $sp->set_feed_url($feedUrl);
    $sp->enable_cache(false);
    $sp->set_timeout(15);

    if (!$sp->init()) {
        fwrite(STDOUT, "  Failed to parse feed: {$feedUrl}\n");
        continue;
    }

    $items = $sp->get_items();
    if (!$items) {
        fwrite(STDOUT, "  No items.\n");
        continue;
    }

    foreach ($items as $item) {
        $url = trim((string) $item->get_permalink());
        if ($url === '') {
            continue;
        }

        $existingId = null;
        $exists = $pdo->prepare('SELECT id FROM articles WHERE url = :url');
        $exists->execute([':url' => $url]);
        $row = $exists->fetch();
        if ($row) {
            $existingId = (int) $row['id'];
            if (!$refresh) {
                continue;
            }
        }

        $title = trim((string) $item->get_title());
        $publishedAt = $item->get_date('Y-m-d H:i:s');

        $contentHtml = trim((string) $item->get_content());
        if ($contentHtml === '') {
            $contentHtml = trim((string) $item->get_description());
        }

        $contentText = trim(strip_tags($contentHtml));

        if ($contentHtml === '' || mb_strlen($contentText) < 400) {
            try {
                $resp = $client->get($url);
                $html = (string) $resp->getBody();
            } catch (Throwable $e) {
                fwrite(STDOUT, "  Failed to fetch article: {$url}\n");
                continue;
            }

            try {
                $readability = new Readability($config);
                $readability->parse($html);
                $contentNode = $readability->getContent();
                if ($contentNode) {
                    $contentHtml = $contentNode->C14N();
                    $contentText = trim(strip_tags($contentHtml));
                }
            } catch (Throwable $e) {
                // Fallback to raw HTML if extraction fails.
            }

            if ($contentHtml === '') {
                $contentHtml = $html;
                $contentText = trim(strip_tags($html));
            }
        }

        if ($existingId) {
            $update = $pdo->prepare(
                'UPDATE articles SET title = :title, content_html = :content_html, content_text = :content_text, '
                . 'published_at = :published_at WHERE id = :id'
            );
            $update->execute([
                ':title' => $title !== '' ? $title : $url,
                ':content_html' => $contentHtml,
                ':content_text' => $contentText,
                ':published_at' => $publishedAt,
                ':id' => $existingId,
            ]);
            fwrite(STDOUT, "  Refreshed: {$title}\n");
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO articles (feed_id, title, url, content_html, content_text, summary, published_at) '
                . 'VALUES (:feed_id, :title, :url, :content_html, :content_text, :summary, :published_at)'
            );
            $insert->execute([
                ':feed_id' => $feedId,
                ':title' => $title !== '' ? $title : $url,
                ':url' => $url,
                ':content_html' => $contentHtml,
                ':content_text' => $contentText,
                ':summary' => null,
                ':published_at' => $publishedAt,
            ]);

            fwrite(STDOUT, "  Added: {$title}\n");
        }
    }

    $update = $pdo->prepare("UPDATE feeds SET last_fetched_at = datetime('now') WHERE id = :id");
    $update->execute([':id' => $feedId]);
}
