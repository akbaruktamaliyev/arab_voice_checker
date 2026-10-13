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

/** Read the app config (loaded once in bootstrap). */
function config(string $key, mixed $default = null): mixed
{
    global $config;
    return $config[$key] ?? $default;
}

/** Build an absolute URL from the configured base_url. */
function url(string $path = ''): string
{
    $base = rtrim((string) config('base_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

/** Redirect and stop. */
function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
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
 * Validate a hex colour (#rrggbb). Returns the lowercased value, or a fallback.
 */
function clean_color(string $value, string $fallback): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
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

/** Read a stored logo into a data: URI, for self-contained SVG export. */
function logo_data_uri(?string $relPath): ?string
{
    if (!$relPath) {
        return null;
    }
    $full = realpath(APP_ROOT . '/public/' . $relPath);
    $base = realpath(logo_dir());
    if ($full === false || $base === false || !str_starts_with($full, $base) || !is_file($full)) {
        return null;
    }
    $info = @getimagesize($full);
    if ($info === false) {
        return null;
    }
    return 'data:' . $info['mime'] . ';base64,' . base64_encode((string) file_get_contents($full));
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
