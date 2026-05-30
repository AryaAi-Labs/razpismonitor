<?php
/**
 * GET /api/status.php
 * Vrne status scraperja in osnovne statistike.
 */

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = db();

    // Ali scraper trenutno teče?
    $scraping = (bool)$db->query(
        "SELECT COUNT(*) FROM scraper_log
         WHERE status = 'running'
         AND started_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    )->fetchColumn();

    // Zadnji uspešen run
    $lastRun = $db->query(
        "SELECT started_at, finished_at, ejn_found, ted_found, new_razpisi
         FROM scraper_log WHERE status='done' ORDER BY id DESC LIMIT 1"
    )->fetch();

    // Statistike
    $stats = [
        'aktivni'     => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt'")->fetchColumn(),
        'skupna_vred' => (float)$db->query("SELECT COALESCE(SUM(vrednost_eur),0) FROM razpisi WHERE status='odprt'")->fetchColumn(),
        'ai_avg'      => (float)$db->query("SELECT COALESCE(AVG(ai_score),0) FROM razpisi WHERE status='odprt' AND ai_score IS NOT NULL")->fetchColumn(),
        'urgent'      => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt' AND rok_za_oddajo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetchColumn(),
        'novi_danes'  => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE datum_zaznave = CURDATE()")->fetchColumn(),
    ];

    echo json_encode([
        'scraping' => $scraping,
        'last_run' => $lastRun ?: null,
        'stats'    => $stats,
        'time'     => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
