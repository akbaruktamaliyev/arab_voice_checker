<?php
/**
 * QRaft — public bio page (Linktree-style), served at /b/{slug}.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$slug = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['s'] ?? ''));
if ($slug === '') {
    http_response_code(404);
    exit('Not found');
}

$stmt = $pdo->prepare('SELECT * FROM bio_pages WHERE slug = ?');
$stmt->execute([$slug]);
$bio = $stmt->fetch();
if (!$bio) {
    http_response_code(404);
    exit('This page does not exist.');
}

$pdo->prepare('UPDATE bio_pages SET views = views + 1 WHERE id = ?')->execute([$bio['id']]);

$links = $pdo->prepare('SELECT label, url FROM bio_links WHERE bio_id = ? ORDER BY sort, id');
$links->execute([$bio['id']]);
$links = $links->fetchAll();

$themes = [
    'light'  => ['bg' => '#f4f6fb', 'card' => '#ffffff', 'text' => '#1b2333', 'btn' => '#ffffff', 'btnText' => '#1b2333', 'border' => '#e3e8f0'],
    'dark'   => ['bg' => '#0f1420', 'card' => '#171d2b', 'text' => '#e7ecf5', 'btn' => '#232a46', 'btnText' => '#e7ecf5', 'border' => '#2a3346'],
    'indigo' => ['bg' => '#4f46e5', 'card' => 'transparent', 'text' => '#ffffff', 'btn' => 'rgba(255,255,255,.15)', 'btnText' => '#ffffff', 'border' => 'rgba(255,255,255,.3)'],
    'sunset' => ['bg' => 'linear-gradient(160deg,#ff7e5f,#feb47b)', 'card' => 'transparent', 'text' => '#2a1a12', 'btn' => 'rgba(255,255,255,.75)', 'btnText' => '#2a1a12', 'border' => 'rgba(255,255,255,.6)'],
];
$t = $themes[$bio['theme']] ?? $themes['light'];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($bio['title']) ?></title>
    <style>
        body{margin:0;min-height:100vh;background:<?= $t['bg'] ?>;color:<?= $t['text'] ?>;
            font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
            display:flex;justify-content:center;padding:48px 20px;box-sizing:border-box}
        .wrap{width:100%;max-width:480px;text-align:center}
        .title{font-size:1.6rem;font-weight:700;margin:0 0 6px}
        .headline{opacity:.85;margin:0 0 28px}
        a.link{display:block;background:<?= $t['btn'] ?>;color:<?= $t['btnText'] ?>;
            border:1px solid <?= $t['border'] ?>;border-radius:12px;padding:14px 18px;margin:12px 0;
            text-decoration:none;font-weight:600;transition:transform .08s}
        a.link:hover{transform:translateY(-2px)}
        .foot{opacity:.6;font-size:.8rem;margin-top:36px}
        .foot a{color:inherit}
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="title"><?= e($bio['title']) ?></h1>
    <?php if ($bio['headline']): ?><p class="headline"><?= e($bio['headline']) ?></p><?php endif; ?>
    <?php foreach ($links as $l): ?>
        <a class="link" href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e($l['label']) ?></a>
    <?php endforeach; ?>
    <?php if (!$links): ?><p class="headline">No links yet.</p><?php endif; ?>
    <p class="foot">Made with <a href="<?= e(url('index.php')) ?>"><?= e(app_name()) ?></a></p>
</div>
</body>
</html>
