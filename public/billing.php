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

$title = t('bill.title');
ob_start();
?>
<div class="section-head">
    <h1 style="margin:0"><?= e(t('bill.heading')) ?></h1>
    <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('common.back_dashboard')) ?></a>
</div>

<?php if (!empty($_GET['paid'])): ?>
    <div class="flash flash-success"><?= e(t('bill.paid')) ?></div>
<?php endif; ?>

<div class="grid-2 section">
    <div class="card stack">
        <h2 style="margin:0;font-size:1.1rem"><?= e(td($plan['name'])) ?></h2>
        <?php $isAdminPlan = (int) $plan['id'] === 0; ?>
        <?php if ($isAdminPlan): ?>
            <p class="muted" style="margin:0"><?= e(t('bill.admin')) ?></p>
        <?php elseif ($expires): ?>
            <p class="muted" style="margin:0"><?= e(t('bill.active_until', date('Y-m-d', strtotime((string) $expires)))) ?></p>
        <?php endif; ?>
        <div>
            <div style="display:flex;justify-content:space-between;font-size:.9rem">
                <span><?= e(t('bill.codes')) ?></span>
                <span class="muted"><?= $codes ?> / <?= $plan['max_codes'] === null ? '∞' : (int) $plan['max_codes'] ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.9rem;margin-top:6px">
                <span><?= e(t('bill.bios')) ?></span>
                <span class="muted"><?= $bios ?> / <?= $plan['max_bios'] === null ? '∞' : (int) $plan['max_bios'] ?></span>
            </div>
        </div>
        <?php if (!$isAdminPlan): ?>
            <a class="btn btn-primary" href="<?= e(url('pricing.php')) ?>"><?= e(t('bill.change')) ?></a>
        <?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
