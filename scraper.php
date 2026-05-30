<?php
/**
 * RazpisMonitor — Scraper
 * Poženi: php scraper.php
 * Cron: 0 7 * * * /usr/bin/php /home/u12345/razpismonitor/scraper.php >> /home/u12345/logs/razpisi.log 2>&1
 */

require __DIR__ . '/config.php';

// ── Zapiši log ─────────────────────────────────────────────────────────────────
$logId = null;
function startLog(): void {
    global $logId;
    $st = db()->prepare("INSERT INTO scraper_log (status) VALUES ('running')");
    $st->execute();
    $logId = (int) db()->lastInsertId();
}

function finishLog(int $ejn, int $ted, int $new, ?string $err = null): void {
    global $logId;
    db()->prepare("UPDATE scraper_log SET finished_at=NOW(), status=?, ejn_found=?, ted_found=?, new_razpisi=?, error_msg=? WHERE id=?")
       ->execute([$err ? 'error' : 'done', $ejn, $ted, $new, $err, $logId]);
}

function log_msg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── HTTP helper ────────────────────────────────────────────────────────────────
function httpGet(string $url, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'RazpisMonitor/1.0 (+https://razpismonitor.eu)',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { log_msg("CURL napaka: $err"); return null; }
    if ($code !== 200) { log_msg("HTTP $code za $url"); return null; }
    return $body;
}

// ── Shrani razpis ──────────────────────────────────────────────────────────────
function saveRazpis(array $r): bool {
    // Preskoci podvojene
    $st = db()->prepare("SELECT id FROM razpisi WHERE external_id = ?");
    $st->execute([$r['external_id']]);
    if ($st->fetch()) return false;

    db()->prepare("
        INSERT INTO razpisi
            (external_id, vir, naslov, narocnik, vrednost, vrednost_eur,
             rok_za_oddajo, datum_objave, cpv_kode, status, link, opis, datum_zaznave)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())
    ")->execute([
        $r['external_id'], $r['vir'], $r['naslov'], $r['narocnik'] ?? null,
        $r['vrednost'] ?? null, $r['vrednost_eur'] ?? null,
        $r['rok_za_oddajo'] ?? null, $r['datum_objave'] ?? null,
        $r['cpv_kode'] ?? null,
        $r['status'] ?? 'odprt',
        $r['link'] ?? null, $r['opis'] ?? null,
    ]);
    return true;
}

// ── Označi stare razpise kot potekle ───────────────────────────────────────────
function updateStatuses(): void {
    db()->exec("UPDATE razpisi SET status='potekel' WHERE rok_za_oddajo < CURDATE() AND status='odprt'");
}

// ── TED EU API ─────────────────────────────────────────────────────────────────
function scrapeTED(): int {
    $found = 0;
    $cpvs  = implode(' OR ', array_map(fn($c) => "cpv_code:{$c}", CPV_KODE));

    // TED v3 API
    $url = 'https://api.ted.europa.eu/v3/notices/search?' . http_build_query([
        'query'    => $cpvs,
        'fields'   => 'BT-21-Procedure,BT-137-Lot,BT-27-Lot,BT-36-Lot,BT-131-Lot,BT-300-Lot,BT-163-Tender,cpv_code,publication_number,notice_type',
        'limit'    => 50,
        'page'     => 1,
        'scope'    => 'ACTIVE',
        'language' => 'SL,EN',
    ]);

    log_msg("TED: scrapin $url");
    $body = httpGet($url, ['Accept: application/json']);
    if (!$body) return 0;

    $data = json_decode($body, true);
    if (!isset($data['notices'])) {
        // Fallback: RSS feed
        return scrapeTEDrss();
    }

    foreach ($data['notices'] as $n) {
        $extId = 'TED-' . ($n['publication_number'] ?? uniqid());
        $razpis = [
            'external_id'   => $extId,
            'vir'           => 'TED',
            'naslov'        => $n['BT-21-Procedure'] ?? $n['title'] ?? 'Brez naslova',
            'narocnik'      => $n['BT-300-Lot'] ?? $n['buyer_name'] ?? null,
            'vrednost'      => isset($n['BT-27-Lot']) ? '€' . number_format((float)$n['BT-27-Lot'], 0, ',', '.') : null,
            'vrednost_eur'  => $n['BT-27-Lot'] ?? null,
            'rok_za_oddajo' => isset($n['BT-131-Lot']) ? date('Y-m-d', strtotime($n['BT-131-Lot'])) : null,
            'datum_objave'  => isset($n['publication_date']) ? date('Y-m-d', strtotime($n['publication_date'])) : date('Y-m-d'),
            'cpv_kode'      => is_array($n['cpv_code'] ?? null) ? implode(', ', $n['cpv_code']) : ($n['cpv_code'] ?? '44315400-1'),
            'link'          => 'https://ted.europa.eu/en/notice/' . urlencode($n['publication_number'] ?? ''),
            'status'        => 'odprt',
        ];
        if (saveRazpis($razpis)) {
            $found++;
            log_msg("TED: nov razpis — {$razpis['naslov']}");
        }
    }
    return $found;
}

function scrapeTEDrss(): int {
    $found = 0;
    $url = 'https://ted.europa.eu/api/latest/search?' . http_build_query([
        'q'      => '44315400',
        'fields' => 'ND,TI,RA,TD,DT,DL',
        'scope'  => '1',
        'limit'  => 25,
    ]);

    $body = httpGet($url);
    if (!$body) return 0;

    $data = json_decode($body, true);
    foreach ($data['results'] ?? [] as $n) {
        $extId = 'TED-' . ($n['ND'] ?? uniqid());
        $razpis = [
            'external_id'  => $extId,
            'vir'          => 'TED',
            'naslov'       => $n['TI'][0] ?? $n['TI'] ?? 'Brez naslova',
            'narocnik'     => $n['RA'][0] ?? $n['RA'] ?? null,
            'rok_za_oddajo'=> isset($n['DL']) ? date('Y-m-d', strtotime($n['DL'])) : null,
            'datum_objave' => isset($n['DT']) ? date('Y-m-d', strtotime($n['DT'])) : date('Y-m-d'),
            'cpv_kode'     => '44315400-1',
            'link'         => 'https://ted.europa.eu/en/notice/' . ($n['ND'] ?? ''),
            'status'       => 'odprt',
        ];
        if (saveRazpis($razpis)) {
            $found++;
            log_msg("TED RSS: nov razpis — {$razpis['naslov']}");
        }
    }
    return $found;
}

// ── e-JN portal (enarocanje.si) ────────────────────────────────────────────────
function scrapeEJN(): int {
    $found = 0;

    foreach (array_merge(CPV_KODE, KLJUCNE_BESEDE) as $kw) {
        $url = 'https://www.enarocanje.si/Obrazci/?' . http_build_query([
            'id_obrazca' => '48',
            'Besedilo'   => $kw,
            'Vrsta'      => '1',  // javno narocilo
        ]);
        log_msg("e-JN: scrapin '$kw'");

        $html = httpGet($url, ['Accept: text/html']);
        if (!$html) continue;

        $parsed = parseEJNHtml($html, $kw);
        foreach ($parsed as $r) {
            if (saveRazpis($r)) {
                $found++;
                log_msg("e-JN: nov razpis — {$r['naslov']}");
            }
        }

        sleep(2); // rate limiting
    }

    // Tudi OpenData CSV ce je dostopen
    $found += scrapeEJNOpenData();

    return $found;
}

function parseEJNHtml(string $html, string $kw): array {
    $results = [];
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);

    // Tabela z razpisi
    $rows = $xpath->query("//table[contains(@class,'table')]//tr[position()>1]");
    if (!$rows || $rows->length === 0) return $results;

    foreach ($rows as $row) {
        $cells = $xpath->query('.//td', $row);
        if (!$cells || $cells->length < 3) continue;

        $celArr = [];
        for ($i = 0; $i < $cells->length; $i++) {
            $celArr[] = trim($cells->item($i)->textContent);
        }

        // Link
        $linkNode = $xpath->query('.//a', $row)->item(0);
        $href = $linkNode ? $linkNode->getAttribute('href') : null;
        $link = $href ? (str_starts_with($href, 'http') ? $href : 'https://www.enarocanje.si' . $href) : null;

        // ID iz linka ali iz vsebine
        preg_match('/id_narocila=(\d+)/i', $link ?? '', $m);
        $extId = 'EJN-' . ($m[1] ?? md5($celArr[0] ?? uniqid()));

        // Parsiraj vrednost
        $vrednostRaw = $celArr[3] ?? null;
        $vrednostEur = null;
        if ($vrednostRaw) {
            $vrednostEur = (float) preg_replace('/[^\d,.]/', '', str_replace('.', '', $vrednostRaw));
        }

        $results[] = [
            'external_id'  => $extId,
            'vir'          => 'e-JN',
            'naslov'       => $celArr[0] ?? 'Brez naslova',
            'narocnik'     => $celArr[1] ?? null,
            'cpv_kode'     => $kw,
            'vrednost'     => $vrednostRaw,
            'vrednost_eur' => $vrednostEur ?: null,
            'rok_za_oddajo'=> parseDate($celArr[4] ?? null),
            'datum_objave' => parseDate($celArr[2] ?? null),
            'link'         => $link,
            'status'       => 'odprt',
        ];
    }
    return $results;
}

function scrapeEJNOpenData(): int {
    // e-narocanje.si ponuja Open Data feed v JSON formatu
    $url = 'https://www.enarocanje.si/opendata/Aktualni_razpisi.json';
    $body = httpGet($url);
    if (!$body) return 0;

    $data = json_decode($body, true);
    if (!is_array($data)) return 0;

    $found = 0;
    $cpvFilter = CPV_KODE;
    $kwFilter  = array_map('strtolower', KLJUCNE_BESEDE);

    foreach ($data as $n) {
        $cpv = $n['cpv_koda'] ?? $n['CPV'] ?? '';
        $naslov = strtolower($n['naslov'] ?? $n['predmet_narocila'] ?? '');

        // Filtriraj po CPV ali ključnih besedah
        $match = false;
        foreach ($cpvFilter as $c) {
            if (str_contains($cpv, substr($c, 0, 8))) { $match = true; break; }
        }
        if (!$match) {
            foreach ($kwFilter as $k) {
                if (str_contains($naslov, $k)) { $match = true; break; }
            }
        }
        if (!$match) continue;

        $extId = 'EJN-' . ($n['id'] ?? $n['stevilka_objave'] ?? md5($n['naslov'] ?? uniqid()));
        $razpis = [
            'external_id'  => $extId,
            'vir'          => 'e-JN',
            'naslov'       => $n['naslov'] ?? $n['predmet_narocila'] ?? 'Brez naslova',
            'narocnik'     => $n['narocnik'] ?? $n['naziv_narocnika'] ?? null,
            'cpv_kode'     => $cpv,
            'vrednost'     => isset($n['ocenjena_vrednost']) ? '€' . number_format((float)$n['ocenjena_vrednost'], 0, ',', '.') : null,
            'vrednost_eur' => $n['ocenjena_vrednost'] ?? null,
            'rok_za_oddajo'=> parseDate($n['rok_oddaje'] ?? $n['rok_za_oddajo'] ?? null),
            'datum_objave' => parseDate($n['datum_objave'] ?? null),
            'link'         => $n['url'] ?? $n['link'] ?? null,
            'status'       => 'odprt',
        ];
        if (saveRazpis($razpis)) {
            $found++;
        }
    }
    return $found;
}

function parseDate(?string $d): ?string {
    if (!$d) return null;
    // DD.MM.YYYY → YYYY-MM-DD
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($d), $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    // Poskusi strtotime
    $ts = strtotime($d);
    if ($ts && $ts > 0) return date('Y-m-d', $ts);
    return null;
}

// ── Claude AI analiza ─────────────────────────────────────────────────────────
function analyzeWithClaude(int $razpisId): void {
    $st = db()->prepare("SELECT * FROM razpisi WHERE id=?");
    $st->execute([$razpisId]);
    $r = $st->fetch();
    if (!$r) return;

    $prompt = "Analiziraj primernost naslednjega javnega razpisa za podjetje Kovinocrom d.o.o.:\n\n" .
              "PROFIL PODJETJA:\n" . KOVINOCROM_PROFIL . "\n\n" .
              "RAZPIS:\n" .
              "Naslov: {$r['naslov']}\n" .
              "Naročnik: {$r['narocnik']}\n" .
              "Vrednost: {$r['vrednost']}\n" .
              "Rok za oddajo: {$r['rok_za_oddajo']}\n" .
              "CPV kode: {$r['cpv_kode']}\n" .
              "Opis: " . substr($r['opis'] ?? '', 0, 500) . "\n\n" .
              "Odgovori IZKLJUČNO v tem JSON formatu (brez dodatnega teksta):\n" .
              '{"score":85,"prednosti":["Prednost 1","Prednost 2"],"slabosti":["Slabost 1"],"priporocilo":"Kratek actionable nasvet"}';

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'      => CLAUDE_MODEL,
            'max_tokens' => CLAUDE_MAX_TOKENS,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    $resp = json_decode($body, true);
    $text = $resp['content'][0]['text'] ?? null;
    if (!$text) return;

    // Parsiraj JSON iz odgovora
    preg_match('/\{.*\}/s', $text, $m);
    $ai = json_decode($m[0] ?? '{}', true);
    if (!isset($ai['score'])) return;

    db()->prepare("
        UPDATE razpisi
        SET ai_score=?, ai_prednosti=?, ai_slabosti=?, ai_priporocilo=?, ai_analizirano=NOW()
        WHERE id=?
    ")->execute([
        (int) $ai['score'],
        json_encode($ai['prednosti'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($ai['slabosti']  ?? [], JSON_UNESCAPED_UNICODE),
        $ai['priporocilo'] ?? null,
        $razpisId,
    ]);
}

// ── Email obvestilo ────────────────────────────────────────────────────────────
function sendNewRazpisEmail(int $razpisId): void {
    $st = db()->prepare("SELECT * FROM razpisi WHERE id=?");
    $st->execute([$razpisId]);
    $r = $st->fetch();
    if (!$r) return;

    $subject = "[RazpisMonitor] Nov razpis: {$r['naslov']}";
    $aiScore = $r['ai_score'] ? " · AI ujemanje: {$r['ai_score']}%" : '';

    $body = "Pozdravljeni,\n\n" .
            "RazpisMonitor je zaznal nov razpis, ki bi bil primeren za Kovinocrom d.o.o.{$aiScore}\n\n" .
            "═══════════════════════════════════════\n" .
            "RAZPIS: {$r['naslov']}\n" .
            "═══════════════════════════════════════\n" .
            "Naročnik:      " . ($r['narocnik'] ?? '—') . "\n" .
            "Vrednost:      " . ($r['vrednost'] ?? '—') . "\n" .
            "Rok za oddajo: " . ($r['rok_za_oddajo'] ?? '—') . "\n" .
            "Portal:        {$r['vir']}\n" .
            "CPV kode:      " . ($r['cpv_kode'] ?? '—') . "\n\n";

    if ($r['ai_score']) {
        $prednosti = json_decode($r['ai_prednosti'] ?? '[]', true);
        $slabosti  = json_decode($r['ai_slabosti']  ?? '[]', true);
        $body .= "AI ANALIZA ({$r['ai_score']}% ujemanje):\n";
        foreach ($prednosti as $p) $body .= "  ✓ {$p}\n";
        foreach ($slabosti  as $s) $body .= "  ✗ {$s}\n";
        if ($r['ai_priporocilo']) $body .= "\nPriporočilo: {$r['ai_priporocilo']}\n";
    }

    $body .= "\nOriginaln razpis: " . ($r['link'] ?? '—') . "\n" .
             "Dashboard:        " . APP_URL . "\n\n" .
             "--\nRazpisMonitor · Kovinocrom d.o.o.\n";

    $headers = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "X-Mailer: RazpisMonitor/" . APP_VERSION;

    mail(NOTIFY_EMAIL, $subject, $body, $headers);
    log_msg("Email poslan za razpis #{$razpisId}");
}

// ── MAIN ───────────────────────────────────────────────────────────────────────
startLog();
log_msg("=== RazpisMonitor Scraper start ===");

$ejnFound = $tedFound = $newTotal = 0;

try {
    // Posodobi statuse
    updateStatuses();

    // Scrape
    log_msg("--- Scrapam TED EU ---");
    $tedFound = scrapeTED();
    log_msg("TED: najdenih $tedFound novih");

    log_msg("--- Scrapam e-JN ---");
    $ejnFound = scrapeEJN();
    log_msg("e-JN: najdenih $ejnFound novih");

    $newTotal = $ejnFound + $tedFound;

    // AI analiza za nove razpise (brez analize)
    $stmt = db()->query("SELECT id FROM razpisi WHERE ai_score IS NULL ORDER BY id DESC LIMIT 20");
    $toAnalyze = $stmt->fetchAll(PDO::FETCH_COLUMN);
    log_msg("AI analiza za " . count($toAnalyze) . " razpisov...");

    foreach ($toAnalyze as $rid) {
        analyzeWithClaude((int)$rid);
        // Email obvestilo za novi razpis z AI analizo
        $st = db()->prepare("SELECT datum_zaznave FROM razpisi WHERE id=?");
        $st->execute([$rid]);
        $row = $st->fetch();
        if ($row && $row['datum_zaznave'] === date('Y-m-d')) {
            sendNewRazpisEmail((int)$rid);
        }
        sleep(1); // rate limiting za API
    }

    log_msg("=== KONEC: $newTotal novih razpisov ===");
    finishLog($ejnFound, $tedFound, $newTotal);

} catch (Throwable $e) {
    log_msg("NAPAKA: " . $e->getMessage());
    finishLog($ejnFound, $tedFound, $newTotal, $e->getMessage());
    exit(1);
}
