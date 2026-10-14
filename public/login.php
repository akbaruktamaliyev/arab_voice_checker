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
$canResend = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    if (login_locked($pdo)) {
        // Too many recent failures from this visitor: refuse without even
        // checking the credentials, so guessing gets no feedback.
        $error = t('login.locked', LOGIN_WINDOW_MINUTES);
    } else {
        $stmt = $pdo->prepare('SELECT id, password_hash, is_blocked, is_verified FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            if ((int) $user['is_blocked'] === 1) {
                $error = t('auth.suspended');
            } elseif (setting('require_email_verification', '0') === '1' && (int) $user['is_verified'] === 0) {
                $error = t('auth.verify_first');
                $canResend = true;
            } else {
                login_succeeded($pdo);
                login_user((int) $user['id']);
                redirect('dashboard.php');
            }
        } else {
            // Same message whether the email or the password was wrong.
            login_failed($pdo);
            $error = t('auth.invalid');
        }
    }
}

$title = t('auth.signin');
$noindex = true;
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('auth.welcome_back')) ?></h1>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($canResend): ?>
            <form method="post" action="<?= e(url('resend-verification.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="<?= e($email) ?>">
                <button type="submit" class="btn btn-ghost btn-block"><?= e(t('verify.resend')) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email"><?= e(t('auth.email')) ?></label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="field">
                <label for="password"><?= e(t('auth.password')) ?></label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.signin')) ?></button>
        </form>
        <p class="muted center" style="margin-bottom:0">
            <a href="<?= e(url('forgot.php')) ?>"><?= e(t('auth.forgot')) ?></a>
        </p>
        <p class="muted center"><?= e(t('auth.no_account')) ?>
            <a href="<?= e(url('register.php')) ?>"><?= e(t('auth.create_one')) ?></a>
        </p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
