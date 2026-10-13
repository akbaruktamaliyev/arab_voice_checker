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

    if (!$errors) {
        $code = generate_code($pdo, (int) config('code_length', 6));
        $stmt = $pdo->prepare(
            'INSERT INTO qr_codes (user_id, code, title, target_url)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([current_user_id(), $code, $title_in, $target]);
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
        <form method="post" class="stack">
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
            <button type="submit" class="btn btn-primary btn-block">Create QR code</button>
        </form>
        <p class="center"><a href="<?= e(url('dashboard.php')) ?>">← Back to dashboard</a></p>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
