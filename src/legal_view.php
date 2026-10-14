<?php
/**
 * QRaft — shared view for the Terms and Privacy pages.
 * Expects $title, $sections = [[heading, text], …].
 */

declare(strict_types=1);

ob_start();
?>
<section class="section" style="max-width:760px;margin-inline:auto">
    <h1 style="margin-bottom:4px"><?= e($title) ?></h1>
    <p class="muted"><?= e(t('legal.updated', LEGAL_UPDATED)) ?></p>
    <?php foreach ($sections as $i => [$heading, $text]): ?>
        <h2 style="font-size:1.1rem;margin:26px 0 6px"><?= ($i + 1) ?>. <?= e($heading) ?></h2>
        <p style="margin:0"><?= e($text) ?></p>
    <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
