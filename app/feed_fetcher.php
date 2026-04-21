<?php

declare(strict_types=1);

require_once __DIR__ . '/article_extractor.php';

function update_feed_fetch_state(PDO $pdo, int $feedId, ?string $lastFetchError = null, bool $touchLastFetchedAt = true): void
{
    if ($touchLastFetchedAt) {
        $update = $pdo->prepare(
            "UPDATE feeds SET last_fetched_at = datetime('now'), last_fetch_error = :last_fetch_error WHERE id = :id"
        );
    } else {
        $update = $pdo->prepare(
            'UPDATE feeds SET last_fetch_error = :last_fetch_error WHERE id = :id'
        );
    }

    $update->execute([
        ':id' => $feedId,
        ':last_fetch_error' => $lastFetchError,
    ]);
}

function fetch_feeds(PDO $pdo, array $options = [], ?callable $log = null): array
{
    $refresh = (bool) ($options['refresh'] ?? false);
    $feedId = isset($options['feed_id']) ? (int) $options['feed_id'] : null;
    $maxFeeds = isset($options['max_feeds']) ? (int) $options['max_feeds'] : null;
    $extractFullContent = (bool) ($options['extract_full_content'] ?? true);
    $maxPerFeed = (int) ($options['max_per_feed'] ?? (getenv('ECHOTREE_FEED_MAX_ITEMS') ?: 30));
    if ($maxPerFeed < 1) {
        $maxPerFeed = 30;
    }
    if ($maxFeeds !== null && $maxFeeds < 1) {
        $maxFeeds = null;
    }

    $pdo->exec('PRAGMA foreign_keys = ON');

    $report = [
        'summary' => [
            'checked' => 0,
            'added' => 0,
            'refreshed' => 0,
            'skipped_existing' => 0,
            'empty' => 0,
            'failed' => 0,
        ],
        'feeds' => [],
    ];

    if ($feedId) {
        $stmt = $pdo->prepare('SELECT id, name, url, is_active FROM feeds WHERE id = :id');
        $stmt->execute([':id' => $feedId]);
        $feeds = $stmt->fetchAll();
    } else {
        $query = 'SELECT id, name, url, is_active FROM feeds WHERE is_active = 1 '
            . 'ORDER BY CASE WHEN last_fetched_at IS NULL THEN 0 ELSE 1 END, last_fetched_at ASC, id ASC';
        if ($maxFeeds !== null) {
            $query .= ' LIMIT ' . (int) $maxFeeds;
        }
        $feeds = $pdo->query($query)->fetchAll();
    }

    if (!$feeds) {
        if ($log) {
            $log("No active feeds.\n");
        }
        return $report;
    }

    foreach ($feeds as $feed) {
        $feedId = (int) $feed['id'];
        $feedUrl = (string) $feed['url'];
        $feedName = (string) $feed['name'];
        $feedReport = [
            'feed_id' => $feedId,
            'feed_name' => $feedName,
            'status' => 'checked',
            'added' => 0,
            'refreshed' => 0,
            'skipped_existing' => 0,
        ];

        if ($log) {
            $log("Fetching: {$feedName} ({$feedUrl})\n");
        }

        $sp = new SimplePie();
        $sp->set_feed_url($feedUrl);
        $sp->enable_cache(false);
        $sp->set_timeout(15);

        if (!$sp->init()) {
            $feedReport['status'] = 'parse_failed';
            $error = trim((string) $sp->error());
            if ($error === '') {
                $error = 'Unknown feed parsing error.';
            }
            $feedReport['error'] = $error;
            $report['summary']['failed']++;
            $report['feeds'][] = $feedReport;
            update_feed_fetch_state($pdo, $feedId, $error, false);
            if ($log) {
                $log("  Failed to parse feed: {$feedUrl} ({$error})\n");
            }
            continue;
        }

        $items = $sp->get_items();
        if (!$items) {
            update_feed_fetch_state($pdo, $feedId);
            $feedReport['status'] = 'empty';
            $report['summary']['checked']++;
            $report['summary']['empty']++;
            $report['feeds'][] = $feedReport;
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
                    $feedReport['skipped_existing']++;
                    $report['summary']['skipped_existing']++;
                    continue;
                }
            }

            $title = trim((string) $item->get_title());
            $publishedAt = $item->get_date('Y-m-d H:i:s');

            $feedContentHtml = trim((string) $item->get_content());
            if ($feedContentHtml === '') {
                $feedContentHtml = trim((string) $item->get_description());
            }
            $feedContentText = trim(strip_tags($feedContentHtml));
            $extractedContentHtml = '';
            $extractedContentText = '';

            if ($extractFullContent) {
                $extracted = extract_and_merge_article_content($url, $title, $feedContentHtml, 15);
                $title = trim((string) ($extracted['title'] ?? $title));
                $extractedContentHtml = trim((string) ($extracted['content_html'] ?? ''));
                $extractedContentText = trim((string) ($extracted['content_text'] ?? ''));
            }

            $contentHtml = $extractedContentHtml !== '' ? $extractedContentHtml : $feedContentHtml;
            $contentText = $extractedContentText !== '' ? $extractedContentText : $feedContentText;

            if ($existingId) {
                $update = $pdo->prepare(
                    'UPDATE articles SET title = :title, feed_content_html = :feed_content_html, '
                    . 'feed_content_text = :feed_content_text, extracted_content_html = :extracted_content_html, '
                    . 'extracted_content_text = :extracted_content_text, content_html = :content_html, '
                    . 'content_text = :content_text, published_at = :published_at WHERE id = :id'
                );
                $update->execute([
                    ':title' => $title !== '' ? $title : $url,
                    ':feed_content_html' => $feedContentHtml !== '' ? $feedContentHtml : null,
                    ':feed_content_text' => $feedContentText !== '' ? $feedContentText : null,
                    ':extracted_content_html' => $extractedContentHtml !== '' ? $extractedContentHtml : null,
                    ':extracted_content_text' => $extractedContentText !== '' ? $extractedContentText : null,
                    ':content_html' => $contentHtml,
                    ':content_text' => $contentText,
                    ':published_at' => $publishedAt,
                    ':id' => $existingId,
                ]);
                $feedReport['refreshed']++;
                $report['summary']['refreshed']++;
                if ($log) {
                    $log("  Refreshed: {$title}\n");
                }
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO articles (feed_id, title, url, feed_content_html, feed_content_text, extracted_content_html, '
                    . 'extracted_content_text, content_html, content_text, summary, published_at) '
                    . 'VALUES (:feed_id, :title, :url, :feed_content_html, :feed_content_text, :extracted_content_html, '
                    . ':extracted_content_text, :content_html, :content_text, :summary, :published_at)'
                );
                $insert->execute([
                    ':feed_id' => $feedId,
                    ':title' => $title !== '' ? $title : $url,
                    ':url' => $url,
                    ':feed_content_html' => $feedContentHtml !== '' ? $feedContentHtml : null,
                    ':feed_content_text' => $feedContentText !== '' ? $feedContentText : null,
                    ':extracted_content_html' => $extractedContentHtml !== '' ? $extractedContentHtml : null,
                    ':extracted_content_text' => $extractedContentText !== '' ? $extractedContentText : null,
                    ':content_html' => $contentHtml,
                    ':content_text' => $contentText,
                    ':summary' => null,
                    ':published_at' => $publishedAt,
                ]);
                $feedReport['added']++;
                $report['summary']['added']++;

                if ($log) {
                    $log("  Added: {$title}\n");
                }
            }

            $count++;
        }

        update_feed_fetch_state($pdo, $feedId);
        $report['summary']['checked']++;
        if ($feedReport['added'] > 0) {
            $feedReport['status'] = 'added';
        } elseif ($feedReport['refreshed'] > 0) {
            $feedReport['status'] = 'refreshed';
        }
        $report['feeds'][] = $feedReport;
    }

    return $report;
}
