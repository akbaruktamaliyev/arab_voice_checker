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
    flash(t('err.not_found'), 'error');
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
        $newSlug = trim((string) ($_POST['slug'] ?? ''));

        // Validate all text fields BEFORE touching any logo file, so a bad
        // field can never leave the stored logo half-updated.
        $errorMsg = null;
        if ($newTitle === '' || mb_strlen($newTitle) > 120 || $newTarget === null) {
            $errorMsg = t('err.title_url');
        } elseif ($newSlug !== $qr['code']) {
            // The custom code changed — validate format and uniqueness.
            if (!valid_slug($newSlug)) {
                $errorMsg = t('err.slug');
            } else {
                $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ? AND id <> ?');
                $dup->execute([$newSlug, $id]);
                if ($dup->fetchColumn() !== false) {
                    $errorMsg = t('err.slug_taken');
                }
            }
        }

        if ($errorMsg !== null) {
            flash($errorMsg, 'error');
        } else {
            try {
                $logoPath = $qr['logo_path'];
                $uploaded = store_logo($_FILES['logo'] ?? []);
                if ($uploaded !== null) {           // a new logo replaces the old one
                    delete_logo($qr['logo_path']);
                    $logoPath = $uploaded;
                } elseif (!empty($_POST['remove_logo'])) {
                    delete_logo($qr['logo_path']);
                    $logoPath = null;
                }

                // Protection: keep, replace or remove the password; expiry/limit.
                $pwHash = $qr['password_hash'];
                if (!empty($_POST['remove_password'])) {
                    $pwHash = null;
                } elseif (trim((string) ($_POST['password'] ?? '')) !== '') {
                    $pwHash = password_hash(trim((string) $_POST['password']), PASSWORD_DEFAULT);
                }
                $expires   = parse_expiry((string) ($_POST['expires_at'] ?? ''));
                $scanLimit = parse_scan_limit((string) ($_POST['scan_limit'] ?? ''));

                $folderId = null;
                if (($_POST['folder_id'] ?? '') !== '' && ctype_digit((string) $_POST['folder_id'])) {
                    $chk = $pdo->prepare('SELECT 1 FROM folders WHERE id = ? AND user_id = ?');
                    $chk->execute([(int) $_POST['folder_id'], $uid]);
                    if ($chk->fetchColumn() !== false) {
                        $folderId = (int) $_POST['folder_id'];
                    }
                }

                $u = $pdo->prepare(
                    'UPDATE qr_codes SET code = ?, title = ?, target_url = ?, fg_color = ?, bg_color = ?,
                        logo_path = ?, password_hash = ?, expires_at = ?, scan_limit = ?, folder_id = ?
                     WHERE id = ? AND user_id = ?'
                );
                $u->execute([$newSlug, $newTitle, $newTarget, $newFg, $newBg, $logoPath,
                             $pwHash, $expires, $scanLimit, $folderId, $id, $uid]);
                flash(t('view.saved'), 'success');
            } catch (RuntimeException $e) {
                // store_logo() failed; the old logo and row are left untouched.
                flash($e->getMessage(), 'error');
            }
        }
    } elseif ($action === 'toggle') {
        $u = $pdo->prepare('UPDATE qr_codes SET is_active = 1 - is_active WHERE id = ? AND user_id = ?');
        $u->execute([$id, $uid]);
        flash(t('view.status_updated'), 'success');
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

// Breakdown by device / OS / browser (top values).
function breakdown(PDO $pdo, int $id, string $col): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE($col, 'Unknown') AS k, COUNT(*) AS n
         FROM qr_scans WHERE qr_id = ? GROUP BY k ORDER BY n DESC LIMIT 5"
    );
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}
$byDevice  = breakdown($pdo, $id, 'device');
$byOs      = breakdown($pdo, $id, 'os');
$byBrowser = breakdown($pdo, $id, 'browser');

$myFolders = $pdo->prepare('SELECT id, name FROM folders WHERE user_id = ? ORDER BY name');
$myFolders->execute([$uid]);
$myFolders = $myFolders->fetchAll();

$shortUrl = url('q/' . $qr['code']);
$logoUrl  = $qr['logo_path'] ? url($qr['logo_path']) : null;
$title = $qr['title'];
ob_start();
?>
<p><a href="<?= e(url('dashboard.php')) ?>"><?= e(t('create.back')) ?></a></p>

<div class="grid-2 section">
    <div class="card stack center">
        <div class="qr-preview" id="qr-holder"></div>
        <div class="row nowrap">
            <button type="button" id="qr-download-png" class="btn btn-primary">PNG</button>
            <button type="button" id="qr-download-svg" class="btn btn-ghost">SVG</button>
            <button type="button" id="qr-download-pdf" class="btn btn-ghost">PDF</button>
        </div>
        <div class="muted" style="font-size:.85rem;word-break:break-all"><?= e($shortUrl) ?></div>
    </div>

    <div class="stack">
        <div class="card stack">
            <h2 style="margin:0"><?= e(t('view.edit')) ?></h2>
            <form method="post" class="stack" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <div class="field">
                    <label for="title"><?= e(t('form.title')) ?></label>
                    <input type="text" id="title" name="title" value="<?= e($qr['title']) ?>" required>
                </div>
                <div class="field">
                    <label for="target_url"><?= e(t('form.dest')) ?></label>
                    <input type="text" id="target_url" name="target_url" value="<?= e($qr['target_url']) ?>" required>
                </div>
                <?php if ($myFolders): ?>
                <div class="field">
                    <label for="folder_id"><?= e(t('form.folder')) ?></label>
                    <select id="folder_id" name="folder_id">
                        <option value=""><?= e(t('form.no_folder')) ?></option>
                        <?php foreach ($myFolders as $f): ?>
                            <option value="<?= (int) $f['id'] ?>" <?= (string) $qr['folder_id'] === (string) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="field">
                    <label for="slug"><?= e(t('form.slug')) ?></label>
                    <input type="text" id="slug" name="slug" value="<?= e($qr['code']) ?>"
                           pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" maxlength="32" required>
                    <p class="muted" style="font-size:.8rem;margin:6px 0 0">
                        <?= e(t('form.slug_warn')) ?>
                    </p>
                </div>
                <div class="row">
                    <div class="field">
                        <label for="fg_color"><?= e(t('form.qr_colour')) ?></label>
                        <input type="color" id="fg_color" name="fg_color" value="<?= e($qr['fg_color']) ?>">
                    </div>
                    <div class="field">
                        <label for="bg_color"><?= e(t('form.background')) ?></label>
                        <input type="color" id="bg_color" name="bg_color" value="<?= e($qr['bg_color']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="logo"><?= e(t('form.logo')) ?> <span class="muted"><?= e(t('form.logo_opt2')) ?></span></label>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                    <?php if (!empty($qr['logo_path'])): ?>
                        <label class="muted" style="font-weight:400;margin-top:8px">
                            <input type="checkbox" name="remove_logo" value="1" style="width:auto"> <?= e(t('view.remove_logo')) ?>
                        </label>
                    <?php endif; ?>
                </div>

                <details <?= ($qr['password_hash'] || $qr['expires_at'] || $qr['scan_limit']) ? 'open' : '' ?>>
                    <summary style="cursor:pointer;font-weight:600;margin:4px 0"><?= e(t('form.protection')) ?></summary>
                    <div class="stack" style="margin-top:12px">
                        <div class="field">
                            <label for="password"><?= e(t('form.password')) ?></label>
                            <input type="text" id="password" name="password"
                                   placeholder="<?= $qr['password_hash'] ? t('view.password_set_ph') : t('view.password_none') ?>">
                            <?php if ($qr['password_hash']): ?>
                                <label class="muted" style="font-weight:400;margin-top:8px">
                                    <input type="checkbox" name="remove_password" value="1" style="width:auto"> <?= e(t('view.remove_password')) ?>
                                </label>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="field">
                                <label for="expires_at"><?= e(t('form.expires')) ?></label>
                                <input type="datetime-local" id="expires_at" name="expires_at"
                                       value="<?= e(to_datetime_local($qr['expires_at'])) ?>">
                            </div>
                            <div class="field">
                                <label for="scan_limit"><?= e(t('form.scan_limit')) ?></label>
                                <input type="number" id="scan_limit" name="scan_limit" min="1"
                                       placeholder="<?= e(t('form.unlimited_ph')) ?>" value="<?= e((string) ($qr['scan_limit'] ?? '')) ?>">
                            </div>
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-primary btn-block"><?= e(t('view.save')) ?></button>
            </form>
        </div>

        <?php [$statusLabel, $statusClass] = code_status($qr); ?>
        <div class="card actions" style="justify-content:space-between">
            <div class="actions">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <button type="submit" class="btn btn-ghost"><?= e($qr['is_active'] ? t('view.pause') : t('view.activate')) ?></button>
                </form>
                <form method="post" action="<?= e(url('delete.php')) ?>"
                      onsubmit='return confirm(<?= e(json_encode(t('view.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);'>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $qr['id'] ?>">
                    <button type="submit" class="btn btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
            <div class="muted"><?= e(t('view.status')) ?> <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
        </div>
    </div>
</div>

<div class="card section">
    <div class="section-head">
        <h2 style="margin:0"><?= e(t('view.scans_title')) ?></h2>
        <div style="display:flex;align-items:center;gap:14px">
            <span class="muted"><?= e(t('view.total')) ?> <strong><?= (int) $qr['scan_count'] ?></strong></span>
            <?php if ((int) $qr['scan_count'] > 0): ?>
                <a class="btn btn-ghost" href="<?= e(url('export.php?id=' . (int) $qr['id'])) ?>"><?= e(t('view.export')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <svg viewBox="0 0 560 160" width="100%" height="160" role="img" aria-label="<?= e(t('view.scans_aria')) ?>">
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
                      fill="var(--muted)" text-anchor="middle"><?= date('d.m', strtotime($d['date'])) ?></text>
            <?php endif; ?>
        <?php endforeach; ?>
    </svg>
</div>

<?php if ((int) $qr['scan_count'] > 0): ?>
<?php
    $panel = function (string $heading, array $rows): string {
        $total = array_sum(array_column($rows, 'n')) ?: 1;
        $html = '<div class="card"><h2 style="margin:0 0 12px;font-size:1.05rem">' . e($heading) . '</h2>';
        foreach ($rows as $r) {
            $pct = round($r['n'] / $total * 100);
            $html .= '<div style="margin-bottom:10px">'
                . '<div style="display:flex;justify-content:space-between;font-size:.88rem">'
                . '<span>' . e($r['k'] === 'Unknown' ? t('view.unknown') : $r['k']) . '</span><span class="muted">' . (int) $r['n'] . '</span></div>'
                . '<div style="height:6px;background:var(--bg);border-radius:4px;overflow:hidden;margin-top:3px">'
                . '<div style="height:100%;width:' . $pct . '%;background:var(--primary)"></div></div></div>';
        }
        return $html . '</div>';
    };
?>
<div class="grid-3 section">
    <?= $panel(t('view.by_device'), $byDevice) ?>
    <?= $panel(t('view.by_os'), $byOs) ?>
    <?= $panel(t('view.by_browser'), $byBrowser) ?>
</div>
<?php endif; ?>

<script src="<?= e(url('assets/js/qrcode-generator.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/qrender.js')) ?>"></script>
<script>
    (function () {
        var holder = document.getElementById('qr-holder');
        var url  = <?= json_encode($shortUrl) ?>;
        var name = 'qr-<?= e($qr['code']) ?>';
        var logoUrl = <?= json_encode($logoUrl) ?>;
        var fg = document.getElementById('fg_color');
        var bg = document.getElementById('bg_color');
        var pngBtn = document.getElementById('qr-download-png');
        var svgBtn = document.getElementById('qr-download-svg');

        var logoImg = null;
        var ecc = logoUrl ? 'H' : 'M'; // stronger correction when a logo is set

        function draw() {
            return QRaftRender.paint(holder, url, 240, fg.value, bg.value, ecc, logoImg);
        }
        var qr = draw();

        // The stored logo is same-origin, so the canvas stays exportable.
        // Hold PNG export until the logo has loaded, or it would export without it.
        if (logoUrl) {
            pngBtn.setAttribute('disabled', 'disabled');
            var img = new Image();
            img.onload = function () { logoImg = img; qr = draw(); pngBtn.removeAttribute('disabled'); };
            img.src = logoUrl;
        }

        [fg, bg].forEach(function (el) {
            el.addEventListener('input', function () { qr = draw(); });
        });
        pngBtn.addEventListener('click', function () {
            QRaftRender.downloadPng(qr, 512, fg.value, bg.value, name, logoImg);
        });

        // PDF: lazy-load jsPDF on first click, then compose a one-page sheet.
        var jspdfUrl = <?= json_encode(url('assets/js/jspdf.umd.min.js')) ?>;
        function withJsPDF(cb) {
            if (window.jspdf && window.jspdf.jsPDF) { cb(); return; }
            var s = document.createElement('script');
            s.src = jspdfUrl; s.onload = cb; document.head.appendChild(s);
        }
        document.getElementById('qr-download-pdf').addEventListener('click', function () {
            withJsPDF(function () {
                var canvas = QRaftRender.toCanvas(qr, 1000, fg.value, bg.value, logoImg);
                var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', compress: true });
                var pw = doc.internal.pageSize.getWidth();
                doc.setFontSize(18);
                doc.text(<?= json_encode($qr['title']) ?>, pw / 2, 30, { align: 'center' });
                var size = 100;
                doc.addImage(canvas.toDataURL('image/png'), 'PNG', (pw - size) / 2, 45, size, size, undefined, 'FAST');
                doc.setFontSize(11); doc.setTextColor(120);
                doc.text(url, pw / 2, 160, { align: 'center' });
                doc.save(name + '.pdf');
            });
        });

        svgBtn.addEventListener('click', function () {
            // Fetch the logo only now (on demand) and inline it for a
            // self-contained SVG — nothing is base64-encoded on page load.
            if (!logoUrl) {
                QRaftRender.downloadSvg(qr, 512, fg.value, bg.value, name, null);
                return;
            }
            fetch(logoUrl).then(function (r) { return r.blob(); }).then(function (blob) {
                var fr = new FileReader();
                fr.onload = function () { QRaftRender.downloadSvg(qr, 512, fg.value, bg.value, name, fr.result); };
                fr.readAsDataURL(blob);
            }).catch(function () {
                QRaftRender.downloadSvg(qr, 512, fg.value, bg.value, name, null);
            });
        });
    })();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
