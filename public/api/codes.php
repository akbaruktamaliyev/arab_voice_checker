<?php
/**
 * QRaft REST API — dynamic codes.
 *
 *   GET    /api/codes.php          list your codes
 *   GET    /api/codes.php?id=N     one code
 *   POST   /api/codes.php          create { title, target_url, slug?, fg_color?, bg_color? }
 *   PATCH  /api/codes.php?id=N     update { target_url?, title?, is_active? }
 *   DELETE /api/codes.php?id=N     delete
 *
 * Auth: header  Authorization: Bearer <token>
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/api.php';

$uid    = api_user($pdo);   // also picks the response language
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

/** Fetch one of the caller's codes or 404. */
function own_code(PDO $pdo, int $id, int $uid): array
{
    $stmt = $pdo->prepare('SELECT * FROM qr_codes WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('not_found', 404);
    }
    return $row;
}

if ($method === 'GET') {
    if ($id > 0) {
        json_out(['code' => api_code(own_code($pdo, $id, $uid))]);
    }
    $stmt = $pdo->prepare('SELECT * FROM qr_codes WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$uid]);
    json_out(['codes' => array_map('api_code', $stmt->fetchAll())]);
}

if ($method === 'POST') {
    // Enforce the plan's dynamic-code limit (same as the web create flow).
    $plan = user_plan($pdo, $uid);
    $planCodes = $plan['max_codes'] === null ? null : (int) $plan['max_codes'];
    $have = (int) $pdo->query('SELECT COUNT(*) FROM qr_codes WHERE user_id = ' . $uid)->fetchColumn();
    if (!within_limit($planCodes, $have)) {
        api_error('plan_limit', 403);
    }

    $in = api_input();
    $title  = trim((string) ($in['title'] ?? ''));
    $target = clean_url((string) ($in['target_url'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) {
        api_error('title_required', 422);
    }
    if ($target === null) {
        api_error('url_required', 422);
    }
    $slug = trim((string) ($in['slug'] ?? ''));
    if ($slug !== '') {
        if (!valid_slug($slug)) {
            api_error('invalid_slug', 422);
        }
        $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
        $dup->execute([$slug]);
        if ($dup->fetchColumn() !== false) {
            api_error('slug_taken', 409);
        }
    }
    $code = $slug !== '' ? $slug : generate_code($pdo, (int) config('code_length', 6));
    $fg = clean_color((string) ($in['fg_color'] ?? ''), '#000000');
    $bg = clean_color((string) ($in['bg_color'] ?? ''), '#ffffff');

    $stmt = $pdo->prepare(
        'INSERT INTO qr_codes (user_id, code, title, target_url, fg_color, bg_color)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$uid, $code, $title, $target, $fg, $bg]);
    json_out(['code' => api_code(own_code($pdo, (int) $pdo->lastInsertId(), $uid))], 201);
}

if ($method === 'PATCH' || $method === 'PUT') {
    if ($id <= 0) {
        api_error('need_id', 400);
    }
    own_code($pdo, $id, $uid);
    $in = api_input();
    $sets = [];
    $params = [];
    if (isset($in['target_url'])) {
        $t = clean_url((string) $in['target_url']);
        if ($t === null) { api_error('invalid_url', 422); }
        $sets[] = 'target_url = ?'; $params[] = $t;
    }
    if (isset($in['title'])) {
        $t = trim((string) $in['title']);
        if ($t === '' || mb_strlen($t) > 120) { api_error('invalid_title', 422); }
        $sets[] = 'title = ?'; $params[] = $t;
    }
    if (isset($in['is_active'])) {
        $sets[] = 'is_active = ?'; $params[] = $in['is_active'] ? 1 : 0;
    }
    if (!$sets) {
        api_error('nothing', 422);
    }
    $params[] = $id; $params[] = $uid;
    $pdo->prepare('UPDATE qr_codes SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')
        ->execute($params);
    json_out(['code' => api_code(own_code($pdo, $id, $uid))]);
}

if ($method === 'DELETE') {
    if ($id <= 0) {
        api_error('need_id', 400);
    }
    $row = own_code($pdo, $id, $uid);
    delete_logo($row['logo_path'] ?? null);
    $pdo->prepare('DELETE FROM qr_codes WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_out(['deleted' => true]);
}

api_error('method', 405);
