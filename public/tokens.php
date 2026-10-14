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
        flash('Token created — copy it now, it is shown only once.', 'success');
    } elseif ($action === 'revoke') {
        $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $uid]);
        flash('Token revoked.', 'info');
    }
    redirect('tokens.php');
}

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);

$tokens = $pdo->prepare('SELECT id, name, last_used, created_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$uid]);
$tokens = $tokens->fetchAll();

$title = 'API tokens';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">API tokens</h1>
    <a href="<?= e(url('dashboard.php')) ?>">← Dashboard</a>
</div>

<?php if ($newToken): ?>
    <div class="card" style="border-color:var(--primary);margin-bottom:16px">
        <strong>Your new token</strong>
        <p class="muted" style="margin:6px 0">Copy it now — it will not be shown again.</p>
        <code style="display:block;word-break:break-all;background:var(--bg);padding:10px 12px;border-radius:8px"><?= e($newToken) ?></code>
    </div>
<?php endif; ?>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Create a token</h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="field">
                <label for="name">Label</label>
                <input type="text" id="name" name="name" placeholder="e.g. My integration" maxlength="80">
            </div>
            <button class="btn btn-primary">Create token</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:1.1rem">How to use</h2>
        <p class="muted" style="margin:0 0 8px">Send the token as a bearer header:</p>
        <code style="display:block;word-break:break-all;background:var(--bg);padding:10px 12px;border-radius:8px;font-size:.82rem">curl <?= e(url('api/codes.php')) ?> \
 -H "Authorization: Bearer YOUR_TOKEN"</code>
    </div>
</div>

<div class="card">
    <h2 style="margin:0 0 12px;font-size:1.1rem">Your tokens</h2>
    <?php if (!$tokens): ?>
        <p class="muted center" style="padding:16px 0">No tokens yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Label</th><th>Last used</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td class="muted"><?= $t['last_used'] ? e($t['last_used']) : 'Never' ?></td>
                    <td class="muted"><?= e(date('Y-m-d', strtotime((string) $t['created_at']))) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Revoke this token?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn btn-danger" style="padding:5px 10px;font-size:.85rem">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
