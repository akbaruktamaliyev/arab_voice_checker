<?php
/**
 * QRaft — bio pages: list and create the user's Linktree-style pages.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            $errors[] = 'Enter a page title.';
        }
        if (!valid_slug($slug)) {
            $errors[] = 'Handle: 3–32 letters, digits, hyphens or underscores.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM bio_pages WHERE slug = ?');
            $dup->execute([$slug]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = 'That handle is already taken.';
            }
        }
        if (!$errors) {
            $pdo->prepare('INSERT INTO bio_pages (user_id, slug, title) VALUES (?, ?, ?)')
                ->execute([$uid, $slug, $title]);
            flash('Bio page created.', 'success');
            redirect('bio-edit.php?id=' . (int) $pdo->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM bio_pages WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $uid]);
        flash('Bio page deleted.', 'info');
        redirect('bios.php');
    }
}

$pages = $pdo->prepare(
    'SELECT p.*, (SELECT COUNT(*) FROM bio_links l WHERE l.bio_id = p.id) AS links
     FROM bio_pages p WHERE p.user_id = ? ORDER BY p.created_at DESC'
);
$pages->execute([$uid]);
$pages = $pages->fetchAll();

$title = 'Bio pages';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Bio pages</h1>
    <a href="<?= e(url('dashboard.php')) ?>">← Dashboard</a>
</div>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">New bio page</h2>
        <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" placeholder="My links" required>
            </div>
            <div class="field">
                <label for="slug">Handle</label>
                <input type="text" id="slug" name="slug" placeholder="my-handle"
                       pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" required>
                <p class="muted" style="font-size:.8rem;margin:6px 0 0">Public at <code><?= e(url('b/')) ?>your-handle</code></p>
            </div>
            <button class="btn btn-primary">Create</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:1.1rem">Your pages</h2>
        <?php if (!$pages): ?>
            <p class="muted center" style="padding:16px 0">No bio pages yet.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Page</th><th>Links</th><th>Views</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pages as $p): ?>
                    <tr>
                        <td><a href="<?= e(url('bio-edit.php?id=' . (int) $p['id'])) ?>"><?= e($p['title']) ?></a>
                            <div class="muted" style="font-size:.8rem"><?= e(url('b/' . $p['slug'])) ?></div></td>
                        <td><?= (int) $p['links'] ?></td>
                        <td><?= (int) $p['views'] ?></td>
                        <td>
                            <a class="btn btn-ghost" style="padding:4px 9px;font-size:.8rem" href="<?= e(url('b/' . $p['slug'])) ?>" target="_blank">Open</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this bio page?');">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-danger" style="padding:4px 9px;font-size:.8rem">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
