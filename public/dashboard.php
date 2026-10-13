<?php
/**
 * QRaft — dashboard: list the user's dynamic QR codes and totals.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();

// Totals for the stat tiles.
$totals = $pdo->prepare(
    'SELECT COUNT(*) AS codes, COALESCE(SUM(scan_count), 0) AS scans
     FROM qr_codes WHERE user_id = ?'
);
$totals->execute([$uid]);
$sum = $totals->fetch();

// Scans in the last 7 days.
$week = $pdo->prepare(
    'SELECT COUNT(*) FROM qr_scans s
     JOIN qr_codes q ON q.id = s.qr_id
     WHERE q.user_id = ? AND s.scanned_at >= (NOW() - INTERVAL 7 DAY)'
);
$week->execute([$uid]);
$weekScans = (int) $week->fetchColumn();

// The user's codes.
$list = $pdo->prepare(
    'SELECT id, code, title, target_url, is_active, scan_count, created_at
     FROM qr_codes WHERE user_id = ? ORDER BY created_at DESC'
);
$list->execute([$uid]);
$codes = $list->fetchAll();

$title = 'Dashboard';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Your QR codes</h1>
    <a class="btn btn-primary" href="<?= e(url('create.php')) ?>">+ New QR code</a>
</div>

<div class="stats section">
    <div class="stat"><div class="num"><?= (int) $sum['codes'] ?></div><div class="lbl">Dynamic codes</div></div>
    <div class="stat"><div class="num"><?= (int) $sum['scans'] ?></div><div class="lbl">Total scans</div></div>
    <div class="stat"><div class="num"><?= $weekScans ?></div><div class="lbl">Scans (last 7 days)</div></div>
</div>

<div class="card">
    <?php if (!$codes): ?>
        <p class="center muted" style="padding:24px 0">
            No dynamic QR codes yet. <a href="<?= e(url('create.php')) ?>">Create your first one.</a>
        </p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Title</th><th>Destination</th><th>Status</th><th>Scans</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($codes as $c): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('view.php?id=' . (int) $c['id'])) ?>"><?= e($c['title']) ?></a>
                        <div class="muted" style="font-size:.8rem"><?= e(url('q/' . $c['code'])) ?></div>
                    </td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <a href="<?= e($c['target_url']) ?>" target="_blank" rel="noopener"><?= e($c['target_url']) ?></a>
                    </td>
                    <td>
                        <span class="badge <?= $c['is_active'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $c['is_active'] ? 'Active' : 'Paused' ?>
                        </span>
                    </td>
                    <td><strong><?= (int) $c['scan_count'] ?></strong></td>
                    <td><a href="<?= e(url('view.php?id=' . (int) $c['id'])) ?>">Details →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
