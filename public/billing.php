<?php
/**
 * QRaft — the user's current plan and usage.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = (int) current_user_id();
$plan = user_plan($pdo, $uid);
$codes = (int) $pdo->query('SELECT COUNT(*) FROM qr_codes WHERE user_id = ' . $uid)->fetchColumn();
$bios  = (int) $pdo->query('SELECT COUNT(*) FROM bio_pages WHERE user_id = ' . $uid)->fetchColumn();

$row = $pdo->prepare('SELECT plan_expires FROM users WHERE id = ?');
$row->execute([$uid]);
$expires = $row->fetchColumn();

$title = 'Billing';
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0">Your plan</h1>
    <a href="<?= e(url('dashboard.php')) ?>">← Dashboard</a>
</div>

<?php if (!empty($_GET['paid'])): ?>
    <div class="flash flash-success">Payment received — your plan is active.</div>
<?php endif; ?>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e($plan['name']) ?></h2>
        <?php if ($expires): ?>
            <p class="muted" style="margin:0">Active until <?= e(date('Y-m-d', strtotime((string) $expires))) ?></p>
        <?php endif; ?>
        <div>
            <div style="display:flex;justify-content:space-between;font-size:.9rem">
                <span>Dynamic codes</span>
                <span class="muted"><?= $codes ?> / <?= $plan['max_codes'] === null ? '∞' : (int) $plan['max_codes'] ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.9rem;margin-top:6px">
                <span>Bio pages</span>
                <span class="muted"><?= $bios ?> / <?= $plan['max_bios'] === null ? '∞' : (int) $plan['max_bios'] ?></span>
            </div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('pricing.php')) ?>">Change plan</a>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
