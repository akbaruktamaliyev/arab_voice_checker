<?php
/**
 * QRaft — delete a dynamic QR code (and its scans, via the FK cascade).
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM qr_codes WHERE id = ? AND user_id = ?');
$stmt->execute([$id, current_user_id()]);

flash($stmt->rowCount() ? 'QR code deleted.' : 'QR code not found.', 'info');
redirect('dashboard.php');
