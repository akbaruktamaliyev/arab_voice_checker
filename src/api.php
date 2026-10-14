<?php
/**
 * QRaft — REST API helpers (JSON output + bearer-token authentication).
 */

declare(strict_types=1);

/** Send a JSON response and stop. */
function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Extract the bearer token from the request, across server setups. */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
        }
    }
    return preg_match('/Bearer\s+(\S+)/i', (string) $header, $m) ? $m[1] : null;
}

/** Authenticate the API caller and return their user id, or send 401. */
function api_user(PDO $pdo): int
{
    $token = bearer_token();
    if ($token === null) {
        json_out(['error' => 'Missing API token. Send: Authorization: Bearer <token>'], 401);
    }
    $stmt = $pdo->prepare('SELECT id, user_id FROM api_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(['error' => 'Invalid API token.'], 401);
    }
    $pdo->prepare('UPDATE api_tokens SET last_used = NOW() WHERE id = ?')->execute([$row['id']]);

    // Refuse suspended accounts.
    $u = $pdo->prepare('SELECT is_blocked FROM users WHERE id = ?');
    $u->execute([$row['user_id']]);
    if ((int) $u->fetchColumn() === 1) {
        json_out(['error' => 'Account suspended.'], 403);
    }
    return (int) $row['user_id'];
}

/** Read and decode a JSON request body (falls back to form fields). */
function api_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

/** Shape a code row for API output. */
function api_code(array $c): array
{
    return [
        'id'         => (int) $c['id'],
        'code'       => $c['code'],
        'title'      => $c['title'],
        'target_url' => $c['target_url'],
        'short_url'  => url('q/' . $c['code']),
        'is_active'  => (bool) $c['is_active'],
        'scan_count' => (int) $c['scan_count'],
        'created_at' => $c['created_at'],
    ];
}
