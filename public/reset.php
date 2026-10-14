<?php
/**
 * QRaft — set a new password from a reset link.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user_id() !== null) {
    redirect('dashboard.php');
}

$token = (string) ($_REQUEST['token'] ?? '');
$token = preg_replace('/[^a-f0-9]/', '', $token);

// Look up a valid, unexpired token.
$user = null;
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
}

$errors = [];

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        flash('Password updated — please sign in.', 'success');
        redirect('login.php');
    }
}

$title = 'Set a new password';
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('reset.title')) ?></h1>
        <?php if (!$user): ?>
            <div class="flash flash-error"><?= e(t('reset.invalid')) ?></div>
            <a class="btn btn-ghost" href="<?= e(url('forgot.php')) ?>"><?= e(t('reset.request')) ?></a>
        <?php else: ?>
            <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="password"><?= e(t('reset.new')) ?></label>
                    <input type="password" id="password" name="password" minlength="8" required autofocus>
                </div>
                <button class="btn btn-primary btn-block"><?= e(t('reset.update')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
