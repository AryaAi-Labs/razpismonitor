<?php
// ── RazpisMonitor — konfiguracija ─────────────────────────────────────────────
// KOPIRAJ to datoteko v config.local.php in nastavi vrednosti.
// config.local.php je v .gitignore in se ne commitira.

// ── MySQL ──────────────────────────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'razpismonitor');
define('DB_USER', getenv('DB_USER') ?: 'razpismonitor_user');
define('DB_PASS', getenv('DB_PASS') ?: 'ZAMENJAJ_Z_GESLOM');
define('DB_CHARSET', 'utf8mb4');

// ── Claude API ─────────────────────────────────────────────────────────────────
define('CLAUDE_API_KEY',   getenv('CLAUDE_API_KEY') ?: 'ZAMENJAJ_Z_API_KLJUCEM');
define('CLAUDE_MODEL',     'claude-opus-4-6');
define('CLAUDE_MAX_TOKENS', 1024);

// ── Email obvestila ────────────────────────────────────────────────────────────
define('NOTIFY_EMAIL',   'tilen.burja@kovinocrom.si');
define('FROM_EMAIL',     'razpismonitor@razpismonitor.eu');
define('FROM_NAME',      'RazpisMonitor · Kovinocrom');

// ── Aplikacija ─────────────────────────────────────────────────────────────────
define('APP_NAME',    'RazpisMonitor');
define('APP_VERSION', '1.0.0');
define('APP_URL',     'https://razpismonitor.eu');
define('TIMEZONE',    'Europe/Ljubljana');

// ── Kovinocrom profil za AI analizo ────────────────────────────────────────────
define('KOVINOCROM_PROFIL', <<<'PROFIL'
Kovinocrom d.o.o. je slovensko podjetje, ki od leta 1980 proizvaja vijake, matice, podložke in vezne elemente.
Ključne kompetence:
- Standardni program: vijaki, matice, podložke, pritrdilni material (CPV 44315400-1 in sorodni)
- Lastna CNC/kavalna proizvodnja
- Zaloga ~2.000 ton pritrdilnega materiala
- ISO 9001 certifikat
- Izkušnje z javnimi naročili v gradbeništvu, industriji, infrastrukturi
- Prisotnost na trgu EU (možnost oddaje na TED razpise)
Kovinocrom NE pokriva: posebnih zaščitnih premazov (razen standardnih), HACCP dokumentacije za živila, varnostnih certifikatov NATO/vojaških standardov (brez predhodnega preverjanja).
PROFIL);

// ── CPV kode za scraping ────────────────────────────────────────────────────────
define('CPV_KODE', [
    '44315400-1',  // Pritrdilni material
    '44315300-0',  // Kovinska vlečena žica
    '44316000-2',  // Kovinarstvo
    '44532000-2',  // Vijaki
    '44533000-9',  // Matice
    '44531510-9',  // Šrafi
]);

define('KLJUCNE_BESEDE', [
    'vijaki', 'vijak', 'matice', 'matica',
    'podložke', 'pritrdilni material', 'vezni elementi',
    'fasteners', 'bolts', 'nuts', 'washers',
    'kovinski elementi', 'pritrdila',
]);

// ── Nalaganje lokalnih nastavitev — MORA BITI PRED define() klici ─────────────
// config.local.php uporablja spremenljivke ($db_host itd.), ne define()
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ── MySQL — uporabi vrednosti iz config.local.php če obstajajo ────────────────
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'razpismonitor');
if (!defined('DB_USER')) define('DB_USER', 'razpismonitor_user');
if (!defined('DB_PASS')) define('DB_PASS', 'ZAMENJAJ_Z_GESLOM');

// ── Timezone ───────────────────────────────────────────────────────────────────
date_default_timezone_set(TIMEZONE);

// ── DB helper ─────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── JSON response helper (void namesto never — kompatibilno s PHP 7.4+) ────────
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
