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
