# EchoTree 🌲

> If an article is read but never shared, was it ever read?

EchoTree is a small, self-hosted RSS reader and social publishing console.

It quietly collects articles from your feeds, lets you read them distraction-free,
add a short thought, and schedule them to be shared across your social accounts.

Each post is an **echo** — proof the tree fell.

This is a personal tool, not a platform.

---

## Philosophy

EchoTree is intentionally boring software.

Not a startup.  
Not multi-tenant.  
Not real-time.  
Not microservices.

Just:

Read → Think → Echo

Design principles:

- single user only
- simple > clever
- local data ownership
- minimal dependencies
- cron jobs instead of workers
- server-rendered HTML
- SQLite instead of a database server
- easy to deploy anywhere

If something feels complex, it's probably the wrong solution.

---

## Features

- RSS feed management
- Full article extraction (reader mode)
- Optional AI summaries (on demand)
- Compose short commentary
- Multiple accounts per network
- Schedule posts
- Automatic publishing via cron
- Per-account rate limit (1 post / 10 minutes)
- Self-hosted

---

## Tech Stack

Backend:
- Python 3.11+
- FastAPI
- SQLAlchemy
- Jinja templates

Storage:
- SQLite

Extraction:
- feedparser
- trafilatura

Hosting:
- DreamHost shared hosting
- Passenger (WSGI)
- cron jobs

No Docker.  
No Celery.  
No Redis.  
No frontend framework.

---

## Installation (local dev)

### 1. Clone

```bash
git clone <your-repo>
cd echotree