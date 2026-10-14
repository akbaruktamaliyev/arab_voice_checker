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
function redirect(string $path): never
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
        throw new RuntimeException('The logo failed to upload. Please try again.');
    }
    if ($file['size'] > 1024 * 1024) {
        throw new RuntimeException('The logo must be 1 MB or smaller.');
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
    ];
    if ($info === false || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Please upload a PNG, JPG or WebP image.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$info[2]];
    $dest = logo_dir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the logo. Check folder permissions.');
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
