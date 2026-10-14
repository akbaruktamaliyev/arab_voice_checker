<?php
/**
 * QRaft — public pricing page.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort, price')->fetchAll();
$uid = current_user_id();
$current = $uid !== null ? user_plan($pdo, (int) $uid) : null;

$title = 'Pricing';
ob_start();
?>
<section class="hero">
    <h1>Simple pricing</h1>
    <p>Start free. Upgrade when you need more codes and features.</p>
</section>

<section class="section">
    <div class="grid-3">
        <?php foreach ($plans as $p): $isCurrent = $current && (int) $current['id'] === (int) $p['id']; ?>
            <div class="card plan-card <?= $p['sort'] == 1 ? 'featured' : '' ?>">
                <h2 style="margin:0"><?= e($p['name']) ?></h2>
                <div>
                    <span class="price"><?= e(format_price((int) $p['price'])) ?></span>
                    <?php if ($p['price'] > 0): ?><span class="muted">/ <?= e($p['period']) ?></span><?php endif; ?>
                </div>
                <ul class="muted">
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) $p['features']))) as $f): ?>
                        <li><?= e($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($isCurrent): ?>
                    <button class="btn btn-ghost btn-block" disabled>Current plan</button>
                <?php elseif ((int) $p['price'] === 0): ?>
                    <span class="muted center" style="font-size:.85rem">Default plan</span>
                <?php elseif ($uid === null): ?>
                    <a class="btn btn-primary btn-block" href="<?= e(url('register.php')) ?>">Get started</a>
                <?php else: ?>
                    <a class="btn btn-primary btn-block" href="<?= e(url('checkout.php?plan=' . (int) $p['id'])) ?>">Choose <?= e($p['name']) ?></a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
