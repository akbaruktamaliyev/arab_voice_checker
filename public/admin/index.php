<?php
/**
 * QRaft — admin dashboard (overview + quick links).
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin($pdo);

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'codes' => (int) $pdo->query('SELECT COUNT(*) FROM qr_codes')->fetchColumn(),
    'scans' => (int) $pdo->query('SELECT COUNT(*) FROM qr_scans')->fetchColumn(),
];
$recent = $pdo->query(
    'SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 8'
)->fetchAll();

$title = 'Admin';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Admin</h1>
    <div style="display:flex;gap:10px">
        <a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Users</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/plans.php')) ?>">Plans</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/settings.php')) ?>">Settings</a>
    </div>
</div>

<?php if (is_dir(__DIR__ . '/../install')): ?>
    <div class="flash flash-warn"><strong>Security:</strong> delete the <code>public/install/</code> folder — the installer is no longer needed and should not stay on a live site.</div>
<?php endif; ?>

<div class="stats section">
    <div class="stat"><div class="num"><?= $stats['users'] ?></div><div class="lbl">Users</div></div>
    <div class="stat"><div class="num"><?= $stats['codes'] ?></div><div class="lbl">Dynamic codes</div></div>
    <div class="stat"><div class="num"><?= $stats['scans'] ?></div><div class="lbl">Total scans</div></div>
</div>

<div class="card">
    <div class="section-head"><h2 style="margin:0">Newest users</h2>
        <a href="<?= e(url('admin/users.php')) ?>">All users →</a></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-on' : '' ?>"><?= e($u['role']) ?></span></td>
                <td class="muted"><?= e(date('Y-m-d', strtotime((string) $u['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
