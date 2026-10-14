<?php
/**
 * QRaft — /robots.txt (served through the rewrite in .htaccess / router.php).
 * Public pages may be indexed; the app itself (accounts, admin, API) may not.
 */

declare(strict_types=1);
define('QRAFT_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
$base = rtrim((string) parse_url(url(), PHP_URL_PATH), '/');
echo "User-agent: *\n";
foreach (['admin/', 'api/', 'install/', 'uploads/', 'dashboard.php', 'create.php', 'view.php', 'bulk.php', 'bios.php',
          'bio-edit.php', 'tokens.php', 'billing.php', 'account.php', 'checkout.php', 'export.php', 'login.php',
          'register.php', 'forgot.php', 'reset.php', 'verify.php', 'q/'] as $path) {
    echo 'Disallow: ' . $base . '/' . $path . "\n";
}
echo "\nSitemap: " . url('sitemap.xml') . "\n";
