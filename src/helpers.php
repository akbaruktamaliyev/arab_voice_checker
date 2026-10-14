<?php
/**
 * QRaft — small shared helpers.
 */

declare(strict_types=1);

/** Escape a value for safe output inside HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translate a key for the current language (sprintf args optional). */
function t(string $key, mixed ...$args): string
{
    $s = $GLOBALS['lang'][$key] ?? $key;
    return $args ? vsprintf($s, $args) : $s;
}

/** Load every string file for a language (lang/xx.php, xx.app.php, xx.admin.php). */
function load_lang_strings(string $code): array
{
    $all = [];
    foreach (['', '.app', '.admin'] as $part) {
        $file = APP_ROOT . '/lang/' . $code . $part . '.php';
        if (is_file($file)) {
            $all = array_merge($all, require $file);
        }
    }
    return $all;
}

/** Make $code the active language (English fills any key it does not define). */
function set_language(string $code): void
{
    $GLOBALS['lang_code'] = $code;
    $en = load_lang_strings('en');
    $GLOBALS['lang'] = $code === 'en' ? $en : array_merge($en, load_lang_strings($code));
}

/**
 * Translate admin-entered text stored in the database (e.g. plan names and
 * feature lines). Only known default texts have a "dyn:<text>" key; anything
 * the admin typed themselves is shown exactly as written.
 */
function td(string $text): string
{
    return $GLOBALS['lang']['dyn:' . $text] ?? $text;
}

/** Current language code (e.g. "en"). */
function lang_code(): string
{
    return $GLOBALS['lang_code'] ?? 'en';
}

/** Is the current language right-to-left? */
function is_rtl(): bool
{
    return in_array(lang_code(), ['ar', 'fa', 'he', 'ur'], true);
}

/** Read the app config from config.php (loaded once in bootstrap). */
function config(string $key, mixed $default = null): mixed
{
    global $config;
    return $config[$key] ?? $default;
}

/** Read an admin-configurable setting (from the settings table). */
function setting(string $name, ?string $default = null): ?string
{
    $s = $GLOBALS['settings'] ?? [];
    return array_key_exists($name, $s) ? $s[$name] : $default;
}

/** Persist a setting and update the in-memory copy. */
function set_setting(PDO $pdo, string $name, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$name, $value]);
    $GLOBALS['settings'][$name] = $value;
}

/** App display name: admin setting first, then config, then a default. */
function app_name(): string
{
    return setting('app_name') ?: (string) config('app_name', 'QRaft');
}

/**
 * The user's effective plan (limits). Returns their paid plan while it is
 * active, otherwise the free plan (cheapest active plan), otherwise a default.
 */
function user_plan(PDO $pdo, int $uid): array
{
    // Administrators run the site — they are never limited by plan quotas.
    $role = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $role->execute([$uid]);
    if ($role->fetchColumn() === 'admin') {
        return ['id' => 0, 'name' => t('plan.admin'), 'max_codes' => null, 'max_bios' => null, 'price' => 0, 'period' => 'lifetime'];
    }

    $stmt = $pdo->prepare(
        'SELECT p.* FROM users u JOIN plans p ON p.id = u.plan_id
         WHERE u.id = ? AND (u.plan_expires IS NULL OR u.plan_expires > NOW())'
    );
    $stmt->execute([$uid]);
    if ($plan = $stmt->fetch()) {
        return $plan;
    }
    $free = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY price, sort LIMIT 1')->fetch();
    return $free ?: ['name' => 'Free', 'max_codes' => 5, 'max_bios' => 1];
}

/** Is a count still under a (nullable = unlimited) limit? */
function within_limit(?int $max, int $count): bool
{
    return $max === null || $count < $max;
}

/** Price formatted from minor units, e.g. 900 -> "$9.00". */
function format_price(int $minor, ?string $currency = null): string
{
    $cur = strtoupper($currency ?: (string) setting('currency', 'usd'));
    $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'UZS' => 'soʻm ', 'RUB' => '₽'];
    $sym = $symbols[$cur] ?? ($cur . ' ');
    return $sym . number_format($minor / 100, 2);
}

/**
 * Best-effort device / OS / browser from a User-Agent string, for analytics.
 * Returns [device, os, browser] with short labels (no external library).
 */
function parse_user_agent(string $ua): array
{
    $ua = $ua ?: '';
    $device = preg_match('/Mobi|Android|iPhone|iPod/i', $ua) ? 'Mobile'
            : (preg_match('/iPad|Tablet/i', $ua) ? 'Tablet' : 'Desktop');

    $os = 'Other';
    foreach ([
        'Windows' => '/Windows NT/i', 'Android' => '/Android/i',
        'iOS' => '/iPhone|iPad|iPod/i', 'macOS' => '/Mac OS X/i',
        'Linux' => '/Linux/i',
    ] as $label => $re) {
        if (preg_match($re, $ua)) { $os = $label; break; }
    }

    $browser = 'Other';
    foreach ([
        'Edge' => '/Edg/i', 'Opera' => '/OPR|Opera/i', 'Chrome' => '/Chrome|CriOS/i',
        'Firefox' => '/Firefox|FxiOS/i', 'Safari' => '/Safari/i',
    ] as $label => $re) {
        if (preg_match($re, $ua)) { $browser = $label; break; }
    }

    return [$device, $os, $browser];
}

/** Build an absolute URL from the configured base_url. */
function url(string $path = ''): string
{
    $base = rtrim((string) config('base_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * Redirect and stop.
 *
 * Internal targets redirect RELATIVE to the current host, so the app always
 * stays on whatever address the visitor opened it with (localhost, a LAN IP, a
 * domain behind a proxy) and the session cookie keeps working. base_url is only
 * for QR content, not for navigation. Absolute http(s) targets pass through.
 */
function redirect(string $path)
{
    if (str_starts_with($path, 'http')) {
        header('Location: ' . $path);
        exit;
    }
    // Root-relative to the app's base path (from base_url's path component),
    // so it works from any sub-directory (e.g. /admin/) and from a sub-folder
    // install, while staying on the host the visitor is actually using.
    $basePath = (string) (parse_url((string) config('base_url'), PHP_URL_PATH) ?? '');
    $basePath = '/' . trim($basePath, '/');
    if ($basePath !== '/') {
        $basePath .= '/';
    }
    header('Location: ' . $basePath . ltrim($path, '/'));
    exit;
}

/** Flash a one-off message into the session. */
function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** Pull and clear any flash messages. */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Generate a unique short code for a dynamic QR.
 * Uses an unambiguous alphabet (no 0/O/1/l) so printed codes stay readable.
 */
function generate_code(PDO $pdo, int $length): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;

    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM qr_codes WHERE code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() !== false);

    return $code;
}

/**
 * Is this a valid custom short code (slug)?
 * 3–32 chars, letters/digits/hyphen/underscore, must start and end alphanumeric
 * (so no leading/trailing separators). Used for the /q/{code} link.
 */
function valid_slug(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,30}[A-Za-z0-9]$/', $value);
}

/**
 * Validate a hex colour (#rrggbb). Returns the lowercased value, or a fallback.
 */
function clean_color(string $value, string $fallback): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}

/** Parse an optional expiry datetime into 'Y-m-d H:i:s', or null. */
function parse_expiry(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Parse an optional positive scan limit, or null. */
function parse_scan_limit(string $value): ?int
{
    $value = trim($value);
    return ($value !== '' && ctype_digit($value) && (int) $value > 0) ? (int) $value : null;
}

/** Format a stored datetime for an <input type="datetime-local"> value. */
function to_datetime_local(?string $value): string
{
    return $value ? date('Y-m-d\TH:i', strtotime((string) $value)) : '';
}

/** Absolute path of the logo uploads directory (created on demand). */
function logo_dir(): string
{
    $dir = APP_ROOT . '/public/uploads/logos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Validate and store an uploaded logo image.
 *
 * Returns the stored relative path (e.g. "uploads/logos/ab12.png") on success,
 * null when no file was sent, or throws a RuntimeException on a bad file.
 * Only real PNG/JPEG/WebP images up to 1 MB are accepted; the extension comes
 * from the detected type, never from the uploaded filename.
 */
function store_logo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('err.logo_upload'));
    }
    if ($file['size'] > 1024 * 1024) {
        throw new RuntimeException(t('err.logo_size'));
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
    ];
    if ($info === false || !isset($allowed[$info[2]])) {
        throw new RuntimeException(t('err.logo_type'));
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$info[2]];
    $dest = logo_dir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException(t('err.logo_save'));
    }
    return 'uploads/logos/' . $name;
}

/** Delete a stored logo file if it exists. */
function delete_logo(?string $relPath): void
{
    if (!$relPath) {
        return;
    }
    // Guard against path traversal — only touch files inside the logos folder.
    $base = realpath(logo_dir());
    $full = realpath(APP_ROOT . '/public/' . $relPath);
    if ($base !== false && $full !== false && str_starts_with($full, $base)) {
        @unlink($full);
    }
}

/**
 * Validate and normalise a URL supplied by the user.
 * Returns the cleaned URL, or null when it is not an acceptable http(s) link.
 */
function clean_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }
    // Only allow http/https schemes (block javascript:, data:, etc.).
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    return $value;
}

/** Human status for a code row: [label, badge class]. */
function code_status(array $c): array
{
    if (!$c['is_active']) {
        return [t('status.paused'), 'badge-off'];
    }
    if ($c['expires_at'] !== null && strtotime((string) $c['expires_at']) < time()) {
        return [t('status.expired'), 'badge-warn'];
    }
    if ($c['scan_limit'] !== null && (int) $c['scan_count'] >= (int) $c['scan_limit']) {
        return [t('status.limit'), 'badge-warn'];
    }
    return [t('status.active'), 'badge-on'];
}

/**
 * Render a small standalone message page (no app layout) and stop. Used by the
 * public endpoints visitors see directly: scanned QR codes and bio pages.
 * Follows the visitor's light/dark preference.
 */
function standalone_page(string $title, string $body, int $status = 200)
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="' . e($GLOBALS['lang_code'] ?? 'en') . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">'
        . '<title>' . e($title) . '</title><style>'
        . ':root{--bg:#f4f6fb;--card:#fff;--line:#e3e8f0;--text:#1b2333;--muted:#6b7691;--input:#fff}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#0f1420;--card:#171d2b;--line:#2a3346;--text:#e7ecf5;--muted:#93a0b8;--input:#0f1420}}'
        . 'body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);'
        . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center}'
        . '.c{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;box-shadow:0 8px 24px rgba(20,30,60,.08)}'
        . 'input{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:1rem;box-sizing:border-box;margin:10px 0;background:var(--input);color:var(--text)}'
        . 'button{width:100%;padding:11px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:600;font-size:1rem;cursor:pointer}'
        . 'h1{font-size:1.2rem;margin:0 0 6px}.m{color:var(--muted);font-size:.9rem}a{color:#6d64f0}</style></head><body><div class="c">'
        . $body . '</div></body></html>';
    exit;
}