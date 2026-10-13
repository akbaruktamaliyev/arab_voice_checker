<?php
/**
 * QRaft — dynamic QR resolver.
 *
 * A dynamic QR encodes {base_url}/q/{code}. This endpoint looks the code up,
 * records the scan and forwards the visitor to the current destination.
 * The .htaccess rewrite maps /q/{code} to this file; ?c={code} also works.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$code = (string) ($_GET['c'] ?? '');
$code = preg_replace('/[^a-zA-Z0-9]/', '', $code);

if ($code === '') {
    http_response_code(404);
    exit('QR code not found.');
}

$stmt = $pdo->prepare('SELECT id, target_url, is_active FROM qr_codes WHERE code = ?');
$stmt->execute([$code]);
$qr = $stmt->fetch();

if (!$qr) {
    http_response_code(404);
    exit('This QR code does not exist.');
}

if (!$qr['is_active']) {
    http_response_code(410);
    exit('This QR code is currently paused.');
}

// Record the scan. The IP is stored only as a salted hash (privacy-friendly,
// still lets the buyer de-duplicate if they extend the app).
try {
    $pdo->beginTransaction();

    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = (string) config('base_url');
    $ipHash = $ip !== '' ? hash('sha256', $salt . '|' . $ip) : null;

    $ins = $pdo->prepare(
        'INSERT INTO qr_scans (qr_id, ip_hash, user_agent, referer)
         VALUES (?, ?, ?, ?)'
    );
    $ins->execute([
        $qr['id'],
        $ipHash,
        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
    ]);

    $pdo->prepare('UPDATE qr_codes SET scan_count = scan_count + 1 WHERE id = ?')
        ->execute([$qr['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Never block the visitor if logging fails — just forward them.
}

header('Location: ' . $qr['target_url'], true, 302);
exit;
