<?php
/**
 * QRaft — web installer.
 *
 * Collects database credentials and the first admin account, imports the
 * schema, writes config.php, then locks itself. Delete the install/ folder
 * after setup. Standalone: it must run before config.php exists.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Split a SQL script into individual statements.
 *
 * Understands '...', "..." and `...` quoting (backslash and doubled-quote
 * escapes), "-- " / "#" line comments and block comments, so a ";" only ends a
 * statement when it is outside quotes and comments, and multi-line statements
 * (such as CREATE TABLE) stay intact.
 */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $sql[$i + 1] ?? '';

        // Line comments: "-- " (dash dash then space/EOL) and "#".
        if (($c === '-' && $n === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\r", "\n"], true)) || $c === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $buf .= "\n";
            continue;
        }
        // Block comments.
        if ($c === '/' && $n === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            $buf .= ' ';
            continue;
        }
        // Quoted strings / identifiers.
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            for ($i++; $i < $len; $i++) {
                $d = $sql[$i];
                $buf .= $d;
                if ($d === '\\' && $quote !== '`') {
                    $i++;
                    if ($i < $len) {
                        $buf .= $sql[$i];
                    }
                    continue;
                }
                if ($d === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {   // doubled quote = literal quote
                        $i++;
                        $buf .= $quote;
                        continue;
                    }
                    break;
                }
            }
            continue;
        }
        if ($c === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }
    return $out;
}

$root = dirname(dirname(__DIR__));           // project root
$configFile = $root . '/config.php';
$schemaFile = $root . '/database.sql';

$done = false;
$errors = [];

// Already installed? Refuse to run again (prevents overwriting config).
$alreadyInstalled = is_file($configFile);

// Sensible defaults / posted values.
$in = [
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'qraft',
    'db_user' => $_POST['db_user'] ?? 'root',
    'db_pass' => $_POST['db_pass'] ?? '',
    'base_url' => $_POST['base_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    'app_name' => $_POST['app_name'] ?? 'QRaft',
    'timezone' => $_POST['timezone'] ?? 'UTC',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
    'admin_pass' => $_POST['admin_pass'] ?? '',
];

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate.
    if (trim($in['admin_name']) === '') { $errors[] = 'Enter an admin name.'; }
    if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid admin email.'; }
    if (strlen($in['admin_pass']) < 8) { $errors[] = 'Admin password must be at least 8 characters.'; }
    if (!is_file($schemaFile)) { $errors[] = 'database.sql is missing from the project root.'; }

    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $in['db_name']);
    if ($dbName === '') { $errors[] = 'Enter a valid database name.'; }

    if (!$errors) {
        try {
            // Connect to the server (no DB yet) and create the database.
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $in['db_host'], (int) $in['db_port']);
            $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");

            // Import the schema statement by statement. CREATE DATABASE / USE
            // are skipped so the database name chosen above is honoured.
            foreach (split_sql((string) file_get_contents($schemaFile)) as $stmt) {
                if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
                    continue;
                }
                $pdo->exec($stmt);
            }

            // Create the first admin.
            $ins = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, is_verified)
                 VALUES (?, ?, ?, 'admin', 1)"
            );
            $ins->execute([
                trim($in['admin_name']), $in['admin_email'],
                password_hash($in['admin_pass'], PASSWORD_DEFAULT),
            ]);
            $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['app_name', trim($in['app_name']) ?: 'QRaft']);

            // Time zone: validated, stored as a setting, and mirrored in config.php.
            $tz = in_array($in['timezone'], DateTimeZone::listIdentifiers(), true) ? $in['timezone'] : 'UTC';
            $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['timezone', $tz]);

            // Write config.php.
            $cfg = "<?php\nreturn " . var_export([
                'db' => [
                    'host' => $in['db_host'], 'port' => (int) $in['db_port'], 'name' => $dbName,
                    'user' => $in['db_user'], 'pass' => $in['db_pass'], 'charset' => 'utf8mb4',
                ],
                'base_url' => rtrim($in['base_url'], '/'),
                'app_name' => trim($in['app_name']) ?: 'QRaft',
                'timezone' => $tz,
                'code_length' => 6,
            ], true) . ";\n";

            if (@file_put_contents($configFile, $cfg) === false) {
                $errors[] = 'Could not write config.php. Make the project root writable and retry.';
            } else {
                $done = true;
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------- UI
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install QRaft</title>
    <style>
        body{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f6fb;color:#1b2333;margin:0;padding:40px 16px;line-height:1.55}
        .box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e3e8f0;border-radius:12px;box-shadow:0 8px 24px rgba(20,30,60,.06);padding:28px}
        h1{margin:0 0 6px}.muted{color:#6b7691}
        label{display:block;font-weight:600;font-size:.9rem;margin:14px 0 6px}
        input,select{width:100%;padding:10px 12px;border:1px solid #e3e8f0;border-radius:10px;font-size:1rem;box-sizing:border-box}
        .row{display:flex;gap:12px}.row>*{flex:1}
        .btn{margin-top:20px;display:inline-block;width:100%;padding:11px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:600;font-size:1rem;cursor:pointer}
        .err{background:#fbeaea;color:#d64545;padding:10px 14px;border-radius:10px;margin:8px 0}
        .ok{background:#e6f6ee;color:#0f9d58;padding:12px 16px;border-radius:10px}
        code{background:#f4f6fb;padding:2px 6px;border-radius:6px}
        h2{font-size:1rem;margin:22px 0 0}
    </style>
</head>
<body>
<div class="box">
    <h1>Install QRaft</h1>
    <?php if ($alreadyInstalled): ?>
        <div class="ok">QRaft is already installed.</div>
        <p class="muted">For security, delete the <code>public/install/</code> folder. Then open the site.</p>
        <a class="btn" href="../index.php" style="text-align:center;text-decoration:none">Open QRaft</a>
    <?php elseif ($done): ?>
        <div class="ok">Installation complete!</div>
        <p class="muted"><strong>Important:</strong> delete the <code>public/install/</code> folder now, then sign in with your admin account.</p>
        <a class="btn" href="../login.php" style="text-align:center;text-decoration:none">Go to sign in</a>
    <?php else: ?>
        <p class="muted">Enter your database details and create the admin account.</p>
        <?php foreach ($errors as $err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
        <form method="post">
            <h2>Database</h2>
            <div class="row">
                <div><label>Host</label><input name="db_host" value="<?= htmlspecialchars($in['db_host']) ?>"></div>
                <div style="max-width:110px"><label>Port</label><input name="db_port" value="<?= htmlspecialchars($in['db_port']) ?>"></div>
            </div>
            <label>Database name</label><input name="db_name" value="<?= htmlspecialchars($in['db_name']) ?>">
            <div class="row">
                <div><label>User</label><input name="db_user" value="<?= htmlspecialchars($in['db_user']) ?>"></div>
                <div><label>Password</label><input type="password" name="db_pass" value="<?= htmlspecialchars($in['db_pass']) ?>"></div>
            </div>

            <h2>Site</h2>
            <label>Base URL</label><input name="base_url" value="<?= htmlspecialchars($in['base_url']) ?>">
            <label>Site name</label><input name="app_name" value="<?= htmlspecialchars($in['app_name']) ?>">
            <label>Time zone</label>
            <select name="timezone" id="tz">
                <?php foreach (DateTimeZone::listIdentifiers() as $z): ?>
                    <option value="<?= htmlspecialchars($z) ?>" <?= $in['timezone'] === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <script>
                // Preselect the visitor's own time zone (first load only).
                (function () { try { var z = Intl.DateTimeFormat().resolvedOptions().timeZone, s = document.getElementById('tz');
                    if (z && s && !s.dataset.touched) { for (var i = 0; i < s.options.length; i++) { if (s.options[i].value === z) { s.selectedIndex = i; break; } } } } catch (e) {} })();
            </script>
            <?php endif; ?>

            <h2>Admin account</h2>
            <label>Name</label><input name="admin_name" value="<?= htmlspecialchars($in['admin_name']) ?>">
            <label>Email</label><input type="email" name="admin_email" value="<?= htmlspecialchars($in['admin_email']) ?>">
            <label>Password</label><input type="password" name="admin_pass">

            <button class="btn" type="submit">Install</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
