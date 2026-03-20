# EchoTree – Implementation Tasks

Follow SPEC.md strictly.
Implement ONE task at a time only.

---

# Pending

## Task P1 — Fix newly added feed refresh/fetch behavior
Bug: after adding a new feed and triggering a fetch, the feed did not refresh as expected.

Investigate and fix:
- whether the feed is being fetched at all
- whether items are being skipped incorrectly
- whether the UI is not surfacing the refreshed state
- whether background fetch vs per-feed fetch is causing the issue

Confirm the fix with a newly added feed end-to-end.

---

## Task P2 — Add reader feed fetch controls
Undocumented UI improvement currently implemented locally but not documented in the backlog.

Add feed refresh actions directly in the reader:
- `Fetch all` from the reader chrome
- `Refresh feed` when a specific feed is selected
- preserve the current reader context after the action
- surface fetch success/failure state in the reader UI

---

## Task P3 — Auto-suggest feed title from feed URL
Undocumented UI improvement currently implemented locally but not documented in the backlog.

When adding or editing a feed:
- read the feed title from the RSS/Atom metadata after entering the URL
- suggest/populate the title automatically
- allow the user to override the suggestion
- preserve server-side fallback so saving still works when the UI does not autofill

---

## Task P4 — Mark stale feeds in feeds list and reader
Undocumented UI improvement currently implemented locally but not documented in the backlog.

Mark feeds that have not been fetched in more than 6 months with a red dot:
- in the feeds list
- in the reader site navigation

Use `last_fetched_at` as the source of truth and keep the indicator purely informational.

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

---

# Phase 12 — Refactoring

## Task 15 — Split article routes by feature
`app/routes/article_routes.php` is too large and mixes reader, share, history, scheduling helpers, embed rendering, and AJAX endpoints.

Refactor into smaller route modules, for example:
- reader routes
- share routes
- history routes
- article utility endpoints

Keep shared helpers in a dedicated support file instead of inline at the top of one route file.

---

## Task 16 — Extract post creation + publish orchestration
Post scheduling and immediate publish status resolution are duplicated across `/posts` and `/history/{id}/repost`.

Create a dedicated application service for:
- validation-ready post creation
- duplicate detection
- immediate publish
- redirect/status payload generation

Route handlers should become thin wrappers around that service.

---

## Task 17 — Separate repositories from presentation hydration
`app/repositories.php` currently mixes SQL access with UI shaping (`hydrate_article_presenter_fields`, thumbnail extraction, accent colors, favicon URLs).

Refactor into:
- data repositories
- presenter/view-model builders

Database access should return domain-shaped records, not template-decorated arrays.

---

## Task 18 — Introduce proper schema migrations
Schema changes are currently spread across:
- `scripts/schema.sql`
- `app/db.php`
- ad hoc `ensure_*_table()` functions in runtime code

Create a real migration system so schema evolution is explicit, ordered, and deploy-safe.

Goals:
- one migration source of truth
- no runtime table creation scattered across features
- safe upgrades for existing SQLite installs

---

## Task 19 — Centralize configuration and environment parsing
Environment access is scattered through the codebase with repeated defaults and fallback chains.

Create a config layer that exposes typed helpers for:
- auth/session config
- OAuth config
- posting/rate limit config
- feed fetch config
- schedule timezone config

This reduces drift and makes defaults consistent.

---

## Task 20 — Unify SQLite retry and locking behavior
SQLite write retry logic exists in multiple places (`retry_db_write`, `run_sqlite_write_with_retry`) and locking concerns are spread across auth, publishing, and feed scripts.

Refactor to a shared persistence utility with:
- retry policy
- lock error detection
- logging hooks
- consistent transaction boundaries

---

## Task 21 — Refactor feed ingestion into smaller services
`app/feed_fetcher.php` currently does feed selection, parsing, dedupe, extraction, refresh behavior, and persistence in one function.

Split into focused units:
- feed selection
- item normalization
- article upsert
- extraction policy
- fetch logging/reporting

This will make refresh behavior and failure handling easier to reason about.

---

## Task 22 — Create an article source/upsert service
Article creation logic for URLs is duplicated conceptually across:
- feed fetching
- `/share`
- `/articles/follow`
- history repost preparation

Create a reusable article source service that can:
- upsert from feed item
- upsert from manual URL
- refresh existing article content

Use that from all entry points.

---

## Task 23 — Break up the reader template
`templates/articles/index.twig` is too large and carries layout, controls, list rendering, compose UI, notices, and JavaScript in one file.

Split into partials/components such as:
- site nav
- article list
- selected article header
- reader iframe/original panel
- compose form
- notice bar
- page scripts

Keep the page shell thin.

---

## Task 24 — Reuse compose UI across reader, share, history, and scheduled flows
The app has multiple compose/schedule forms with overlapping structure and behavior.

Extract reusable template partials and server-side preparation for:
- comment field
- account selection
- schedule input
- submit actions
- post status messaging

This will reduce drift between reader/share/history UIs.

---

## Task 25 — Normalize flash/status messaging
Success/error handling currently relies on ad hoc query params and session fragments like:
- `status`
- `error`
- `last_post_details`
- `last_post_status`

Introduce a small flash message abstraction so routes can emit structured feedback and templates can render it consistently.

---

## Task 26 — Introduce form/request validators
Route handlers manually parse and validate request bodies inline.

Create dedicated validators or request DTO builders for:
- login
- feed forms
- account forms
- post creation
- scheduled post updates
- share/history repost forms

This will shrink route handlers and make validation rules testable.

---

## Task 27 — Normalize adapter contracts and publish results
Platform adapters likely expose slightly different failure modes and response shapes behind a thin interface.

Refactor adapter outputs into a standard result object containing:
- success/failure
- external id
- retryability
- normalized error message

This will simplify publisher logic and history recording.

---

## Task 28 — Consolidate auth/session/remember-me concerns
`app/auth.php` handles table bootstrapping, cookie configuration, throttling, remember-me rotation, retries, and current-user lookup in one module.

Split into focused auth components:
- session/cookie policy
- login throttling
- remember token store
- user authentication

This should also remove the need for auth-specific runtime schema creation.

---

## Task 29 — Introduce lightweight article list models
The recent production issue showed that inbox/list routes should not load article detail payloads.

Create separate read models for:
- article list cards
- article detail/reader view
- history entries

Make payload size intentional instead of relying on `SELECT a.*`.

---

## Task 30 — Add regression tests around critical workflows
There is no obvious automated coverage protecting the core workflows.

Add tests for:
- login and remember-me flow
- feed fetch dedupe/upsert behavior
- post scheduling and duplicate detection
- publisher state transitions
- history recording and repost
- article list memory-safe query behavior

Prioritize integration-style tests around the highest-risk routes and scripts.
