<?php
/**
 * QRaft — brute-force protection for the sign-in form.
 *
 * Failed attempts are counted per visitor (a salted hash of the IP, never the
 * raw address) over a sliding window. Once the limit is reached further sign-in
 * attempts are refused until old failures age out.
 */

declare(strict_types=1);

/** Failed sign-ins allowed per visitor within the window. */
const LOGIN_MAX_ATTEMPTS = 8;
/** Length of the sliding window, in minutes. */
const LOGIN_WINDOW_MINUTES = 15;

/** Salted hash of the visitor's IP address. */
function client_ip_hash(): string
{
    return hash('sha256', (string) config('base_url') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Has this visitor used up their failed sign-in allowance? */
function login_locked(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_hash = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([client_ip_hash()]);
        return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (PDOException $e) {
        // Table missing on an older install — never lock everyone out because of it.
        return false;
    }
}

/** Record a failed sign-in (and prune rows older than a day). */
function login_failed(PDO $pdo): void
{
    try {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([client_ip_hash()]);
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (PDOException $e) {
        // Throttling is best-effort.
    }
}

/** Forget this visitor's failed attempts after a successful sign-in. */
function login_succeeded(PDO $pdo): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([client_ip_hash()]);
    } catch (PDOException $e) {
        // Best-effort.
    }
}
