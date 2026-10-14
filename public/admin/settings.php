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

    <button type="submit" class="btn btn-primary">Save settings</button>
</form>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
