<?php
/**
 * QRaft — bulk-create dynamic codes from a CSV.
 *
 * CSV columns: title, target_url, slug (optional). A header row is detected
 * and skipped. Up to 500 rows per import.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();
$MAX = 500;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $file = $_FILES['csv'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash(t('bulk.err_choose'), 'error');
        redirect('bulk.php');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        flash(t('bulk.err_size'), 'error');
        redirect('bulk.php');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        flash(t('bulk.err_read'), 'error');
        redirect('bulk.php');
    }

    // Respect the plan's dynamic-code limit across the whole import.
    $plan = user_plan($pdo, $uid);
    $planCodes = $plan['max_codes'] === null ? null : (int) $plan['max_codes'];
    $have = (int) $pdo->query('SELECT COUNT(*) FROM qr_codes WHERE user_id = ' . $uid)->fetchColumn();

    $created = 0;
    $errors = [];
    $rowNo = 0;
    $seenHeader = false;

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rowNo++;
        if ($rowNo > $MAX) {
            $errors[] = t('bulk.err_stopped', $MAX);
            break;
        }
        // Skip a header row — only when the cells are exactly the column names.
        if (!$seenHeader && isset($row[0], $row[1])
            && strcasecmp(trim((string) $row[0]), 'title') === 0
            && preg_match('/^(target_?url|url)$/i', trim((string) $row[1]))) {
            $seenHeader = true;
            continue;
        }
        $seenHeader = true;

        if (!within_limit($planCodes, $have)) {
            $errors[] = t('bulk.err_plan', td($plan['name']), (int) $planCodes);
            break;
        }

        $title = trim((string) ($row[0] ?? ''));
        $target = clean_url((string) ($row[1] ?? ''));
        $slug = trim((string) ($row[2] ?? ''));

        if ($title === '' && $target === null) {
            continue; // blank line
        }
        if ($title === '' || mb_strlen($title) > 120) {
            $errors[] = t('bulk.err_row_title', $rowNo);
            continue;
        }
        if ($target === null) {
            $errors[] = t('bulk.err_row_url', $rowNo);
            continue;
        }
        if ($slug !== '') {
            if (!valid_slug($slug)) {
                $errors[] = t('bulk.err_row_slug', $rowNo, $slug);
                continue;
            }
            $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
            $dup->execute([$slug]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = t('bulk.err_row_taken', $rowNo, $slug);
                continue;
            }
            $code = $slug;
        } else {
            $code = generate_code($pdo, (int) config('code_length', 6));
        }

        $pdo->prepare('INSERT INTO qr_codes (user_id, code, title, target_url) VALUES (?, ?, ?, ?)')
            ->execute([$uid, $code, $title, $target]);
        $created++;
        $have++;
    }
    fclose($handle);

    $result = ['created' => $created, 'errors' => $errors];
    flash(t('bulk.created', $created), $created ? 'success' : 'info');
}

$title = t('bulk.title');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('bulk.title')) ?></h1>
    <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('common.back_dashboard')) ?></a>
</div>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(t('bulk.upload')) ?></h2>
        <p class="muted" style="margin:0"><?= t('bulk.help', (int) $MAX) ?></p>
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </div>
            <button class="btn btn-primary"><?= e(t('bulk.import')) ?></button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 10px;font-size:1.1rem"><?= e(t('bulk.example')) ?></h2>
        <code style="display:block;white-space:pre;background:var(--bg);padding:10px 12px;border-radius:8px;font-size:.82rem">title,target_url,slug
Summer menu,https://example.com/menu,summer-menu
Promo,https://example.com/promo,
Contact,https://example.com/contact,</code>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="card">
        <h2 style="margin:0 0 10px;font-size:1.1rem"><?= e(t('bulk.result')) ?></h2>
        <p><?= t('bulk.created_html', (int) $result['created']) ?></p>
        <?php if ($result['errors']): ?>
            <p class="muted" style="margin:10px 0 4px"><?= e(t('bulk.skipped')) ?></p>
            <ul class="muted" style="margin:0;font-size:.9rem">
                <?php foreach (array_slice($result['errors'], 0, 50) as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="btn btn-primary" style="margin-top:12px" href="<?= e(url('dashboard.php')) ?>"><?= e(t('bulk.view_codes')) ?></a>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
