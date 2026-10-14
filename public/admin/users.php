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
        flash('You cannot change your own account here.', 'error');
        redirect('admin/users.php');
    }

    switch ($action) {
        case 'block':
            $pdo->prepare('UPDATE users SET is_blocked = 1 WHERE id = ?')->execute([$target]);
            flash('User suspended.', 'success');
            break;
        case 'unblock':
            $pdo->prepare('UPDATE users SET is_blocked = 0 WHERE id = ?')->execute([$target]);
            flash('User restored.', 'success');
            break;
        case 'make_admin':
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$target]);
            flash('User promoted to admin.', 'success');
            break;
        case 'remove_admin':
            $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$target]);
            flash('Admin rights removed.', 'success');
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
            flash('User deleted.', 'info');
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

$title = 'Users';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Users</h1>
    <a href="<?= e(url('admin/index.php')) ?>">← Admin</a>
</div>

<div class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>User</th><th>Codes</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $id = (int) $u['id']; ?>
            <tr>
                <td><?= e($u['name']) ?><div class="muted" style="font-size:.8rem"><?= e($u['email']) ?></div></td>
                <td><?= (int) $u['codes'] ?></td>
                <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-on' : '' ?>"><?= e($u['role']) ?></span></td>
                <td><span class="badge <?= $u['is_blocked'] ? 'badge-off' : 'badge-on' ?>"><?= $u['is_blocked'] ? 'Suspended' : 'Active' ?></span></td>
                <td>
                    <?php if ($id === $me): ?>
                        <span class="muted" style="font-size:.85rem">You</span>
                    <?php else: ?>
                        <?= $u['is_blocked'] ? act($id, 'unblock', 'Restore', 'btn-ghost') : act($id, 'block', 'Suspend', 'btn-ghost') ?>
                        <?= $u['role'] === 'admin' ? act($id, 'remove_admin', 'Demote', 'btn-ghost') : act($id, 'make_admin', 'Make admin', 'btn-ghost') ?>
                        <?= act($id, 'delete', 'Delete', 'btn-danger') ?>
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
