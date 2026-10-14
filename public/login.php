<?php
/**
 * QRaft — sign in.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user_id() !== null) {
    redirect('dashboard.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, password_hash, is_blocked, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        if ((int) $user['is_blocked'] === 1) {
            $error = 'Your account has been suspended.';
        } elseif (setting('require_email_verification', '0') === '1' && (int) $user['is_verified'] === 0) {
            $error = 'Please confirm your email before signing in.';
        } else {
            login_user((int) $user['id']);
            redirect('dashboard.php');
        }
    } else {
        // Same message whether the email or the password was wrong.
        $error = 'Invalid email or password.';
    }
}

$title = 'Sign in';
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0">Welcome back</h1>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
        <p class="muted center" style="margin-bottom:0">
            <a href="<?= e(url('forgot.php')) ?>">Forgot password?</a>
        </p>
        <p class="muted center">No account yet?
            <a href="<?= e(url('register.php')) ?>">Create one</a>
        </p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
