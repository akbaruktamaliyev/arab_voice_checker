<?php
/**
 * QRaft — create a dynamic QR code.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

// Enforce the plan's dynamic-code limit.
$plan = user_plan($pdo, (int) current_user_id());
$planCodes = $plan['max_codes'] === null ? null : (int) $plan['max_codes'];
$codeCount = (int) $pdo->query('SELECT COUNT(*) FROM qr_codes WHERE user_id = ' . (int) current_user_id())->fetchColumn();
if (!within_limit($planCodes, $codeCount)) {
    $title = t('limit.page_title');
    ob_start(); ?>
    <section class="section" style="max-width:480px;margin-inline:auto">
        <div class="card center stack">
            <h1 style="margin:0"><?= e(t('limit.title')) ?></h1>
            <p class="muted"><?= t('limit.text', e(td($plan['name'])), (int) $planCodes) ?></p>
            <a class="btn btn-primary" href="<?= e(url('pricing.php')) ?>"><?= e(t('limit.see_plans')) ?></a>
            <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('common.back')) ?></a>
        </div>
    </section>
    <?php $content = ob_get_clean(); require __DIR__ . '/../src/layout.php'; exit;
}

$errors = [];
$title_in = $target_in = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title_in  = trim((string) ($_POST['title'] ?? ''));
    $target_in = trim((string) ($_POST['target_url'] ?? ''));

    if ($title_in === '' || mb_strlen($title_in) > 120) {
        $errors[] = t('create.err_title');
    }
    $target = clean_url($target_in);
    if ($target === null) {
        $errors[] = t('create.err_url');
    }
    $fg = clean_color((string) ($_POST['fg_color'] ?? ''), '#000000');
    $bg = clean_color((string) ($_POST['bg_color'] ?? ''), '#ffffff');

    // Optional custom code (slug). Empty → a random code is generated.
    $slug_in = trim((string) ($_POST['slug'] ?? ''));
    if ($slug_in !== '') {
        if (!valid_slug($slug_in)) {
            $errors[] = t('err.slug');
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
            $dup->execute([$slug_in]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = t('err.slug_taken');
            }
        }
    }

    // Optional folder (must belong to the user).
    $folderId = null;
    if (($_POST['folder_id'] ?? '') !== '' && ctype_digit((string) $_POST['folder_id'])) {
        $chk = $pdo->prepare('SELECT 1 FROM folders WHERE id = ? AND user_id = ?');
        $chk->execute([(int) $_POST['folder_id'], current_user_id()]);
        if ($chk->fetchColumn() !== false) {
            $folderId = (int) $_POST['folder_id'];
        }
    }

    // Optional protection.
    $pw        = (string) ($_POST['password'] ?? '');
    $pwHash    = $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null;
    $expires   = parse_expiry((string) ($_POST['expires_at'] ?? ''));
    $scanLimit = parse_scan_limit((string) ($_POST['scan_limit'] ?? ''));

    $logoPath = null;
    if (!$errors) {
        try {
            $logoPath = store_logo($_FILES['logo'] ?? []);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $code = $slug_in !== '' ? $slug_in : generate_code($pdo, (int) config('code_length', 6));
        $stmt = $pdo->prepare(
            'INSERT INTO qr_codes (user_id, folder_id, code, title, target_url, fg_color, bg_color, logo_path, password_hash, expires_at, scan_limit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([current_user_id(), $folderId, $code, $title_in, $target, $fg, $bg, $logoPath, $pwHash, $expires, $scanLimit]);
        flash(t('create.created'), 'success');
        redirect('view.php?id=' . (int) $pdo->lastInsertId());
    }
}

$myFolders = $pdo->prepare('SELECT id, name FROM folders WHERE user_id = ? ORDER BY name');
$myFolders->execute([current_user_id()]);
$myFolders = $myFolders->fetchAll();

$title = t('create.page_title');
ob_start();
?>
<section class="section" style="max-width:520px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0"><?= e(t('create.title')) ?></h1>
        <p class="muted"><?= e(t('create.intro')) ?></p>
        <?php foreach ($errors as $err): ?>
            <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="stack" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label for="title"><?= e(t('form.title')) ?></label>
                <input type="text" id="title" name="title"
                       value="<?= e($title_in) ?>" placeholder="<?= e(t('form.title_ph')) ?>" required>
            </div>
            <div class="field">
                <label for="target_url"><?= e(t('form.dest')) ?></label>
                <input type="text" id="target_url" name="target_url"
                       value="<?= e($target_in) ?>" placeholder="https://example.com/menu" required>
            </div>
            <?php if ($myFolders): ?>
            <div class="field">
                <label for="folder_id"><?= e(t('form.folder')) ?> <span class="muted"><?= e(t('form.optional')) ?></span></label>
                <select id="folder_id" name="folder_id">
                    <option value=""><?= e(t('form.no_folder')) ?></option>
                    <?php foreach ($myFolders as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= ($_POST['folder_id'] ?? '') == $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="field">
                <label for="slug"><?= e(t('form.slug')) ?> <span class="muted"><?= e(t('form.optional')) ?></span></label>
                <input type="text" id="slug" name="slug"
                       value="<?= e($_POST['slug'] ?? '') ?>" placeholder="<?= e(t('form.slug_ph')) ?>"
                       pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" maxlength="32">
                <p class="muted" style="font-size:.8rem;margin:6px 0 0">
                    <?= t('form.slug_hint', '<code>' . e(url('q/')) . 'your-code</code>') ?>
                </p>
            </div>
            <div class="row">
                <div class="field">
                    <label for="fg_color"><?= e(t('form.qr_colour')) ?></label>
                    <input type="color" id="fg_color" name="fg_color"
                           value="<?= e($_POST['fg_color'] ?? '#000000') ?>">
                </div>
                <div class="field">
                    <label for="bg_color"><?= e(t('form.background')) ?></label>
                    <input type="color" id="bg_color" name="bg_color"
                           value="<?= e($_POST['bg_color'] ?? '#ffffff') ?>">
                </div>
            </div>
            <div class="field">
                <label for="logo"><?= e(t('form.logo')) ?> <span class="muted"><?= e(t('form.logo_opt')) ?></span></label>
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            </div>

            <details>
                <summary style="margin:4px 0"><?= e(t('form.protection')) ?> <span class="muted"><?= e(t('form.optional')) ?></span></summary>
                <div class="stack" style="margin-top:12px">
                    <div class="field">
                        <label for="password"><?= e(t('form.password')) ?></label>
                        <input type="text" id="password" name="password" placeholder="<?= e(t('form.password_ph')) ?>">
                    </div>
                    <div class="row">
                        <div class="field">
                            <label for="expires_at"><?= e(t('form.expires')) ?></label>
                            <input type="datetime-local" id="expires_at" name="expires_at" value="<?= e($_POST['expires_at'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="scan_limit"><?= e(t('form.scan_limit')) ?></label>
                            <input type="number" id="scan_limit" name="scan_limit" min="1" placeholder="<?= e(t('form.unlimited_ph')) ?>" value="<?= e($_POST['scan_limit'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </details>

            <button type="submit" class="btn btn-primary btn-block"><?= e(t('create.submit')) ?></button>
        </form>
        <p class="center"><a href="<?= e(url('dashboard.php')) ?>"><?= e(t('create.back')) ?></a></p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
