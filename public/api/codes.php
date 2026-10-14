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

$uid    = api_user($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

/** Fetch one of the caller's codes or 404. */
function own_code(PDO $pdo, int $id, int $uid): array
{
    $stmt = $pdo->prepare('SELECT * FROM qr_codes WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['error' => 'Code not found.'], 404);
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
        json_out(['error' => 'Plan limit reached. Upgrade to create more codes.'], 403);
    }

    $in = api_input();
    $title  = trim((string) ($in['title'] ?? ''));
    $target = clean_url((string) ($in['target_url'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) {
        json_out(['error' => 'A title (1–120 chars) is required.'], 422);
    }
    if ($target === null) {
        json_out(['error' => 'A valid http(s) target_url is required.'], 422);
    }
    $slug = trim((string) ($in['slug'] ?? ''));
    if ($slug !== '') {
        if (!valid_slug($slug)) {
            json_out(['error' => 'Invalid slug.'], 422);
        }
        $dup = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
        $dup->execute([$slug]);
        if ($dup->fetchColumn() !== false) {
            json_out(['error' => 'Slug already taken.'], 409);
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
        json_out(['error' => 'Provide ?id=N.'], 400);
    }
    own_code($pdo, $id, $uid);
    $in = api_input();
    $sets = [];
    $params = [];
    if (isset($in['target_url'])) {
        $t = clean_url((string) $in['target_url']);
        if ($t === null) { json_out(['error' => 'Invalid target_url.'], 422); }
        $sets[] = 'target_url = ?'; $params[] = $t;
    }
    if (isset($in['title'])) {
        $t = trim((string) $in['title']);
        if ($t === '' || mb_strlen($t) > 120) { json_out(['error' => 'Invalid title.'], 422); }
        $sets[] = 'title = ?'; $params[] = $t;
    }
    if (isset($in['is_active'])) {
        $sets[] = 'is_active = ?'; $params[] = $in['is_active'] ? 1 : 0;
    }
    if (!$sets) {
        json_out(['error' => 'Nothing to update.'], 422);
    }
    $params[] = $id; $params[] = $uid;
    $pdo->prepare('UPDATE qr_codes SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')
        ->execute($params);
    json_out(['code' => api_code(own_code($pdo, $id, $uid))]);
}

if ($method === 'DELETE') {
    if ($id <= 0) {
        json_out(['error' => 'Provide ?id=N.'], 400);
    }
    $row = own_code($pdo, $id, $uid);
    delete_logo($row['logo_path'] ?? null);
    $pdo->prepare('DELETE FROM qr_codes WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_out(['deleted' => true]);
}

json_out(['error' => 'Method not allowed.'], 405);
