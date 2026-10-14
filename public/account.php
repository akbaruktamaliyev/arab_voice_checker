<?php
/**
 * QRaft — account settings: profile, password and account deletion.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid  = (int) current_user_id();
$user = current_user($pdo);

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$uid]);
$hash = (string) $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            flash('Enter your name (up to 80 characters).', 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email address.', 'error');
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $dup->execute([$email, $uid]);
            if ($dup->fetchColumn() !== false) {
                flash('That email is already used by another account.', 'error');
            } else {
                $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $uid]);
                flash('Profile updated.', 'success');
            }
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $hash)) {
            flash('Your current password is incorrect.', 'error');
        } elseif (strlen($new) < 8) {
            flash('The new password must be at least 8 characters.', 'error');
        } elseif ($new !== $confirm) {
            flash('The new passwords do not match.', 'error');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            // Rotate the session id after a credential change.
            session_regenerate_id(true);
            flash('Password changed.', 'success');
        }
    } elseif ($action === 'delete') {
        if (!password_verify((string) ($_POST['confirm_delete_password'] ?? ''), $hash)) {
            flash('Password incorrect — account not deleted.', 'error');
        } else {
            // Never remove the last administrator: the site would be unmanageable.
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($user['role'] === 'admin' && $admins <= 1) {
                flash('You are the only administrator — promote someone else before deleting this account.', 'error');
            } else {
                // Delete uploaded logos first; the FK cascade only removes DB rows.
                $logos = $pdo->prepare('SELECT logo_path FROM qr_codes WHERE user_id = ? AND logo_path IS NOT NULL');
                $logos->execute([$uid]);
                foreach ($logos->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    delete_logo($path);
                }
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                logout_user();
                flash('Your account has been deleted.', 'info');
                redirect('index.php');
            }
        }
    }
    redirect('account.php');
}

$title = 'Account';
ob_start();
?>
<div class="section-head">
    <h1>Account</h1>
    <a href="<?= e(url('dashboard.php')) ?>">← Dashboard</a>
</div>

<div class="grid-2 section" style="margin-top:0">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Profile</h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="field"><label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" maxlength="80" required></div>
            <div class="field"><label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required></div>
            <button class="btn btn-primary">Save profile</button>
        </form>
    </div>

    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Change password</h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="field"><label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
            <div class="field"><label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required></div>
            <div class="field"><label for="confirm_password">Repeat new password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
            <button class="btn btn-primary">Change password</button>
        </form>
    </div>
</div>

<div class="card stack" style="border-color:var(--danger)">
    <h2 style="margin:0;font-size:1.1rem;color:var(--danger)">Delete account</h2>
    <p class="muted" style="margin:0">Permanently deletes your account, all QR codes, scan history, folders and bio pages. This cannot be undone.</p>
    <form method="post" class="actions" onsubmit="return confirm('Delete your account permanently?');">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input type="password" name="confirm_delete_password" placeholder="Confirm with your password" autocomplete="current-password" required style="max-width:300px">
        <button class="btn btn-danger">Delete my account</button>
    </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
