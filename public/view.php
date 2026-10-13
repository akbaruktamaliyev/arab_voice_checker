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
        $newFg = clean_color((string) ($_POST['fg_color'] ?? ''), '#000000');
        $newBg = clean_color((string) ($_POST['bg_color'] ?? ''), '#ffffff');

        $logoError = null;
        $logoPath  = $qr['logo_path'];
        try {
            $uploaded = store_logo($_FILES['logo'] ?? []);
            if ($uploaded !== null) {           // a new logo replaces the old one
                delete_logo($qr['logo_path']);
                $logoPath = $uploaded;
            } elseif (!empty($_POST['remove_logo'])) {
                delete_logo($qr['logo_path']);
                $logoPath = null;
            }
        } catch (RuntimeException $e) {
            $logoError = $e->getMessage();
        }

        if ($logoError !== null) {
            flash($logoError, 'error');
        } elseif ($newTitle !== '' && mb_strlen($newTitle) <= 120 && $newTarget !== null) {
            $u = $pdo->prepare(
                'UPDATE qr_codes SET title = ?, target_url = ?, fg_color = ?, bg_color = ?, logo_path = ?
                 WHERE id = ? AND user_id = ?'
            );
            $u->execute([$newTitle, $newTarget, $newFg, $newBg, $logoPath, $id, $uid]);
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
$logoUrl  = $qr['logo_path'] ? url($qr['logo_path']) : null;
$logoData = logo_data_uri($qr['logo_path']); // self-contained SVG export
$title = $qr['title'];
ob_start();
?>
<p><a href="<?= e(url('dashboard.php')) ?>">← Back to dashboard</a></p>

<div class="grid-2 section">
    <div class="card stack center">
        <div class="qr-preview" id="qr-holder"></div>
        <div class="row">
            <button type="button" id="qr-download-png" class="btn btn-primary">Download PNG</button>
            <button type="button" id="qr-download-svg" class="btn btn-ghost">Download SVG</button>
        </div>
        <div class="muted" style="font-size:.85rem;word-break:break-all"><?= e($shortUrl) ?></div>
    </div>

    <div class="stack">
        <div class="card stack">
            <h2 style="margin:0">Edit</h2>
            <form method="post" class="stack" enctype="multipart/form-data">
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
                <div class="row">
                    <div class="field">
                        <label for="fg_color">QR colour</label>
                        <input type="color" id="fg_color" name="fg_color" value="<?= e($qr['fg_color']) ?>">
                    </div>
                    <div class="field">
                        <label for="bg_color">Background</label>
                        <input type="color" id="bg_color" name="bg_color" value="<?= e($qr['bg_color']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="logo">Centre logo <span class="muted">(PNG/JPG/WebP, max 1 MB)</span></label>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                    <?php if (!empty($qr['logo_path'])): ?>
                        <label class="muted" style="font-weight:400;margin-top:8px">
                            <input type="checkbox" name="remove_logo" value="1" style="width:auto"> Remove current logo
                        </label>
                    <?php endif; ?>
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
        <div style="display:flex;align-items:center;gap:14px">
            <span class="muted">Total: <strong><?= (int) $qr['scan_count'] ?></strong></span>
            <?php if ((int) $qr['scan_count'] > 0): ?>
                <a class="btn btn-ghost" href="<?= e(url('export.php?id=' . (int) $qr['id'])) ?>">Export CSV</a>
            <?php endif; ?>
        </div>
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

<script src="<?= e(url('assets/js/qrcode-generator.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/qrender.js')) ?>"></script>
<script>
    (function () {
        var holder = document.getElementById('qr-holder');
        var url  = <?= json_encode($shortUrl) ?>;
        var name = 'qr-<?= e($qr['code']) ?>';
        var logoUrl  = <?= json_encode($logoUrl) ?>;
        var logoData = <?= json_encode($logoData) ?>;
        var fg = document.getElementById('fg_color');
        var bg = document.getElementById('bg_color');

        var logoImg = null;
        var ecc = logoData ? 'H' : 'M'; // stronger correction when a logo is set

        function draw() {
            return QRaftRender.paint(holder, url, 240, fg.value, bg.value, ecc, logoImg);
        }
        var qr = draw();

        // The stored logo is same-origin, so the canvas stays exportable.
        if (logoUrl) {
            var img = new Image();
            img.onload = function () { logoImg = img; qr = draw(); };
            img.src = logoUrl;
        }

        [fg, bg].forEach(function (el) {
            el.addEventListener('input', function () { qr = draw(); });
        });
        document.getElementById('qr-download-png').addEventListener('click', function () {
            QRaftRender.downloadPng(qr, 512, fg.value, bg.value, name, logoImg);
        });
        document.getElementById('qr-download-svg').addEventListener('click', function () {
            QRaftRender.downloadSvg(qr, 512, fg.value, bg.value, name, logoData);
        });
    })();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
