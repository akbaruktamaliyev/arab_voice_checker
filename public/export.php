<?php
/**
 * QRaft — export scans as CSV.
 *
 * export.php?id=N  → scans for one of the user's codes
 * export.php       → every scan across all of the user's codes
 *
 * Rows are scoped to the logged-in user. A UTF-8 BOM is emitted so Excel opens
 * the file correctly, and fields are guarded against CSV/formula injection.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;

/** Neutralise spreadsheet formula injection in a user-controlled field. */
function csv_safe(?string $value): string
{
    $value = (string) $value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

if ($id > 0) {
    // Single code — verify ownership first.
    $own = $pdo->prepare('SELECT title, code FROM qr_codes WHERE id = ? AND user_id = ?');
    $own->execute([$id, $uid]);
    $qr = $own->fetch();
    if (!$qr) {
        http_response_code(404);
        flash('QR code not found.', 'error');
        redirect('dashboard.php');
    }
    $filename = 'scans-' . $qr['code'] . '-' . date('Ymd') . '.csv';
    $header   = ['Scanned at', 'Visitor (hashed)', 'User agent', 'Referer'];
    $query = $pdo->prepare(
        'SELECT scanned_at, ip_hash, user_agent, referer
         FROM qr_scans WHERE qr_id = ? ORDER BY scanned_at DESC'
    );
    $query->execute([$id]);
    $withCode = false;
} else {
    // All codes for this user.
    $filename = 'scans-all-' . date('Ymd') . '.csv';
    $header   = ['Code title', 'Code', 'Scanned at', 'Visitor (hashed)', 'User agent', 'Referer'];
    $query = $pdo->prepare(
        'SELECT q.title, q.code, s.scanned_at, s.ip_hash, s.user_agent, s.referer
         FROM qr_scans s JOIN qr_codes q ON q.id = s.qr_id
         WHERE q.user_id = ? ORDER BY s.scanned_at DESC'
    );
    $query->execute([$uid]);
    $withCode = true;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, $header);

while ($row = $query->fetch()) {
    if ($withCode) {
        fputcsv($out, [
            csv_safe($row['title']),
            csv_safe($row['code']),
            $row['scanned_at'],
            (string) $row['ip_hash'],
            csv_safe($row['user_agent']),
            csv_safe($row['referer']),
        ]);
    } else {
        fputcsv($out, [
            $row['scanned_at'],
            (string) $row['ip_hash'],
            csv_safe($row['user_agent']),
            csv_safe($row['referer']),
        ]);
    }
}
fclose($out);
