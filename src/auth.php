<?php
/**
 * QRaft — authentication, CSRF and the current user.
 */

declare(strict_types=1);

/** Return the logged-in user id, or null. */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** Return the logged-in user row, or null. */
function current_user(PDO $pdo): ?array
{
    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email, role, is_blocked, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/** Stop and send guests to the login page. A blocked account is logged out. */
function require_login(): void
{
    global $pdo;
    if (current_user_id() === null) {
        flash('Please sign in to continue.', 'info');
        redirect('login.php');
    }
    $user = current_user($pdo);
    if ($user && (int) $user['is_blocked'] === 1) {
        logout_user();
        flash('Your account has been suspended.', 'error');
        redirect('login.php');
    }
}

/** Is the current user an administrator? */
function is_admin(PDO $pdo): bool
{
    $user = current_user($pdo);
    return $user !== null && $user['role'] === 'admin';
}

/** Stop unless the current user is an administrator. */
function require_admin(PDO $pdo): void
{
    require_login();
    if (!is_admin($pdo)) {
        http_response_code(403);
        flash('Administrator access required.', 'error');
        redirect('dashboard.php');
    }
}

/** Log a user in by id (regenerates the session id to prevent fixation). */
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/** Log the current user out. */
function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

// --- CSRF -------------------------------------------------------------

/** Current CSRF token (created on first use). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input with the CSRF token, for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Abort unless the submitted token matches. Call at the top of every POST. */
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expired. Please go back and try again.');
    }
}
