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

4) Use `/share` to paste any URL and schedule a one-off post (non-RSS).

## Notes

- This is a minimal Slim + Twig scaffold.
- The database auto-initializes from `scripts/schema.sql` on first run.
- No Docker, no background workers, no queues.
- Set the login password via `php scripts/set_password.php <username>`.

## Auth

- Login at `/login` and logout at `/logout`.
- All pages require a valid session.

## OAuth Connect

- Callback URL: `https://danielpradilla.info/oauth/callback`
- Connect buttons are on `/accounts`.
 - Bluesky uses app password flow at `/oauth/bluesky`.

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
- `ECHOTREE_RATE_LIMIT_MINUTES`: per-account posting limit (default: 10 minutes).
- `ECHOTREE_MASTODON_BASE_URL`: base URL for your Mastodon instance (e.g., `https://mastodon.social`).
- `ECHOTREE_MASTODON_CLIENT_ID` / `ECHOTREE_MASTODON_CLIENT_SECRET`: Mastodon app credentials.
- `ECHOTREE_BLUESKY_PDS`: Bluesky PDS base URL (defaults to `https://bsky.social`).
- `ECHOTREE_LINKEDIN_AUTHOR_URN`: LinkedIn author URN for posting (e.g., `urn:li:person:...`).
- `ECHOTREE_LINKEDIN_CLIENT_ID` / `ECHOTREE_LINKEDIN_CLIENT_SECRET`: LinkedIn app credentials.
- `ECHOTREE_X_CLIENT_ID` / `ECHOTREE_X_CLIENT_SECRET`: X (Twitter) app credentials.
- `OPENAI_API_KEY`: required for on-demand summaries.

### .env setup

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

## Summaries

- POST `/articles/{id}/summary` returns a cached summary or generates one on demand.
- Summaries are stored in `articles.summary` and generated from the first ~12k chars of `content_text`.

## Comments (AI)

- Use “Generate comment” on `/articles` and `/share` to create a short comment from the article text.
- Requires `OPENAI_API_KEY`.
 - Modes: Comment, Summary, Impactful phrase.

## Posting

- Use “Schedule” to set a future time.
- Use “Share now” to skip scheduling and post as soon as the publisher runs.

## Security Notes

- CSRF protection is enforced on all POST routes.
- OAuth tokens are encrypted at rest using `ECHOTREE_SECRET_KEY`.

## iOS Shortcut (share to EchoTree)

Create a Shortcut that takes the shared URL and opens the Share page:

1) In Shortcuts, create a new shortcut.
2) Add action: “Get URLs from Input”.
3) Add action: “URL Encode”.
4) Add action: “Open URL” with:

```
https://your-domain.com/share?url={{URL Encode}}
```

Now you can use the Share Sheet in Safari/Reader and jump directly to the preview + schedule screen.
