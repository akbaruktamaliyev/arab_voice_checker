<?php
/**
 * QRaft — dynamic QR resolver.
 *
 * Resolves /q/{code}: enforces active/expiry/scan-limit/password, records the
 * scan (device/OS/browser), then forwards to the current destination.
 * The .htaccess rewrite (or dev router) maps /q/{code} here; ?c={code} works too.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$code = (string) ($_GET['c'] ?? '');
$code = preg_replace('/[^A-Za-z0-9_-]/', '', $code);

if ($code === '') {
    http_response_code(404);
    exit('QR code not found.');
}

$stmt = $pdo->prepare(
    'SELECT id, target_url, is_active, password_hash, expires_at, scan_limit, scan_count
     FROM qr_codes WHERE code = ?'
);
$stmt->execute([$code]);
$qr = $stmt->fetch();

/** Minimal standalone page (no app layout — this is the public scan endpoint). */
function scan_page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
        . '<style>body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f6fb;color:#1b2333;'
        . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center}'
        . '.c{background:#fff;border:1px solid #e3e8f0;border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;box-shadow:0 8px 24px rgba(20,30,60,.06)}'
        . 'input{width:100%;padding:10px 12px;border:1px solid #e3e8f0;border-radius:10px;font-size:1rem;box-sizing:border-box;margin:10px 0}'
        . 'button{width:100%;padding:11px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:600;font-size:1rem;cursor:pointer}'
        . 'h1{font-size:1.2rem;margin:0 0 6px}.m{color:#6b7691;font-size:.9rem}</style></head><body><div class="c">'
        . $body . '</div></body></html>';
    exit;
}

if (!$qr) {
    scan_page('Not found', '<h1>QR code not found</h1><p class="m">This code does not exist.</p>', 404);
}
if (!$qr['is_active']) {
    scan_page('Paused', '<h1>Temporarily unavailable</h1><p class="m">This QR code is paused.</p>', 410);
}
if ($qr['expires_at'] !== null && strtotime((string) $qr['expires_at']) < time()) {
    scan_page('Expired', '<h1>This QR code has expired</h1><p class="m">The link is no longer active.</p>', 410);
}
if ($qr['scan_limit'] !== null && (int) $qr['scan_count'] >= (int) $qr['scan_limit']) {
    scan_page('Limit reached', '<h1>Scan limit reached</h1><p class="m">This QR code is no longer available.</p>', 410);
}

// Password gate: show a prompt and only continue on the correct password.
if ($qr['password_hash'] !== null && $qr['password_hash'] !== '') {
    $given = (string) ($_POST['password'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !password_verify($given, $qr['password_hash'])) {
        $wrong = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? '<p class="m" style="color:#d64545">Incorrect password.</p>' : '';
        scan_page('Protected',
            '<h1>Password required</h1><p class="m">Enter the password to continue.</p>' . $wrong
            . '<form method="post"><input type="password" name="password" autofocus placeholder="Password">'
            . '<button type="submit">Continue</button></form>');
    }
}

// Record the scan (best-effort) and forward.
try {
    $pdo->beginTransaction();

    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
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
