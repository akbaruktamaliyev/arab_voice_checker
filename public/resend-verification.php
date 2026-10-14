<?php
/**
 * QRaft — send a new e-mail confirmation link. POST-only (CSRF-protected) and
 * rate-limited; the answer is always the same, so it never reveals whether an
 * address is registered.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}
csrf_check();

$email = trim((string) ($_POST['email'] ?? ''));

if (throttle_blocked($pdo, 'verify-resend', VERIFY_RESEND_MAX_PER_IP, 60)) {
    flash(t('err.rate_limited'), 'error');
    redirect('login.php');
}
throttle_hit($pdo, 'verify-resend');

if (filter_var($email, FILTER_VALIDATE_EMAIL) && mail_enabled()) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_verified = 0 AND is_blocked = 0');
    $stmt->execute([$email]);
    $uid = $stmt->fetchColumn();
    $mailKey = hash('sha256', 'verify|' . strtolower($email));
    if ($uid !== false && !throttle_blocked($pdo, 'verify-mail', VERIFY_RESEND_MAX_PER_EMAIL, 60, $mailKey)) {
        throttle_hit($pdo, 'verify-mail', $mailKey);
        send_verification_mail($pdo, (int) $uid, $email);
    }
}

flash(t('verify.resent'), 'success');
redirect('login.php');
