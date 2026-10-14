<?php
/**
 * QRaft — bio page editor: page settings and its links.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
$id  = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bio_pages WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $uid]);
$bio = $stmt->fetch();
if (!$bio) {
    http_response_code(404);
    flash(t('bio.not_found'), 'error');
    redirect('bios.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_page') {
        $newTitle = trim((string) ($_POST['title'] ?? ''));
        $headline = trim((string) ($_POST['headline'] ?? ''));
        $theme    = in_array($_POST['theme'] ?? 'light', ['light', 'dark', 'indigo', 'sunset'], true) ? $_POST['theme'] : 'light';
        $newSlug  = trim((string) ($_POST['slug'] ?? ''));

        $err = null;
        if ($newTitle === '' || mb_strlen($newTitle) > 120) {
            $err = t('bio.err_title');
        } elseif ($newSlug !== $bio['slug']) {
            if (!valid_slug($newSlug)) {
                $err = t('bio.err_handle_short');
            } else {
                $dup = $pdo->prepare('SELECT 1 FROM bio_pages WHERE slug = ? AND id <> ?');
                $dup->execute([$newSlug, $id]);
                if ($dup->fetchColumn() !== false) { $err = t('bio.err_taken'); }
            }
        }
        if ($err) {
            flash($err, 'error');
        } else {
            $pdo->prepare('UPDATE bio_pages SET title = ?, headline = ?, theme = ?, slug = ? WHERE id = ? AND user_id = ?')
                ->execute([$newTitle, $headline ?: null, $theme, $newSlug, $id, $uid]);
            flash(t('bio.saved'), 'success');
        }
    } elseif ($action === 'add_link') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $url   = clean_url((string) ($_POST['url'] ?? ''));
        if ($label !== '' && $url !== null) {
            $max = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM bio_links WHERE bio_id = ?');
            $max->execute([$id]);
            $pdo->prepare('INSERT INTO bio_links (bio_id, label, url, sort) VALUES (?, ?, ?, ?)')
                ->execute([$id, mb_substr($label, 0, 120), $url, (int) $max->fetchColumn()]);
            flash(t('bio.link_added'), 'success');
        } else {
            flash(t('bio.err_link'), 'error');
        }
    } elseif ($action === 'delete_link') {
        $pdo->prepare('DELETE FROM bio_links WHERE id = ? AND bio_id = ?')
            ->execute([(int) ($_POST['link_id'] ?? 0), $id]);
    } elseif ($action === 'move') {
        // Swap sort with the adjacent link in the requested direction.
        $lid = (int) ($_POST['link_id'] ?? 0);
        $dir = $_POST['dir'] === 'up' ? 'up' : 'down';
        $cur = $pdo->prepare('SELECT sort FROM bio_links WHERE id = ? AND bio_id = ?');
        $cur->execute([$lid, $id]);
        $sort = $cur->fetchColumn();
        if ($sort !== false) {
            $op = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nb = $pdo->prepare("SELECT id, sort FROM bio_links WHERE bio_id = ? AND sort $op ? ORDER BY sort $ord LIMIT 1");
            $nb->execute([$id, $sort]);
            if ($row = $nb->fetch()) {
                $pdo->prepare('UPDATE bio_links SET sort = ? WHERE id = ?')->execute([$row['sort'], $lid]);
                $pdo->prepare('UPDATE bio_links SET sort = ? WHERE id = ?')->execute([$sort, $row['id']]);
            }
        }
    }
    redirect('bio-edit.php?id=' . $id);
}

$links = $pdo->prepare('SELECT * FROM bio_links WHERE bio_id = ? ORDER BY sort, id');
$links->execute([$id]);
$links = $links->fetchAll();

$title = t('bio.edit_title');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e($bio['title']) ?></h1>
    <div style="display:flex;gap:10px">
        <a class="btn btn-ghost" href="<?= e(url('b/' . $bio['slug'])) ?>" target="_blank"><?= e(t('bio.open')) ?></a>
        <a href="<?= e(url('bios.php')) ?>"><?= e(t('bio.all_pages')) ?></a>
    </div>
</div>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(t('bio.page')) ?></h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_page">
            <div class="field"><label for="title"><?= e(t('bio.f_title')) ?></label>
                <input type="text" id="title" name="title" value="<?= e($bio['title']) ?>" required></div>
            <div class="field"><label for="headline"><?= e(t('bio.headline')) ?></label>
                <input type="text" id="headline" name="headline" value="<?= e($bio['headline'] ?? '') ?>" placeholder="<?= e(t('bio.headline_ph')) ?>"></div>
            <div class="row">
                <div class="field"><label for="slug"><?= e(t('bio.handle')) ?></label>
                    <input type="text" id="slug" name="slug" value="<?= e($bio['slug']) ?>"
                           pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]" required></div>
                <div class="field"><label for="theme"><?= e(t('bio.theme')) ?></label>
                    <select id="theme" name="theme">
                        <?php foreach (['light' => t('bio.theme_light'), 'dark' => t('bio.theme_dark'), 'indigo' => t('bio.theme_indigo'), 'sunset' => t('bio.theme_sunset')] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= $bio['theme'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <button class="btn btn-primary"><?= e(t('bio.save_page')) ?></button>
        </form>
    </div>

    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(t('bio.add_link')) ?></h2>
        <form method="post" class="stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_link">
            <div class="field"><label for="label"><?= e(t('bio.label')) ?></label>
                <input type="text" id="label" name="label" placeholder="<?= e(t('bio.label_ph')) ?>" required></div>
            <div class="field"><label for="url">URL</label>
                <input type="text" id="url" name="url" placeholder="https://example.com" required></div>
            <button class="btn btn-primary"><?= e(t('bio.add_link_btn')) ?></button>
        </form>
    </div>
</div>

<div class="card">
    <h2 style="margin:0 0 12px;font-size:1.1rem"><?= e(t('bio.links')) ?></h2>
    <?php if (!$links): ?>
        <p class="muted center" style="padding:16px 0"><?= e(t('bio.no_links')) ?></p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <tbody>
            <?php foreach ($links as $l): ?>
                <tr>
                    <td><strong><?= e($l['label']) ?></strong>
                        <div class="muted" style="font-size:.8rem;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($l['url']) ?></div></td>
                    <td style="text-align:right">
                        <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $sym): ?>
                            <form method="post" style="display:inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="move">
                                <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                                <input type="hidden" name="dir" value="<?= $dir ?>">
                                <button class="btn btn-ghost" style="padding:4px 9px"><?= $sym ?></button>
                            </form>
                        <?php endforeach; ?>
                        <form method="post" style="display:inline" onsubmit='return confirm(<?= e(json_encode(t('bio.confirm_delete_link'), JSON_UNESCAPED_UNICODE)) ?>);'>
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete_link">
                            <input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
                            <button class="btn btn-danger" style="padding:4px 9px;font-size:.8rem"><?= e(t('common.delete')) ?></button>
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
