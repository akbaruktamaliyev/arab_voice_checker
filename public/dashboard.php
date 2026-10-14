<?php
/**
 * QRaft — dashboard: the user's dynamic QR codes, totals, folders, search and
 * pagination.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
const PER_PAGE = 20;

// Folder actions (create / delete).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_folder') {
        $name = trim((string) ($_POST['folder_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            flash(t('dash.folder_name_err'), 'error');
        } else {
            $pdo->prepare('INSERT INTO folders (user_id, name) VALUES (?, ?)')->execute([$uid, $name]);
            flash(t('dash.folder_created'), 'success');
        }
    } elseif ($action === 'delete_folder') {
        // Codes in it become unfiled (FK ON DELETE SET NULL).
        $pdo->prepare('DELETE FROM folders WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['folder_id'] ?? 0), $uid]);
        flash(t('dash.folder_deleted'), 'info');
    }
    redirect('dashboard.php');
}

// Filters: folder ('' = all, 'none' = unfiled, or an id), search text, page.
$filter = (string) ($_GET['folder'] ?? '');
if ($filter !== '' && $filter !== 'none' && !ctype_digit($filter)) {
    $filter = '';
}
$search = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));

/** Build a dashboard URL with some filters overridden. */
function dash_url(array $over = []): string
{
    global $filter, $search;
    $p = array_merge(['folder' => $filter, 'q' => $search, 'page' => 1], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 1);
    return url('dashboard.php') . ($p ? '?' . http_build_query($p) : '');
}

$folders = $pdo->prepare(
    'SELECT f.id, f.name, COUNT(c.id) AS n
     FROM folders f LEFT JOIN qr_codes c ON c.folder_id = f.id
     WHERE f.user_id = ? GROUP BY f.id, f.name ORDER BY f.name'
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

// The user's codes: folder + search filters, paginated.
$where = 'user_id = ?';
$params = [$uid];
if ($filter === 'none') {
    $where .= ' AND folder_id IS NULL';
} elseif ($filter !== '') {
    $where .= ' AND folder_id = ?';
    $params[] = (int) $filter;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (title LIKE ? OR code LIKE ? OR target_url LIKE ?)';
    array_push($params, $like, $like, $like);
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM qr_codes WHERE $where");
$cnt->execute($params);
$matched = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($matched / PER_PAGE));
$page = min($page, $pages);

$list = $pdo->prepare(
    "SELECT id, code, title, target_url, is_active, admin_disabled, scan_count, scan_limit, expires_at,
            (password_hash IS NOT NULL) AS locked
     FROM qr_codes WHERE $where
     ORDER BY created_at DESC, id DESC
     LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE)
);
$list->execute($params);
$codes = $list->fetchAll();

$title = t('dash.title');
ob_start();
?>
<div class="section-head">
    <h1><?= e(t('dash.title')) ?></h1>
    <a class="btn btn-primary" href="<?= e(url('create.php')) ?>">+ <?= e(t('dash.new')) ?></a>
</div>

<div class="actions" style="margin-bottom:22px">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('bulk.php')) ?>"><?= e(t('dash.bulk')) ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('bios.php')) ?>"><?= e(t('dash.bios')) ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('tokens.php')) ?>"><?= e(t('dash.api')) ?></a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('billing.php')) ?>"><?= e(t('dash.billing')) ?></a>
    <?php if ((int) $sum['scans'] > 0): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('export.php')) ?>"><?= e(t('dash.export')) ?></a>
    <?php endif; ?>
</div>

<div class="stats section" style="margin-top:0">
    <div class="stat"><div class="num"><?= (int) $sum['codes'] ?></div><div class="lbl"><?= e(t('dash.stat_codes')) ?></div></div>
    <div class="stat"><div class="num"><?= (int) $sum['scans'] ?></div><div class="lbl"><?= e(t('dash.stat_scans')) ?></div></div>
    <div class="stat"><div class="num"><?= $weekScans ?></div><div class="lbl"><?= e(t('dash.stat_week')) ?></div></div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="folder-bar">
        <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(dash_url(['folder' => ''])) ?>"><?= e(t('dash.all')) ?></a>
        <a class="btn btn-sm <?= $filter === 'none' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e(dash_url(['folder' => 'none'])) ?>"><?= e(t('dash.unfiled')) ?></a>
        <?php foreach ($folders as $f): ?>
            <span class="actions" style="gap:4px;flex-wrap:nowrap">
                <a class="btn btn-sm <?= $filter === (string) $f['id'] ? 'btn-primary' : 'btn-ghost' ?>"
                   href="<?= e(dash_url(['folder' => (string) $f['id']])) ?>"><?= e($f['name']) ?> <span style="opacity:.7">(<?= (int) $f['n'] ?>)</span></a>
                <form method="post" onsubmit="return confirm(<?= e(json_encode(t('dash.confirm_delete_folder'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_folder">
                    <input type="hidden" name="folder_id" value="<?= (int) $f['id'] ?>">
                    <button class="btn btn-ghost btn-sm" title="<?= e(t('dash.delete_folder')) ?>" aria-label="<?= e(t('dash.delete_folder') . ' ' . $f['name']) ?>">✕</button>
                </form>
            </span>
        <?php endforeach; ?>
        <form method="post" class="folder-add">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_folder">
            <input type="text" name="folder_name" placeholder="<?= e(t('dash.new_folder')) ?>" maxlength="80" aria-label="<?= e(t('dash.new_folder_label')) ?>">
            <button class="btn btn-ghost btn-sm"><?= e(t('dash.add')) ?></button>
        </form>
    </div>
</div>

<div class="card">
    <form method="get" class="actions" style="margin-bottom:14px;flex-wrap:nowrap">
        <?php if ($filter !== ''): ?><input type="hidden" name="folder" value="<?= e($filter) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('dash.search_ph')) ?>" aria-label="<?= e(t('dash.search_label')) ?>">
        <button class="btn btn-ghost"><?= e(t('dash.search')) ?></button>
        <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= e(dash_url(['q' => ''])) ?>"><?= e(t('dash.clear')) ?></a><?php endif; ?>
    </form>

    <?php if (!$codes): ?>
        <p class="center muted" style="padding:24px 0">
            <?php if ($search !== '' || $filter !== ''): ?>
                <?= e(t('dash.none_match')) ?> <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('dash.show_all')) ?></a>
            <?php else: ?>
                <?= e(t('dash.empty')) ?> <a href="<?= e(url('create.php')) ?>"><?= e(t('dash.create_first')) ?></a>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th><?= e(t('dash.col_title')) ?></th><th class="hide-sm"><?= e(t('dash.col_dest')) ?></th><th><?= e(t('dash.col_status')) ?></th><th><?= e(t('dash.col_scans')) ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($codes as $c): [$sLabel, $sClass] = code_status($c); $short = url('q/' . $c['code']); ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('view.php?id=' . (int) $c['id'])) ?>"><?= e($c['title']) ?></a>
                            <div class="muted" style="font-size:.8rem;display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px">
                                <span class="hide-sm"><?= e($short) ?></span><span class="show-sm">/q/<?= e($c['code']) ?></span>
                                <button type="button" class="btn btn-ghost btn-sm js-copy" data-copy="<?= e($short) ?>" style="padding:1px 8px;font-size:.72rem"><?= e(t('dash.copy')) ?></button>
                            </div>
                        </td>
                        <td class="hide-sm" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <a href="<?= e($c['target_url']) ?>" target="_blank" rel="noopener"><?= e($c['target_url']) ?></a>
                        </td>
                        <td>
                            <span class="badge <?= e($sClass) ?>"><?= e($sLabel) ?></span>
                            <?php if ($c['locked']): ?><span class="badge" title="<?= e(t('status.locked')) ?>">🔒</span><?php endif; ?>
                        </td>
                        <td><strong><?= (int) $c['scan_count'] ?></strong><?= $c['scan_limit'] !== null ? '<span class="muted"> / ' . (int) $c['scan_limit'] . '</span>' : '' ?></td>
                        <td style="white-space:nowrap"><a href="<?= e(url('view.php?id=' . (int) $c['id'])) ?>"><?= e(t('dash.details')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="actions" style="justify-content:center;margin-top:16px">
                <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(dash_url(['page' => $page - 1])) ?>"><?= e(t('dash.prev')) ?></a><?php endif; ?>
                <span class="muted" style="font-size:.9rem"><?= e(t('dash.page_of', $page, $pages, $matched)) ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(dash_url(['page' => $page + 1])) ?>"><?= e(t('dash.next')) ?></a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    // "Copy" buttons: copy the short link to the clipboard.
    var copiedLabel = <?= json_encode(t('dash.copied'), JSON_UNESCAPED_UNICODE) ?>;
    document.querySelectorAll('.js-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var text = b.getAttribute('data-copy');
            var done = function () { var o = b.textContent; b.textContent = copiedLabel; setTimeout(function () { b.textContent = o; }, 1200); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
        });
    });
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
