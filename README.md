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
- Live preview, four sizes, custom QR + background colours
- Optional **centre logo** (auto-raises error correction so it still scans)
- One-click **PNG and SVG** download
- Generated entirely in the visitor's browser — nothing is uploaded

**Admin & setup**
- Web installer: database setup, schema import and admin creation from the browser
- Admin panel: user management (suspend, promote, delete) and site settings
- Configurable site name, registration toggle and SMTP, all from the admin UI
- Light / dark theme with a one-click toggle

**Dynamic QR codes** (SaaS, account required)
- One short link per code: `yoursite.com/q/XXXXXX`, or a custom code like `/q/summer-menu`
- Change the destination any time — the printed QR never changes
- Custom colours and a centre logo saved per code; download as PNG or SVG
- Optional protection per code: password, expiry date and scan limit
- Every scan is counted; pause or delete a code at any point
- Per-code analytics: 14-day chart plus device / OS / browser breakdown
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

- PHP 8.0 or newer (PDO + pdo_mysql enabled — standard in Laragon/XAMPP)
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
docs/                Installation guide
```

## Security notes

- Keep `config.php` out of the web root's listing (handled by `.htaccess`).
- Always serve over HTTPS in production so sessions and logins stay private.
- Set `base_url` to your real public address — it is what the QR codes encode.

## Credits

QR rendering in the browser uses **qrcode-generator** by Kazuhiko Arase (MIT),
bundled in
`public/assets/js/qrcode-generator.min.js`. Everything else is original.

## License

Sold under the Envato Market license you purchased (Regular or Extended). See
your purchase for details.
