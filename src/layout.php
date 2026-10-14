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
        <nav class="nav" id="site-nav" aria-label="Main">
            <a href="<?= e(url('index.php')) ?>"><?= e(t('nav.generator')) ?></a>
            <a href="<?= e(url('pricing.php')) ?>"><?= e(t('nav.pricing')) ?></a>
            <?php if ($uid !== null): ?>
                <a href="<?= e(url('dashboard.php')) ?>"><?= e(t('nav.dashboard')) ?></a>
                <a href="<?= e(url('account.php')) ?>"><?= e(t('nav.account')) ?></a>
                <?php if ($isAdmin): ?>
                    <a href="<?= e(url('admin/index.php')) ?>"><?= e(t('nav.admin')) ?></a>
                <?php endif; ?>
                <form method="post" action="<?= e(url('logout.php')) ?>" class="nav-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><?= e(t('nav.signout')) ?></button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('login.php')) ?>"><?= e(t('nav.signin')) ?></a>
                <a class="btn btn-primary btn-sm" href="<?= e(url('register.php')) ?>"><?= e(t('nav.getstarted')) ?></a>
            <?php endif; ?>
        </nav>
        <button type="button" id="theme-toggle" class="icon-btn" title="Toggle dark mode" aria-label="Toggle dark mode">◐</button>
        <button type="button" id="nav-toggle" class="icon-btn nav-toggle" aria-label="Menu" aria-controls="site-nav" aria-expanded="false">☰</button>
    </div>
</header>

<main class="wrap">
    <?php foreach (take_flashes() as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?? '' ?>
</main>

<footer class="site-footer">
    <div class="wrap footer-inner">
        <p>&copy; <?= date('Y') ?> <?= e($appName) ?></p>
        <nav class="lang-switch" aria-label="Language">
            <?php
                // Preserve the current query string when switching language.
                $qs = $_GET; unset($qs['setlang']);
                foreach (($GLOBALS['available_langs'] ?? ['en']) as $lc):
                    $href = '?' . http_build_query($qs + ['setlang' => $lc]);
            ?>
                <a href="<?= e($href) ?>" class="<?= lang_code() === $lc ? 'active' : '' ?>"><?= e(strtoupper($lc)) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</footer>

<script>
    (function () {
        // Dark-mode toggle, remembered per browser. When no theme was chosen
        // yet, start from the OS preference so the very first click flips it.
        var root = document.documentElement;
        var tbtn = document.getElementById('theme-toggle');
        if (tbtn) {
            tbtn.addEventListener('click', function () {
                var cur = root.getAttribute('data-theme');
                if (!cur) {
                    cur = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                var next = cur === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem('qraft-theme', next); } catch (e) {}
            });
        }
        // Mobile menu.
        var nbtn = document.getElementById('nav-toggle');
        var nav = document.getElementById('site-nav');
        if (nbtn && nav) {
            nbtn.addEventListener('click', function () {
                var open = nav.classList.toggle('open');
                nbtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
    })();
</script>
</body>
</html>
