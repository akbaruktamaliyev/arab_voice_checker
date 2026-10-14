<?php
/**
 * QRaft — API token management. Users create and revoke personal API tokens.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? '')) ?: 'API token';
        $plain = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash) VALUES (?, ?, ?)')
            ->execute([$uid, mb_substr($name, 0, 80), hash('sha256', $plain)]);
        // Shown once, right after creation.
        $_SESSION['new_token'] = $plain;
        flash(t('tok.created'), 'success');
    } elseif ($action === 'revoke') {
        $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $uid]);
        flash(t('tok.revoked'), 'info');
    }
    redirect('tokens.php');
}

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);

$tokens = $pdo->prepare('SELECT id, name, last_used, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$uid]);
$tokens = $tokens->fetchAll();

$title = t('tok.title');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('tok.title')) ?></h1>
    <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('common.back_dashboard')) ?></a>
</div>

<?php if ($newToken): ?>
    <div class="card" style="border-color:var(--primary);margin-bottom:16px">
        <strong><?= e(t('tok.new')) ?></strong>
        <p class="muted" style="margin:6px 0"><?= e(t('tok.copy_now')) ?></p>
        <code style="display:block;word-break:break-all;background:var(--bg);padding:10px 12px;border-radius:8px"><?= e($newToken) ?></code>
    </div>
<?php endif; ?>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(t('tok.create')) ?></h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="field">
                <label for="name"><?= e(t('tok.label')) ?></label>
                <input type="text" id="name" name="name" placeholder="<?= e(t('tok.label_ph')) ?>" maxlength="80">
            </div>
            <button class="btn btn-primary"><?= e(t('tok.create_btn')) ?></button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:1.1rem"><?= e(t('tok.how')) ?></h2>
        <p class="muted" style="margin:0 0 8px"><?= e(t('tok.how_text')) ?></p>
        <code style="display:block;word-break:break-all;background:var(--bg);padding:10px 12px;border-radius:8px;font-size:.82rem">curl <?= e(url('api/codes.php')) ?> \
 -H "Authorization: Bearer YOUR_TOKEN"</code>
    </div>
</div>

<div class="card">
    <h2 style="margin:0 0 12px;font-size:1.1rem"><?= e(t('tok.yours')) ?></h2>
    <?php if (!$tokens): ?>
        <p class="muted center" style="padding:16px 0"><?= e(t('tok.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= e(t('tok.th_label')) ?></th><th><?= e(t('tok.th_last')) ?></th><th><?= e(t('tok.th_created')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td class="muted"><?= $t['last_used'] ? e($t['last_used']) : e(t('tok.never')) ?></td>
                    <td class="muted"><?= e(date('Y-m-d', strtotime((string) $t['created_at']))) ?></td>
                    <td>
                        <form method="post" onsubmit='return confirm(<?= e(json_encode(t('tok.confirm_revoke'), JSON_UNESCAPED_UNICODE)) ?>);'>
                            <?= csrf_field() ?><input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-danger" style="padding:5px 10px;font-size:.85rem"><?= e(t('tok.revoke')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
