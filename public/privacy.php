<?php
/**
 * QRaft — Privacy Policy (a generic template; the text lives in lang/*.app.php).
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$title = t('legal.privacy');
$description = t('privacy.s4.p');
$retention = (int) setting('scan_retention_days', '0');
$sections = [];
for ($n = 1; isset($GLOBALS['lang']["privacy.s$n.h"]); $n++) {
    $args = [];
    if ($n === 1) { $args = [app_name(), legal_contact()]; }
    $sections[] = [t("privacy.s$n.h"), t("privacy.s$n.p", ...$args)];
    if ($n === 4) { // how long scan records are kept (depends on the admin setting)
        $sections[] = [t('privacy.s4b.h'), $retention > 0 ? t('privacy.retention_days', $retention) : t('privacy.retention_forever')];
    }
}
require __DIR__ . '/../src/legal_view.php';
