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
        // Enforce the plan's bio-page limit.
        $plan = user_plan($pdo, $uid);
        $planBios = $plan['max_bios'] === null ? null : (int) $plan['max_bios'];
        $bioCount = (int) $pdo->query('SELECT COUNT(*) FROM bio_pages WHERE user_id = ' . (int) $uid)->fetchColumn();
        if (!within_limit($planBios, $bioCount)) {
            $errors[] = t('limit.bio_text', td($plan['name']), (int) $planBios);
        }
        if ($title === '' || mb_strlen($title) > 120) {
            $errors[] = t('bio.err_title');
        }
        if (!valid_slug($slug)) {
            $errors[] = t('bio.err_handle');
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM bio_pages WHERE slug = ?');
            $dup->execute([$slug]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = t('bio.err_taken');
            }
        }
        if (!$errors) {
            $pdo->prepare('INSERT INTO bio_pages (user_id, slug, title) VALUES (?, ?, ?)')
                ->execute([$uid, $slug, $title]);
            flash(t('bio.created'), 'success');
            redirect('bio-edit.php?id=' . (int) $pdo->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM bio_pages WHERE id = ? AND user_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $uid]);
        flash(t('bio.deleted'), 'info');
        redirect('bios.php');
    }
}

$pages = $pdo->prepare(
    'SELECT p.*, (SELECT COUNT(*) FROM bio_links l WHERE l.bio_id = p.id) AS links
     FROM bio_pages p WHERE p.user_id = ? ORDER BY p.created_at DESC'
);
$pages->execute([$uid]);
$pages = $pages->fetchAll();

$title = t('bio.title');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('bio.title')) ?></h1>
    <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('common.back_dashboard')) ?></a>
</div>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(t('bio.new')) ?></h2>
        <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="field">
                <label for="title"><?= e(t('bio.f_title')) ?></label>
                <input type="text" id="title" name="title" placeholder="<?= e(t('bio.title_ph')) ?>" required>
            </div>
            <div class="field">
                <label for="slug"><?= e(t('bio.handle')) ?></label>
                <input type="text" id="slug" name="slug" placeholder="<?= e(t('bio.handle_ph')) ?>"
                       pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" required>
                <p class="muted" style="font-size:.8rem;margin:6px 0 0"><?= t('bio.public_at', '<code>' . e(url('b/')) . 'your-handle</code>') ?></p>
            </div>
            <button class="btn btn-primary"><?= e(t('bio.create')) ?></button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:1.1rem"><?= e(t('bio.your_pages')) ?></h2>
        <?php if (!$pages): ?>
            <p class="muted center" style="padding:16px 0"><?= e(t('bio.none')) ?></p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th><?= e(t('bio.th_page')) ?></th><th><?= e(t('bio.th_links')) ?></th><th><?= e(t('bio.th_views')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pages as $p): ?>
                    <tr>
                        <td><a href="<?= e(url('bio-edit.php?id=' . (int) $p['id'])) ?>"><?= e($p['title']) ?></a>
                            <div class="muted" style="font-size:.8rem"><?= e(url('b/' . $p['slug'])) ?></div></td>
                        <td><?= (int) $p['links'] ?></td>
                        <td><?= (int) $p['views'] ?></td>
                        <td>
                            <a class="btn btn-ghost" style="padding:4px 9px;font-size:.8rem" href="<?= e(url('b/' . $p['slug'])) ?>" target="_blank"><?= e(t('bio.open')) ?></a>
                            <form method="post" style="display:inline" onsubmit='return confirm(<?= e(json_encode(t('bio.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);'>
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-danger" style="padding:4px 9px;font-size:.8rem"><?= e(t('common.delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
