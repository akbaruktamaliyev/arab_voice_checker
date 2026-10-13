<?php
/**
 * QRaft — single dynamic QR: image, edit destination, pause, scan chart.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
$id  = (int) ($_GET['id'] ?? 0);

// Fetch the code, scoped to the owner so nobody can view another user's QR.
$stmt = $pdo->prepare('SELECT * FROM qr_codes WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $uid]);
$qr = $stmt->fetch();
if (!$qr) {
    http_response_code(404);
    flash('QR code not found.', 'error');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $newTitle  = trim((string) ($_POST['title'] ?? ''));
        $newTarget = clean_url((string) ($_POST['target_url'] ?? ''));
        if ($newTitle !== '' && mb_strlen($newTitle) <= 120 && $newTarget !== null) {
            $u = $pdo->prepare('UPDATE qr_codes SET title = ?, target_url = ? WHERE id = ? AND user_id = ?');
            $u->execute([$newTitle, $newTarget, $id, $uid]);
            flash('Changes saved.', 'success');
        } else {
            flash('Please provide a title and a valid http(s) URL.', 'error');
        }
    } elseif ($action === 'toggle') {
        $u = $pdo->prepare('UPDATE qr_codes SET is_active = 1 - is_active WHERE id = ? AND user_id = ?');
        $u->execute([$id, $uid]);
        flash('Status updated.', 'success');
    }
    redirect('view.php?id=' . $id);
}

// Last 14 days of scans for the chart.
$chart = $pdo->prepare(
    "SELECT DATE(scanned_at) AS d, COUNT(*) AS n
     FROM qr_scans WHERE qr_id = ? AND scanned_at >= (CURDATE() - INTERVAL 13 DAY)
     GROUP BY DATE(scanned_at)"
);
$chart->execute([$id]);
$byDay = [];
foreach ($chart->fetchAll() as $r) {
    $byDay[$r['d']] = (int) $r['n'];
}
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['date' => $d, 'n' => $byDay[$d] ?? 0];
}
$maxN = max(1, max(array_column($days, 'n')));

$shortUrl = url('q/' . $qr['code']);
$title = $qr['title'];
ob_start();
?>
<p><a href="<?= e(url('dashboard.php')) ?>">← Back to dashboard</a></p>

<div class="grid-2 section">
    <div class="card stack center">
        <div class="qr-preview" id="qr-holder"></div>
        <button type="button" id="qr-download" class="btn btn-primary btn-block">Download PNG</button>
        <div class="muted" style="font-size:.85rem;word-break:break-all"><?= e($shortUrl) ?></div>
    </div>

    <div class="stack">
        <div class="card stack">
            <h2 style="margin:0">Edit</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="<?= e($qr['title']) ?>" required>
                </div>
                <div class="field">
                    <label for="target_url">Destination URL</label>
                    <input type="text" id="target_url" name="target_url" value="<?= e($qr['target_url']) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save changes</button>
            </form>
        </div>

        <div class="card row" style="align-items:center">
            <form method="post" style="flex:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <button type="submit" class="btn btn-ghost">
                    <?= $qr['is_active'] ? 'Pause' : 'Activate' ?>
                </button>
            </form>
            <form method="post" action="<?= e(url('delete.php')) ?>" style="flex:0"
                  onsubmit="return confirm('Delete this QR code and its scan history?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $qr['id'] ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
            <div style="flex:1;text-align:right" class="muted">
                Status:
                <span class="badge <?= $qr['is_active'] ? 'badge-on' : 'badge-off' ?>">
                    <?= $qr['is_active'] ? 'Active' : 'Paused' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="card section">
    <div class="section-head">
        <h2 style="margin:0">Scans — last 14 days</h2>
        <div class="muted">Total: <strong><?= (int) $qr['scan_count'] ?></strong></div>
    </div>
    <svg viewBox="0 0 560 160" width="100%" height="160" role="img" aria-label="Scans per day">
        <?php $barW = 560 / count($days); ?>
        <?php foreach ($days as $i => $d): ?>
            <?php
                $h = (int) round(($d['n'] / $maxN) * 120);
                $x = $i * $barW + 4;
                $y = 130 - $h;
            ?>
            <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barW - 8 ?>" height="<?= max($h, 1) ?>"
                  rx="3" fill="#4f46e5" opacity="<?= $d['n'] ? 1 : .25 ?>"></rect>
            <?php if ($i % 2 === 0): ?>
                <text x="<?= $x + ($barW - 8) / 2 ?>" y="150" font-size="9"
                      fill="#6b7691" text-anchor="middle"><?= date('d.m', strtotime($d['date'])) ?></text>
            <?php endif; ?>
        <?php endforeach; ?>
    </svg>
</div>

<script src="<?= e(url('assets/js/qrcode.min.js')) ?>"></script>
<script>
    (function () {
        var holder = document.getElementById('qr-holder');
        new QRCode(holder, { text: <?= json_encode($shortUrl) ?>, width: 240, height: 240, correctLevel: QRCode.CorrectLevel.M });
        document.getElementById('qr-download').addEventListener('click', function () {
            var img = holder.querySelector('img'), canvas = holder.querySelector('canvas');
            var data = img ? img.src : (canvas ? canvas.toDataURL('image/png') : null);
            if (!data) return;
            var a = document.createElement('a');
            a.href = data; a.download = 'qr-<?= e($qr['code']) ?>.png';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });
    })();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
