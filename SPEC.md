# EchoTree 🌲
### Personal RSS → Social Publishing Console

## Philosophy

“If an article is read but never shared, was it ever read?”

EchoTree is a small, self-hosted RSS reader and social publishing console.

It collects articles from feeds, lets me read them distraction-free,
add a short thought, and schedule posts across my social accounts.

Each post is an echo.

This is a personal tool, not a platform.

Keep it small. Keep it boring.

---

# Core Principles

- single user only
- simple > clever
- minimal dependencies
- server-rendered HTML only
- cron jobs instead of background workers
- SQLite instead of database servers
- works perfectly on DreamHost shared hosting

No overengineering.

---

# Tech Stack (strict)

Language:
- PHP 8.2+

Backend:
- plain PHP or Slim (micro framework)
- Twig templates (or simple PHP templates)

Storage:
- SQLite via PDO

Libraries:
- SimplePie (RSS parsing)
- Readability.php (article extraction)
- Guzzle (HTTP calls)
- OpenAI via HTTP

Infrastructure:
- DreamHost shared hosting
- Apache + PHP (native)
- cron jobs
- no Docker
- no daemons
- no long-running processes

---

# Mental Model

Feed → Article → Post → Delivery → Account

Feeds provide content  
Articles store extracted text  
Posts are commentary + schedule  
Deliveries are per-account publish attempts  
Accounts represent social identities

---

# Functional Requirements

## Feeds
- add/edit/delete
- enable/disable
- manual fetch

## Articles
- list (unread first)
- filter by feed
- reader mode
- mark read/unread
- mark all read
- cleanup: delete articles immediately when marked read

## Share (manual)
- paste any URL to preview in reader mode
- schedule a post without RSS
- stores a local article snapshot for reuse

## Reader
Split view:
- left: article content
- right: comment + schedule + account checkboxes

Notes:
- articles list supports a split preview layout with reader/original toggle

## Summaries (optional)
- generated on demand only
- cached

## Comments (optional)
- generated on demand from article text
- used in share flows
- modes: comment, summary, impactful phrase

## Posting
- multiple accounts per post
- scheduled publish
- share now (bypass schedule)

## Rate limit
Per account:
- max 1 post every 10 minutes

## Scheduler
Cron scripts:
- fetch feeds
- publish posts

No workers.

---

# Security

- single username/password
- password hashed
- session cookies
- CSRF protection
- OAuth tokens encrypted at rest

## Accounts / OAuth
- connect buttons for X, Mastodon, LinkedIn
- Bluesky uses app password flow
- OAuth callback: https://danielpradilla.info/oauth/callback

---

# Non-goals

Do NOT add:
- SPA frameworks
- WebSockets
- background workers
- queues
- microservices
- multi-user auth
- complex infra

EchoTree should remain understandable in one afternoon.
