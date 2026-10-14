# QRaft — QR Code Generator & Dynamic QR SaaS

A clean, self-hosted QR code platform built with plain PHP and MySQL. No
framework, no Composer dependencies, no external APIs — drop it on any PHP 8
host and it runs.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-00618a)
![License](https://img.shields.io/badge/license-Envato-4f46e5)

## What it does

**Free static generator** (no account needed)
- URL, text, email, phone, SMS, Wi-Fi, vCard, WhatsApp, location, event and crypto
- Live preview, four sizes, custom colours, gradient fill, dot and eye styles
- Optional **centre logo** (auto-raises error correction so it still scans)
- One-click **PNG and SVG** download
- Generated entirely in the visitor's browser — nothing is uploaded

**Monetization**
- Subscription plans with per-plan limits (dynamic codes, bio pages), enforced
- Optional Stripe checkout + webhook to sell upgrades (one-time period access)
- Pricing page, per-user billing page, and admin plan management

**REST API**
- Personal bearer tokens (create and revoke from the account)
- List, create, update and delete dynamic codes over JSON — e.g.
  `curl /api/codes.php -H "Authorization: Bearer <token>"`

**Accounts & admin**
- Web installer: database setup, schema import and admin creation from the browser
- Admin panel: user management (suspend, promote, delete) and site settings
- Password reset and optional email verification over SMTP (dependency-free mailer)
- Account page: edit profile, change password, delete account (cleans up uploads)
- Sign-in brute-force lockout, hashed reset/verify tokens, Secure/HttpOnly cookies and
  baseline security headers; one configurable time zone for PHP and MySQL
- Configurable site name, registration toggle and SMTP, all from the admin UI
- Light / dark theme with a one-click toggle
- Multi-language (English, Russian, Uzbek) with a language switcher and RTL support

**Dynamic QR codes** (SaaS, account required)
- One short link per code: `yoursite.com/q/XXXXXX`, or a custom code like `/q/summer-menu`
- Change the destination any time — the printed QR never changes
- Custom colours and a centre logo saved per code; download as PNG, SVG or PDF
- Organise codes into folders; bulk-create many codes from a CSV
- Bio pages: a hosted Linktree-style link page per handle, with themes
- Optional protection per code: password, expiry date and scan limit
- Every scan is counted; pause or delete a code at any point
- Per-code analytics: 14-day chart plus device / OS / browser breakdown; link-preview
  bots and HEAD probes are not counted as scans
- Export scans to CSV, per code or across every code

## Why buyers like it

- **Zero dependencies.** Pure PHP + PDO. No `composer install`, no build step.
- **Secure by default.** Prepared statements everywhere, hashed passwords
  (`password_hash`), CSRF tokens on every form, output escaping, and
  scheme-validated URLs. IPs are stored only as salted hashes.
- **Readable code.** Small files, clear structure, commented throughout — easy
  to rebrand and extend.
- **Mobile-friendly UI** with a single, themeable stylesheet.

## Requirements

- PHP 8.0 or newer with the `pdo_mysql`, `mbstring`, `json`, `session` and `ctype` extensions
  (all standard in Laragon/XAMPP); `curl` is needed only for Stripe payments. The web
  installer checks all of this for you before it does anything
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite, or nginx (one rewrite rule — see docs)

## Quick start

```bash
# 1. Create the database and tables
mysql -u root -p < database.sql

# 2. Configure
cp config.example.php config.php
#    then edit config.php: database credentials + base_url

# 3. Point your web server's document root at the /public folder
#    (Laragon: the vhost is created automatically)
```

Open the site, create an account, and make your first dynamic QR code.
Full steps are in [docs/installation.md](docs/installation.md).

## Project structure

```
config.example.php   Configuration template
database.sql         Schema (users, qr_codes, qr_scans)
src/                 Bootstrap, DB, auth, CSRF, helpers, layout
public/              Web root — pages, redirect endpoint, assets
docs/                Installation guide, API and payments docs
tests/               End-to-end test suite (php tests/run.php)
lang/                Translations (en, ru, uz)
```

## Legal pages

`/terms.php` and `/privacy.php` ship as generic templates (English, Russian, Uzbek; text in
`lang/*.app.php`, keys `terms.*` and `privacy.*`). They are **not legal advice** — adapt them to
your country and business and bump `LEGAL_UPDATED` in `src/helpers.php`. Set the contact e-mail and
how long individual scan records are kept in **Admin → Settings**. The site sets only functional
cookies (session, language), so no cookie banner is required.

## Adding a language

Each language is three files in `lang/`: `xx.php` (landing, sign-in), `xx.app.php`
(signed-in pages) and `xx.admin.php` (admin panel). Copy the `en.*` files, rename them
to your language code (e.g. `de.php`, `de.app.php`, `de.admin.php`) and translate the
values — missing keys fall back to English. Text of the default plans lives under
`dyn:` keys in `en.app.php`; add the same keys to translate plan names and features.
The installer has its own small string table at the top of `public/install/index.php`; the REST API's error messages are translated too (see docs/api.md).

## Security notes

- Keep `config.php` out of the web root's listing (handled by `.htaccess`).
- Always serve over HTTPS in production so sessions and logins stay private.
- Set `base_url` to your real public address — it is what the QR codes encode.
- Rate limiting protects sign-in, QR passwords, password resets, registration and the API.
  Behind a proxy/CDN set `trusted_proxy_header` in `config.php` (see the installation guide).
- Public endpoints (QR redirects, bio pages, API) set no cookies and open no sessions.

## Credits

Third-party libraries bundled in `public/assets/js/` (all MIT): **qr-code-styling**, **qrcode-generator**
and **jsPDF** — see [THIRD-PARTY.md](THIRD-PARTY.md). Everything else is original.

## License

Sold under the Envato Market license you purchased (Regular or Extended) — see
[LICENSE.txt](LICENSE.txt). Release notes are in [CHANGELOG.md](CHANGELOG.md).
