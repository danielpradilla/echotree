<?php

declare(strict_types=1);

require_once __DIR__ . '/article_extractor.php';

function fetch_feeds(PDO $pdo, array $options = [], ?callable $log = null): void
{
    $refresh = (bool) ($options['refresh'] ?? false);
    $feedId = isset($options['feed_id']) ? (int) $options['feed_id'] : null;
    $maxPerFeed = (int) ($options['max_per_feed'] ?? (getenv('ECHOTREE_FEED_MAX_ITEMS') ?: 30));
    if ($maxPerFeed < 1) {
        $maxPerFeed = 30;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($feedId) {
        $stmt = $pdo->prepare('SELECT id, name, url FROM feeds WHERE is_active = 1 AND id = :id');
        $stmt->execute([':id' => $feedId]);
        $feeds = $stmt->fetchAll();
    } else {
        $feeds = $pdo->query('SELECT id, name, url FROM feeds WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
    }

    if (!$feeds) {
        if ($log) {
            $log("No active feeds.\n");
        }
        return;
    }

    foreach ($feeds as $feed) {
        $feedId = (int) $feed['id'];
        $feedUrl = (string) $feed['url'];
        $feedName = (string) $feed['name'];

        if ($log) {
            $log("Fetching: {$feedName} ({$feedUrl})\n");
        }

        $sp = new SimplePie();
        $sp->set_feed_url($feedUrl);
        $sp->enable_cache(false);
        $sp->set_timeout(15);

        if (!$sp->init()) {
            if ($log) {
                $log("  Failed to parse feed: {$feedUrl}\n");
            }
            continue;
        }

        $items = $sp->get_items();
        if (!$items) {
            if ($log) {
                $log("  No items.\n");
            }
            continue;
        }

        usort($items, function ($a, $b) {
            $ad = (int) $a->get_date('U');
            $bd = (int) $b->get_date('U');
            return $bd <=> $ad;
        });

        $count = 0;
        foreach ($items as $item) {
            if ($count >= $maxPerFeed) {
                break;
            }
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

            $extracted = extract_and_merge_article_content($url, $title, $contentHtml, 15);
            $title = trim((string) ($extracted['title'] ?? $title));
            $contentHtml = (string) ($extracted['content_html'] ?? $contentHtml);
            $contentText = (string) ($extracted['content_text'] ?? trim(strip_tags($contentHtml)));

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
                if ($log) {
                    $log("  Refreshed: {$title}\n");
                }
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

                if ($log) {
                    $log("  Added: {$title}\n");
                }
            }

            $count++;
        }

        $update = $pdo->prepare("UPDATE feeds SET last_fetched_at = datetime('now') WHERE id = :id");
        $update->execute([':id' => $feedId]);
    }
}
