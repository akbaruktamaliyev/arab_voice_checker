<?php
/**
 * QRaft — request a password reset link.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user_id() !== null) {
    redirect('dashboard.php');
}

$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = (NOW() + INTERVAL 1 HOUR) WHERE id = ?')
                ->execute([$token, $user['id']]);
            $link = url('reset.php?token=' . $token);
            send_mail($email, app_name() . ' password reset',
                '<p>Reset your ' . e(app_name()) . ' password:</p>'
                . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
                . '<p>This link expires in 1 hour. If you did not request it, ignore this email.</p>');
        }
    }
    // Always the same response — never reveal whether an email is registered.
    $sent = true;
}

$title = 'Reset password';
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('forgot.title')) ?></h1>
        <?php if ($sent): ?>
            <div class="flash flash-success"><?= e(t('forgot.sent')) ?></div>
            <?php if (!mail_enabled()): ?>
                <p class="muted" style="font-size:.85rem">Note: email is not configured yet, so no message was actually sent. An admin can set SMTP in the admin panel.</p>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>"><?= e(t('forgot.back')) ?></a>
        <?php else: ?>
            <p class="muted"><?= e(t('forgot.intro')) ?></p>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email"><?= e(t('auth.email')) ?></label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
                </div>
                <button class="btn btn-primary btn-block"><?= e(t('forgot.send')) ?></button>
            </form>
            <p class="muted center"><a href="<?= e(url('login.php')) ?>"><?= e(t('forgot.back')) ?></a></p>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
