<?php
/**
 * GET /api/razpisi.php
 * Vrne JSON seznam razpisov z opcijskimi filtri.
 *
 * Query params:
 *   status   = odprt | zaprt | potekel
 *   vir      = e-JN | TED
 *   search   = string (išče v naslovu, naročniku, CPV)
 *   ai_min   = int 0–100 (minimalni AI score)
 *   urgent   = 1  (rok v 14 dneh)
 *   sort     = rok | vrednost | ai | novo
 *   limit    = int (default 100)
 */

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$where  = ['1=1'];
$params = [];

// ── Filtri ─────────────────────────────────────────────────────
$status = $_GET['status'] ?? null;
if ($status && in_array($status, ['odprt','zaprt','potekel'])) {
    $where[]  = 'status = ?';
    $params[] = $status;
}

$vir = $_GET['vir'] ?? null;
if ($vir && in_array($vir, ['e-JN','TED'])) {
    $where[]  = 'vir = ?';
    $params[] = $vir;
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $s = '%' . $search . '%';
    $where[]  = '(naslov LIKE ? OR narocnik LIKE ? OR cpv_kode LIKE ?)';
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}

$aiMin = isset($_GET['ai_min']) ? (int)$_GET['ai_min'] : null;
if ($aiMin !== null) {
    $where[]  = 'ai_score >= ?';
    $params[] = $aiMin;
}

if (!empty($_GET['urgent'])) {
    $where[] = 'rok_za_oddajo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)';
}

// ── Sortiranje ─────────────────────────────────────────────────
$sortKey = $_GET['sort'] ?? 'rok';
if ($sortKey === 'vrednost')      $sort = 'vrednost_eur DESC';
elseif ($sortKey === 'ai')        $sort = 'ai_score DESC';
elseif ($sortKey === 'novo')      $sort = 'created_at DESC';
else                              $sort = 'CASE WHEN rok_za_oddajo IS NULL THEN 1 ELSE 0 END, rok_za_oddajo ASC';

$limit = min((int)($_GET['limit'] ?? 100), 500);

// ── Query ──────────────────────────────────────────────────────
$sql = "SELECT
    id, external_id, vir, naslov, narocnik,
    vrednost, vrednost_eur,
    rok_za_oddajo, datum_objave, cpv_kode,
    status, link,
    ai_score, ai_prednosti, ai_slabosti, ai_priporocilo,
    datum_zaznave, created_at
FROM razpisi
WHERE " . implode(' AND ', $where) . "
ORDER BY $sort
LIMIT $limit";

try {
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // Pretvori JSON polja v arrays za lažji JS
    foreach ($rows as &$r) {
        $r['ai_prednosti'] = json_decode($r['ai_prednosti'] ?? 'null');
        $r['ai_slabosti']  = json_decode($r['ai_slabosti']  ?? 'null');
        $r['vrednost_eur'] = $r['vrednost_eur'] ? (float)$r['vrednost_eur'] : null;
        $r['ai_score']     = $r['ai_score'] !== null ? (int)$r['ai_score'] : null;
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
