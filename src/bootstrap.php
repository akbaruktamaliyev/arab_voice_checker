<?php
/**
 * QRaft — application bootstrap.
 *
 * Loads configuration, opens the database connection, starts the session
 * and pulls in the shared helpers. Every entry point includes this file.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to buyers' visitors

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    // Not installed yet → send the visitor to the web installer.
    if (is_dir(APP_ROOT . '/public/install')) {
        header('Location: install/');
        exit;
    }
    http_response_code(500);
    exit('Configuration missing. Copy config.example.php to config.php and fill it in.');
}

/** @var array $config */
$config = require $configFile;

// --- Database (PDO) ---------------------------------------------------
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int) $db['port'],
    $db['name'],
    $db['charset']
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check your settings in config.php.');
}

// --- Admin-configurable settings (loaded once from the DB) ------------
$settings = [];
try {
    foreach ($pdo->query('SELECT name, value FROM settings') as $row) {
        $settings[$row['name']] = $row['value'];
    }
} catch (PDOException $e) {
    // settings table not present yet (pre-migration) — fall back to config
}
$GLOBALS['settings'] = $settings;

// --- Time zone --------------------------------------------------------
// One time zone for the whole app: PHP and the MySQL session must agree, or
// expiry dates, "last 14 days" charts and scan times drift by the UTC offset.
$tz = (string) (($settings['timezone'] ?? '') ?: ($config['timezone'] ?? 'UTC'));
if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    $tz = 'UTC';
}
date_default_timezone_set($tz);
$pdo->exec("SET time_zone = '" . date('P') . "'");

// --- Session ----------------------------------------------------------
// Mark the cookie Secure whenever the request really arrived over HTTPS
// (directly or via a proxy), so it is never sent over plain HTTP.
$onHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $onHttps,
]);
ini_set('session.use_strict_mode', '1'); // never adopt a session id the server did not issue
session_start();

// Baseline security headers: no MIME sniffing, no framing by other sites
// (clickjacking on admin/billing pages), and a conservative referrer policy.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/throttle.php';

// --- Language (i18n) --------------------------------------------------
$availableLangs = ['en', 'ru', 'uz'];

// Switch language via ?setlang=xx, then redirect to the same page.
if (isset($_GET['setlang']) && in_array($_GET['setlang'], $availableLangs, true)) {
    $_SESSION['lang'] = $_GET['setlang'];
    setcookie('lang', $_GET['setlang'], time() + 31536000, '/');
    // Back to the same page, keeping every other query parameter.
    $rest = $_GET;
    unset($rest['setlang']);
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    header('Location: ' . $path . ($rest ? '?' . http_build_query($rest) : ''));
    exit;
}

// Note: $GLOBALS['lang'] aliases a global $lang, so avoid a variable named $lang.
$chosenLang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? (setting('default_lang') ?: 'en'));
if (!in_array($chosenLang, $availableLangs, true)) {
    $chosenLang = 'en';
}
$enStrings = require APP_ROOT . '/lang/en.php';
$GLOBALS['lang_code'] = $chosenLang;
$GLOBALS['available_langs'] = $availableLangs;
$GLOBALS['lang'] = $chosenLang === 'en'
    ? $enStrings
    : array_merge($enStrings, require APP_ROOT . '/lang/' . $chosenLang . '.php');
