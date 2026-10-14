# Tests

`run.php` is an end-to-end suite with no dependencies. It drives a **running** copy of the
site over HTTP (like a browser: cookies, CSRF tokens, redirects) and reads the database
through `config.php`.

```bash
php -S localhost:8000 -t public router.php      # terminal 1: start the site
php tests/run.php --base=http://localhost:8000  # terminal 2: run the tests
```

Without `--base` it uses `base_url` from `config.php`. Needs the PHP `curl` extension.
Exit code is `0` when everything passes, so it works in CI.

**Use a development database.** The suite creates users, codes and pages whose names start
with `qrtest`, deletes them again when it finishes, clears the `rate_hits` table, and briefly
changes two settings (Stripe webhook secret, default language), which it restores.

It checks: PHP syntax, translation completeness (every language has every key, `printf`
placeholders match, every `t()` key used in code exists), guest/admin access control,
CSRF, sign-in lockout, dynamic codes (password, expiry, scan limit, pause, bots, rate
limiting), ownership/IDOR, CSV export, folders, bulk import, plan limits, bio pages, the
REST API (auth, CRUD, localized errors and labels, rate limiting), admin moderation,
suspended accounts, Russian/Uzbek pages (no untranslated keys), the Stripe webhook
(signature, activation, idempotency) and the schema migration.

Not covered: sending real e-mail (needs an SMTP server), real Stripe payments (needs your
keys), and the web installer (run it once by hand on a fresh database).
