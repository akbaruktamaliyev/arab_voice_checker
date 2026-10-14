<?php
/**
 * QRaft — dynamic QR resolver.
 *
 * Resolves /q/{code}: enforces active/expiry/scan-limit/password, records the
 * scan (device/OS/browser), then forwards to the current destination.
 * The .htaccess rewrite (or dev router) maps /q/{code} here; ?c={code} works too.
 */

declare(strict_types=1);
define('QRAFT_NO_SESSION', true);   // anonymous endpoint: no cookie, no session file
require __DIR__ . '/../src/bootstrap.php';

$code = (string) ($_GET['c'] ?? '');
$code = preg_replace('/[^A-Za-z0-9_-]/', '', $code);

if ($code === '') {
    http_response_code(404);
    exit(t('q.nf_title'));
}

$stmt = $pdo->prepare(
    'SELECT q.id, q.target_url, q.is_active, q.admin_disabled, u.is_blocked AS owner_blocked,
            q.password_hash, q.expires_at, q.scan_limit, q.scan_count
     FROM qr_codes q JOIN users u ON u.id = q.user_id WHERE q.code = ?'
);
$stmt->execute([$code]);
$qr = $stmt->fetch();

if (!$qr) {
    standalone_page(t('q.nf_page'), '<h1>' . e(t('q.nf_title')) . '</h1><p class="m">' . e(t('q.nf_text')) . '</p>', 404);
}
// Disabled by an administrator, or the owner's account is suspended.
if ($qr['admin_disabled'] || $qr['owner_blocked']) {
    standalone_page(t('q.disabled_page'), '<h1>' . e(t('q.disabled_title')) . '</h1><p class="m">' . e(t('q.disabled_text')) . '</p>', 410);
}
if (!$qr['is_active']) {
    standalone_page(t('q.paused_page'), '<h1>' . e(t('q.paused_title')) . '</h1><p class="m">' . e(t('q.paused_text')) . '</p>', 410);
}
if ($qr['expires_at'] !== null && strtotime((string) $qr['expires_at']) < time()) {
    standalone_page(t('q.expired_page'), '<h1>' . e(t('q.expired_title')) . '</h1><p class="m">' . e(t('q.expired_text')) . '</p>', 410);
}
if ($qr['scan_limit'] !== null && (int) $qr['scan_count'] >= (int) $qr['scan_limit']) {
    standalone_page(t('limit.page_title'), '<h1>' . e(t('q.limit_title')) . '</h1><p class="m">' . e(t('q.limit_text')) . '</p>', 410);
}

// Password gate: show a prompt and only continue on the correct password.
if ($qr['password_hash'] !== null && $qr['password_hash'] !== '') {
    $given = (string) ($_POST['password'] ?? '');
    $bucket = 'qrpw:' . $code;
    // Too many wrong guesses from this visitor for this code: refuse without checking.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && throttle_blocked($pdo, $bucket, QRPW_MAX_ATTEMPTS, QRPW_WINDOW_MINUTES)) {
        header('Retry-After: ' . (QRPW_WINDOW_MINUTES * 60));
        standalone_page(t('q.protected_page'),
            '<h1>' . e(t('q.locked_title')) . '</h1><p class="m">' . e(t('q.locked_text', QRPW_WINDOW_MINUTES)) . '</p>', 429);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !password_verify($given, $qr['password_hash'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            throttle_hit($pdo, $bucket);
        }
        $wrong = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? '<p class="m" style="color:#d64545">' . e(t('q.wrong_pw')) . '</p>' : '';
        standalone_page(t('q.protected_page'),
            '<h1>' . e(t('q.pw_title')) . '</h1><p class="m">' . e(t('q.pw_text')) . '</p>' . $wrong
            . '<form method="post"><input type="password" name="password" autofocus placeholder="' . e(t('form.password')) . '">'
            . '<button type="submit">' . e(t('q.continue')) . '</button></form>');
    }
}

// Link-preview bots (Telegram, WhatsApp, Slack, search crawlers…) and HEAD
// probes fetch URLs automatically. Forward them without counting a scan, or they
// would inflate the statistics and burn through a code's scan limit.
$uaHeader = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'
    || preg_match('~bot\b|bot/|crawler|spider|facebookexternalhit|whatsapp|telegrambot|slackbot|skypeuripreview|linkpreview|embedly|vkshare|preview~i', $uaHeader)) {
    header('Location: ' . $qr['target_url'], true, 302);
    exit;
}

// Record the scan (best-effort) and forward.
try {
    $pdo->beginTransaction();

    $ip   = client_ip();
    $salt = (string) config('base_url');
    $ipHash = $ip !== '' ? hash('sha256', $salt . '|' . $ip) : null;

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    [$device, $os, $browser] = parse_user_agent($ua);

    $pdo->prepare(
        'INSERT INTO qr_scans (qr_id, ip_hash, user_agent, referer, device, os, browser)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $qr['id'], $ipHash, mb_substr($ua, 0, 255),
        mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        $device, $os, $browser,
    ]);

    $pdo->prepare('UPDATE qr_codes SET scan_count = scan_count + 1 WHERE id = ?')
        ->execute([$qr['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

header('Location: ' . $qr['target_url'], true, 302);
exit;
