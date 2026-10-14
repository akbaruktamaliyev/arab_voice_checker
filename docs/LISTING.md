# QRaft — CodeCanyon listing copy

Ready-to-paste text for the item page. Edit the price, demo URL and your author
details before submitting.

---

## Item title

**QRaft — QR Code Generator & Dynamic QR SaaS (PHP, zero-dependency)**

## Short description (one line)

Self-hosted QR platform: styled QR generator, dynamic codes with scan
analytics, bio-link pages, REST API and Stripe plans — pure PHP, no Composer.

## Tagline options

- Turn any link into a trackable, re-editable QR — on your own server.
- The dependency-free QR SaaS: install anywhere PHP runs.

---

## Overview

QRaft is a complete, self-hosted QR code platform you can resell or run as your
own service. It pairs a beautiful in-browser QR **generator** (dot styles, eye
styles, gradients, logos) with a full **dynamic-QR SaaS**: short links you can
re-point after printing, scan analytics, password/expiry/limit protection,
folders, bulk import, a REST API, Linktree-style bio pages, and subscription
plans with Stripe.

It is built in **plain PHP 8 + MySQL** with **no Composer dependencies and no
build step** — upload it to any PHP host, run the web installer, and you are
live. Code is parsed and QR images are generated **in the browser**, so nothing
is uploaded to third-party services: a genuine privacy and reliability edge over
framework-heavy competitors.

## Features

**Free QR generator (no account):**
- 11 content types: URL, text, email, phone, SMS, Wi-Fi, vCard, WhatsApp,
  location, calendar event, crypto
- Dot styles, eye styles, two-colour gradients, centre logo
- Live preview, custom colours and sizes, PNG & SVG download
- Generated entirely on the visitor's device

**Dynamic QR codes (accounts):**
- One editable short link per code — change the destination, the printed QR
  stays the same
- Custom codes (vanity slugs), colours and a centre logo; PNG, SVG & PDF export
- Protection per code: password, expiry date, scan limit
- Scan analytics: 14-day chart plus device / OS / browser breakdown, CSV export
- Folders to organise codes; bulk-create from a CSV

**Platform:**
- REST API with personal bearer tokens (list/create/update/delete codes)
- Bio-link pages (Linktree-style) at /b/{handle} with four themes
- Subscription plans (Free / Pro / Business) with enforced limits and optional
  Stripe checkout + webhook
- Web installer, admin panel (users, plans, settings)
- Password reset & email verification over SMTP (dependency-free mailer)
- Multi-language (English, Russian, Uzbek) with RTL support
- Light / dark theme

**Built for buyers:**
- Zero Composer dependencies, no build tools
- Secure by default: prepared statements, CSRF, output escaping, hashed
  passwords, validated uploads, signed Stripe webhooks
- Clean, commented code and full documentation

## What's included

- Full PHP source (`public/`, `src/`, `lang/`)
- `database.sql` schema and a browser-based installer
- Documentation: installation, REST API, payments/Stripe, listing notes
- Bundled MIT front-end libraries (qr-code-styling, jsPDF, qrcode-generator)

## Requirements

- PHP 8.0+ (pdo_mysql, mbstring, json, session, ctype; cURL for Stripe payments)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with mod_rewrite, or nginx (one rewrite rule)

## Why QRaft

- **Runs anywhere.** No Composer, no Node, no build — shared hosting friendly.
- **Privacy-first.** QR rendering happens in the browser; code never leaves the
  server.
- **Complete.** Generator + dynamic SaaS + bio links + API + billing in one app.
- **Readable & extendable.** Small, documented files; add languages, QR types or
  payment logic easily.

## Quick start

1. Upload and point the web root at `/public`.
2. Open the site — the installer sets up the database and admin account.
3. Sign in, create your first dynamic code, and share the short link.

## Changelog

See `CHANGELOG` on the repository / item updates. v1.0 — initial release:
generator, dynamic codes, analytics, protection, folders, bulk import, REST
API, bio pages, plans + Stripe, installer, admin, email, i18n.

## Support

Describe your support policy here (e.g. 6 months included, response time, where
buyers should contact you).
