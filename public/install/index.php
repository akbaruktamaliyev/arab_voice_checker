<?php
/**
 * QRaft — web installer.
 *
 * Collects database credentials and the first admin account, imports the
 * schema, writes config.php, then locks itself. Delete the install/ folder
 * after setup. Standalone: it must run before config.php exists.
 */

declare(strict_types=1);
if (PHP_VERSION_ID < 80000) {
    exit('QRaft requires PHP 8.0 or newer. This server runs PHP ' . PHP_VERSION . '.');
}
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

// --- Installer language (standalone: the app's i18n is not loaded yet) -----
$INSTALL_LANGS = ['en' => 'EN', 'ru' => 'RU', 'uz' => 'UZ'];
$ilang = (string) ($_POST['lang'] ?? $_GET['lang'] ?? '');
if (!isset($INSTALL_LANGS[$ilang])) {
    $ilang = 'en';
    foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
        $code = substr(trim($part), 0, 2);
        if (isset($INSTALL_LANGS[$code])) { $ilang = $code; break; }
    }
}
$IT = [
    'en' => [
        'title' => 'Install QRaft', 'installed' => 'QRaft is already installed.',
        'installed_hint' => 'For security, delete the <code>public/install/</code> folder. Then open the site.',
        'open' => 'Open QRaft', 'complete' => 'Installation complete!',
        'complete_hint' => '<strong>Important:</strong> delete the <code>public/install/</code> folder now, then sign in with your admin account.',
        'to_login' => 'Go to sign in', 'intro' => 'Enter your database details and create the admin account.',
        'h_db' => 'Database', 'host' => 'Host', 'port' => 'Port', 'db_name' => 'Database name', 'db_user' => 'User', 'password' => 'Password',
        'h_site' => 'Site', 'base_url' => 'Base URL', 'site_name' => 'Site name', 'tz' => 'Time zone',
        'h_admin' => 'Admin account', 'name' => 'Name', 'email' => 'Email', 'install' => 'Install',
        'e_name' => 'Enter an admin name.', 'e_email' => 'Enter a valid admin email.',
        'e_pass' => 'Admin password must be at least 8 characters.',
        'e_schema' => 'database.sql is missing from the project root.', 'e_dbname' => 'Enter a valid database name.',
        'e_config' => 'Could not write config.php. Make the project root writable and retry.', 'e_db' => 'Database error: ',
        'h_req' => 'Server requirements', 'rq_php' => 'PHP 8.0 or newer (this server: %s)', 'rq_ext' => 'PHP extension: %s',
        'rq_root' => 'Project folder is writable (to create config.php)', 'rq_logos' => 'public/uploads/logos is writable (logo uploads)',
        'rq_curl' => 'PHP extension: curl (needed only for Stripe payments)', 'rq_blocked' => 'Fix the items marked ✗ and reload this page — the installer cannot continue until they pass.',
    ],
    'ru' => [
        'title' => 'Установка QRaft', 'installed' => 'QRaft уже установлен.',
        'installed_hint' => 'В целях безопасности удалите папку <code>public/install/</code>. Затем откройте сайт.',
        'open' => 'Открыть QRaft', 'complete' => 'Установка завершена!',
        'complete_hint' => '<strong>Важно:</strong> сейчас же удалите папку <code>public/install/</code>, затем войдите под аккаунтом администратора.',
        'to_login' => 'Перейти ко входу', 'intro' => 'Укажите данные базы данных и создайте аккаунт администратора.',
        'h_db' => 'База данных', 'host' => 'Хост', 'port' => 'Порт', 'db_name' => 'Имя базы данных', 'db_user' => 'Пользователь', 'password' => 'Пароль',
        'h_site' => 'Сайт', 'base_url' => 'Базовый URL', 'site_name' => 'Название сайта', 'tz' => 'Часовой пояс',
        'h_admin' => 'Аккаунт администратора', 'name' => 'Имя', 'email' => 'Email', 'install' => 'Установить',
        'e_name' => 'Введите имя администратора.', 'e_email' => 'Введите корректный email администратора.',
        'e_pass' => 'Пароль администратора должен содержать не менее 8 символов.',
        'e_schema' => 'Файл database.sql отсутствует в корне проекта.', 'e_dbname' => 'Введите корректное имя базы данных.',
        'e_config' => 'Не удалось записать config.php. Сделайте корневую папку проекта доступной для записи и повторите.', 'e_db' => 'Ошибка базы данных: ',
        'h_req' => 'Требования к серверу', 'rq_php' => 'PHP 8.0 или новее (на этом сервере: %s)', 'rq_ext' => 'Расширение PHP: %s',
        'rq_root' => 'Папка проекта доступна для записи (чтобы создать config.php)', 'rq_logos' => 'public/uploads/logos доступна для записи (загрузка логотипов)',
        'rq_curl' => 'Расширение PHP: curl (нужно только для платежей Stripe)', 'rq_blocked' => 'Исправьте пункты с ✗ и обновите страницу — пока они не выполнены, установка невозможна.',
    ],
    'uz' => [
        'title' => 'QRaft ni o‘rnatish', 'installed' => 'QRaft allaqachon o‘rnatilgan.',
        'installed_hint' => 'Xavfsizlik uchun <code>public/install/</code> papkasini o‘chiring. Keyin saytni oching.',
        'open' => 'QRaft ni ochish', 'complete' => 'O‘rnatish yakunlandi!',
        'complete_hint' => '<strong>Muhim:</strong> <code>public/install/</code> papkasini hoziroq o‘chiring, so‘ng administrator akkaunti bilan kiring.',
        'to_login' => 'Kirishga o‘tish', 'intro' => 'Ma’lumotlar bazasi ma’lumotlarini kiriting va administrator akkauntini yarating.',
        'h_db' => 'Ma’lumotlar bazasi', 'host' => 'Xost', 'port' => 'Port', 'db_name' => 'Ma’lumotlar bazasi nomi', 'db_user' => 'Foydalanuvchi', 'password' => 'Parol',
        'h_site' => 'Sayt', 'base_url' => 'Asosiy URL', 'site_name' => 'Sayt nomi', 'tz' => 'Vaqt zonasi',
        'h_admin' => 'Administrator akkaunti', 'name' => 'Ism', 'email' => 'Email', 'install' => 'O‘rnatish',
        'e_name' => 'Administrator ismini kiriting.', 'e_email' => 'Administratorning to‘g‘ri emailini kiriting.',
        'e_pass' => 'Administrator paroli kamida 8 belgidan iborat bo‘lishi kerak.',
        'e_schema' => 'database.sql fayli loyiha ildizida topilmadi.', 'e_dbname' => 'Ma’lumotlar bazasining to‘g‘ri nomini kiriting.',
        'e_config' => 'config.php ni yozib bo‘lmadi. Loyiha ildiz papkasini yozish uchun ochib, qayta urinib ko‘ring.', 'e_db' => 'Ma’lumotlar bazasi xatosi: ',
        'h_req' => 'Server talablari', 'rq_php' => 'PHP 8.0 yoki yangiroq (bu serverda: %s)', 'rq_ext' => 'PHP kengaytmasi: %s',
        'rq_root' => 'Loyiha papkasi yozish uchun ochiq (config.php yaratish uchun)', 'rq_logos' => 'public/uploads/logos yozish uchun ochiq (logotip yuklash)',
        'rq_curl' => 'PHP kengaytmasi: curl (faqat Stripe to‘lovlari uchun kerak)', 'rq_blocked' => '✗ bilan belgilangan bandlarni tuzating va sahifani yangilang — ular bajarilmaguncha o‘rnatish mumkin emas.',
    ],
];
/** Installer string in the chosen language (falls back to English). */
function it(string $key): string
{
    global $IT, $ilang;
    return $IT[$ilang][$key] ?? $IT['en'][$key] ?? $key;
}

// --- Server requirements ---------------------------------------------------
// [label, ok, hard]: a failed hard check stops the installer.
$checks = [
    [sprintf(it('rq_php'), PHP_VERSION), PHP_VERSION_ID >= 80000, true],
];
foreach (['pdo_mysql', 'mbstring', 'json', 'session', 'ctype'] as $ext) {
    $checks[] = [sprintf(it('rq_ext'), $ext), extension_loaded($ext), true];
}
$checks[] = [it('rq_root'), is_writable($root), true];
$checks[] = [it('rq_logos'), is_dir($root . '/public/uploads/logos') && is_writable($root . '/public/uploads/logos'), false];
$checks[] = [it('rq_curl'), extension_loaded('curl'), false];
$blocked = false;
foreach ($checks as $c) {
    if (!$c[1] && $c[2]) { $blocked = true; }
}

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

if (!$alreadyInstalled && !$blocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate.
    if (trim($in['admin_name']) === '') { $errors[] = it('e_name'); }
    if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) { $errors[] = it('e_email'); }
    if (strlen($in['admin_pass']) < 8) { $errors[] = it('e_pass'); }
    if (!is_file($schemaFile)) { $errors[] = it('e_schema'); }

    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $in['db_name']);
    if ($dbName === '') { $errors[] = it('e_dbname'); }

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
            // The chosen installer language becomes the site's default language.
            $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['default_lang', $ilang]);

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
                $errors[] = it('e_config');
            } else {
                $done = true;
            }
        } catch (PDOException $e) {
            $errors[] = it('e_db') . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------- UI
?><!DOCTYPE html>
<html lang="<?= $ilang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(it('title')) ?></title>
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
        .req{list-style:none;padding:0;margin:8px 0 0;font-size:.9rem}.req li{padding:2px 0}
        .req .y{color:#0f9d58}.req .n{color:#d64545;font-weight:600}.req .w{color:#b7791f}
        .langs{float:right;font-size:.85rem}.langs a{color:#6b7691;text-decoration:none;margin-left:8px}.langs a.on{color:#4f46e5;font-weight:700}
    </style>
</head>
<body>
<div class="box">
    <div class="langs"><?php foreach ($INSTALL_LANGS as $lc => $ll): ?><a href="?lang=<?= $lc ?>" class="<?= $lc === $ilang ? 'on' : '' ?>"><?= $ll ?></a><?php endforeach; ?></div>
    <h1><?= htmlspecialchars(it('title')) ?></h1>
    <?php if ($alreadyInstalled): ?>
        <div class="ok"><?= htmlspecialchars(it('installed')) ?></div>
        <p class="muted"><?= it('installed_hint') ?></p>
        <a class="btn" href="../index.php" style="text-align:center;text-decoration:none"><?= htmlspecialchars(it('open')) ?></a>
    <?php elseif ($done): ?>
        <div class="ok"><?= htmlspecialchars(it('complete')) ?></div>
        <p class="muted"><?= it('complete_hint') ?></p>
        <a class="btn" href="../login.php" style="text-align:center;text-decoration:none"><?= htmlspecialchars(it('to_login')) ?></a>
    <?php else: ?>
        <p class="muted"><?= htmlspecialchars(it('intro')) ?></p>
        <h2><?= htmlspecialchars(it('h_req')) ?></h2>
        <ul class="req">
            <?php foreach ($checks as [$label, $ok, $hard]): ?>
                <li class="<?= $ok ? 'y' : ($hard ? 'n' : 'w') ?>"><?= $ok ? '✓' : ($hard ? '✗' : '!') ?> <?= htmlspecialchars($label) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($blocked): ?><div class="err"><?= htmlspecialchars(it('rq_blocked')) ?></div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
        <?php if (!$blocked): ?>
        <form method="post">
            <input type="hidden" name="lang" value="<?= $ilang ?>">
            <h2><?= htmlspecialchars(it('h_db')) ?></h2>
            <div class="row">
                <div><label><?= htmlspecialchars(it('host')) ?></label><input name="db_host" value="<?= htmlspecialchars($in['db_host']) ?>"></div>
                <div style="max-width:110px"><label><?= htmlspecialchars(it('port')) ?></label><input name="db_port" value="<?= htmlspecialchars($in['db_port']) ?>"></div>
            </div>
            <label><?= htmlspecialchars(it('db_name')) ?></label><input name="db_name" value="<?= htmlspecialchars($in['db_name']) ?>">
            <div class="row">
                <div><label><?= htmlspecialchars(it('db_user')) ?></label><input name="db_user" value="<?= htmlspecialchars($in['db_user']) ?>"></div>
                <div><label><?= htmlspecialchars(it('password')) ?></label><input type="password" name="db_pass" value="<?= htmlspecialchars($in['db_pass']) ?>"></div>
            </div>

            <h2><?= htmlspecialchars(it('h_site')) ?></h2>
            <label><?= htmlspecialchars(it('base_url')) ?></label><input name="base_url" value="<?= htmlspecialchars($in['base_url']) ?>">
            <label><?= htmlspecialchars(it('site_name')) ?></label><input name="app_name" value="<?= htmlspecialchars($in['app_name']) ?>">
            <label><?= htmlspecialchars(it('tz')) ?></label>
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

            <h2><?= htmlspecialchars(it('h_admin')) ?></h2>
            <label><?= htmlspecialchars(it('name')) ?></label><input name="admin_name" value="<?= htmlspecialchars($in['admin_name']) ?>">
            <label><?= htmlspecialchars(it('email')) ?></label><input type="email" name="admin_email" value="<?= htmlspecialchars($in['admin_email']) ?>">
            <label><?= htmlspecialchars(it('password')) ?></label><input type="password" name="admin_pass">

            <button class="btn" type="submit"><?= htmlspecialchars(it('install')) ?></button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
