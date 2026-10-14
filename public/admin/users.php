<?php
/**
 * QRaft — admin user management: block, unblock, promote, delete.
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin($pdo);

$me = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $target = (int) ($_POST['user_id'] ?? 0);

    // Never let an admin lock themselves out by acting on their own account.
    if ($target === $me) {
        flash(t('adm.err_self'), 'error');
        redirect('admin/users.php');
    }

    switch ($action) {
        case 'block':
            $pdo->prepare('UPDATE users SET is_blocked = 1 WHERE id = ?')->execute([$target]);
            flash(t('adm.u_suspended'), 'success');
            break;
        case 'unblock':
            $pdo->prepare('UPDATE users SET is_blocked = 0 WHERE id = ?')->execute([$target]);
            flash(t('adm.u_restored'), 'success');
            break;
        case 'make_admin':
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$target]);
            flash(t('adm.u_promoted'), 'success');
            break;
        case 'remove_admin':
            $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$target]);
            flash(t('adm.u_demoted'), 'success');
            break;
        case 'delete':
            // Remove the user's uploaded logo files first — the FK cascade only
            // deletes database rows, which would leave the files orphaned on disk.
            $logos = $pdo->prepare('SELECT logo_path FROM qr_codes WHERE user_id = ? AND logo_path IS NOT NULL');
            $logos->execute([$target]);
            foreach ($logos->fetchAll(PDO::FETCH_COLUMN) as $path) {
                delete_logo($path);
            }
            // FK cascades remove the user's codes and scans.
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$target]);
            flash(t('adm.u_deleted'), 'info');
            break;
    }
    redirect('admin/users.php');
}

$users = $pdo->query(
    'SELECT u.id, u.name, u.email, u.role, u.is_blocked, u.created_at,
            (SELECT COUNT(*) FROM qr_codes c WHERE c.user_id = u.id) AS codes
     FROM users u ORDER BY u.created_at DESC'
)->fetchAll();

/** One inline action form. */
function act(int $id, string $action, string $label, string $class): string
{
    return '<form method="post" style="display:inline">' . csrf_field()
        . '<input type="hidden" name="user_id" value="' . $id . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button class="btn ' . e($class) . '" style="padding:5px 10px;font-size:.85rem">' . e($label) . '</button></form>';
}

$title = t('adm.users');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('adm.users')) ?></h1>
    <a href="<?= e(url('admin/index.php')) ?>"><?= e(t('adm.back')) ?></a>
</div>

<div class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= e(t('adm.th_user')) ?></th><th><?= e(t('adm.th_codes')) ?></th><th><?= e(t('adm.th_role')) ?></th><th><?= e(t('adm.th_status')) ?></th><th><?= e(t('adm.th_actions')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $id = (int) $u['id']; ?>
            <tr>
                <td><?= e($u['name']) ?><div class="muted" style="font-size:.8rem"><?= e($u['email']) ?></div></td>
                <td><?= (int) $u['codes'] ?></td>
                <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-on' : '' ?>"><?= e($u['role'] === 'admin' ? t('adm.role_admin') : t('adm.role_user')) ?></span></td>
                <td><span class="badge <?= $u['is_blocked'] ? 'badge-off' : 'badge-on' ?>"><?= e($u['is_blocked'] ? t('adm.suspended') : t('status.active')) ?></span></td>
                <td>
                    <?php if ($id === $me): ?>
                        <span class="muted" style="font-size:.85rem"><?= e(t('adm.you')) ?></span>
                    <?php else: ?>
                        <?= $u['is_blocked'] ? act($id, 'unblock', t('adm.restore'), 'btn-ghost') : act($id, 'block', t('adm.suspend'), 'btn-ghost') ?>
                        <?= $u['role'] === 'admin' ? act($id, 'remove_admin', t('adm.demote'), 'btn-ghost') : act($id, 'make_admin', t('adm.make_admin'), 'btn-ghost') ?>
                        <?= act($id, 'delete', t('common.delete'), 'btn-danger') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
