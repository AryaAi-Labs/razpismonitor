<?php
/**
 * POST /api/import.php
 * Webhook — sprejme razpise od GitHub Actions scraperja in jih shrani v MySQL.
 * Zavarovan s skrivnim ključem (IMPORT_SECRET).
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

// ── DB direktno ───────────────────────────────────────────────────
$DB_HOST = 'localhost';
$DB_NAME = 'u476516023_razpisi';
$DB_USER = 'u476516023_razpisi';
$DB_PASS = 'TVOJE_GESLO';  // ← zamenjaj

// ── Import secret (mora biti enak kot v GitHub Secrets) ───────────
define('IMPORT_SECRET', 'TVOJ_IMPORT_SECRET');  // ← zamenjaj z naključnim nizom

// ── Samo POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ── Preveri secret ────────────────────────────────────────────────
if (($input['secret'] ?? '') !== IMPORT_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$razpisi = $input['razpisi'] ?? [];
if (!is_array($razpisi)) {
    http_response_code(400);
    echo json_encode(['error' => 'razpisi mora biti array']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4',
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB: ' . $e->getMessage()]);
    exit;
}

// ── Shrani razpise ────────────────────────────────────────────────
function toDate($d) {
    if (!$d) return null;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($d), $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    $ts = strtotime($d);
    return ($ts && $ts > 0) ? date('Y-m-d', $ts) : null;
}

$saved   = 0;
$skipped = 0;
$errors  = [];

foreach ($razpisi as $r) {
    $extId = trim($r['external_id'] ?? '');
    if (!$extId) { $errors[] = 'Prazni external_id'; continue; }

    try {
        // Preveri duplicate
        $st = $pdo->prepare("SELECT id FROM razpisi WHERE external_id = ?");
        $st->execute([$extId]);
        if ($st->fetch()) { $skipped++; continue; }

        // Vstavi
        $pdo->prepare(
            "INSERT INTO razpisi
                (external_id, vir, naslov, narocnik, vrednost, vrednost_eur,
                 rok_za_oddajo, datum_objave, cpv_kode, status, link, datum_zaznave)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,CURDATE())"
        )->execute([
            $extId,
            $r['vir']           ?? 'e-JN',
            $r['naslov']        ?? 'Brez naslova',
            $r['narocnik']      ?? null,
            $r['vrednost']      ?? null,
            $r['vrednost_eur']  ?? null,
            toDate($r['rok_za_oddajo'] ?? null),
            toDate($r['datum_objave']  ?? null),
            $r['cpv_kode']      ?? '44315400-1',
            $r['status']        ?? 'odprt',
            $r['link']          ?? null,
        ]);
        $saved++;
    } catch (Throwable $e) {
        $errors[] = $extId . ': ' . $e->getMessage();
    }
}

// ── Označi potekle ────────────────────────────────────────────────
try {
    $pdo->exec("UPDATE razpisi SET status='potekel' WHERE rok_za_oddajo < CURDATE() AND status='odprt'");
} catch (Throwable $e) {}

echo json_encode([
    'ok'      => true,
    'saved'   => $saved,
    'skipped' => $skipped,
    'total'   => count($razpisi),
    'errors'  => $errors,
], JSON_UNESCAPED_UNICODE);
