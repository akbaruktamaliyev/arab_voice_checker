# Changelog

## 1.0.0 — 2026-10-14

First public release.

### Free generator
- 11 QR types (link, text, e-mail, phone, SMS, Wi-Fi, vCard, WhatsApp, location, calendar,
  crypto), dot / eye styles, colours, gradients, centre logo, PNG and SVG download — all in
  the browser, nothing is uploaded.

### Dynamic codes & analytics
- Editable destination behind a permanent short link, custom codes (slugs), colours and
  centre logo, PNG / SVG / PDF download.
- Password, expiry date and scan limit per code; folders; search; bulk import from CSV.
- Scan analytics: last 14 days, device / OS / browser, CSV export. Link-preview bots are not
  counted.
- Bio-link pages (Linktree style) with themes.

### Accounts, plans & admin
- Registration, sign-in, password reset, optional e-mail confirmation (with resend),
  account page, account deletion.
- Plans with enforced limits (Free / Pro / Business, editable), Stripe Checkout and a signed,
  idempotent webhook.
- Admin panel: overview, users (suspend / promote / delete), **QR-code moderation**
  (disable, enable, delete — a disabled code cannot be re-enabled by its owner), plans,
  settings (site name, time zone, registration, SMTP, Stripe, default language).
- Suspended users' codes and bio pages go offline immediately.

### API
- REST API with bearer tokens (`/api/codes.php`): list, read, create, update, delete.
- Localized errors with stable `error_code`, opt-in localized field labels (`?labels=1`),
  per-token rate limiting.

### Languages
- English, Russian and Uzbek across the whole product: pages, admin, e-mails, installer,
  API messages, CSV headers, the free generator. Right-to-left layouts are supported.
  See "Adding a language" in the README.

### Security
- Prepared statements everywhere, CSRF tokens, escaped output, `password_hash`, validated
  uploads in a no-execute folder, CSV-formula guard, salted IP hashing.
- Rate limiting for sign-in, QR passwords, password resets, registration and the API
  (`trusted_proxy_header` for sites behind a proxy / CDN).
- Public endpoints (QR redirect, bio pages, API) set no cookies and open no sessions.

### Installation
- Web installer with a server-requirements check; schema upgrades for later releases run
  automatically (`src/migrate.php`).
- Needs PHP 8.0+ (`pdo_mysql`, `mbstring`, `json`, `session`, `ctype`; `curl` for Stripe)
  and MySQL 5.7+ / MariaDB 10.3+.
- End-to-end test suite in `tests/`.
