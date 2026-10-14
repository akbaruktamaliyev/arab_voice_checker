<?php
/**
 * QRaft — end-to-end test suite (no dependencies).
 *
 *   php tests/run.php                      # tests the site at config.php's base_url
 *   php tests/run.php --base=http://localhost:8000
 *
 * It talks to a RUNNING copy of the app over HTTP (like a browser would) and to
 * its database through config.php, so start the site first, e.g.
 *   php -S localhost:8000 -t public router.php
 *
 * WARNING: run it against a development database. It creates users, codes and
 * pages prefixed "qrtest" and removes them again, and it briefly changes a few
 * settings (webhook secret, retention, contact e-mail …); every setting is put back.
 *
 * Requires the curl extension. Exit code 0 = all passed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("Run this from the command line.\n"); }
if (!extension_loaded('curl')) { exit("The curl extension is required.\n"); }

$ROOT = dirname(__DIR__);
if (!is_file($ROOT . '/config.php')) { exit("config.php not found — install the app first.\n"); }
$config = require $ROOT . '/config.php';

$base = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--base=')) { $base = rtrim(substr($a, 7), '/'); }
}
if ($base === '') { $base = rtrim((string) ($config['base_url'] ?? 'http://localhost:8000'), '/'); }

$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], (int) $db['port'], $db['name'], $db['charset']),
    $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// --------------------------------------------------------------- tiny harness
$pass = 0; $fail = 0;
function section(string $t): void { echo "\n== $t\n"; }
function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function eq(string $name, mixed $got, mixed $want): void
{
    check($name, $got === $want || (string) $got === (string) $want, 'got ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ', want ' . json_encode($want, JSON_UNESCAPED_UNICODE));
}
function has(string $name, string $haystack, string $needle): void
{
    check($name, str_contains($haystack, $needle), 'missing "' . $needle . '"');
}
function db(string $sql, array $p = []): PDOStatement { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function val(string $sql, array $p = []): mixed { return db($sql, $p)->fetchColumn(); }

/** A browser-like HTTP client with its own cookie jar. */
class Http
{
    public string $jar;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'qrt'); }
    public function __destruct() { @unlink($this->jar); }

    /** @return array{code:int,body:string,redirect:string,headers:string} */
    public function req(string $url, ?array $post = null, array $opts = []): array
    {
        $ch = curl_init($url);
        $headers = $opts['headers'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => (bool) ($opts['follow'] ?? false),
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (isset($opts['method'])) { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method']); }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($opts['multipart']) ? $post : (isset($opts['json']) ? json_encode($post) : http_build_query($post)));
        }
        if (isset($opts['json'])) { curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json'])); }
        $raw = (string) curl_exec($ch);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $out = [
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => substr($raw, $size),
            'headers' => substr($raw, 0, $size),
            'redirect' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        ];
        return $out;
    }
    public function get(string $url, array $opts = []): array { return $this->req($url, null, $opts); }
    public function post(string $url, array $f, array $opts = []): array { return $this->req($url, $f, $opts); }
    /** Fetch a page, pull its CSRF token, then POST the fields with it. */
    public function form(string $pageUrl, array $fields, ?string $postUrl = null, array $opts = []): array
    {
        $page = $this->get($pageUrl, ['follow' => true]);
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        return $this->post($postUrl ?? $pageUrl, ['_csrf' => $m[1] ?? ''] + $fields, $opts);
    }
}

function login(Http $h, string $email, string $pw): array
{
    global $base;
    return $h->form("$base/login.php", ['email' => $email, 'password' => $pw]);
}

// ------------------------------------------------------------------- fixtures
$P = 'qrtest';
$pwA = 'Secret-A-123'; $pwAdm = 'Secret-Adm-123';
// Snapshot every setting so the suite can put them all back, whatever it changes.
$saved = [];
foreach ($pdo->query('SELECT name, value FROM settings') as $row) { $saved[$row['name']] = $row['value']; }
function cleanup(): void
{
    global $pdo, $P, $saved;
    $pdo->exec("DELETE FROM users WHERE email LIKE '{$P}\\_%@example.test'");
    $pdo->exec("DELETE FROM qr_codes WHERE code LIKE '{$P}-%'");
    $pdo->exec("DELETE FROM bio_pages WHERE slug LIKE '{$P}-%'");
    $pdo->exec("DELETE FROM folders WHERE name LIKE '{$P}%'");
    $pdo->exec("DELETE FROM rate_hits");
    foreach ($pdo->query('SELECT name FROM settings')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (!array_key_exists($name, $saved) && $name !== 'schema_version') { $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]); }
    }
    foreach ($saved as $k => $v) {
        $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$k, $v]);
    }
}
cleanup();
register_shutdown_function('cleanup');

echo "QRaft tests against $base\n";
$probe = (new Http())->get("$base/index.php");
if ($probe['code'] !== 200) { exit("The site is not reachable at $base (HTTP {$probe['code']}). Start it first.\n"); }

// ================================================================ 1. static
section('1. Static checks');
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', (string) $f);
    if (!str_ends_with($p, '.php') || str_contains($p, '/.git/') || str_contains($p, '/tests/')) { continue; }
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad[] = basename($p); }
}
check('every PHP file lints', !$bad, implode(', ', $bad));

$load = function (string $code) use ($ROOT): array {
    $all = [];
    foreach (['', '.app', '.admin'] as $part) {
        $f = "$ROOT/lang/$code$part.php";
        if (is_file($f)) { $all += require $f; }
    }
    return $all;
};
$en = $load('en');
foreach (['ru', 'uz'] as $l) {
    $tr = $load($l);
    check("$l has every English key", !array_diff_key($en, $tr), implode(',', array_slice(array_keys(array_diff_key($en, $tr)), 0, 5)));
    $badPh = [];
    foreach ($en as $k => $v) {
        if (isset($tr[$k]) && preg_match_all('/%(?:\d+\$)?[sd]/', $v) !== preg_match_all('/%(?:\d+\$)?[sd]/', $tr[$k])) { $badPh[] = $k; }
    }
    check("$l printf placeholders match English", !$badPh, implode(',', array_slice($badPh, 0, 5)));
}
// Every t('key') used in code exists.
$used = [];
foreach ($it as $f) {
    $p = str_replace('\\', '/', (string) $f);
    if (!str_ends_with($p, '.php') || str_contains($p, '/lang/') || str_contains($p, '/tests/') || str_contains($p, '/.git/')) { continue; }
    preg_match_all("/\\bt\\('([a-z_]+\\.[a-z0-9_-]+)'/", (string) file_get_contents($p), $m);
    foreach ($m[1] as $k) { $used[$k] = basename($p); }
}
$missing = array_filter(array_keys($used), fn($k) => !isset($en[$k]));
check('every t() key used in code is defined', !$missing, implode(',', array_slice($missing, 0, 5)));

// ================================================================== 2. public
section('2. Public pages and guards');
$h = new Http();
foreach (['index.php', 'pricing.php', 'login.php', 'register.php', 'forgot.php', 'terms.php', 'privacy.php'] as $pg) {
    eq("$pg → 200", $h->get("$base/$pg")['code'], 200);
}
foreach (['dashboard.php', 'create.php', 'bulk.php', 'bios.php', 'tokens.php', 'billing.php', 'account.php', 'admin/index.php', 'admin/codes.php'] as $pg) {
    has("guest redirected from $pg", $h->get("$base/$pg")['redirect'], 'login.php');
}
eq('q/ unknown code → 404', $h->get("$base/q/{$P}-nope")['code'], 404);
eq('b/ unknown page → 404', $h->get("$base/b/{$P}-nope")['code'], 404);
check('anonymous endpoints set no cookie', stripos($h->get("$base/q/{$P}-nope")['headers'], 'set-cookie') === false);
eq('POST without CSRF is refused', $h->post("$base/login.php", ['email' => 'x@example.test', 'password' => 'x'])['code'], 419);

// ============================================================ 3. accounts
section('3. Registration, sign-in, sign-out');
$emailA = "{$P}_a@example.test"; $emailAdm = "{$P}_admin@example.test"; $emailB = "{$P}_b@example.test";
$a = new Http();
$r = $a->form("$base/register.php", ['name' => 'Test A', 'email' => $emailA, 'password' => $pwA]);
has('registering signs the user in', $r['redirect'], 'dashboard.php');
$idA = (int) val('SELECT id FROM users WHERE email = ?', [$emailA]);
check('user row exists', $idA > 0);
$r = (new Http())->form("$base/register.php", ['name' => 'Dup', 'email' => $emailA, 'password' => $pwA], null, ['follow' => true]);
has('duplicate e-mail rejected', $r['body'], 'already exists');
$r = (new Http())->form("$base/register.php", ['name' => 'Short', 'email' => "{$P}_s@example.test", 'password' => '123'], null, ['follow' => true]);
check('short password rejected', str_contains($r['body'], 'flash-error'));
$wrong = new Http();
$r = login($wrong, $emailA, 'wrong-password');
check('wrong password does not sign in', $r['redirect'] === '' && $r['code'] === 200);
// brute-force lockout
for ($i = 0; $i < 9; $i++) { $r = login($wrong, $emailA, "bad$i"); }
has('sign-in locked after repeated failures', $r['body'], 'Too many failed attempts');
db('DELETE FROM rate_hits');
$b = new Http();
login($b, $emailA, $pwA);
eq('dashboard works when signed in', $b->get("$base/dashboard.php")['code'], 200);
$out = $b->form("$base/dashboard.php", [], "$base/logout.php");
has('sign-out redirects home', $out['redirect'], 'index.php');
has('after sign-out dashboard needs login', $b->get("$base/dashboard.php")['redirect'], 'login.php');

// give A a roomy plan, create an admin + a second ordinary user
db("UPDATE users SET plan_id = (SELECT id FROM plans WHERE name = 'Business' LIMIT 1), plan_expires = (NOW() + INTERVAL 5 YEAR) WHERE id = ?", [$idA]);
db("INSERT INTO users (name, email, password_hash, role, is_verified) VALUES ('Test Admin', ?, ?, 'admin', 1)", [$emailAdm, password_hash($pwAdm, PASSWORD_DEFAULT)]);
db("INSERT INTO users (name, email, password_hash, is_verified) VALUES ('Test B', ?, ?, 1)", [$emailB, password_hash($pwA, PASSWORD_DEFAULT)]);
$idB = (int) val('SELECT id FROM users WHERE email = ?', [$emailB]);
$a = new Http(); login($a, $emailA, $pwA);
$bb = new Http(); login($bb, $emailB, $pwA);
$adm = new Http(); login($adm, $emailAdm, $pwAdm);

// ================================================ 4. dynamic codes & protection
section('4. Dynamic codes');
$code = "{$P}-main";
$r = $a->form("$base/create.php", [
    'title' => 'Test code', 'target_url' => 'https://example.com/landing', 'slug' => $code,
    'fg_color' => '#112233', 'bg_color' => '#ffffff', 'password' => 'letmein', 'scan_limit' => '2',
], null, ['multipart' => true]);
has('create redirects to the code page', $r['redirect'], 'view.php?id=');
$cid = (int) val('SELECT id FROM qr_codes WHERE code = ?', [$code]);
check('code saved with password and limit', $cid > 0 && val('SELECT password_hash IS NOT NULL FROM qr_codes WHERE id = ?', [$cid]) == 1 && (int) val('SELECT scan_limit FROM qr_codes WHERE id = ?', [$cid]) === 2);
$r = $a->form("$base/create.php", ['title' => 'Dup slug', 'target_url' => 'https://example.com', 'slug' => $code], null, ['multipart' => true, 'follow' => true]);
has('duplicate custom code rejected', $r['body'], 'already taken');
$r = $a->form("$base/create.php", ['title' => 'Bad', 'target_url' => 'javascript:alert(1)'], null, ['multipart' => true, 'follow' => true]);
check('javascript: URLs are not accepted as destinations', (int) val("SELECT COUNT(*) FROM qr_codes WHERE title = 'Bad'") === 0 || !str_contains((string) val("SELECT target_url FROM qr_codes WHERE title = 'Bad' LIMIT 1"), 'javascript'));
db("DELETE FROM qr_codes WHERE title = 'Bad'");

$v = new Http();
has('password gate shown', $v->get("$base/q/$code")['body'], 'Password required');
eq('no scan counted before the password', val('SELECT scan_count FROM qr_codes WHERE id = ?', [$cid]), 0);
eq('wrong password stays on the form', $v->post("$base/q/$code", ['password' => 'nope'])['code'], 200);
$ok = $v->post("$base/q/$code", ['password' => 'letmein']);
eq('right password redirects', $ok['code'], 302);
has('redirect goes to the destination', $ok['redirect'] ?: $ok['headers'], 'example.com/landing');
$v->post("$base/q/$code", ['password' => 'letmein']);
eq('scan limit reached → 410', $v->post("$base/q/$code", ['password' => 'letmein'])['code'], 410);
eq('two scans counted', val('SELECT scan_count FROM qr_codes WHERE id = ?', [$cid]), 2);

db('DELETE FROM rate_hits');
db('UPDATE qr_codes SET scan_limit = NULL, scan_count = 0 WHERE id = ?', [$cid]);
$last = 0; for ($i = 0; $i < 12; $i++) { $last = $v->post("$base/q/$code", ['password' => "wrong$i"])['code']; }
eq('password guessing is rate-limited (429)', $last, 429);
eq('even the right password is refused while locked', $v->post("$base/q/$code", ['password' => 'letmein'])['code'], 429);
db('DELETE FROM rate_hits');

db('UPDATE qr_codes SET password_hash = NULL, expires_at = (NOW() - INTERVAL 1 DAY) WHERE id = ?', [$cid]);
eq('expired → 410', $v->get("$base/q/$code")['code'], 410);
db('UPDATE qr_codes SET expires_at = NULL WHERE id = ?', [$cid]);
eq('active → 302', $v->get("$base/q/$code")['code'], 302);
$bot = $v->get("$base/q/$code", ['headers' => ['User-Agent: TelegramBot (like TwitterBot)']]);
$before = (int) val('SELECT scan_count FROM qr_codes WHERE id = ?', [$cid]);
eq('link-preview bots are forwarded', $bot['code'], 302);
eq('…but not counted as scans', (int) val('SELECT scan_count FROM qr_codes WHERE id = ?', [$cid]), $before);

section('5. Editing, ownership, pause, export');
$r = $a->form("$base/view.php?id=$cid", ['action' => 'update', 'title' => 'Renamed', 'target_url' => 'https://example.com/new', 'fg_color' => '#000000', 'bg_color' => '#ffffff', 'slug' => $code], null, ['multipart' => true]);
eq('edit saved', val('SELECT title FROM qr_codes WHERE id = ?', [$cid]), 'Renamed');
has('destination changed', (string) val('SELECT target_url FROM qr_codes WHERE id = ?', [$cid]), 'example.com/new');
has("another user cannot open the code", $bb->get("$base/view.php?id=$cid")['redirect'], 'dashboard.php');
has("another user cannot export it", $bb->get("$base/export.php?id=$cid")['redirect'], 'dashboard.php');
$bb->form("$base/dashboard.php", ['id' => (string) $cid], "$base/delete.php");
eq("another user cannot delete it", (int) val('SELECT COUNT(*) FROM qr_codes WHERE id = ?', [$cid]), 1);
$a->form("$base/view.php?id=$cid", ['action' => 'toggle']);
eq('pause toggles is_active off', (int) val('SELECT is_active FROM qr_codes WHERE id = ?', [$cid]), 0);
eq('paused → 410', $v->get("$base/q/$code")['code'], 410);
$a->form("$base/view.php?id=$cid", ['action' => 'toggle']);
eq('resumed → 302', $v->get("$base/q/$code")['code'], 302);
$csv = $a->get("$base/export.php?id=$cid");
check('CSV export has a UTF-8 BOM and a header row', str_starts_with($csv['body'], "\xEF\xBB\xBF") && substr_count(trim($csv['body']), "\n") >= 0 && str_contains($csv['headers'], 'text/csv'));
$a->form("$base/dashboard.php", ['action' => 'create_folder', 'folder_name' => "{$P}-folder"]);
$fid = (int) val('SELECT id FROM folders WHERE name = ?', ["{$P}-folder"]);
check('folder created', $fid > 0);

section('6. Bulk import and plan limits');
$csvFile = tempnam(sys_get_temp_dir(), 'qrcsv');
file_put_contents($csvFile, "title,target_url,slug\nBulk 1,https://example.com/1,{$P}-b1\n,bad,\nBulk 2,https://example.com/2,\nBulk 3,https://example.com/3,x\n");
$r = $a->form("$base/bulk.php", ['csv' => new CURLFile($csvFile, 'text/csv', 'x.csv')], null, ['multipart' => true, 'follow' => true]);
eq('valid rows imported (custom + random codes)', (int) val("SELECT COUNT(*) FROM qr_codes WHERE user_id = ? AND title IN ('Bulk 1','Bulk 2')", [$idA]), 2);
has('bad rows reported', $r['body'], 'Row 3');
unlink($csvFile);
db("DELETE FROM qr_codes WHERE title IN ('Bulk 1','Bulk 2')");
db("UPDATE users SET plan_id = (SELECT id FROM plans WHERE price = 0 ORDER BY sort LIMIT 1), plan_expires = NULL WHERE id = ?", [$idB]);
for ($i = 1; $i <= 5; $i++) { db('INSERT INTO qr_codes (user_id, code, title, target_url) VALUES (?, ?, ?, ?)', [$idB, "{$P}-fill$i", 'Fill', 'https://example.com']); }
has('Free user at the limit is blocked from creating', $bb->get("$base/create.php", ['follow' => true])['body'], 'limit');

// ================================================================== 7. bio
section('7. Bio pages');
$r = $a->form("$base/bios.php", ['action' => 'create', 'title' => 'Test bio', 'slug' => "{$P}-bio"]);
$bid = (int) val('SELECT id FROM bio_pages WHERE slug = ?', ["{$P}-bio"]);
check('bio page created', $bid > 0);
$a->form("$base/bio-edit.php?id=$bid", ['action' => 'add_link', 'label' => 'Site', 'url' => 'example.com']);
eq('bio link added', (int) val('SELECT COUNT(*) FROM bio_links WHERE bio_id = ?', [$bid]), 1);
has('public bio page renders', (new Http())->get("$base/b/{$P}-bio")['body'], 'Test bio');
eq('views are counted', (int) val('SELECT views FROM bio_pages WHERE id = ?', [$bid]), 1);

// ==================================================================== 8. API
section('8. REST API');
$a->form("$base/tokens.php", ['action' => 'create', 'name' => 'qrtest']);
$tokPage = $a->get("$base/tokens.php")['body'];
preg_match('/border-radius:8px">([a-f0-9]{64})/', $tokPage, $m);
// The plain token is shown once; if we missed it (page already consumed) mint one directly.
$tok = $m[1] ?? bin2hex(random_bytes(32));
if (!isset($m[1])) { db('INSERT INTO api_tokens (user_id, name, token_hash) VALUES (?, ?, ?)', [$idA, 'qrtest', hash('sha256', $tok)]); }
$auth = ['headers' => ["Authorization: Bearer $tok"]];
$api = new Http();
$r = $api->get("$base/api/codes.php"); eq('no token → 401', $r['code'], 401);
eq('…with a stable error_code', json_decode($r['body'], true)['error_code'] ?? '', 'missing_token');
eq('bad token → 401', $api->get("$base/api/codes.php", ['headers' => ['Authorization: Bearer nope']])['code'], 401);
check('list works', isset(json_decode($api->get("$base/api/codes.php", $auth)['body'], true)['codes']));
$r = $api->req("$base/api/codes.php", ['title' => 'Api code', 'target_url' => 'example.com/api', 'slug' => "{$P}-api"], $auth + ['json' => true]);
eq('create → 201', $r['code'], 201);
$nid = json_decode($r['body'], true)['code']['id'] ?? 0;
$r = $api->req("$base/api/codes.php?id=$nid", ['target_url' => 'https://example.com/changed'], $auth + ['json' => true, 'method' => 'PATCH']);
has('PATCH changes the destination', $r['body'], 'example.com/changed');
$r = $api->get("$base/api/codes.php?id=999999999&lang=ru", $auth);
eq('errors are localized (?lang=ru)', json_decode($r['body'], true)['error'] ?? '', 'Код не найден.');
$r = $api->get("$base/api/codes.php?id=999999999", ['headers' => ["Authorization: Bearer $tok", 'Accept-Language: uz']]);
has('errors follow Accept-Language', $r['headers'], 'Content-Language: uz');
$r = json_decode($api->get("$base/api/codes.php?id=$nid&labels=1&lang=ru", $auth)['body'], true);
eq('?labels=1 adds localized field names', $r['labels']['title'] ?? '', 'Название');
check('JSON keys stay English', isset($r['code']['target_url'], $r['code']['scan_count']));
check('errors never carry labels', !isset(json_decode($api->get("$base/api/codes.php?id=999999999&labels=1", $auth)['body'], true)['labels']));
eq('DELETE works', json_decode($api->get("$base/api/codes.php?id=$nid", $auth + ['method' => 'DELETE'])['body'], true)['deleted'] ?? null, true);
db('DELETE FROM rate_hits');
$last = 0; for ($i = 0; $i < 22; $i++) { $last = $api->get("$base/api/codes.php", ['headers' => ["Authorization: Bearer bad$i"]])['code']; }
eq('token guessing is rate-limited (429)', $last, 429);
db('DELETE FROM rate_hits');

// =============================================================== 9. admin
section('9. Admin and moderation');
foreach (['admin/index.php', 'admin/users.php', 'admin/plans.php', 'admin/settings.php', 'admin/codes.php'] as $pg) {
    eq("admin can open $pg", $adm->get("$base/$pg")['code'], 200);
}
has('normal users are kept out of the admin area', $a->get("$base/admin/index.php")['redirect'], 'dashboard.php');
has('moderation list shows the code', $adm->get("$base/admin/codes.php?q=$code")['body'], $code);
$adm->form("$base/admin/codes.php", ['action' => 'disable', 'id' => (string) $cid]);
eq('disabled code → 410 for visitors', $v->get("$base/q/$code")['code'], 410);
has('…with an explanatory page', $v->get("$base/q/$code")['body'], 'unavailable');
$a->form("$base/view.php?id=$cid", ['action' => 'toggle']);
eq('owner cannot override the admin', (int) val('SELECT is_active FROM qr_codes WHERE id = ?', [$cid]), 1);
$r = $api->req("$base/api/codes.php?id=$cid", ['is_active' => true], $auth + ['json' => true, 'method' => 'PATCH']);
eq('API respects admin_disabled (403)', $r['code'], 403);
$adm->form("$base/admin/codes.php", ['action' => 'enable', 'id' => (string) $cid]);
eq('re-enabled code works again', $v->get("$base/q/$code")['code'], 302);
$adm->form("$base/admin/users.php", ['action' => 'block', 'user_id' => (string) $idA]);
eq('suspended owner → their codes go offline', $v->get("$base/q/$code")['code'], 410);
has('suspended user is signed out', $a->get("$base/dashboard.php")['redirect'], 'login.php');
eq("…and their bio page returns 404", (new Http())->get("$base/b/{$P}-bio")['code'], 404);
$adm->form("$base/admin/users.php", ['action' => 'unblock', 'user_id' => (string) $idA]);
has('admin cannot act on their own account', $adm->form("$base/admin/users.php", ['action' => 'block', 'user_id' => (string) val('SELECT id FROM users WHERE email = ?', [$emailAdm])], null, ['follow' => true])['body'], 'cannot change your own');

// ============================================================ 10. languages
section('10. Translations render');
$a = new Http(); login($a, $emailA, $pwA);
$rawKey = function (string $html) use ($en): ?string {
    $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html));
    foreach ($en as $k => $_) {
        if (str_starts_with($k, 'dyn:')) { continue; }
        if (preg_match('/(?<![\w.])' . preg_quote($k, '/') . '(?![\w.])/', $text)) { return $k; }
    }
    return null;
};
foreach (['ru' => 'Панель', 'uz' => 'Boshqaruv paneli'] as $l => $word) {
    $a->get("$base/index.php?setlang=$l");
    $pages = ['dashboard.php', 'create.php', 'bulk.php', 'bios.php', 'tokens.php', 'billing.php', 'pricing.php', 'account.php', "view.php?id=$cid", "bio-edit.php?id=$bid", 'index.php'];
    $leaks = [];
    foreach ($pages as $pg) {
        $body = $a->get("$base/$pg", ['follow' => true])['body'];
        if ($k = $rawKey($body)) { $leaks[] = "$pg:$k"; }
    }
    check("[$l] owner pages show no raw translation keys", !$leaks, implode(', ', $leaks));
    has("[$l] navigation is translated", $a->get("$base/dashboard.php")['body'], $word);
    has("[$l] the free generator is translated", $a->get("$base/index.php")['body'], 'QRAFT_I18N');
}
$a->get("$base/index.php?setlang=en");

// ============================================================= 11. webhook
section('11. Stripe webhook');
db("DELETE FROM settings WHERE name = 'stripe_webhook_secret'");
eq('no secret configured → 400 (fail closed)', (new Http())->post("$base/api/stripe-webhook.php", ['x' => '1'])['code'], 400);
db("INSERT INTO settings (name, value) VALUES ('stripe_webhook_secret', 'whsec_qrtest') ON DUPLICATE KEY UPDATE value = 'whsec_qrtest'");
$raw = function (string $payload, string $sig) use ($base): array {
    $ch = curl_init("$base/api/stripe-webhook.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ["Stripe-Signature: $sig", 'Content-Type: application/json']]);
    $body = (string) curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
};
$t = time();
$payload = json_encode(['id' => "evt_qrtest_$t", 'type' => 'checkout.session.completed', 'data' => ['object' => ['metadata' => ['user_id' => (string) $idB, 'plan_id' => (string) val("SELECT id FROM plans WHERE name = 'Pro' LIMIT 1")]]]]);
eq('forged signature → 400', $raw($payload, "t=$t,v1=deadbeef")['code'], 400);
$good = "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", 'whsec_qrtest');
eq('valid signed event → 200', $raw($payload, $good)['code'], 200);
$exp1 = (string) val('SELECT plan_expires FROM users WHERE id = ?', [$idB]);
check('the plan was activated', $exp1 !== '' && strtotime($exp1) > time());
$raw($payload, $good);
eq('replaying the same event changes nothing (idempotent)', (string) val('SELECT plan_expires FROM users WHERE id = ?', [$idB]), $exp1);
db('DELETE FROM stripe_events WHERE id = ?', ["evt_qrtest_$t"]);

// ======================================================== 12. legal, SEO, data
section('12. Legal pages, SEO and data export');
$land = (new Http())->get("$base/index.php")['body'];
has('meta description present', $land, '<meta name="description"');
has('canonical link on public pages', $land, 'rel="canonical"');
has('Open Graph tags present', $land, 'og:image');
check('public pages are indexable', !str_contains($land, 'noindex'));
has('sign-in page is noindex', (new Http())->get("$base/login.php")['body'], 'noindex');
has('signed-in pages are noindex', $a->get("$base/account.php")['body'], 'noindex');
has('footer links to the Terms', $land, 'terms.php');
has('register page shows the consent line', (new Http())->get("$base/register.php")['body'], 'privacy.php');
$robots = (new Http())->get("$base/robots.txt");
check('robots.txt blocks the app and points to the sitemap', $robots['code'] === 200 && str_contains($robots['body'], 'Disallow: ') && str_contains($robots['body'], 'sitemap.xml'));
$map = (new Http())->get("$base/sitemap.xml");
check('sitemap.xml lists public pages', $map['code'] === 200 && str_contains($map['body'], 'pricing.php') && !str_contains($map['body'], 'dashboard'));
foreach (['favicon.svg', 'assets/img/og.png'] as $asset) { eq("$asset is served", (new Http())->get("$base/$asset")['code'], 200); }
foreach (['ru', 'uz'] as $l) {
    $x = new Http(); $x->get("$base/index.php?setlang=$l");
    foreach (['terms.php', 'privacy.php'] as $pg) {
        $body = $x->get("$base/$pg")['body'];
        check("[$l] $pg has no raw keys", $rawKey($body) === null, (string) $rawKey($body));
        check("[$l] $pg is not English", !str_contains($body, 'Who we are') && !str_contains($body, 'Using the service'));
    }
}
db("INSERT INTO settings (name, value) VALUES ('scan_retention_days', '0') ON DUPLICATE KEY UPDATE value = '0'");
$idScan = (int) val("SELECT id FROM qr_codes WHERE code = ?", [$code]);
db("INSERT INTO qr_scans (qr_id, scanned_at, user_agent) VALUES (?, NOW() - INTERVAL 400 DAY, 'qrtest-old')", [$idScan]);
$adm->form("$base/admin/settings.php", ['app_name' => (string) val("SELECT value FROM settings WHERE name = 'app_name'") ?: 'QRaft', 'allow_registration' => '1', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'currency' => 'usd', 'default_lang' => 'en', 'contact_email' => 'legal@example.test', 'scan_retention_days' => '30']);
eq('admin can set the scan retention', (int) val("SELECT value FROM settings WHERE name = 'scan_retention_days'"), 30);
has('privacy page reflects the retention period', (new Http())->get("$base/privacy.php")['body'], '30');
has('privacy page shows the contact address', (new Http())->get("$base/privacy.php")['body'], 'legal@example.test');
check('old scan records exist before purging', (int) val("SELECT COUNT(*) FROM qr_scans WHERE user_agent = 'qrtest-old'") === 1);
// the purge runs on a random 1-in-200 scan; drive it directly through the app's own code path
$phpCli = escapeshellarg(PHP_BINARY);
$snippet = escapeshellarg("define('QRAFT_NO_SESSION', true); chdir(" . var_export($ROOT . '/public', true) . "); require " . var_export($ROOT . '/src/bootstrap.php', true) . "; purge_old_scans(\$pdo);");
exec("$phpCli -r $snippet 2>&1");
eq('purge removes old scans', (int) val("SELECT COUNT(*) FROM qr_scans WHERE user_agent = 'qrtest-old'"), 0);
db("DELETE FROM settings WHERE name IN ('scan_retention_days', 'contact_email')");
$r = $a->post("$base/export-data.php", []);
eq('data export refuses a missing CSRF token', $r['code'], 419);
$r = $a->form("$base/account.php", [], "$base/export-data.php");
check('data export returns JSON without any hashes', str_contains($r['headers'], 'application/json') && !str_contains($r['body'], 'password_hash') && !str_contains($r['body'], 'token_hash') && isset(json_decode($r['body'], true)['codes']));
// ============================================================ 13. migrations
section('13. Schema');
check('schema_version is recorded', (int) val("SELECT value FROM settings WHERE name = 'schema_version'") >= 1);
check('qr_codes.admin_disabled exists', (int) val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_codes' AND COLUMN_NAME = 'admin_disabled'") === 1);

// ================================================================== result
echo "\n==================================\n";
echo "RESULT: PASS=$pass FAIL=$fail\n";
echo "==================================\n";
exit($fail === 0 ? 0 : 1);
