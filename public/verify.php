<?php
/**
 * QRaft — confirm an email address from a verification link.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$token = preg_replace('/[^a-f0-9]/', '', $token);

$ok = false;
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE verify_token = ?');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $pdo->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')
            ->execute([$user['id']]);
        $ok = true;
    }
}

$title = 'Email verification';
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack center">
        <?php if ($ok): ?>
            <h1 style="margin:0">Email verified</h1>
            <p class="muted">Your email is confirmed. You can sign in now.</p>
            <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Sign in</a>
        <?php else: ?>
            <h1 style="margin:0">Invalid link</h1>
            <p class="muted">This verification link is invalid or already used.</p>
            <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>">Back to sign in</a>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
