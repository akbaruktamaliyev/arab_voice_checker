<?php
/**
 * QRaft — admin settings: site name, registration, email verification, SMTP.
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin($pdo);

$fields = [
    'app_name', 'allow_registration', 'require_email_verification',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_secure',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    set_setting($pdo, 'app_name', trim((string) ($_POST['app_name'] ?? 'QRaft')) ?: 'QRaft');
    $tzIn = (string) ($_POST['timezone'] ?? 'UTC');
    set_setting($pdo, 'timezone', in_array($tzIn, DateTimeZone::listIdentifiers(), true) ? $tzIn : 'UTC');
    set_setting($pdo, 'allow_registration', isset($_POST['allow_registration']) ? '1' : '0');
    set_setting($pdo, 'require_email_verification', isset($_POST['require_email_verification']) ? '1' : '0');
    set_setting($pdo, 'smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
    set_setting($pdo, 'smtp_port', (string) ((int) ($_POST['smtp_port'] ?? 587) ?: 587));
    set_setting($pdo, 'smtp_user', trim((string) ($_POST['smtp_user'] ?? '')));
    // Keep the stored password if the field was left blank.
    $pass = (string) ($_POST['smtp_pass'] ?? '');
    if ($pass !== '') {
        set_setting($pdo, 'smtp_pass', $pass);
    }
    set_setting($pdo, 'smtp_from', trim((string) ($_POST['smtp_from'] ?? '')));
    $secure = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls';
    set_setting($pdo, 'smtp_secure', $secure);

    // Payments (Stripe) + currency + default language.
    set_setting($pdo, 'stripe_public', trim((string) ($_POST['stripe_public'] ?? '')));
    if (!empty($_POST['stripe_clear'])) {
        // Disconnect Stripe: remove both stored secrets.
        set_setting($pdo, 'stripe_secret', '');
        set_setting($pdo, 'stripe_webhook_secret', '');
    } else {
        // Secrets are write-only: a blank field keeps the stored value.
        foreach (['stripe_secret', 'stripe_webhook_secret'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($v !== '') {
                set_setting($pdo, $k, $v);
            }
        }
    }
    set_setting($pdo, 'currency', preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['currency'] ?? 'usd'))) ?: 'usd');
    $dl = in_array($_POST['default_lang'] ?? 'en', $GLOBALS['available_langs'] ?? ['en'], true) ? $_POST['default_lang'] : 'en';
    set_setting($pdo, 'default_lang', $dl);

    flash('Settings saved.', 'success');
    redirect('admin/settings.php');
}

$title = 'Settings';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Settings</h1>
    <a href="<?= e(url('admin/index.php')) ?>">← Admin</a>
</div>

<form method="post" class="stack" style="max-width:620px">
    <?= csrf_field() ?>

    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">General</h2>
        <div class="field">
            <label for="app_name">Site name</label>
            <input type="text" id="app_name" name="app_name" value="<?= e(setting('app_name', 'QRaft')) ?>" required>
        </div>
        <div class="field">
            <label for="timezone">Time zone</label>
            <select id="timezone" name="timezone">
                <?php foreach (DateTimeZone::listIdentifiers() as $z): ?>
                    <option value="<?= e($z) ?>" <?= date_default_timezone_get() === $z ? 'selected' : '' ?>><?= e($z) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted" style="font-size:.8rem;margin:6px 0 0">Used for expiry dates, scan times and charts. Now: <?= e(date('Y-m-d H:i')) ?></p>
        </div>
        <label style="font-weight:400"><input type="checkbox" name="allow_registration" style="width:auto"
            <?= setting('allow_registration', '1') === '1' ? 'checked' : '' ?>> Allow new user registration</label>
        <label style="font-weight:400"><input type="checkbox" name="require_email_verification" style="width:auto"
            <?= setting('require_email_verification', '0') === '1' ? 'checked' : '' ?>> Require email verification to sign in</label>
    </div>

    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Email (SMTP)</h2>
        <p class="muted" style="margin:0">Used for password resets and verification. Leave blank to disable email.</p>
        <div class="row">
            <div class="field"><label for="smtp_host">SMTP host</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?= e(setting('smtp_host', '')) ?>" placeholder="smtp.example.com"></div>
            <div class="field" style="max-width:120px"><label for="smtp_port">Port</label>
                <input type="text" id="smtp_port" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>"></div>
        </div>
        <div class="row">
            <div class="field"><label for="smtp_user">Username</label>
                <input type="text" id="smtp_user" name="smtp_user" value="<?= e(setting('smtp_user', '')) ?>"></div>
            <div class="field"><label for="smtp_pass">Password</label>
                <input type="password" id="smtp_pass" name="smtp_pass" placeholder="<?= setting('smtp_pass') ? '•••••• (unchanged)' : '' ?>"></div>
        </div>
        <div class="row">
            <div class="field"><label for="smtp_from">From address</label>
                <input type="email" id="smtp_from" name="smtp_from" value="<?= e(setting('smtp_from', '')) ?>" placeholder="no-reply@example.com"></div>
            <div class="field" style="max-width:140px"><label for="smtp_secure">Encryption</label>
                <select id="smtp_secure" name="smtp_secure">
                    <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= setting('smtp_secure', 'tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
    </div>

    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Payments (Stripe)</h2>
        <p class="muted" style="margin:0">Leave blank to disable paid plans. Webhook URL:
           <code><?= e(url('api/stripe-webhook.php')) ?></code> (event <code>checkout.session.completed</code>).</p>
        <div class="field"><label for="stripe_public">Publishable key</label>
            <input type="text" id="stripe_public" name="stripe_public" value="<?= e(setting('stripe_public', '')) ?>" placeholder="pk_live_... / pk_test_..."></div>
        <div class="field"><label for="stripe_secret">Secret key</label>
            <input type="password" id="stripe_secret" name="stripe_secret" autocomplete="off" placeholder="<?= setting('stripe_secret') ? '•••••• saved — type to replace' : 'sk_live_... / sk_test_...' ?>"></div>
        <div class="field"><label for="stripe_webhook_secret">Webhook signing secret</label>
            <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" autocomplete="off" placeholder="<?= setting('stripe_webhook_secret') ? '•••••• saved — type to replace' : 'whsec_...' ?>"></div>
        <?php if (setting('stripe_secret') || setting('stripe_webhook_secret')): ?>
            <label class="check"><input type="checkbox" name="stripe_clear" value="1"> Disconnect Stripe (remove saved secret keys)</label>
        <?php endif; ?>
        <div class="row">
            <div class="field" style="max-width:140px"><label for="currency">Currency</label>
                <input type="text" id="currency" name="currency" value="<?= e(setting('currency', 'usd')) ?>" maxlength="3"></div>
            <div class="field" style="max-width:160px"><label for="default_lang">Default language</label>
                <select id="default_lang" name="default_lang">
                    <?php foreach (($GLOBALS['available_langs'] ?? ['en']) as $lc): ?>
                        <option value="<?= e($lc) ?>" <?= setting('default_lang', 'en') === $lc ? 'selected' : '' ?>><?= e(strtoupper($lc)) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <p class="muted" style="margin:0"><a href="<?= e(url('admin/plans.php')) ?>">Manage plans →</a></p>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
