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

$appName = (string) config('app_name', 'QRaft');
$uid     = current_user_id();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? $appName) . ' · ' . $appName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">▦</span> <?= e($appName) ?>
        </a>
        <nav class="nav">
            <a href="<?= e(url('index.php')) ?>">Generator</a>
            <?php if ($uid !== null): ?>
                <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
                <a class="btn btn-ghost" href="<?= e(url('logout.php')) ?>">Sign out</a>
            <?php else: ?>
                <a href="<?= e(url('login.php')) ?>">Sign in</a>
                <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Get started</a>
            <?php endif; ?>
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
    <div class="wrap">
        <p>&copy; <?= date('Y') ?> <?= e($appName) ?>. Built with QRaft.</p>
    </div>
</footer>
</body>
</html>
