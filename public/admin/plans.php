<?php
/**
 * QRaft — admin plan management (create, edit, delete subscription plans).
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int) ($_POST['id'] ?? 0);
        $name      = trim((string) ($_POST['name'] ?? '')) ?: 'Plan';
        $price     = max(0, (int) ($_POST['price'] ?? 0));            // minor units
        $period    = in_array($_POST['period'] ?? 'month', ['month', 'year', 'lifetime'], true) ? $_POST['period'] : 'month';
        $maxCodes  = ($_POST['max_codes'] ?? '') === '' ? null : max(0, (int) $_POST['max_codes']);
        $maxBios   = ($_POST['max_bios'] ?? '') === '' ? null : max(0, (int) $_POST['max_bios']);
        $features  = trim((string) ($_POST['features'] ?? ''));
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $sort      = (int) ($_POST['sort'] ?? 0);

        if ($id > 0) {
            $pdo->prepare('UPDATE plans SET name=?, price=?, period=?, max_codes=?, max_bios=?, features=?, is_active=?, sort=? WHERE id=?')
                ->execute([$name, $price, $period, $maxCodes, $maxBios, $features, $isActive, $sort, $id]);
        } else {
            $pdo->prepare('INSERT INTO plans (name, price, period, max_codes, max_bios, features, is_active, sort) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $price, $period, $maxCodes, $maxBios, $features, $isActive, $sort]);
        }
        flash('Plan saved.', 'success');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        flash('Plan deleted.', 'info');
    }
    redirect('admin/plans.php');
}

$plans = $pdo->query('SELECT * FROM plans ORDER BY sort, price')->fetchAll();

/** Render the editable row form for a plan (or a blank new one). */
function plan_form(array $p = []): string
{
    $v = fn(string $k, $d = '') => e((string) ($p[$k] ?? $d));
    ob_start(); ?>
    <form method="post" class="card stack" style="margin-bottom:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
        <div class="row">
            <div class="field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
            <div class="field" style="max-width:140px"><label>Price (minor units)</label><input type="number" name="price" min="0" value="<?= $v('price', '0') ?>"></div>
            <div class="field" style="max-width:130px"><label>Period</label>
                <select name="period">
                    <?php foreach (['month', 'year', 'lifetime'] as $pr): ?>
                        <option value="<?= $pr ?>" <?= ($p['period'] ?? 'month') === $pr ? 'selected' : '' ?>><?= $pr ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <div class="row">
            <div class="field"><label>Max codes <span class="muted">(blank = ∞)</span></label><input type="number" name="max_codes" min="0" value="<?= $v('max_codes') ?>"></div>
            <div class="field"><label>Max bios <span class="muted">(blank = ∞)</span></label><input type="number" name="max_bios" min="0" value="<?= $v('max_bios') ?>"></div>
            <div class="field" style="max-width:100px"><label>Sort</label><input type="number" name="sort" value="<?= $v('sort', '0') ?>"></div>
        </div>
        <div class="field"><label>Features <span class="muted">(comma-separated)</span></label>
            <input type="text" name="features" value="<?= $v('features') ?>"></div>
        <label style="font-weight:400"><input type="checkbox" name="is_active" style="width:auto" <?= ($p['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label>
        <div class="row" style="align-items:center">
            <button class="btn btn-primary" style="flex:0">Save</button>
            <?php if (!empty($p['id'])): ?>
                <span style="flex:0">
                    <button class="btn btn-danger" formnovalidate name="action" value="delete"
                            onclick="return confirm('Delete this plan?');">Delete</button>
                </span>
            <?php endif; ?>
        </div>
    </form>
    <?php return (string) ob_get_clean();
}

$title = 'Plans';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Plans</h1>
    <a href="<?= e(url('admin/index.php')) ?>">← Admin</a>
</div>

<div class="grid-2">
    <div>
        <h2 style="font-size:1.1rem">Existing</h2>
        <?php foreach ($plans as $p) echo plan_form($p); ?>
        <?php if (!$plans): ?><p class="muted">No plans yet.</p><?php endif; ?>
    </div>
    <div>
        <h2 style="font-size:1.1rem">Add a plan</h2>
        <?= plan_form() ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../../src/layout.php';
