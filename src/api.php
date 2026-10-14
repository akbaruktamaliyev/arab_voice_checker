<?php
/**
 * QRaft — REST API helpers (JSON output + bearer-token authentication).
 */

declare(strict_types=1);

/**
 * Human-readable field names in the response language, for building UIs.
 * Opt-in with ?labels=1; the JSON keys themselves never change.
 */
function api_labels(): array
{
    $out = [];
    foreach (['id', 'code', 'codes', 'title', 'target_url', 'short_url', 'slug', 'fg_color', 'bg_color',
              'is_active', 'disabled_by_admin', 'scan_count', 'created_at', 'deleted'] as $k) {
        $out[$k] = t('apilabel.' . $k);
    }
    return $out;
}

/** Send a JSON response and stop. */
function json_out(mixed $data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Language: ' . ($GLOBALS['lang_code'] ?? 'en'));
    if ($status < 400 && is_array($data) && in_array($_GET['labels'] ?? '', ['1', 'true'], true)) {
        $data['labels'] = api_labels();
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Pick the API response language. API clients are stateless, so the session
 * and cookie are ignored: ?lang=xx wins, then the Accept-Language header, then
 * the site's default language.
 */
function api_pick_language(): void
{
    $avail = $GLOBALS['available_langs'] ?? ['en'];
    $want = (string) ($_GET['lang'] ?? '');
    if (!in_array($want, $avail, true)) {
        $want = '';
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if (in_array($code, $avail, true)) { $want = $code; break; }
        }
    }
    if ($want === '') {
        $want = (string) (setting('default_lang') ?: 'en');
    }
    set_language(in_array($want, $avail, true) ? $want : 'en');
}

/**
 * Send a localized JSON error: {"error": "<message>", "error_code": "<stable id>"}.
 * Clients should branch on error_code; the message text depends on the language.
 */
function api_error(string $code, int $status)
{
    json_out(['error' => t('api.' . $code), 'error_code' => $code], $status);
}

/** 429 with a Retry-After header. */
function api_rate_limited(int $retryAfter)
{
    header('Retry-After: ' . $retryAfter);
    api_error('rate_limited', 429);
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
    api_pick_language();
    $token = bearer_token();
    if ($token === null) {
        api_error('missing_token', 401);
    }
    // Stop token guessing: too many bad tokens from one visitor → 429.
    if (throttle_blocked($pdo, 'api-bad', API_MAX_BAD_TOKENS, 15)) {
        api_rate_limited(15 * 60);
    }
    $stmt = $pdo->prepare('SELECT id, user_id FROM api_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        throttle_hit($pdo, 'api-bad');
        api_error('invalid_token', 401);
    }
    // Per-token request budget.
    $tokenKey = hash('sha256', 'api-token|' . $row['id']);
    if (throttle_blocked($pdo, 'api', API_MAX_PER_MINUTE, 1, $tokenKey)) {
        api_rate_limited(60);
    }
    throttle_hit($pdo, 'api', $tokenKey);
    $pdo->prepare('UPDATE api_tokens SET last_used = NOW() WHERE id = ?')->execute([$row['id']]);

    // Refuse suspended accounts.
    $u = $pdo->prepare('SELECT is_blocked FROM users WHERE id = ?');
    $u->execute([$row['user_id']]);
    if ((int) $u->fetchColumn() === 1) {
        api_error('suspended', 403);
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
        'disabled_by_admin' => (bool) ($c['admin_disabled'] ?? 0),
        'scan_count' => (int) $c['scan_count'],
        'created_at' => $c['created_at'],
    ];
}
