<?php
/**
 * QRaft — shared page layout.
 *
 * Usage:
 *   $title = 'Page title';
 *   ob_start();
 *   // ... page HTML ...
 *   $content = ob_get_clean();
 *   require __DIR__ . '/../src/layout.php';
 */

declare(strict_types=1);

$appName  = app_name();
$uid      = current_user_id();
$viewer   = $uid !== null ? current_user($pdo) : null;
$isAdmin  = $viewer !== null && $viewer['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="<?= e(lang_code()) ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? $appName) . ' · ' . $appName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <script>
        // Apply the saved theme before paint to avoid a flash.
        try {
            var t = localStorage.getItem('qraft-theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    </script>
</head>
<body>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">▦</span> <?= e($appName) ?>
        </a>
        <nav class="nav">
            <a href="<?= e(url('index.php')) ?>"><?= e(t('nav.generator')) ?></a>
            <a href="<?= e(url('pricing.php')) ?>">Pricing</a>
            <?php if ($uid !== null): ?>
                <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('nav.dashboard')) ?></a>
                <?php if ($isAdmin): ?>
                    <a href="<?= e(url('admin/index.php')) ?>"><?= e(t('nav.admin')) ?></a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e(url('logout.php')) ?>"><?= e(t('nav.signout')) ?></a>
            <?php else: ?>
                <a href="<?= e(url('login.php')) ?>"><?= e(t('nav.signin')) ?></a>
                <a class="btn btn-primary" href="<?= e(url('register.php')) ?>"><?= e(t('nav.getstarted')) ?></a>
            <?php endif; ?>
            <button type="button" id="theme-toggle" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">◐</button>
        </nav>
    </div>
</header>

<main class="wrap">
    <?php foreach (take_flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?? '' ?>
</main>

<footer class="site-footer">
    <div class="wrap" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <p style="margin:0">&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        <nav style="display:flex;gap:10px;font-size:.85rem">
            <?php foreach (($GLOBALS['available_langs'] ?? ['en']) as $lc): ?>
                <a href="?setlang=<?= e($lc) ?>" style="<?= lang_code() === $lc ? 'font-weight:700' : '' ?>"><?= e(strtoupper($lc)) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</footer>

<script>
    // Dark-mode toggle, remembered per browser.
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', cur);
            try { localStorage.setItem('qraft-theme', cur); } catch (e) {}
        });
    })();
</script>
</body>
</html>
