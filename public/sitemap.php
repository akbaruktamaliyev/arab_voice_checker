<?php
/**
 * QRaft — /sitemap.xml: the public, indexable pages.
 */

declare(strict_types=1);
define('QRAFT_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ([['index.php', '1.0'], ['pricing.php', '0.8'], ['terms.php', '0.3'], ['privacy.php', '0.3']] as [$page, $prio]) {
    echo '  <url><loc>' . e($page === 'index.php' ? url() : url($page)) . '</loc><priority>' . $prio . '</priority></url>' . "\n";
}
echo "</urlset>\n";
