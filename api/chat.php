<?php
/**
 * POST /api/chat.php
 * Body: { "message": "...", "razpis_id": 42 }
 * Vrne: { "response": "..." }
 *
 * Pošlje vprašanje Claude API s kontekstom razpisa in profilom Kovinocroma.
 */

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($body['message'] ?? '');
$razpisId    = isset($body['razpis_id']) ? (int)$body['razpis_id'] : null;

if (!$userMessage) {
    http_response_code(400);
    echo json_encode(['error' => 'Manjka sporočilo']);
    exit;
}

// ── Naloži kontekst razpisa (če je podan) ─────────────────────
$razpisKontekst = '';
if ($razpisId) {
    $st = db()->prepare("SELECT * FROM razpisi WHERE id = ?");
    $st->execute([$razpisId]);
    $r = $st->fetch();
    if ($r) {
        $prednosti = implode(', ', json_decode($r['ai_prednosti'] ?? '[]', true) ?: []);
        $slabosti  = implode(', ', json_decode($r['ai_slabosti']  ?? '[]', true) ?: []);
        $razpisKontekst = "\n\nKONTEKST RAZPISA:\n" .
            "Naslov: {$r['naslov']}\n" .
            "Naročnik: {$r['narocnik']}\n" .
            "Vrednost: {$r['vrednost']}\n" .
            "Rok za oddajo: {$r['rok_za_oddajo']}\n" .
            "Portal: {$r['vir']}\n" .
            "CPV kode: {$r['cpv_kode']}\n" .
            ($r['ai_score'] ? "AI ujemanje: {$r['ai_score']}%\n" : '') .
            ($prednosti ? "AI prednosti: $prednosti\n" : '') .
            ($slabosti  ? "AI slabosti: $slabosti\n"  : '') .
            "Link: {$r['link']}\n";
    }
}

// ── System prompt ─────────────────────────────────────────────
$systemPrompt = "Si AI svetovalec za javna naročila pri podjetju Kovinocrom d.o.o. Odgovarjaš IZKLJUČNO v slovenščini. Si strokoven, jedrnat in praktičen.

PROFIL KOVINOCROM:
" . KOVINOCROM_PROFIL . "

Svetuješ o:
- Primernosti razpisov za Kovinocrom
- Strategiji ponudbe (cena, dokumentacija, diferenciatorji)
- Tehničnih zahtevah in CPV kodah
- Rokih in prioritizaciji
- Konkurenci in tržnem pozicioniranju

Vprašanja na razpise odgovarjaš konkretno — kaj točno storiti, kateri certifikati so potrebni, kako oblikovati ponudbo." . $razpisKontekst;

// ── Shranjuj zgodovino v session (poenostavljeno) ──────────────
session_start();
$sessionKey = 'chat_' . ($razpisId ?: 'general');
if (!isset($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [];
}

// Dodaj user sporočilo
$_SESSION[$sessionKey][] = ['role' => 'user', 'content' => $userMessage];

// Ohrani zadnjih 10 sporočil (5 izmenjav)
if (count($_SESSION[$sessionKey]) > 10) {
    $_SESSION[$sessionKey] = array_slice($_SESSION[$sessionKey], -10);
}

// ── Claude API klic ───────────────────────────────────────────
$payload = [
    'model'      => CLAUDE_MODEL,
    'max_tokens' => 1024,
    'system'     => $systemPrompt,
    'messages'   => $_SESSION[$sessionKey],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);

$respBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['error' => 'Napaka pri povezavi: ' . $curlErr]);
    exit;
}

$resp = json_decode($respBody, true);
$aiText = $resp['content'][0]['text'] ?? null;

if (!$aiText) {
    http_response_code(500);
    $apiErr = $resp['error']['message'] ?? 'Neznan API odgovor';
    echo json_encode(['error' => "Claude API napaka: $apiErr"]);
    exit;
}

// Shrani AI odgovor v session
$_SESSION[$sessionKey][] = ['role' => 'assistant', 'content' => $aiText];

// Opcijsko: shrani v DB
try {
    db()->prepare("INSERT INTO chat_history (session_id, razpis_id, role, content) VALUES (?,?,?,?)")
       ->execute([session_id(), $razpisId, 'user', $userMessage]);
    db()->prepare("INSERT INTO chat_history (session_id, razpis_id, role, content) VALUES (?,?,?,?)")
       ->execute([session_id(), $razpisId, 'assistant', $aiText]);
} catch (Throwable) {
    // Chat history ni kritična — nadaljuj brez napake
}

echo json_encode(['response' => $aiText], JSON_UNESCAPED_UNICODE);
