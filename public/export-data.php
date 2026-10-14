<?php
/**
 * QRaft — "download my data": a JSON copy of the signed-in user's own data
 * (profile, plan, folders, codes, bio pages with links, API token names).
 * POST-only with a CSRF token. Password hashes and token hashes are never included.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account.php');
}
csrf_check();

$uid = (int) current_user_id();
$rows = function (string $sql) use ($pdo, $uid): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
};

$user = $rows('SELECT id, name, email, role, plan_id, plan_expires, is_verified, created_at FROM users WHERE id = ?')[0] ?? [];
$plan = user_plan($pdo, $uid);

$bios = $rows('SELECT id, slug, title, headline, theme, views, created_at FROM bio_pages WHERE user_id = ?');
foreach ($bios as &$b) {
    $l = $pdo->prepare('SELECT label, url, sort FROM bio_links WHERE bio_id = ? ORDER BY sort, id');
    $l->execute([$b['id']]);
    $b['links'] = $l->fetchAll();
}
unset($b);

$data = [
    'exported_at' => date('c'),
    'site'        => app_name(),
    'account'     => $user + ['current_plan' => $plan['name'] ?? null],
    'folders'     => $rows('SELECT id, name, created_at FROM folders WHERE user_id = ?'),
    'codes'       => $rows(
        'SELECT id, code, title, target_url, fg_color, bg_color, (password_hash IS NOT NULL) AS password_protected,
                expires_at, scan_limit, is_active, admin_disabled, scan_count, created_at
         FROM qr_codes WHERE user_id = ?'
    ),
    'bio_pages'   => $bios,
    'api_tokens'  => $rows('SELECT name, last_used, created_at FROM api_tokens WHERE user_id = ?'),
];

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', app_name()) . '-my-data-' . date('Ymd') . '.json"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
