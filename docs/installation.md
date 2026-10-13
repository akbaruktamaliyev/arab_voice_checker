# QRaft — Installation Guide

This guide covers a local install with **Laragon** (Windows) and a generic
install on any Apache or nginx host.

---

## 1. Create the database

Open a terminal (or Laragon → Menu → MySQL → console) and run:

```bash
mysql -u root -p < database.sql
```

This creates a database called `qraft` with three tables: `users`,
`qr_codes` and `qr_scans`. To use a different database name, edit the top of
`database.sql` and the `db.name` value in your config.

You can also import `database.sql` through phpMyAdmin or HeidiSQL instead.

---

## 2. Configure the app

```bash
cp config.example.php config.php
```

Edit `config.php`:

| Setting      | What to put                                                        |
|--------------|--------------------------------------------------------------------|
| `db.host`    | Usually `127.0.0.1`                                                 |
| `db.name`    | `qraft` (or your chosen name)                                      |
| `db.user`    | Your MySQL user (`root` on Laragon)                                |
| `db.pass`    | Your MySQL password (empty on a default Laragon install)          |
| `base_url`   | The public address of the site, **no trailing slash**             |
| `app_name`   | The name shown in the header and titles                           |
| `code_length`| Characters in each dynamic short code (default 6)                 |

`base_url` matters: it is encoded into every dynamic QR code. Locally it might
be `http://qraft.test`; in production it must be your real HTTPS domain.

---

## 3. Point the web server at `/public`

The document root must be the **`public`** folder, so that `src/` and
`config.php` stay outside the web root.

### Laragon (Windows)

1. Put the project in `C:\laragon\www\qraft`.
2. Laragon auto-creates the host `http://qraft.test` pointing at the project.
   If it serves the project root instead of `public`, set the document root to
   the `public` subfolder in the auto-created Apache vhost, then
   **Menu → Apache → Reload**.
3. Set `base_url` to `http://qraft.test`.

### Generic Apache

Create a vhost whose `DocumentRoot` is the `public` folder and allow
`.htaccess` overrides:

```apache
<VirtualHost *:80>
    ServerName qraft.example.com
    DocumentRoot /var/www/qraft/public
    <Directory /var/www/qraft/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Make sure `mod_rewrite` is enabled (`a2enmod rewrite`).

### Quick run without Apache/nginx (PHP built-in server)

For a fast local try-out you can skip Apache entirely and use PHP's built-in
server with the bundled `router.php` (it reproduces the `/q/{code}` rewrite,
which the built-in server would otherwise ignore):

```bash
php -S 0.0.0.0:8000 -t public router.php
```

Then set `base_url` to the address you open — `http://localhost:8000`, or your
machine's LAN IP (e.g. `http://192.168.1.50:8000`) if you want to scan the
dynamic codes from a phone on the same network. `router.php` is only for this
dev server; Apache/nginx use the rules below instead.

### nginx

Point the root at `public` and add the dynamic-QR rewrite:

```nginx
server {
    server_name qraft.example.com;
    root /var/www/qraft/public;
    index index.php;

    location /q/ {
        rewrite ^/q/([A-Za-z0-9]+)/?$ /q.php?c=$1 last;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 4. First run

1. Open the site — you should see the free generator.
2. Click **Get started** and create an account.
3. On the dashboard, create a dynamic QR code, scan it, and watch the count
   rise and the 14-day chart fill in.

---

## 5. Going live checklist

- [ ] Serve over **HTTPS** and set `base_url` to the `https://` address.
- [ ] Use a dedicated MySQL user (not `root`) with access to only this database.
- [ ] Confirm `config.php` is not web-accessible (the bundled `.htaccess`
      blocks `.sql`/`.md`; `config.php` sits outside `public` already).
- [ ] Set up regular database backups.

---

## Troubleshooting

**"Database connection failed"** — check the credentials in `config.php` and
that MySQL is running.

**Dynamic links 404** — `mod_rewrite` is off, or the document root is not
`public`. Test `http://yoursite/q.php?c=CODE` directly; if that works, the
rewrite is the issue.

**QR image doesn't render** — make sure
`public/assets/js/qrcode.min.js` is being served (check the browser console).
