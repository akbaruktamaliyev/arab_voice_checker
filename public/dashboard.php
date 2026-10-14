<?php
/**
 * QRaft — dashboard: list the user's dynamic QR codes and totals.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();

// Folder actions (create / delete).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_folder') {
        $name = trim((string) ($_POST['folder_name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 80) {
            $pdo->prepare('INSERT INTO folders (user_id, name) VALUES (?, ?)')->execute([$uid, $name]);
            flash('Folder created.', 'success');
        }
    } elseif ($action === 'delete_folder') {
        // Codes in it become unfiled (FK ON DELETE SET NULL).
        $pdo->prepare('DELETE FROM folders WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['folder_id'] ?? 0), $uid]);
        flash('Folder deleted.', 'info');
    }
    redirect('dashboard.php');
}

// Current folder filter: '' = all, 'none' = unfiled, or a folder id.
$filter = (string) ($_GET['folder'] ?? '');

$folders = $pdo->prepare(
    'SELECT f.id, f.name, (SELECT COUNT(*) FROM qr_codes c WHERE c.folder_id = f.id) AS n
     FROM folders f WHERE f.user_id = ? ORDER BY f.name'
);
$folders->execute([$uid]);
$folders = $folders->fetchAll();

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

// The user's codes, filtered by the selected folder.
$where = 'user_id = ?';
$params = [$uid];
if ($filter === 'none') {
    $where .= ' AND folder_id IS NULL';
} elseif ($filter !== '' && ctype_digit($filter)) {
    $where .= ' AND folder_id = ?';
    $params[] = (int) $filter;
}
$list = $pdo->prepare(
    "SELECT id, code, title, target_url, is_active, scan_count, created_at
     FROM qr_codes WHERE $where ORDER BY created_at DESC"
);
$list->execute($params);
$codes = $list->fetchAll();

$title = 'Dashboard';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Your QR codes</h1>
    <div style="display:flex;gap:10px">
        <a class="btn btn-ghost" href="<?= e(url('tokens.php')) ?>">API</a>
        <?php if ((int) $sum['scans'] > 0): ?>
            <a class="btn btn-ghost" href="<?= e(url('export.php')) ?>">Export all scans (CSV)</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('create.php')) ?>">+ New QR code</a>
    </div>
</div>

<div class="stats section">
    <div class="stat"><div class="num"><?= (int) $sum['codes'] ?></div><div class="lbl">Dynamic codes</div></div>
    <div class="stat"><div class="num"><?= (int) $sum['scans'] ?></div><div class="lbl">Total scans</div></div>
    <div class="stat"><div class="num"><?= $weekScans ?></div><div class="lbl">Scans (last 7 days)</div></div>
</div>

<div class="card" style="margin-bottom:16px">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <a class="btn <?= $filter === '' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:5px 12px;font-size:.85rem" href="<?= e(url('dashboard.php')) ?>">All</a>
        <a class="btn <?= $filter === 'none' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:5px 12px;font-size:.85rem" href="<?= e(url('dashboard.php?folder=none')) ?>">Unfiled</a>
        <?php foreach ($folders as $f): ?>
            <span style="display:inline-flex;align-items:center;gap:4px">
                <a class="btn <?= $filter === (string) $f['id'] ? 'btn-primary' : 'btn-ghost' ?>" style="padding:5px 12px;font-size:.85rem"
                   href="<?= e(url('dashboard.php?folder=' . (int) $f['id'])) ?>"><?= e($f['name']) ?> <span class="muted">(<?= (int) $f['n'] ?>)</span></a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this folder? Its codes become unfiled.');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_folder">
                    <input type="hidden" name="folder_id" value="<?= (int) $f['id'] ?>">
                    <button class="btn btn-ghost" style="padding:4px 7px;font-size:.75rem" title="Delete folder">✕</button>
                </form>
            </span>
        <?php endforeach; ?>
        <form method="post" style="display:inline-flex;gap:6px;margin-left:auto">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_folder">
            <input type="text" name="folder_name" placeholder="New folder" maxlength="80" style="width:150px;padding:5px 10px">
            <button class="btn btn-ghost" style="padding:5px 12px;font-size:.85rem">Add</button>
        </form>
    </div>
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
