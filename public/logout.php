<?php
/**
 * QRaft — sign out. POST-only with a CSRF token, so a stray link or image on
 * another site cannot log a visitor out.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout_user();
    flash(t('auth.signed_out'), 'info');
}
redirect('index.php');
