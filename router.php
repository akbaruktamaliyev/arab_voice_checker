<?php
/**
 * QRaft — router for PHP's built-in web server (development/testing only).
 *
 * The built-in server does not read .htaccess, so the pretty dynamic-QR URL
 * /q/{code} would not route on its own. This router maps it to q.php, exactly
 * like the Apache rewrite does, and serves every other file normally.
 *
 * Run it with:
 *   php -S 0.0.0.0:8000 -t public router.php
 *
 * In production (Apache/nginx) this file is NOT used — the .htaccess rewrite
 * (or the nginx location block in docs/installation.md) handles /q/{code}.
 */

declare(strict_types=1);

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Pretty dynamic-QR link: /q/{code}  ->  q.php?c={code} (allows - and _ too)
if (preg_match('#^/q/([A-Za-z0-9_-]+)/?$#', $uri, $m)) {
    $_GET['c'] = $m[1];
    require __DIR__ . '/public/q.php';
    return true;
}

// Public bio page: /b/{slug} -> b.php?s={slug}
if (preg_match('#^/b/([A-Za-z0-9_-]+)/?$#', $uri, $m)) {
    $_GET['s'] = $m[1];
    require __DIR__ . '/public/b.php';
    return true;
}

// /robots.txt and /sitemap.xml are generated (same as the .htaccess rewrites).
if ($uri === '/robots.txt') {
    require __DIR__ . '/public/robots.php';
    return true;
}
if ($uri === '/sitemap.xml') {
    require __DIR__ . '/public/sitemap.php';
    return true;
}

// Let the built-in server serve any real file in the document root as-is.
$file = __DIR__ . '/public' . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Everything else falls through to the front page / normal handling.
return false;
