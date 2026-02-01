# EchoTree 🌲

Personal RSS → Social Publishing Console.

EchoTree is a calm, self-hosted tool for reading articles and turning them into scheduled social posts. It is intentionally small, single-user, and boring.

## Local Development

### 1) Install dependencies

```bash
composer install
```

### 2) Run the app

```bash
php -S localhost:8000 -t public
```

Open http://localhost:8000

## Getting Started (quick)

1) Set your login password:

```bash
php scripts/set_password.php <username>
```

2) Start the server and log in (the database auto-initializes on first run):

```bash
php -S localhost:8000 -t public
```

3) Add feeds at `/feeds`, then run the fetcher:

```bash
php scripts/fetch_feeds.php
```

To refresh existing articles (re-extract content), run:

```bash
php scripts/fetch_feeds.php --refresh
```

## Notes

- This is a minimal Slim + Twig scaffold.
- The database auto-initializes from `scripts/schema.sql` on first run.
- No Docker, no background workers, no queues.
- Set the login password via `php scripts/set_password.php <username>`.

## Auth

- Login at `/login` and logout at `/logout`.
- All pages require a valid session.

## Cron (publisher)

Example cron entry (runs every minute):

```bash
* * * * * cd /path/to/echotree && /usr/bin/php scripts/publisher.php >> logs/publisher.log 2>&1
```

## Cron (feed fetcher)

Example cron entry (runs every 5 minutes):

```bash
*/5 * * * * cd /path/to/echotree && /usr/bin/php scripts/fetch_feeds.php >> logs/fetch_feeds.log 2>&1
```

## Environment Variables

- `ECHOTREE_SECRET_KEY`: base64-encoded 32-byte key for encrypting OAuth tokens.
- `ECHOTREE_MASTODON_BASE_URL`: base URL for your Mastodon instance (e.g., `https://mastodon.social`).
- `ECHOTREE_BLUESKY_PDS`: Bluesky PDS base URL (defaults to `https://bsky.social`).
- `ECHOTREE_LINKEDIN_AUTHOR_URN`: LinkedIn author URN for posting (e.g., `urn:li:person:...`).
- `OPENAI_API_KEY`: required for on-demand summaries.

## Summaries

- POST `/articles/{id}/summary` returns a cached summary or generates one on demand.
- Summaries are stored in `articles.summary` and generated from the first ~12k chars of `content_text`.

## Security Notes

- CSRF protection is enforced on all POST routes.
- OAuth tokens are encrypted at rest using `ECHOTREE_SECRET_KEY`.
