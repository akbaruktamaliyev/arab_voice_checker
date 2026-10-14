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
        flash('Please choose a CSV file.', 'error');
        redirect('bulk.php');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        flash('The CSV must be 2 MB or smaller.', 'error');
        redirect('bulk.php');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        flash('Could not read the uploaded file.', 'error');
        redirect('bulk.php');
    }

    $created = 0;
    $errors = [];
    $rowNo = 0;
    $seenHeader = false;

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rowNo++;
        if ($rowNo > $MAX) {
            $errors[] = "Stopped at row $MAX (import limit).";
            break;
        }
        // Skip a header row ("title,target_url,...").
        if (!$seenHeader && isset($row[0]) && preg_match('/title/i', (string) $row[0])
            && isset($row[1]) && preg_match('/url|target/i', (string) $row[1])) {
            $seenHeader = true;
            continue;
        }
        $seenHeader = true;

        $title = trim((string) ($row[0] ?? ''));
        $target = clean_url((string) ($row[1] ?? ''));
        $slug = trim((string) ($row[2] ?? ''));

        if ($title === '' && $target === null) {
            continue; // blank line
        }
        if ($title === '' || mb_strlen($title) > 120) {
            $errors[] = "Row $rowNo: missing/invalid title.";
            continue;
        }
        if ($target === null) {
            $errors[] = "Row $rowNo: invalid URL.";
            continue;
        }
        if ($slug !== '') {
            if (!valid_slug($slug)) {
                $errors[] = "Row $rowNo: invalid custom code '$slug'.";
                continue;
            }
            $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
            $dup->execute([$slug]);
            if ($dup->fetchColumn() !== false) {
                $errors[] = "Row $rowNo: code '$slug' already taken.";
                continue;
            }
            $code = $slug;
        } else {
            $code = generate_code($pdo, (int) config('code_length', 6));
        }

        $pdo->prepare('INSERT INTO qr_codes (user_id, code, title, target_url) VALUES (?, ?, ?, ?)')
            ->execute([$uid, $code, $title, $target]);
        $created++;
    }
    fclose($handle);

    $result = ['created' => $created, 'errors' => $errors];
    flash("$created code(s) created.", $created ? 'success' : 'info');
}

$title = 'Bulk import';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Bulk import</h1>
    <a href="<?= e(url('dashboard.php')) ?>">← Dashboard</a>
</div>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem">Upload a CSV</h2>
        <p class="muted" style="margin:0">Columns: <code>title, target_url, slug</code> (slug optional).
           A header row is detected automatically. Up to <?= $MAX ?> rows.</p>
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </div>
            <button class="btn btn-primary">Import</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin:0 0 10px;font-size:1.1rem">Example</h2>
        <code style="display:block;white-space:pre;background:var(--bg);padding:10px 12px;border-radius:8px;font-size:.82rem">title,target_url,slug
Summer menu,https://example.com/menu,summer-menu
Promo,https://example.com/promo,
Contact,https://example.com/contact,</code>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="card">
        <h2 style="margin:0 0 10px;font-size:1.1rem">Result</h2>
        <p><strong><?= (int) $result['created'] ?></strong> code(s) created.</p>
        <?php if ($result['errors']): ?>
            <p class="muted" style="margin:10px 0 4px">Skipped rows:</p>
            <ul class="muted" style="margin:0;font-size:.9rem">
                <?php foreach (array_slice($result['errors'], 0, 50) as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="btn btn-primary" style="margin-top:12px" href="<?= e(url('dashboard.php')) ?>">View codes</a>
    </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
