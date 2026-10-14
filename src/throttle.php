<?php
/**
 * QRaft — rate limiting (brute-force and abuse protection).
 *
 * Hits are recorded per bucket ("login", "qrpw:<code>", "forgot", …) and per
 * subject — normally a salted hash of the visitor's IP, never the raw address.
 * A bucket is blocked once it has `max` hits inside a sliding window of
 * `minutes`. Everything is best-effort: if the table is unavailable the check
 * fails open, so throttling can never lock the whole site.
 */

declare(strict_types=1);

/** Failed sign-ins allowed per visitor within the window. */
const LOGIN_MAX_ATTEMPTS = 8;
/** Length of the sliding sign-in window, in minutes. */
const LOGIN_WINDOW_MINUTES = 15;
/** Wrong passwords allowed per visitor and QR code within the window. */
const QRPW_MAX_ATTEMPTS = 10;
const QRPW_WINDOW_MINUTES = 15;
/** Password-reset requests per visitor (and per target address) per hour. */
const FORGOT_MAX_PER_IP = 6;
const FORGOT_MAX_PER_EMAIL = 3;
/** Confirmation-mail resends per visitor / per address per hour. */
const VERIFY_RESEND_MAX_PER_IP = 6;
const VERIFY_RESEND_MAX_PER_EMAIL = 3;
/** Registration attempts per visitor per hour. */
const REGISTER_MAX_PER_HOUR = 10;
/** API requests per token per minute, and failed token attempts per visitor. */
const API_MAX_PER_MINUTE = 120;
const API_MAX_BAD_TOKENS = 20;

/**
 * The visitor's IP address. Behind a reverse proxy or CDN set
 * 'trusted_proxy_header' in config.php (e.g. 'CF-Connecting-IP', 'X-Real-IP'
 * or 'X-Forwarded-For') — otherwise every visitor would share the proxy's IP
 * and one person's failed sign-ins would lock everybody out. Only enable it
 * when the proxy always sets that header, or clients could spoof their IP.
 */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $header = trim((string) config('trusted_proxy_header', ''));
    if ($header !== '') {
        $raw = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
        if ($raw !== '') {
            // X-Forwarded-For is a list; the right-most entry is the one our proxy appended.
            $parts = array_map('trim', explode(',', $raw));
            $candidate = strtolower($header) === 'x-forwarded-for' ? end($parts) : $parts[0];
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidate;
            }
        }
    }
    return $ip;
}

/** Salted hash of the visitor's IP address. */
function client_ip_hash(): string
{
    return hash('sha256', (string) config('base_url') . '|' . client_ip());
}

/** Create the hit table on installs that predate it. */
function throttle_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `rate_hits` (
            `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `bucket`  VARCHAR(64) NOT NULL,
            `subject` CHAR(64)    NOT NULL,
            `hit_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rate` (`bucket`, `subject`, `hit_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Run a throttle query, creating the table once if it is missing. */
function throttle_run(PDO $pdo, string $sql, array $params): ?PDOStatement
{
    for ($try = 0; $try < 2; $try++) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if ($try === 0 && $e->getCode() === '42S02') {
                try { throttle_ensure_table($pdo); } catch (PDOException $e2) { return null; }
                continue;
            }
            return null;
        }
    }
    return null;
}

/** Hits recorded for this bucket/subject inside the window. */
function throttle_count(PDO $pdo, string $bucket, int $minutes, ?string $subject = null): int
{
    $stmt = throttle_run(
        $pdo,
        'SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND subject = ?
         AND hit_at > (NOW() - INTERVAL ' . (int) $minutes . ' MINUTE)',
        [$bucket, $subject ?? client_ip_hash()]
    );
    return $stmt ? (int) $stmt->fetchColumn() : 0;
}

/** Has the bucket used up its allowance? */
function throttle_blocked(PDO $pdo, string $bucket, int $max, int $minutes, ?string $subject = null): bool
{
    return throttle_count($pdo, $bucket, $minutes, $subject) >= $max;
}

/** Record one hit (and now and then prune rows older than a day). */
function throttle_hit(PDO $pdo, string $bucket, ?string $subject = null): void
{
    throttle_run($pdo, 'INSERT INTO rate_hits (bucket, subject) VALUES (?, ?)', [$bucket, $subject ?? client_ip_hash()]);
    if (random_int(1, 25) === 1) {
        throttle_run($pdo, 'DELETE FROM rate_hits WHERE hit_at < (NOW() - INTERVAL 1 DAY)', []);
    }
}

/** Forget a bucket's hits (e.g. after a successful sign-in). */
function throttle_clear(PDO $pdo, string $bucket, ?string $subject = null): void
{
    throttle_run($pdo, 'DELETE FROM rate_hits WHERE bucket = ? AND subject = ?', [$bucket, $subject ?? client_ip_hash()]);
}

// --- Sign-in --------------------------------------------------------------

/** Has this visitor used up their failed sign-in allowance? */
function login_locked(PDO $pdo): bool
{
    return throttle_blocked($pdo, 'login', LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_MINUTES);
}

/** Record a failed sign-in. */
function login_failed(PDO $pdo): void
{
    throttle_hit($pdo, 'login');
}

/** Forget this visitor's failed attempts after a successful sign-in. */
function login_succeeded(PDO $pdo): void
{
    throttle_clear($pdo, 'login');
}
