<?php
/**
 * QRaft — create a dynamic QR code.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$errors = [];
$title_in = $target_in = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title_in  = trim((string) ($_POST['title'] ?? ''));
    $target_in = trim((string) ($_POST['target_url'] ?? ''));

    if ($title_in === '' || mb_strlen($title_in) > 120) {
        $errors[] = 'Please enter a title (up to 120 characters).';
    }
    $target = clean_url($target_in);
    if ($target === null) {
        $errors[] = 'Please enter a valid http(s) destination URL.';
    }
    $fg = clean_color((string) ($_POST['fg_color'] ?? ''), '#000000');
    $bg = clean_color((string) ($_POST['bg_color'] ?? ''), '#ffffff');

    // Optional custom code (slug). Empty → a random code is generated.
    $slug_in = trim((string) ($_POST['slug'] ?? ''));
    if ($slug_in !== '') {
        if (!valid_slug($slug_in)) {
            $errors[] = 'Custom code: 3–32 letters, digits, hyphens or underscores, starting and ending with a letter or digit.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
            $dup->execute([$slug_in]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = 'That custom code is already taken — try another.';
            }
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
            'INSERT INTO qr_codes (user_id, code, title, target_url, fg_color, bg_color, logo_path, password_hash, expires_at, scan_limit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([current_user_id(), $code, $title_in, $target, $fg, $bg, $logoPath, $pwHash, $expires, $scanLimit]);
        flash('Dynamic QR code created.', 'success');
        redirect('view.php?id=' . (int) $pdo->lastInsertId());
    }
}

$title = 'New dynamic QR';
ob_start();
?>
<section class="section" style="max-width:520px;margin-inline:auto">
    <div class="card stack">
        <h1 style="margin:0">New dynamic QR code</h1>
        <p class="muted">The QR image stays the same forever — you can change where
           it points and watch the scan count grow.</p>
        <?php foreach ($errors as $err): ?>
            <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" class="stack" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title"
                       value="<?= e($title_in) ?>" placeholder="e.g. Summer menu" required>
            </div>
            <div class="field">
                <label for="target_url">Destination URL</label>
                <input type="text" id="target_url" name="target_url"
                       value="<?= e($target_in) ?>" placeholder="https://example.com/menu" required>
            </div>
            <div class="field">
                <label for="slug">Custom code <span class="muted">(optional)</span></label>
                <input type="text" id="slug" name="slug"
                       value="<?= e($_POST['slug'] ?? '') ?>" placeholder="e.g. summer-menu"
                       pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" maxlength="32">
                <p class="muted" style="font-size:.8rem;margin:6px 0 0">
                    Your link becomes <code><?= e(url('q/')) ?>your-code</code>. Leave blank for a random code.
                </p>
            </div>
            <div class="row">
                <div class="field">
                    <label for="fg_color">QR colour</label>
                    <input type="color" id="fg_color" name="fg_color"
                           value="<?= e($_POST['fg_color'] ?? '#000000') ?>">
                </div>
                <div class="field">
                    <label for="bg_color">Background</label>
                    <input type="color" id="bg_color" name="bg_color"
                           value="<?= e($_POST['bg_color'] ?? '#ffffff') ?>">
                </div>
            </div>
            <div class="field">
                <label for="logo">Centre logo <span class="muted">(optional, PNG/JPG/WebP, max 1 MB)</span></label>
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            </div>

            <details>
                <summary style="cursor:pointer;font-weight:600;margin:4px 0">Protection <span class="muted">(optional)</span></summary>
                <div class="stack" style="margin-top:12px">
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="text" id="password" name="password" placeholder="Visitors must enter this to continue">
                    </div>
                    <div class="row">
                        <div class="field">
                            <label for="expires_at">Expires at</label>
                            <input type="datetime-local" id="expires_at" name="expires_at" value="<?= e($_POST['expires_at'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="scan_limit">Scan limit</label>
                            <input type="number" id="scan_limit" name="scan_limit" min="1" placeholder="Unlimited" value="<?= e($_POST['scan_limit'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </details>

            <button type="submit" class="btn btn-primary btn-block">Create QR code</button>
        </form>
        <p class="center"><a href="<?= e(url('dashboard.php')) ?>">← Back to dashboard</a></p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
