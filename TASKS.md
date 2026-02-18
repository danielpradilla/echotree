# EchoTree – Implementation Tasks

Follow SPEC.md strictly.
Implement ONE task at a time only.

---

# Phase 1 — Foundation

## Task 1 — Project scaffold
Create basic PHP app structure.

Generate:

/public/index.php (front controller)
/app/routes.php
/app/db.php
/templates/layout.twig
/templates/home.twig
/composer.json
/README.md

Use Slim or simple router.

Render a homepage.

Stop after scaffold.

---

## Task 2 — SQLite connection
Implement PDO SQLite setup.

Create:
- db.php
- helper to get connection
- auto-create database file

No models yet.

---

# Phase 2 — Models

## Task 3 — Database schema
Create schema and migrations.

Tables:
- feeds
- articles
- accounts
- posts
- deliveries
- users

Provide SQL schema or migration script.

---

# Phase 3 — Auth

## Task 4 — Login system
Single user login.

Requirements:
- password hash
- sessions
- login/logout routes
- protect all pages
- CLI script to set password

---

# Phase 4 — Feeds

## Task 5 — Feed CRUD
Implement:
- add/edit/delete feeds
- enable/disable
- list page

Templates included.

---

# Phase 5 — RSS ingestion

## Task 6 — Fetch script
Create:

scripts/fetch_feeds.php

Use:
- SimplePie
- Readability.php

Behavior:
- fetch active feeds
- dedupe
- extract content
- store locally

Cron-safe and idempotent.

---

# Phase 6 — Articles UI

## Task 7 — Article list
Inbox-style list with unread markers.

---

## Task 8 — Reader + compose
Split layout:
- article content
- comment box
- schedule datetime
- account checkboxes

Save draft.

---

# Phase 7 — Accounts

## Task 9 — Account CRUD
Manage social accounts.
Encrypt tokens at rest.

---

# Phase 8 — Scheduling

## Task 10 — Post creation
Save scheduled posts + deliveries.

---

## Task 11 — Publisher script
Create:

scripts/publisher.php

Behavior:
- find due posts
- enforce 10-minute rate limit
- publish
- retry failures

Cron-safe.

---

# Phase 9 — Adapters

## Task 12 — Platform adapters
Implement minimal HTTP clients for:
- Twitter/X
- Mastodon
- Bluesky
- LinkedIn

---

# Phase 10 — Summaries

## Task 13 — LLM summary endpoint
Generate summary on demand and cache.

---

# Phase 11 — Deployment

## Task 14 — DreamHost instructions
Document:
- composer install
- cron setup
- deployment steps