<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

@set_time_limit(300);
@ini_set('max_execution_time', 300);

// ── DB direktno (brez config.php) ─────────────────────────────────
$DB_HOST = 'localhost';
$DB_NAME = 'u476516023_razpisi';
$DB_USER = 'u476516023_razpisi';
$DB_PASS = 'Dolinari31.';

// ── Claude API ────────────────────────────────────────────────────
$CLAUDE_API_KEY = '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';
$CLAUDE_MODEL   = 'claude-haiku-4-5-20251001';

// ── Kovinocrom profil ─────────────────────────────────────────────
$KOVINOCROM_PROFIL = 'Kovinocrom d.o.o. je slovensko podjetje, ki od leta 1980 proizvaja vijake, matice, podlozke in vezne elemente. Kljucne kompetence: standardni program vijaki/matice/podlozke/pritrdilni material (CPV 44315400-1), lastna CNC proizvodnja, zaloga ~2.000 ton, ISO 9001, izkusnje z javnimi narocili v gradbenistvu in infrastrukturi, prisotnost na trgu EU.';

// ── CPV kode in kljucne besede ────────────────────────────────────
$CPV_KODE = ['44315400-1','44315300-0','44316000-2','44532000-2','44533000-9','44531510-9'];
$KLJUCNE_BESEDE = ['vijaki','vijak','matice','matica','podlozke','pritrdilni material','vezni elementi','fasteners','bolts','nuts','washers','kovinski elementi','pritrdila'];

// ── DB singleton ──────────────────────────────────────────────────
function db() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── HTTP GET ──────────────────────────────────────────────────────
function httpGet($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 RazpisMonitor/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return ($body && strlen($body) > 10) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 RazpisMonitor/1.0', 'follow_location' => 1, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body && strlen($body) > 10) ? $body : null;
}

// ── HTTP POST ─────────────────────────────────────────────────────
function httpPost($url, $jsonPayload) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'RazpisMonitor/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return ($body && strlen($body) > 10) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'timeout'       => 20,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nContent-Length: " . strlen($jsonPayload),
            'content'       => $jsonPayload,
        ],
        'ssl' => ['verify_peer' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body && strlen($body) > 10) ? $body : null;
}

// ── Shrani razpis ─────────────────────────────────────────────────
function saveRazpis($r) {
    $st = db()->prepare("SELECT id FROM razpisi WHERE external_id = ?");
    $st->execute([$r['external_id']]);
    if ($st->fetch()) return false;
    db()->prepare(
        "INSERT INTO razpisi (external_id,vir,naslov,narocnik,vrednost,vrednost_eur,rok_za_oddajo,datum_objave,cpv_kode,status,link,datum_zaznave)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURDATE())"
    )->execute([
        $r['external_id'], $r['vir'], $r['naslov'],
        $r['narocnik'] ?? null, $r['vrednost'] ?? null, $r['vrednost_eur'] ?? null,
        $r['rok_za_oddajo'] ?? null, $r['datum_objave'] ?? null,
        $r['cpv_kode'] ?? '44315400-1', $r['status'] ?? 'odprt', $r['link'] ?? null,
    ]);
    return true;
}

// ── Datum normalize ───────────────────────────────────────────────
function toDate($d) {
    if (!$d) return null;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($d), $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    $ts = strtotime($d);
    return ($ts && $ts > 0) ? date('Y-m-d', $ts) : null;
}

// ── TED scraper (začasno onemogočen — TED blokira bote, API ima nedokumentirano sintakso)
// Za aktivacijo: pridobi TED API ključ na https://developer.ted.europa.eu in implementiraj OAuth2
function scrapeTED(&$log) {
    $log[] = 'TED: preskočen (API ni zanesljiv — razpisi so dostopni na https://ted.europa.eu/en/browse-by-business-sector)';
    return 0;

    // ── MRTVA KODA — ohranjena za referenco ──────────────────────
    $found   = 0;
    $cpvList = ['44315400', '44532000', '44533000', '44315300', '44316000'];

    foreach ($cpvList as $cpv) {
        $url  = 'https://ted.europa.eu/en/search/result?' . http_build_query([
            'query' => 'cpv=' . $cpv,
            'scope' => 'ACTIVE',
        ]);
        $html = httpGet($url);
        if (!$html) {
            $log[] = "TED: ni odgovora za CPV $cpv";
            continue;
        }

        preg_match_all('#/en/notice/(\d+-\d{4})#', $html, $m);
        $pubNums = array_unique($m[1] ?? []);
        $log[] = "TED CPV $cpv: " . count($pubNums) . ' zadetkov';

        foreach ($pubNums as $pubNum) {
            $extId = 'TED-' . $pubNum;
            $st = db()->prepare("SELECT id FROM razpisi WHERE external_id = ?");
            $st->execute([$extId]);
            if ($st->fetch()) continue;

            $title = 'Brez naslova';
            $esc   = preg_quote($pubNum, '#');
            if (preg_match('#' . $esc . '.*?class="[^"]*title[^"]*"[^>]*>([^<]{5,})#si', $html, $tm)) {
                $t = html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strlen(trim($t)) > 5) $title = trim($t);
            }

            if (saveRazpis([
                'external_id'   => $extId,
                'vir'           => 'TED',
                'naslov'        => $title,
                'narocnik'      => null,
                'vrednost'      => null,
                'vrednost_eur'  => null,
                'rok_za_oddajo' => null,
                'datum_objave'  => date('Y-m-d'),
                'cpv_kode'      => $cpv . '-1',
                'link'          => 'https://ted.europa.eu/en/notice/' . $pubNum,
                'status'        => 'odprt',
            ])) {
                $found++;
                $log[] = 'TED nov: ' . $pubNum . ' — ' . mb_substr($title, 0, 60);
            }
        }
        sleep(1);
    }

    $log[] = "TED skupaj: $found novih";
    return $found;
}

// ── e-JN scraper ─────────────────────────────────────────────────
function httpGetBrowser($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
                'Accept: text/html,application/json,*/*',
                'Accept-Language: sl-SI,sl;q=0.9,en;q=0.8',
                'Connection: keep-alive',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return ($body && strlen($body) > 10) ? $body : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'       => 20,
        'follow_location' => 1,
        'ignore_errors' => true,
        'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36\r\nAccept: text/html,application/json,*/*\r\n",
    ], 'ssl' => ['verify_peer' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body && strlen($body) > 10) ? $body : null;
}

function scrapeEJN(&$log) {
    global $CPV_KODE, $KLJUCNE_BESEDE;
    $found = 0;

    // Preizkusi vse znane URL-je
    $ejnUrls = [
        'https://www.enarocanje.si/opendata/Aktualni_razpisi.json',
        'https://enarocanje.si/opendata/Aktualni_razpisi.json',
        'https://www.enarocanje.si/opendata/aktualni_razpisi.json',
    ];

    $body = null;
    foreach ($ejnUrls as $ejnUrl) {
        if (!function_exists('curl_init')) { $log[] = 'e-JN: curl ni na voljo'; break; }
        $ch = curl_init($ejnUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: sl-SI,sl;q=0.9,en;q=0.8',
                'Referer: https://www.enarocanje.si/',
            ],
        ]);
        $resp     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            $log[] = "e-JN [$ejnUrl] curl errno $errno: $errmsg";
            continue;
        }
        $log[] = "e-JN [$ejnUrl] HTTP $httpCode, " . strlen($resp) . ' bytov';
        if ($httpCode === 200 && strlen($resp) > 100) { $body = $resp; break; }
    }

    if (!$body) { $log[] = 'e-JN: vsi URL-ji neuspesni — preverite dostopnost na Hostingerju'; return 0; }

    $log[] = 'e-JN raw (300): ' . substr($body, 0, 300);

    $data = json_decode($body, true);
    if (!is_array($data)) { $log[] = 'e-JN: odgovor ni JSON'; return 0; }

    foreach ($data as $n) {
        $cpv    = $n['cpv_koda'] ?? $n['CPV'] ?? '';
        $naslov = strtolower($n['naslov'] ?? $n['predmet_narocila'] ?? '');
        $match  = false;

        foreach ($CPV_KODE as $c) {
            if (strpos($cpv, substr($c, 0, 8)) !== false) { $match = true; break; }
        }
        if (!$match) {
            foreach ($KLJUCNE_BESEDE as $k) {
                if (strpos($naslov, strtolower($k)) !== false) { $match = true; break; }
            }
        }
        if (!$match) continue;

        $val   = isset($n['ocenjena_vrednost']) ? (float)$n['ocenjena_vrednost'] : null;
        $extId = 'EJN-' . ($n['id'] ?? $n['stevilka_objave'] ?? md5($naslov . $cpv));

        if (saveRazpis([
            'external_id'   => $extId,
            'vir'           => 'e-JN',
            'naslov'        => $n['naslov'] ?? $n['predmet_narocila'] ?? 'Brez naslova',
            'narocnik'      => $n['narocnik'] ?? $n['naziv_narocnika'] ?? null,
            'cpv_kode'      => $cpv,
            'vrednost'      => $val ? number_format($val, 0, ',', '.') : null,
            'vrednost_eur'  => $val,
            'rok_za_oddajo' => toDate($n['rok_oddaje'] ?? $n['rok_za_oddajo'] ?? null),
            'datum_objave'  => toDate($n['datum_objave'] ?? null),
            'link'          => $n['url'] ?? $n['link'] ?? null,
            'status'        => 'odprt',
        ])) { $found++; }
    }

    $log[] = "e-JN skupaj: $found novih";
    return $found;
}

// ── AI analiza ────────────────────────────────────────────────────
function analyzeRazpis($id, &$log) {
    global $CLAUDE_API_KEY, $CLAUDE_MODEL, $KOVINOCROM_PROFIL;
    $st = db()->prepare("SELECT * FROM razpisi WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return;

    $prompt  = "Analiziraj primernost razpisa za Kovinocrom d.o.o.\n\nPROFIL:\n{$KOVINOCROM_PROFIL}\n\nRAZPIS:\nNaslov: {$r['naslov']}\nNarocnik: {$r['narocnik']}\nVrednost: {$r['vrednost']}\nRok: {$r['rok_za_oddajo']}\nCPV: {$r['cpv_kode']}\n\nOdgovori SAMO z JSON: {\"score\":85,\"prednosti\":[\"...\"],\"slabosti\":[\"...\"],\"priporocilo\":\"...\"}";
    $payload = json_encode(['model' => $CLAUDE_MODEL, 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]]);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 20,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/json\r\nx-api-key: {$CLAUDE_API_KEY}\r\nanthropicversion: 2023-06-01\r\nContent-Length: " . strlen($payload),
        'content'       => $payload,
    ]]);
    $body = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if (!$body) { $log[] = "AI napaka za ID $id"; return; }

    $resp = json_decode($body, true);
    $text = $resp['content'][0]['text'] ?? null;
    if (!$text) { $log[] = "AI brez odgovora za ID $id"; return; }

    preg_match('/\{.*\}/s', $text, $m);
    $ai = json_decode($m[0] ?? '{}', true);
    if (empty($ai['score'])) { $log[] = "AI neveljaven JSON za ID $id"; return; }

    db()->prepare("UPDATE razpisi SET ai_score=?,ai_prednosti=?,ai_slabosti=?,ai_priporocilo=?,ai_analizirano=NOW() WHERE id=?")
        ->execute([(int)$ai['score'], json_encode($ai['prednosti'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($ai['slabosti'] ?? [], JSON_UNESCAPED_UNICODE), $ai['priporocilo'] ?? null, $id]);
    $log[] = "AI ok: ID $id = {$ai['score']}%";
}

// ── Email obvestilo ───────────────────────────────────────────────
function sendNotifEmail($id) {
    $st = db()->prepare("SELECT * FROM razpisi WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return;
    $ai      = $r['ai_score'] ? " AI {$r['ai_score']}%" : '';
    $subject = "[RazpisMonitor] Nov razpis{$ai}: " . mb_substr($r['naslov'], 0, 60);
    $body    = "Nov razpis za Kovinocrom d.o.o.{$ai}\n\nNaslov: {$r['naslov']}\nNarocnik: " . ($r['narocnik'] ?? '') . "\nVrednost: " . ($r['vrednost'] ?? '') . "\nRok: " . ($r['rok_za_oddajo'] ?? '') . "\nLink: " . ($r['link'] ?? '') . "\n\nhttps://razpismonitor.eu\n\n--\nRazpisMonitor";
    @mail('tilen.burja@kovinocrom.si', $subject, $body, "From: RazpisMonitor <razpismonitor@razpismonitor.eu>\r\nContent-Type: text/plain; charset=UTF-8");
}

// ════════════════════════════════════════════════════════════════
// MAIN
// ════════════════════════════════════════════════════════════════
try {
    $running = (int) db()->query(
        "SELECT COUNT(*) FROM scraper_log WHERE status='running' AND started_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    )->fetchColumn();
    if ($running) {
        echo json_encode(['started' => false, 'scraping' => true, 'message' => 'Scraper ze tece']);
        exit;
    }

    db()->prepare("INSERT INTO scraper_log (status) VALUES ('running')")->execute();
    $logId = (int) db()->lastInsertId();
    $log   = ['=== START ==='];

    db()->exec("UPDATE razpisi SET status='potekel' WHERE rok_za_oddajo < CURDATE() AND status='odprt'");

    $tedFound = scrapeTED($log);
    $ejnFound = scrapeEJN($log);
    $newTotal = $tedFound + $ejnFound;

    $toAnalyze = db()->query(
        "SELECT id FROM razpisi WHERE ai_score IS NULL ORDER BY id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($toAnalyze as $rid) {
        analyzeRazpis((int)$rid, $log);
        $st = db()->prepare("SELECT datum_zaznave FROM razpisi WHERE id = ?");
        $st->execute([$rid]);
        $row = $st->fetch();
        if ($row && $row['datum_zaznave'] === date('Y-m-d')) {
            sendNotifEmail((int)$rid);
        }
    }

    db()->prepare(
        "UPDATE scraper_log SET finished_at=NOW(), status='done', ejn_found=?, ted_found=?, new_razpisi=? WHERE id=?"
    )->execute([$ejnFound, $tedFound, $newTotal, $logId]);

    $log[] = "=== KONEC: $newTotal novih ===";

    echo json_encode([
        'started'  => true,
        'scraping' => false,
        'ejn'      => $ejnFound,
        'ted'      => $tedFound,
        'novi'     => $newTotal,
        'message'  => "Koncano: $newTotal novih razpisov",
        'log'      => $log,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($logId)) {
        try {
            db()->prepare("UPDATE scraper_log SET finished_at=NOW(), status='error', error_msg=? WHERE id=?")
                ->execute([$e->getMessage(), $logId]);
        } catch (Throwable $e2) {}
    }
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()) . ':' . $e->getLine(),
        'trace' => substr($e->getTraceAsString(), 0, 1000),
    ], JSON_UNESCAPED_UNICODE);
}
