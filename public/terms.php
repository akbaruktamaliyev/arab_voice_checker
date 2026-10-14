<?php
/**
 * QRaft — Terms of Service (a generic template; the text lives in lang/*.app.php).
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$title = t('legal.terms');
$description = t('terms.s1.p', app_name());
$sections = [];
for ($n = 1; isset($GLOBALS['lang']["terms.s$n.h"]); $n++) {
    $args = [];
    if ($n === 1) { $args = [app_name()]; }
    if ($n === 9) { $args = [legal_contact()]; }
    $sections[] = [t("terms.s$n.h"), t("terms.s$n.p", ...$args)];
}
require __DIR__ . '/../src/legal_view.php';
