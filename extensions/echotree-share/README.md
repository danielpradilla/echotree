# EchoTree Share Extension (Hybrid)

This extension does not post directly to an API.
It opens your existing EchoTree `/share` page with the current tab URL prefilled, so scheduling, account selection, and LLM-assisted summary/comment stay in your authenticated web app.

## What it does

- Grabs the current browser tab URL.
- Opens:
  - `<ECHOTREE_BASE_URL>/share?url=<encoded_current_url>`
- You then use EchoTree's existing UI to:
  - set schedule time
  - pick social networks
  - generate summary/comment with AI

## Configure

1. Open extension **Settings**.
2. Set **EchoTree Base URL** (for example `https://your-domain.com` or `http://localhost:8000`).
3. Save.

## Install in Chrome

1. Go to `chrome://extensions`.
2. Enable **Developer mode**.
3. Click **Load unpacked**.
4. Select folder: `extensions/echotree-share`.

## Security model

- No new EchoTree write API is exposed.
- No OAuth/API secrets are stored in the extension.
- Auth remains your existing EchoTree session + CSRF flow.
