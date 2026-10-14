<?php
/**
 * QRaft — account registration.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user_id() !== null) {
    redirect('dashboard.php');
}

// Registration can be switched off by the administrator.
if (setting('allow_registration', '1') !== '1') {
    $title = 'Registration closed';
    ob_start(); ?>
    <section class="section" style="max-width:440px;margin-inline:auto">
        <div class="card center stack">
            <h1 style="margin:0">Registration is closed</h1>
            <p class="muted">New sign-ups are currently disabled. Please check back later.</p>
            <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>">Back to sign in</a>
        </div>
    </section>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../src/layout.php';
    exit;
}

$errors = [];
$name = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Please enter your name (up to 80 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        try {
            $mustVerify = setting('require_email_verification', '0') === '1' && mail_enabled();
            $verifyToken = $mustVerify ? bin2hex(random_bytes(32)) : null;

            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, is_verified, verify_token) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $mustVerify ? 0 : 1, $verifyToken]);
            $newId = (int) $pdo->lastInsertId();

            if ($mustVerify) {
                $link = url('verify.php?token=' . $verifyToken);
                $okMail = send_mail($email, app_name() . ' — confirm your email',
                    '<p>Confirm your email for ' . e(app_name()) . ':</p>'
                    . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>');
                if ($okMail) {
                    flash('Almost there — check your email to confirm your account.', 'success');
                    redirect('login.php');
                }
                // The verification email could not be sent — don't strand the
                // account: verify it now so the user can sign in.
                $pdo->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')
                    ->execute([$newId]);
            }

            login_user($newId);
            flash('Welcome to ' . app_name() . '! Your account is ready.', 'success');
            redirect('dashboard.php');
        } catch (PDOException $e) {
            // Safety net for the check-then-insert race: the unique index on
            // email is the real guard, so a duplicate surfaces as a friendly
            // message instead of an uncaught 500.
            if ($e->getCode() === '23000') {
                $errors[] = 'An account with that email already exists.';
            } else {
                throw $e;
            }
        }
    }
}

$title = 'Create your account';
ob_start();
?>
<section class="section" style="max-width:440px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('auth.create_account')) ?></h1>
        <?php foreach ($errors as $err): ?>
            <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="name"><?= e(t('auth.name')) ?></label>
                <input type="text" id="name" name="name" value="<?= e($name) ?>" required>
            </div>
            <div class="field">
                <label for="email"><?= e(t('auth.email')) ?></label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="field">
                <label for="password"><?= e(t('auth.password')) ?></label>
                <input type="password" id="password" name="password" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.create_account')) ?></button>
        </form>
        <p class="muted center"><?= e(t('auth.have_account')) ?>
            <a href="<?= e(url('login.php')) ?>"><?= e(t('auth.signin')) ?></a>
        </p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
