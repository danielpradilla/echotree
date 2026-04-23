# EchoTree 🌲

Personal RSS → Social Publishing Console.

EchoTree is a self-hosted tool for reading articles and turning them into scheduled social posts. It is intentionally small, single-user, and boring.

## Why EchoTree Is Great

- `Self-hosted + private by default`: your feed corpus, drafts, schedules, and tokens live on your own infra.
- `No platform lock-in`: works with X, LinkedIn, Mastodon, and Bluesky from one queue.
- `Reader-first workflow`: discover from RSS, review content in-app, then schedule from the same screen.
- `One-off URL sharing`: not limited to feed items; anything with a URL can enter the publishing pipeline via `/share`.
- `AI where it helps`: generate comments/summaries/impactful phrases on demand instead of forcing auto-posting.
- `Precise scheduling control`: edit scheduled time, comment, and destination networks per post before publish.
- `Operationally simple`: SQLite + cron + PHP scripts, no queues or always-on workers required.
- `Built for small teams/solo operators`: low cognitive load, fast to run, easy to reason about.
- `Hybrid extension flow`: browser extension opens your existing authenticated `/share` UI, so no new public posting API surface.
- `Security-focused defaults`: CSRF, encrypted OAuth tokens at rest, login throttling, sliding sessions, remember-me auto-login.
- Allows me to share articles with friends for almost nothing!

### Comparison (EchoTree vs Typical Paid Tools)

| Capability | EchoTree | Typical Paid Tool |
| --- | --- | --- |
| Hosting model | Self-hosted on your infra | Vendor-hosted SaaS |
| Data ownership | Full control of DB/content/tokens | Data stored on vendor platform |
| Cross-network posting | X, LinkedIn, Mastodon, Bluesky | Often strong for major networks, limited for federated/open ones |
| RSS-to-publishing flow | Built-in reader + scheduler in one app | Usually separate feed reading workflow |
| Share any URL quickly | Native `/share` flow + browser helper | Usually available, often tied to product UI/API limits |
| AI-assisted writing | On-demand summary/comment/phrase generation | Often token/plan-gated or workflow-constrained |
| Pre-publish control | Edit scheduled time, comment, and networks before send | Varies by plan/workflow |
| API exposure needed | Hybrid flow works without opening a public write API | API/webhook use is common |
| Ops complexity | SQLite + cron + PHP scripts | No ops, but dependent on vendor uptime/pricing |
| Cost model | Infra + API usage you control | Subscription pricing tiers |

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

The scheduled shares monitor now records each publisher run in SQLite and shows:

- last cron execution time and outcome
- recent cron history
- stale `publishing` deliveries automatically recovered back to `pending`
- per-network attempt counts, last attempt time, and delivery errors
- missed / partial / failed states on `/scheduled`

## Cron (feed fetcher)

Example cron entry (runs every 5 minutes):

```bash
*/5 * * * * cd /path/to/echotree && /usr/bin/php scripts/fetch_feeds.php >> logs/fetch_feeds.log 2>&1
```


## Environment Variables

- `ECHOTREE_SECRET_KEY`: base64-encoded 32-byte key for encrypting OAuth tokens.
- `ECHOTREE_POST_RATE_LIMIT_MINUTES`: per-account posting limit (default: 10 minutes).
- `ECHOTREE_PUBLISHING_STALE_MINUTES`: how long a delivery can stay in `publishing` before the next cron run recovers it back to `pending` (default: 15 minutes).
- `ECHOTREE_MONITOR_MISSED_MINUTES`: how late a due share can be before `/scheduled` marks it as missed (default: 5 minutes).
- `ECHOTREE_LOGIN_THROTTLE_MINUTES`: login lockout window after repeated failures (default: 10 minutes).
- `ECHOTREE_FEED_MAX_ITEMS`: max articles per feed fetch (default: 30).
- `ECHOTREE_SESSION_LIFETIME_SECONDS`: session cookie lifetime in seconds (default: session-only).
- `ECHOTREE_REMEMBER_ME_SECONDS`: persistent remember-me login lifetime (default: 30 days).
- `ECHOTREE_MASTODON_BASE_URL`: base URL for your Mastodon instance (e.g., `https://mastodon.social`).
- `ECHOTREE_MASTODON_CLIENT_ID` / `ECHOTREE_MASTODON_CLIENT_SECRET`: Mastodon app credentials.
- `ECHOTREE_BLUESKY_PDS`: Bluesky PDS base URL (defaults to `https://bsky.social`).
- `ECHOTREE_LINKEDIN_AUTHOR_URN`: LinkedIn author URN for posting (e.g., `urn:li:person:...`).
- `ECHOTREE_LINKEDIN_CLIENT_ID` / `ECHOTREE_LINKEDIN_CLIENT_SECRET`: LinkedIn app credentials.
- `ECHOTREE_X_CLIENT_ID` / `ECHOTREE_X_CLIENT_SECRET`: X (Twitter) app credentials.
- `ECHOTREE_X_API_KEY` / `ECHOTREE_X_API_SECRET`: X (Twitter) OAuth 1.0a keys.
- `OPENAI_API_KEY`: required for on-demand summaries.

### .env setup

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

## Account setup (quick)

### X (Twitter)
OAuth 2.0:
1) Set **Type of App** to **Web App** and **Confidential client**.
2) Add your callback URL (local or production) exactly.
3) Ensure OAuth 2.0 is enabled and scopes include `tweet.read tweet.write users.read offline.access`.
4) Set in `.env`:

```
ECHOTREE_X_CLIENT_ID=...
ECHOTREE_X_CLIENT_SECRET=...
```

OAuth 1.0a (alternative):
1) Set App permissions to **Read and write** (OAuth 1.0a section).
2) Regenerate **Access Token & Secret**.
3) In `/accounts`, paste Access Token into “OAuth token” and Access Token Secret into “OAuth token secret”.
4) Set in `.env`:

```
ECHOTREE_X_API_KEY=...
ECHOTREE_X_API_SECRET=...
```

### LinkedIn
1) Create a LinkedIn app with `w_member_social` approved.
2) Set the same callback URL as above.
3) Set in `.env`:

```
ECHOTREE_LINKEDIN_CLIENT_ID=...
ECHOTREE_LINKEDIN_CLIENT_SECRET=...
ECHOTREE_LINKEDIN_AUTHOR_URN=urn:li:person:123456789
```

Then use **Connect LinkedIn** in `/accounts`.

### Bluesky
1) Create an **App Password** in the Bluesky app (Settings → App Passwords).
2) Use **Connect Bluesky** in `/accounts` and paste your handle + app password.
3) Leave PDS blank (auto-discovery). If needed, set:

```
ECHOTREE_BLUESKY_PDS=https://bsky.social
```

### Mastodon
Use the Mastodon setup section below, then **Connect Mastodon** in `/accounts`.

## Mastodon setup (OAuth app)

Create an app on your Mastodon instance and set the redirect URI to your callback URL:

```bash
curl -X POST \
  -F "client_name=EchoTree" \
  -F "redirect_uris=https://yourdomain.com/echotree/oauth/callback" \
  -F "scopes=read write" \
  -F "website=https://yourdomain.com" \
  https://YOUR_INSTANCE/api/v1/apps
```

Set the returned values in `.env`:

```
ECHOTREE_MASTODON_BASE_URL=https://YOUR_INSTANCE
ECHOTREE_MASTODON_CLIENT_ID=...
ECHOTREE_MASTODON_CLIENT_SECRET=...
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

Use this if you want fast sharing from Safari on iPhone/iPad without building an app extension.

Steps:

1) Open the **Shortcuts** app and create a new shortcut (for example: `Share to EchoTree`).
2) Open shortcut details (`i` button) and enable:
   - **Show in Share Sheet**
   - **Receive**: `URLs`
3) Add actions in this order:
   - `Get URLs from Input`
   - `URL Encode`
   - `Open URLs`
4) In the `Open URLs` action, pass this value:

```text
https://your-domain.com/share?url={{URL Encode}}
```

5) Test from Safari:
   - Open any article page.
   - Tap Share.
   - Run `Share to EchoTree`.

Result: EchoTree opens `/share` with the URL prefilled so you can schedule, pick networks, and generate AI summary/comment.

## Browser Extension (Chrome, hybrid)

Use the extension in `extensions/echotree-share` to quickly send the current page to EchoTree.

How it works:

- The extension opens your existing EchoTree UI at:
  - `/share?url=<current_tab_url>`
- You continue in EchoTree to schedule time, choose networks, and generate summary/comment with AI.

Setup:

1) Configure extension settings with your EchoTree base URL (example: `https://your-domain.com` or `http://localhost:8000`).
2) Chrome install:
   - Open `chrome://extensions`
   - Enable Developer mode
   - Load unpacked extension from `extensions/echotree-share`
3) Configure extension domain:
   - Open the extension popup
   - Click **Settings**
   - Set **EchoTree Base URL** (example: `https://your-domain.com` or `http://localhost:8000`)

Security note:

- This hybrid approach does not expose a new posting API.
- It reuses your existing session + CSRF protections in EchoTree.
