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
$limited = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));

    if (throttle_blocked($pdo, 'forgot', FORGOT_MAX_PER_IP, 60)) {
        // Too many requests from this visitor: stop before touching the database or mail.
        $limited = true;
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throttle_hit($pdo, 'forgot');
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Cap mails per target address too, so one inbox cannot be flooded from many IPs.
        $mailKey = hash('sha256', strtolower($email));
        if ($user && !throttle_blocked($pdo, 'forgot-mail', FORGOT_MAX_PER_EMAIL, 60, $mailKey)) {
            throttle_hit($pdo, 'forgot-mail', $mailKey);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = (NOW() + INTERVAL 1 HOUR) WHERE id = ?')
                ->execute([hash('sha256', $token), $user['id']]);
            $link = url('reset.php?token=' . $token);
            send_mail($email, t('mail.reset_subject', app_name()),
                '<p>' . e(t('mail.reset_body', app_name())) . '</p>'
                . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
                . '<p>' . e(t('mail.reset_footer')) . '</p>');
        }
    }
    // Always the same response — never reveal whether an email is registered.
    $sent = empty($limited);
}

$title = t('forgot.page_title');
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('forgot.title')) ?></h1>
        <?php if ($sent): ?>
            <div class="flash flash-success"><?= e(t('forgot.sent')) ?></div>
            <?php if (!mail_enabled()): ?>
                <p class="muted" style="font-size:.85rem"><?= e(t('forgot.no_mail')) ?></p>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>"><?= e(t('forgot.back')) ?></a>
        <?php else: ?>
            <?php if ($limited): ?><div class="flash flash-error"><?= e(t('err.rate_limited')) ?></div><?php endif; ?>
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
