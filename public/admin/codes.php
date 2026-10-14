<?php
/**
 * QRaft — admin moderation of every dynamic code on the site: search, disable
 * (the owner cannot re-enable it), enable, delete.
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin($pdo);

const ADMIN_CODES_PER_PAGE = 25;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $cid = (int) ($_POST['id'] ?? 0);

    if ($action === 'disable' || $action === 'enable') {
        $pdo->prepare('UPDATE qr_codes SET admin_disabled = ? WHERE id = ?')
            ->execute([$action === 'disable' ? 1 : 0, $cid]);
        flash(t($action === 'disable' ? 'adm.c_disabled' : 'adm.c_enabled'), 'success');
    } elseif ($action === 'delete') {
        $find = $pdo->prepare('SELECT logo_path FROM qr_codes WHERE id = ?');
        $find->execute([$cid]);
        $row = $find->fetch();
        if ($row) {
            delete_logo($row['logo_path']);
            $pdo->prepare('DELETE FROM qr_codes WHERE id = ?')->execute([$cid]);
            flash(t('adm.c_deleted'), 'info');
        }
    }
    // Back to the same filtered page.
    $back = array_filter(['q' => (string) ($_POST['q'] ?? ''), 'show' => (string) ($_POST['show'] ?? ''), 'page' => (string) ($_POST['page'] ?? '')], 'strlen');
    redirect('admin/codes.php' . ($back ? '?' . http_build_query($back) : ''));
}

$search = trim((string) ($_GET['q'] ?? ''));
$show   = ($_GET['show'] ?? '') === 'disabled' ? 'disabled' : '';
$page   = max(1, (int) ($_GET['page'] ?? 1));

$where = '1=1';
$params = [];
if ($show === 'disabled') {
    $where .= ' AND c.admin_disabled = 1';
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= ' AND (c.title LIKE ? OR c.code LIKE ? OR c.target_url LIKE ? OR u.email LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM qr_codes c JOIN users u ON u.id = c.user_id WHERE $where");
$cnt->execute($params);
$matched = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($matched / ADMIN_CODES_PER_PAGE));
$page = min($page, $pages);

$list = $pdo->prepare(
    "SELECT c.id, c.code, c.title, c.target_url, c.is_active, c.admin_disabled, c.scan_count, c.expires_at, c.scan_limit,
            c.created_at, u.name AS owner_name, u.email AS owner_email, u.is_blocked AS owner_blocked
     FROM qr_codes c JOIN users u ON u.id = c.user_id
     WHERE $where ORDER BY c.created_at DESC, c.id DESC
     LIMIT " . ADMIN_CODES_PER_PAGE . ' OFFSET ' . (($page - 1) * ADMIN_CODES_PER_PAGE)
);
$list->execute($params);
$codes = $list->fetchAll();

$pageUrl = function (int $p) use ($search, $show): string {
    return url('admin/codes.php?' . http_build_query(array_filter(['q' => $search, 'show' => $show, 'page' => $p > 1 ? (string) $p : ''], 'strlen')));
};

$title = t('adm.codes');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('adm.codes')) ?></h1>
    <a href="<?= e(url('admin/index.php')) ?>"><?= e(t('adm.back')) ?></a>
</div>

<form method="get" class="actions" style="margin-bottom:16px">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('adm.c_search')) ?>" style="flex:1;min-width:220px;max-width:420px">
    <label style="font-weight:400;display:flex;align-items:center;gap:6px;margin:0">
        <input type="checkbox" name="show" value="disabled" style="width:auto" <?= $show === 'disabled' ? 'checked' : '' ?>>
        <?= e(t('adm.c_only_disabled')) ?>
    </label>
    <button class="btn btn-ghost"><?= e(t('adm.c_filter')) ?></button>
</form>

<div class="card">
    <?php if (!$codes): ?>
        <p class="muted center" style="padding:16px 0"><?= e(t('adm.c_none')) ?></p>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th><?= e(t('adm.c_code')) ?></th><th><?= e(t('adm.c_dest')) ?></th><th><?= e(t('adm.c_owner')) ?></th>
            <th><?= e(t('view.total')) ?></th><th><?= e(t('adm.th_status')) ?></th><th><?= e(t('adm.th_actions')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($codes as $c): [$label, $class] = code_status($c); ?>
            <tr>
                <td><a href="<?= e(url('q/' . $c['code'])) ?>" target="_blank" rel="noopener"><strong><?= e($c['code']) ?></strong></a>
                    <div class="muted" style="font-size:.8rem"><?= e($c['title']) ?></div></td>
                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($c['target_url']) ?>"><?= e($c['target_url']) ?></td>
                <td><?= e($c['owner_name']) ?><div class="muted" style="font-size:.8rem"><?= e($c['owner_email']) ?></div></td>
                <td><?= (int) $c['scan_count'] ?></td>
                <td>
                    <span class="badge <?= e($class) ?>"><?= e($label) ?></span>
                    <?php if ($c['owner_blocked']): ?><div class="muted" style="font-size:.75rem"><?= e(t('adm.c_owner_blocked')) ?></div><?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="q" value="<?= e($search) ?>">
                        <input type="hidden" name="show" value="<?= e($show) ?>">
                        <input type="hidden" name="page" value="<?= (int) $page ?>">
                        <?php if ($c['admin_disabled']): ?>
                            <button class="btn btn-ghost" name="action" value="enable" style="padding:5px 10px;font-size:.85rem"><?= e(t('adm.c_enable')) ?></button>
                        <?php else: ?>
                            <button class="btn btn-ghost" name="action" value="disable" style="padding:5px 10px;font-size:.85rem"><?= e(t('adm.c_disable')) ?></button>
                        <?php endif; ?>
                        <button class="btn btn-danger" name="action" value="delete" style="padding:5px 10px;font-size:.85rem"
                                onclick='return confirm(<?= e(json_encode(t('view.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);'><?= e(t('common.delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
        <div class="actions" style="justify-content:center;margin-top:14px">
            <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($pageUrl($page - 1)) ?>">←</a><?php endif; ?>
            <span class="muted"><?= (int) $page ?> / <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($pageUrl($page + 1)) ?>">→</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
