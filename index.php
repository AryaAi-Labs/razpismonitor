<?php
session_start();

// ── Prijava ────────────────────────────────────────────────────────────────────
const AUTH_USER   = 'kovinocrom';
const AUTH_PASS   = 'Razpis2026!';
const SESSION_TTL = 8 * 3600; // 8 ur

// Odjava
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Obdelaj POST prijavo
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (trim($_POST['username']) === AUTH_USER && ($_POST['password'] ?? '') === AUTH_PASS) {
        $_SESSION['loggedin'] = true;
        $_SESSION['login_ts'] = time();
        header('Location: /');
        exit;
    }
    $loginError = 'Napačno uporabniško ime ali geslo.';
}

// Preveri sejo (loggedin + ni potekla)
$loggedIn = !empty($_SESSION['loggedin'])
    && isset($_SESSION['login_ts'])
    && (time() - $_SESSION['login_ts']) < SESSION_TTL;

// Prikaži prijavno stran
if (!$loggedIn): ?>
<!DOCTYPE html>
<html lang="sl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Prijava — RazpisMonitor</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #0f172a;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .login-box {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 12px;
      padding: 40px 36px;
      width: 100%;
      max-width: 380px;
    }
    .login-logo {
      font-size: 22px;
      font-weight: 700;
      color: #f8fafc;
      margin-bottom: 6px;
      letter-spacing: -0.4px;
    }
    .login-sub {
      font-size: 13px;
      color: #64748b;
      margin-bottom: 32px;
    }
    label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 6px;
    }
    input[type=text], input[type=password] {
      width: 100%;
      padding: 10px 14px;
      background: #0f172a;
      border: 1px solid #334155;
      border-radius: 7px;
      color: #f1f5f9;
      font-size: 14px;
      outline: none;
      margin-bottom: 18px;
      transition: border-color .15s;
    }
    input:focus { border-color: #3b82f6; }
    .btn-login {
      width: 100%;
      padding: 11px;
      background: #2563eb;
      color: #fff;
      border: none;
      border-radius: 7px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: background .15s;
    }
    .btn-login:hover { background: #1d4ed8; }
    .error {
      background: #450a0a;
      border: 1px solid #7f1d1d;
      color: #fca5a5;
      font-size: 13px;
      padding: 10px 14px;
      border-radius: 7px;
      margin-bottom: 18px;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <div class="login-logo">📋 RazpisMonitor</div>
    <div class="login-sub">Kovinocrom d.o.o. · Interni dostop</div>
    <?php if ($loginError): ?>
      <div class="error"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <label>Uporabniško ime</label>
      <input type="text" name="username" autocomplete="username" autofocus>
      <label>Geslo</label>
      <input type="password" name="password" autocomplete="current-password">
      <button type="submit" class="btn-login">Prijava</button>
    </form>
  </div>
</body>
</html>
<?php
exit;
endif;
// ── Konec prijave ──────────────────────────────────────────────────────────────

require __DIR__ . '/config.php';

// ── Statistike ─────────────────────────────────────────────────────────────────
function getStats(): array {
    $db = db();
    $aktivni      = (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt'")->fetchColumn();
    $skupnaVred   = (float)$db->query("SELECT COALESCE(SUM(vrednost_eur),0) FROM razpisi WHERE status='odprt'")->fetchColumn();
    $aiUjemanje   = (float)$db->query("SELECT COALESCE(AVG(ai_score),0) FROM razpisi WHERE status='odprt' AND ai_score IS NOT NULL")->fetchColumn();
    $rokDvaTedna  = (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt' AND rok_za_oddajo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetchColumn();
    $zadnjaSync   = $db->query("SELECT MAX(started_at) FROM scraper_log")->fetchColumn();
    $noviTeden    = (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE datum_zaznave >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();

    return compact('aktivni','skupnaVred','aiUjemanje','rokDvaTedna','zadnjaSync','noviTeden');
}

// ── Razpisi iz baze ────────────────────────────────────────────────────────────
function getRazpisi(array $filter = []): array {
    $where = ['1=1'];
    $params = [];

    if (!empty($filter['status'])) {
        $where[]  = 'status = ?'; $params[] = $filter['status'];
    }
    if (!empty($filter['vir'])) {
        $where[]  = 'vir = ?'; $params[] = $filter['vir'];
    }
    if (!empty($filter['search'])) {
        $s = '%' . $filter['search'] . '%';
        $where[]  = '(naslov LIKE ? OR narocnik LIKE ? OR cpv_kode LIKE ?)';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    if (!empty($filter['ai_min'])) {
        $where[]  = 'ai_score >= ?'; $params[] = (int)$filter['ai_min'];
    }
    if (isset($filter['urgent']) && $filter['urgent']) {
        $where[]  = 'rok_za_oddajo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)';
    }

    $sortKey = $filter['sort'] ?? 'rok';
    if ($sortKey === 'vrednost')  $sort = 'vrednost_eur DESC';
    elseif ($sortKey === 'ai')   $sort = 'ai_score DESC';
    elseif ($sortKey === 'novo') $sort = 'created_at DESC';
    else                         $sort = 'CASE WHEN rok_za_oddajo IS NULL THEN 1 ELSE 0 END, rok_za_oddajo ASC';

    $sql = "SELECT * FROM razpisi WHERE " . implode(' AND ', $where) . " ORDER BY $sort LIMIT 100";
    $st  = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ── Sidebari: štetja ───────────────────────────────────────────────────────────
function getSidebarCounts(): array {
    $db = db();
    return [
        'vsi'     => (int)$db->query("SELECT COUNT(*) FROM razpisi")->fetchColumn(),
        'odprti'  => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt'")->fetchColumn(),
        'urgent'  => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status='odprt' AND rok_za_oddajo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetchColumn(),
        'zaprti'  => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE status IN ('zaprt','potekel')")->fetchColumn(),
        'ejn'     => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE vir='e-JN' AND status='odprt'")->fetchColumn(),
        'ted'     => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE vir='TED' AND status='odprt'")->fetchColumn(),
        'high'    => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE ai_score >= 85 AND status='odprt'")->fetchColumn(),
        'mid'     => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE ai_score BETWEEN 60 AND 84 AND status='odprt'")->fetchColumn(),
        'low'     => (int)$db->query("SELECT COUNT(*) FROM razpisi WHERE ai_score < 60 AND ai_score IS NOT NULL AND status='odprt'")->fetchColumn(),
    ];
}

try {
    $stats   = getStats();
    $counts  = getSidebarCounts();
    $razpisi = getRazpisi(['status' => 'odprt']);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Napaka</title>'
       . '<style>body{font-family:sans-serif;background:#0d1117;color:#e6edf3;padding:40px}'
       . 'pre{background:#161b22;padding:20px;border-radius:8px;color:#f85149;overflow:auto}'
       . 'h2{color:#d4a017}</style></head><body>'
       . '<h2>RazpisMonitor — napaka pri zagonu</h2>'
       . '<p>Preverite nastavitve baze in <code>config.local.php</code>:</p>'
       . '<pre>' . htmlspecialchars($e->getMessage()) . "\n\nFile: " . $e->getFile() . ':' . $e->getLine() . '</pre>'
       . '<p><a href="api/status.php" style="color:#58a6ff">Preveri status.php →</a></p>'
       . '</body></html>';
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function rokBadge(?string $rok): string {
    if (!$rok) return '';
    $days = (int)((strtotime($rok) - time()) / 86400);
    if ($days < 0)   return '<span class="badge-rok rok-expired">ROK POTEKEL</span>';
    if ($days <= 5)  return '<span class="badge-rok rok-urgent">ROK ČEZ ' . $days . ' DNI</span>';
    if ($days <= 14) return '<span class="badge-rok rok-soon">ROK BLIZU</span>';
    return '';
}

function fmtDate(?string $d): string {
    if (!$d) return 'Rok potekel';
    $ts = strtotime($d);
    if (!$ts) return $d;
    $days = (int)(($ts - time()) / 86400);
    $formatted = date('j. n. Y', $ts);
    if ($days < 0)  return "$formatted";
    if ($days <= 14) return "$formatted";
    return $formatted;
}

function fmtVrednost(?float $v, ?string $raw): string {
    if ($v && $v > 0) return '€' . number_format($v, 0, ',', '.');
    return $raw ?? '—';
}
?>
<!DOCTYPE html>
<html lang="sl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RazpisMonitor · Kovinocrom d.o.o.</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #0d1117;
      --surface:   #161b22;
      --surface2:  #1c2330;
      --border:    #30363d;
      --gold:      #d4a017;
      --gold-dim:  #a07810;
      --green:     #238636;
      --green-bright: #3fb950;
      --amber:     #d29922;
      --red:       #f85149;
      --blue:      #1f6feb;
      --text:      #e6edf3;
      --text-dim:  #8b949e;
      --text-muted:#484f58;
      --white:     #ffffff;
      --sidebar-w: 220px;
    }

    html, body { height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ── Header ──────────────────────────────────────────── */
    .header {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 0 20px;
      height: 56px;
      display: flex;
      align-items: center;
      gap: 16px;
      flex-shrink: 0;
      z-index: 100;
      position: sticky;
      top: 0;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
    }
    .logo-icon {
      width: 32px; height: 32px;
      background: var(--gold);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
    }
    .logo-text { font-size: 14px; font-weight: 700; letter-spacing: .3px; }
    .logo-sub  { font-size: 10px; color: var(--text-dim); letter-spacing: 1px; text-transform: uppercase; }

    .header-divider { width: 1px; height: 28px; background: var(--border); flex-shrink: 0; }

    .company-badge {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 6px 12px;
      font-size: 13px;
      font-weight: 600;
      flex-shrink: 0;
    }
    .company-badge img {
      width: 20px; height: 20px;
      border-radius: 4px;
      background: var(--gold);
      object-fit: contain;
    }
    .company-badge .sub { font-size: 10px; color: var(--text-dim); font-weight: 400; display: block; }

    .header-stats {
      display: flex;
      gap: 24px;
      flex: 1;
      justify-content: center;
    }
    .hstat {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
    }
    .hstat-val {
      font-size: 20px;
      font-weight: 700;
      line-height: 1;
      color: var(--text);
    }
    .hstat-val.gold   { color: var(--gold); }
    .hstat-val.green  { color: var(--green-bright); }
    .hstat-val.red    { color: var(--red); }
    .hstat-label {
      font-size: 10px;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: .6px;
      white-space: nowrap;
    }
    .hstat-delta {
      font-size: 10px;
      color: var(--green-bright);
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .sync-status {
      font-size: 11px;
      color: var(--green-bright);
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .sync-dot {
      width: 7px; height: 7px;
      background: var(--green-bright);
      border-radius: 50%;
      animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all .15s;
      text-decoration: none;
      white-space: nowrap;
    }
    .btn-ghost {
      background: transparent;
      border-color: var(--border);
      color: var(--text);
    }
    .btn-ghost:hover { background: var(--surface2); }

    .btn-gold {
      background: var(--gold);
      color: #000;
      border-color: var(--gold);
    }
    .btn-gold:hover { background: #c49010; }

    /* ── Layout ───────────────────────────────────────────── */
    .app-body {
      display: flex;
      flex: 1;
      overflow: hidden;
    }

    /* ── Sidebar ──────────────────────────────────────────── */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--surface);
      border-right: 1px solid var(--border);
      overflow-y: auto;
      padding: 16px 0;
    }

    .sidebar-section { margin-bottom: 8px; }
    .sidebar-label {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: var(--text-muted);
      padding: 8px 16px 4px;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      font-size: 13px;
      cursor: pointer;
      transition: background .1s;
      border-left: 2px solid transparent;
      color: var(--text-dim);
      text-decoration: none;
    }
    .nav-item:hover { background: var(--surface2); color: var(--text); }
    .nav-item.active {
      background: rgba(212,160,23,.12);
      border-left-color: var(--gold);
      color: var(--text);
    }
    .nav-badge {
      margin-left: auto;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 2px 7px;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-dim);
    }
    .nav-badge.red { background: rgba(248,81,73,.15); border-color: rgba(248,81,73,.3); color: var(--red); }
    .nav-badge.gold { background: rgba(212,160,23,.15); border-color: rgba(212,160,23,.3); color: var(--gold); }
    .nav-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .dot-green  { background: var(--green-bright); }
    .dot-amber  { background: var(--amber); }
    .dot-red    { background: var(--red); }

    /* ── Main ─────────────────────────────────────────────── */
    .main {
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }

    /* ── Toolbar ──────────────────────────────────────────── */
    .toolbar {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 10px 20px;
      display: flex;
      gap: 10px;
      align-items: center;
      flex-shrink: 0;
      flex-wrap: wrap;
    }

    .search-wrap {
      position: relative;
      flex: 1;
      min-width: 200px;
    }
    .search-wrap svg {
      position: absolute;
      left: 10px; top: 50%;
      transform: translateY(-50%);
      color: var(--text-dim);
      pointer-events: none;
    }
    input[type="search"] {
      width: 100%;
      padding: 8px 12px 8px 34px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 7px;
      color: var(--text);
      font-size: 13px;
      outline: none;
      transition: border-color .15s;
    }
    input[type="search"]:focus { border-color: var(--gold); }
    input[type="search"]::placeholder { color: var(--text-muted); }

    .filter-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 7px 12px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 7px;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-dim);
      cursor: pointer;
      transition: all .15s;
      white-space: nowrap;
    }
    .filter-btn:hover, .filter-btn.active {
      background: rgba(212,160,23,.15);
      border-color: rgba(212,160,23,.4);
      color: var(--gold);
    }

    /* ── Section header ────────────────────────────────────── */
    .section-header {
      padding: 10px 20px 6px;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: .6px;
      flex-shrink: 0;
    }

    /* ── Cards ────────────────────────────────────────────── */
    .cards {
      padding: 0 20px 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
      transition: border-color .15s;
    }
    .card:hover { border-color: rgba(212,160,23,.35); }

    .card-head {
      padding: 14px 16px 10px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
    }

    .card-badges {
      display: flex;
      gap: 5px;
      align-items: center;
      flex-wrap: wrap;
      flex-shrink: 0;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      padding: 3px 8px;
      border-radius: 5px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .4px;
      white-space: nowrap;
    }
    .badge-odprt  { background: rgba(35,134,54,.2);  color: #3fb950; border: 1px solid rgba(35,134,54,.4); }
    .badge-ejn    { background: rgba(31,111,235,.15); color: #58a6ff; border: 1px solid rgba(31,111,235,.3); }
    .badge-ted    { background: rgba(139,148,158,.1); color: #8b949e; border: 1px solid var(--border); }
    .badge-ai     { background: rgba(212,160,23,.12); color: var(--gold); border: 1px solid rgba(212,160,23,.3); }

    .badge-rok { padding: 3px 8px; border-radius: 5px; font-size: 10px; font-weight: 700; }
    .rok-soon   { background: rgba(210,153,34,.15); color: var(--amber); border: 1px solid rgba(210,153,34,.3); }
    .rok-urgent { background: rgba(248,81,73,.15);  color: var(--red);   border: 1px solid rgba(248,81,73,.3); }
    .rok-expired { background: rgba(248,81,73,.1);  color: #f85149;      border: 1px solid rgba(248,81,73,.2); }

    .card-title {
      font-size: 14px;
      font-weight: 600;
      line-height: 1.4;
      color: var(--text);
    }

    .card-meta {
      padding: 0 16px 10px;
      display: flex;
      gap: 20px;
      flex-wrap: wrap;
      font-size: 12px;
    }
    .meta-item { display: flex; gap: 5px; align-items: baseline; }
    .meta-label { color: var(--text-muted); }
    .meta-val   { color: var(--text-dim); font-weight: 500; }
    .meta-val.highlight { color: var(--text); }

    /* ── AI Panel ─────────────────────────────────────────── */
    .ai-panel {
      background: var(--surface2);
      border-top: 1px solid var(--border);
      padding: 12px 16px;
    }

    .ai-panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
      gap: 10px;
    }
    .ai-label {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .8px;
      color: var(--text-dim);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .ai-score-num {
      font-size: 18px;
      font-weight: 700;
      color: var(--green-bright);
      flex-shrink: 0;
    }
    .ai-score-num.mid  { color: var(--amber); }
    .ai-score-num.low  { color: var(--red); }

    .score-bar {
      height: 4px;
      background: var(--border);
      border-radius: 2px;
      margin-bottom: 10px;
      overflow: hidden;
    }
    .score-fill {
      height: 100%;
      border-radius: 2px;
      background: var(--green-bright);
      transition: width .5s ease;
    }
    .score-fill.mid { background: var(--amber); }
    .score-fill.low { background: var(--red); }

    .ai-checks {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4px 16px;
      margin-bottom: 10px;
    }
    .ai-check {
      display: flex;
      align-items: baseline;
      gap: 5px;
      font-size: 12px;
      color: var(--text-dim);
    }
    .check-ok  { color: var(--green-bright); flex-shrink: 0; }
    .check-no  { color: var(--red);          flex-shrink: 0; }

    .ai-recommendation {
      background: rgba(212,160,23,.08);
      border: 1px solid rgba(212,160,23,.2);
      border-left: 3px solid var(--gold);
      border-radius: 0 6px 6px 0;
      padding: 8px 12px;
      font-size: 12px;
      color: var(--text-dim);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .ai-recommendation.urgent {
      background: rgba(248,81,73,.08);
      border-color: rgba(248,81,73,.2);
      border-left-color: var(--red);
    }

    .card-footer {
      padding: 10px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border-top: 1px solid var(--border);
    }

    .tags {
      display: flex;
      gap: 4px;
      flex-wrap: wrap;
      flex: 1;
    }
    .tag {
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 2px 7px;
      font-size: 10px;
      color: var(--text-muted);
    }

    .card-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }
    .btn-sm {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 5px 10px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid var(--border);
      transition: all .15s;
      text-decoration: none;
      white-space: nowrap;
    }
    .btn-portal { background: var(--surface); color: var(--text-dim); }
    .btn-portal:hover { background: var(--surface2); color: var(--text); }
    .btn-chat   { background: var(--gold); color: #000; border-color: var(--gold); }
    .btn-chat:hover { background: #c49010; }

    /* ── Right AI panel ───────────────────────────────────── */
    .ai-chat-panel {
      position: fixed;
      top: 56px;
      right: 0;
      bottom: 0;
      width: 360px;
      background: var(--surface);
      border-left: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      z-index: 200;
      transform: translateX(100%);
      transition: transform .25s ease;
    }
    .ai-chat-panel.open { transform: translateX(0); }

    .chat-header {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .chat-title { font-size: 13px; font-weight: 700; }
    .chat-subtitle { font-size: 11px; color: var(--text-dim); margin-top: 2px; }

    .chat-context {
      padding: 10px 14px;
      background: var(--surface2);
      border-bottom: 1px solid var(--border);
      font-size: 11px;
      color: var(--text-dim);
    }
    .chat-context strong { color: var(--text); }

    .chat-messages {
      flex: 1;
      overflow-y: auto;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .msg {
      max-width: 90%;
      font-size: 13px;
      line-height: 1.5;
    }
    .msg-user {
      align-self: flex-end;
      background: rgba(212,160,23,.15);
      border: 1px solid rgba(212,160,23,.25);
      border-radius: 10px 10px 3px 10px;
      padding: 8px 12px;
      color: var(--text);
    }
    .msg-ai {
      align-self: flex-start;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 10px 10px 10px 3px;
      padding: 8px 12px;
      color: var(--text-dim);
    }

    .chat-ai-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: var(--gold);
      margin-bottom: 4px;
    }

    .chat-input-wrap {
      padding: 12px 14px;
      border-top: 1px solid var(--border);
      display: flex;
      gap: 8px;
      align-items: flex-end;
    }
    textarea#chatInput {
      flex: 1;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--text);
      font-size: 13px;
      padding: 8px 10px;
      resize: none;
      outline: none;
      font-family: inherit;
      line-height: 1.5;
      max-height: 120px;
    }
    textarea#chatInput:focus { border-color: var(--gold); }
    textarea#chatInput::placeholder { color: var(--text-muted); }

    .btn-send {
      width: 36px; height: 36px;
      background: var(--gold);
      border: none;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: background .15s;
    }
    .btn-send:hover { background: #c49010; }
    .btn-send svg { color: #000; }

    /* ── Spinner ─────────────────────────────────────────── */
    .spinner {
      width: 20px; height: 20px;
      border: 2px solid var(--border);
      border-top-color: var(--gold);
      border-radius: 50%;
      animation: spin .7s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Empty state ─────────────────────────────────────── */
    .empty {
      padding: 60px 20px;
      text-align: center;
      color: var(--text-muted);
    }
    .empty p { font-size: 14px; margin-top: 8px; }

    /* ── Close btn ───────────────────────────────────────── */
    .btn-close {
      width: 28px; height: 28px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-dim);
      transition: all .15s;
    }
    .btn-close:hover { background: var(--surface); color: var(--text); }

    @media (max-width: 768px) {
      .sidebar { display: none; }
      .header-stats { display: none; }
      .ai-chat-panel { width: 100%; }
    }
  </style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────── -->
<header class="header">
  <div class="logo">
    <div class="logo-icon">📋</div>
    <div>
      <div class="logo-text">RazpisMonitor</div>
      <div class="logo-sub">AI POWERED</div>
    </div>
  </div>

  <div class="header-divider"></div>

  <div class="company-badge">
    <div style="width:20px;height:20px;background:var(--gold);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#000;flex-shrink:0;">K</div>
    <div>
      <div>KOVINOCROM d.o.o.</div>
      <span class="sub">od leta 1980 · vezni elementi</span>
    </div>
  </div>

  <div class="header-stats">
    <div class="hstat">
      <div class="hstat-val gold" id="hstat-aktivni"><?= $stats['aktivni'] ?></div>
      <div class="hstat-label">Aktivni razpisi</div>
      <?php if ($stats['noviTeden'] > 0): ?>
      <div class="hstat-delta">+<?= $stats['noviTeden'] ?> teden</div>
      <?php endif; ?>
    </div>
    <div class="hstat">
      <div class="hstat-val" id="hstat-vrednost">€<?= number_format($stats['skupnaVred'] / 1000000, 1, ',', '.') ?>M</div>
      <div class="hstat-label">Skupna vrednost</div>
    </div>
    <div class="hstat">
      <div class="hstat-val green" id="hstat-ai"><?= round($stats['aiUjemanje']) ?>%</div>
      <div class="hstat-label">AI ujemanje (avg)</div>
    </div>
    <div class="hstat">
      <div class="hstat-val red" id="hstat-rok"><?= $stats['rokDvaTedna'] ?></div>
      <div class="hstat-label">Rok za 2 tedna</div>
    </div>
  </div>

  <div class="header-right">
    <div class="sync-status">
      <div class="sync-dot"></div>
      Sinhronizirano <?php if ($stats['zadnjaSync']) { $dt = new DateTime($stats['zadnjaSync'], new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('Europe/Ljubljana')); echo $dt->format('j. n. H:i'); } else { echo 'danes'; } ?>
    </div>
    <button class="btn btn-gold" onclick="toggleChat()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      AI Svetovalec
    </button>
    <a href="?logout=1" class="btn btn-ghost" style="opacity:.6;font-size:12px;">Odjava</a>
  </div>
</header>

<!-- ── App body ───────────────────────────────────────────────── -->
<div class="app-body">

  <!-- Sidebar -->
  <nav class="sidebar">
    <div class="sidebar-section">
      <a class="nav-item active" href="?filter=vsi" onclick="filterSidebar('vsi',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        Vsi razpisi
        <span class="nav-badge"><?= $counts['vsi'] ?></span>
      </a>
      <a class="nav-item" href="?filter=odprti" onclick="filterSidebar('odprti',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
        Odprti razpisi
        <span class="nav-badge"><?= $counts['odprti'] ?></span>
      </a>
      <a class="nav-item" href="?filter=urgent" onclick="filterSidebar('urgent',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Rok &lt; 14 dni
        <span class="nav-badge red"><?= $counts['urgent'] ?></span>
      </a>
      <a class="nav-item" href="?filter=zaprti" onclick="filterSidebar('zaprti',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        Zaprti razpisi
        <span class="nav-badge"><?= $counts['zaprti'] ?></span>
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Portal</div>
      <a class="nav-item" href="?vir=e-JN" onclick="filterVir('e-JN',this)">
        <span style="font-size:10px;font-weight:700;color:#58a6ff;">SI</span>
        e-JN Slovenija
        <span class="nav-badge"><?= $counts['ejn'] ?></span>
      </a>
      <a class="nav-item" href="?vir=TED" onclick="filterVir('TED',this)">
        <span style="font-size:10px;font-weight:700;color:#8b949e;">EU</span>
        TED / EU
        <span class="nav-badge"><?= $counts['ted'] ?></span>
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">AI ujemanje</div>
      <a class="nav-item" href="?ai=high" onclick="filterAI(85,this)">
        <div class="nav-dot dot-green"></div>
        Visoko 85%+
        <span class="nav-badge gold"><?= $counts['high'] ?></span>
      </a>
      <a class="nav-item" href="?ai=mid" onclick="filterAI(60,this)">
        <div class="nav-dot dot-amber"></div>
        Srednje 60–84%
        <span class="nav-badge"><?= $counts['mid'] ?></span>
      </a>
      <a class="nav-item" href="?ai=low" onclick="filterAI(0,this)">
        <div class="nav-dot dot-red"></div>
        Nizko &lt;60%
        <span class="nav-badge"><?= $counts['low'] ?></span>
      </a>
    </div>
  </nav>

  <!-- Main content -->
  <main class="main" id="mainContent">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="search-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input type="search" id="searchInput" placeholder="Išči po naslovu, naročniku, CPV kodi, materialu…" oninput="debounceFilter()">
      </div>
      <button class="filter-btn active" onclick="setFilter('vijaki',this)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Vijaki
      </button>
      <button class="filter-btn" onclick="setFilter('kovani',this)">🔩 Kovani deli</button>
      <button class="filter-btn" onclick="setFilter('nerjavni',this)">✨ Nerjavni</button>
      <button class="filter-btn" onclick="setFilter('eu',this)">🇪🇺 su:EU only</button>
    </div>

    <div class="section-header" id="resultsLabel">
      URGENTNO — ROK BLIZU
    </div>

    <!-- Cards -->
    <div class="cards" id="cardsContainer">
      <?php if (empty($razpisi)): ?>
        <div class="empty">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          <p>Ni razpisov. Scraper bo samodejno poiskal razpise vsak dan ob 7:00.</p>
        </div>
      <?php else: ?>
        <?php foreach ($razpisi as $r):
          $ai = (int)($r['ai_score'] ?? 0);
          $aiClass = $ai >= 85 ? '' : ($ai >= 60 ? 'mid' : 'low');
          $prednosti = json_decode($r['ai_prednosti'] ?? '[]', true) ?: [];
          $slabosti  = json_decode($r['ai_slabosti']  ?? '[]', true) ?: [];
          $isUrgent  = $r['rok_za_oddajo'] && (strtotime($r['rok_za_oddajo']) - time()) < 5 * 86400;
          $dniDo     = $r['rok_za_oddajo'] ? max(0, (int)((strtotime($r['rok_za_oddajo']) - time()) / 86400)) : null;
          $cpvArr    = array_map('trim', explode(',', $r['cpv_kode'] ?? ''));
        ?>
        <div class="card" data-id="<?= $r['id'] ?>" data-naslov="<?= htmlspecialchars($r['naslov']) ?>" data-vir="<?= $r['vir'] ?>">
          <div class="card-head">
            <div class="card-title"><?= htmlspecialchars($r['naslov']) ?></div>
            <div class="card-badges">
              <?= rokBadge($r['rok_za_oddajo']) ?>
              <span class="badge badge-odprt">+ ODPRT</span>
              <span class="badge badge-<?= strtolower($r['vir']) === 'e-jn' ? 'ejn' : 'ted' ?>">
                <?= $r['vir'] === 'e-JN' ? 'e-JN' : 'TED' ?>
              </span>
              <?php if ($ai): ?>
              <span class="badge badge-ai"><?= $ai ?>%</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="card-meta">
            <div class="meta-item">
              <span class="meta-label">Naročnik</span>
              <span class="meta-val highlight"><?= htmlspecialchars($r['narocnik'] ?? '—') ?></span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Vrednost</span>
              <span class="meta-val"><?= fmtVrednost($r['vrednost_eur'], $r['vrednost']) ?></span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Rok</span>
              <span class="meta-val <?= $dniDo !== null && $dniDo <= 14 ? 'highlight' : '' ?>">
                <?= $r['rok_za_oddajo'] ? fmtDate($r['rok_za_oddajo']) : 'Rok potekel' ?>
              </span>
            </div>
            <?php if ($r['cpv_kode']): ?>
            <div class="meta-item">
              <span class="meta-label">CPV</span>
              <span class="meta-val"><?= htmlspecialchars($cpvArr[0]) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <?php if ($ai > 0): ?>
          <div class="ai-panel">
            <div class="ai-panel-header">
              <div class="ai-label">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                AI Analiza primernosti za Kovinocrom
              </div>
              <div class="ai-score-num <?= $aiClass ?>"><?= $ai ?>%</div>
            </div>
            <div class="score-bar">
              <div class="score-fill <?= $aiClass ?>" style="width:<?= $ai ?>%"></div>
            </div>
            <?php if ($prednosti || $slabosti): ?>
            <div class="ai-checks">
              <?php foreach ($prednosti as $p): ?>
              <div class="ai-check"><span class="check-ok">✓</span> <?= htmlspecialchars($p) ?></div>
              <?php endforeach; ?>
              <?php foreach ($slabosti as $s): ?>
              <div class="ai-check"><span class="check-no">✗</span> <?= htmlspecialchars($s) ?></div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($r['ai_priporocilo']): ?>
            <div class="ai-recommendation <?= $isUrgent ? 'urgent' : '' ?>">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              <?= htmlspecialchars($r['ai_priporocilo']) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php elseif ($r['ai_score'] === null): ?>
          <div class="ai-panel" style="padding:10px 16px;">
            <div class="ai-label" style="color:var(--text-muted);">
              <div class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:6px;"></div>
              AI analiza v teku…
            </div>
          </div>
          <?php endif; ?>

          <div class="card-footer">
            <div class="tags">
              <?php foreach (array_slice($cpvArr, 0, 2) as $cpv): if ($cpv): ?>
              <span class="tag"><?= htmlspecialchars($cpv) ?></span>
              <?php endif; endforeach; ?>
              <?php
              $naslovLower = strtolower($r['naslov']);
              $kwTags = array_filter(KLJUCNE_BESEDE, function($k) use ($naslovLower) { return stripos($naslovLower, $k) !== false; });
              foreach (array_slice($kwTags, 0, 3) as $kw):
              ?>
              <span class="tag"><?= htmlspecialchars($kw) ?></span>
              <?php endforeach; ?>
              <?php if ($r['vir'] === 'TED'): ?><span class="tag">EU</span><?php endif; ?>
            </div>
            <div class="card-actions">
              <?php if ($r['link']): ?>
              <a href="<?= htmlspecialchars($r['link']) ?>" target="_blank" rel="noopener" class="btn-sm btn-portal">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Portal
              </a>
              <?php endif; ?>
              <button class="btn-sm btn-chat" onclick="openChatForRazpis(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars($r['naslov'])) ?>')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                AI Chat
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- ── AI Chat panel ──────────────────────────────────────────── -->
<div class="ai-chat-panel" id="chatPanel">
  <div class="chat-header">
    <div>
      <div class="chat-title">🤖 AI Svetovalec</div>
      <div class="chat-subtitle">Prilagojen za Kovinocrom d.o.o.</div>
    </div>
    <button class="btn-close" onclick="toggleChat()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>
  <div class="chat-context" id="chatContext" style="display:none;">
    Kontekst razpisa: <strong id="chatContextTitle"></strong>
  </div>
  <div class="chat-messages" id="chatMessages">
    <div style="text-align:center;padding:20px 0;">
      <div style="font-size:24px;margin-bottom:8px;">🤖</div>
      <div style="font-size:13px;color:var(--text-dim);line-height:1.6;">
        Pozdravljeni! Sem AI svetovalec za javne razpise,<br>
        prilagojen za profil Kovinocrom d.o.o.<br><br>
        Vprašajte me karkoli o razpisih —<br>dokumentacijo, konkurenco, strategijo ponudbe.
      </div>
    </div>
  </div>
  <div class="chat-input-wrap">
    <textarea id="chatInput" rows="2" placeholder="Vprašajte AI o razpisu ali strategiji…" onkeydown="handleChatKey(event)"></textarea>
    <button class="btn-send" onclick="sendChatMessage()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </div>
</div>

<script>
// ── State ───────────────────────────────────────────────────────
let currentFilter = { status: 'odprt', vir: null, aiMin: null, search: '' };
let chatRazpisId  = null;
let chatRazpisTitle = null;
let filterTimer   = null;

// ── Filter/sidebar ──────────────────────────────────────────────
function filterSidebar(type, el) {
  event.preventDefault();
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  el.classList.add('active');

  currentFilter.vir   = null;
  currentFilter.aiMin = null;
  switch(type) {
    case 'odprti': currentFilter.status = 'odprt'; break;
    case 'zaprti': currentFilter.status = 'zaprt'; break;
    case 'urgent': currentFilter.status = 'odprt'; currentFilter.urgent = true; break;
    default:       currentFilter.status = null;
  }
  if (type !== 'urgent') currentFilter.urgent = false;
  loadRazpisi();
}

function filterVir(vir, el) {
  event.preventDefault();
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  el.classList.add('active');
  currentFilter.vir = vir;
  currentFilter.status = 'odprt';
  loadRazpisi();
}

function filterAI(min, el) {
  event.preventDefault();
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  el.classList.add('active');
  currentFilter.aiMin = min;
  currentFilter.status = 'odprt';
  loadRazpisi();
}

function setFilter(type, el) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  switch(type) {
    case 'vijaki':  currentFilter.search = 'vijak'; break;
    case 'kovani':  currentFilter.search = 'kovani'; break;
    case 'nerjavni':currentFilter.search = 'nerjavni'; break;
    case 'eu':      currentFilter.vir = 'TED'; break;
  }
  loadRazpisi();
}

function debounceFilter() {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(() => {
    currentFilter.search = document.getElementById('searchInput').value;
    loadRazpisi();
  }, 350);
}

// ── Load razpisi via AJAX ───────────────────────────────────────
async function loadRazpisi() {
  const params = new URLSearchParams();
  if (currentFilter.status) params.set('status', currentFilter.status);
  if (currentFilter.vir)    params.set('vir',    currentFilter.vir);
  if (currentFilter.search) params.set('search', currentFilter.search);
  if (currentFilter.aiMin)  params.set('ai_min', currentFilter.aiMin);
  if (currentFilter.urgent) params.set('urgent', '1');

  const container = document.getElementById('cardsContainer');
  container.innerHTML = '<div style="padding:40px;text-align:center;"><div class="spinner"></div></div>';

  try {
    const resp = await fetch('api/razpisi.php?' + params);
    const data = await resp.json();
    renderCards(data);
    updateLabel(data.length);
  } catch(e) {
    container.innerHTML = '<div class="empty"><p>Napaka pri nalaganju. Preveri strežnik.</p></div>';
  }
}

function updateLabel(count) {
  const label = document.getElementById('resultsLabel');
  if (currentFilter.urgent) label.textContent = 'URGENTNO — ROK BLIZU';
  else if (currentFilter.aiMin >= 85) label.textContent = 'VISOKO UJEMANJE (85%+)';
  else label.textContent = `PRIKAZUJEM ${count} RAZPISOV`;
}

function renderCards(razpisi) {
  const c = document.getElementById('cardsContainer');
  if (!razpisi.length) {
    c.innerHTML = '<div class="empty"><p>Ni razpisov za izbrani filter.</p></div>';
    return;
  }

  c.innerHTML = razpisi.map(r => {
    const ai = parseInt(r.ai_score || 0);
    const aiClass = ai >= 85 ? '' : ai >= 60 ? 'mid' : 'low';
    const prednosti = JSON.parse(r.ai_prednosti || '[]');
    const slabosti  = JSON.parse(r.ai_slabosti  || '[]');
    const rokBadgeHtml = getRokBadge(r.rok_za_oddajo);
    const dniDo = r.rok_za_oddajo ? Math.max(0, Math.floor((new Date(r.rok_za_oddajo) - new Date()) / 86400000)) : null;
    const isUrgent = dniDo !== null && dniDo <= 5;
    const virBadge = r.vir === 'e-JN'
      ? '<span class="badge badge-ejn">e-JN</span>'
      : '<span class="badge badge-ted">TED</span>';

    const aiPanel = ai > 0 ? `
      <div class="ai-panel">
        <div class="ai-panel-header">
          <div class="ai-label">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
            AI Analiza primernosti za Kovinocrom
          </div>
          <div class="ai-score-num ${aiClass}">${ai}%</div>
        </div>
        <div class="score-bar"><div class="score-fill ${aiClass}" style="width:${ai}%"></div></div>
        <div class="ai-checks">
          ${prednosti.map(p => `<div class="ai-check"><span class="check-ok">✓</span> ${esc(p)}</div>`).join('')}
          ${slabosti.map(s => `<div class="ai-check"><span class="check-no">✗</span> ${esc(s)}</div>`).join('')}
        </div>
        ${r.ai_priporocilo ? `<div class="ai-recommendation ${isUrgent?'urgent':''}">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          ${esc(r.ai_priporocilo)}
        </div>` : ''}
      </div>` : '';

    const cpvArr = (r.cpv_kode || '').split(',').map(x => x.trim()).filter(Boolean);
    const tags = cpvArr.slice(0,2).map(c => `<span class="tag">${esc(c)}</span>`).join('')
               + (r.vir === 'TED' ? '<span class="tag">EU</span>' : '');

    return `<div class="card" data-id="${r.id}">
      <div class="card-head">
        <div class="card-title">${esc(r.naslov)}</div>
        <div class="card-badges">
          ${rokBadgeHtml}
          <span class="badge badge-odprt">+ ODPRT</span>
          ${virBadge}
          ${ai ? `<span class="badge badge-ai">${ai}%</span>` : ''}
        </div>
      </div>
      <div class="card-meta">
        <div class="meta-item"><span class="meta-label">Naročnik</span><span class="meta-val highlight">${esc(r.narocnik||'—')}</span></div>
        <div class="meta-item"><span class="meta-label">Vrednost</span><span class="meta-val">${fmtVrednost(r.vrednost_eur, r.vrednost)}</span></div>
        <div class="meta-item"><span class="meta-label">Rok</span><span class="meta-val">${r.rok_za_oddajo ? fmtDate(r.rok_za_oddajo) : 'Rok potekel'}</span></div>
        ${cpvArr[0] ? `<div class="meta-item"><span class="meta-label">CPV</span><span class="meta-val">${esc(cpvArr[0])}</span></div>` : ''}
      </div>
      ${aiPanel}
      <div class="card-footer">
        <div class="tags">${tags}</div>
        <div class="card-actions">
          ${r.link ? `<a href="${esc(r.link)}" target="_blank" rel="noopener" class="btn-sm btn-portal">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            Portal</a>` : ''}
          <button class="btn-sm btn-chat" onclick="openChatForRazpis(${r.id},'${r.naslov.replace(/'/g,"\\'")}')">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            AI Chat
          </button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtVrednost(eur, raw) {
  if (eur && parseFloat(eur) > 0) {
    const v = parseFloat(eur);
    if (v >= 1000000) return '€' + (v/1000000).toFixed(1).replace('.',',') + 'M';
    return '€' + Math.round(v).toLocaleString('sl-SI');
  }
  return raw || '—';
}

function fmtDate(d) {
  if (!d) return 'Rok potekel';
  const dt = new Date(d);
  return dt.toLocaleDateString('sl-SI', {day:'numeric',month:'numeric',year:'numeric'});
}

function getRokBadge(rok) {
  if (!rok) return '';
  const days = Math.floor((new Date(rok) - new Date()) / 86400000);
  if (days < 0)  return '<span class="badge-rok rok-expired">ROK POTEKEL</span>';
  if (days <= 5) return `<span class="badge-rok rok-urgent">ROK ČEZ ${days} DNI</span>`;
  if (days <= 14) return '<span class="badge-rok rok-soon">ROK BLIZU</span>';
  return '';
}

// ── Refresh ─────────────────────────────────────────────────────
// Scraper teče sinhrono — fetch čaka na dokončanje (~10-30s)
async function triggerRefresh() {
  const btn = document.getElementById('btnRefresh');
  const refreshIcon = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/></svg> Osveži`;
  const spinnerHtml = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .7s linear infinite"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/></svg> Scrapin…`;

  btn.disabled = true;
  btn.innerHTML = spinnerHtml;

  try {
    const resp = await fetch('api/refresh.php', { method: 'POST' });

    // Preveri HTTP status
    if (!resp.ok) {
      const txt = await resp.text();
      console.error('refresh.php napaka:', resp.status, txt);
      alert('Napaka scraperja (' + resp.status + '):\n' + txt.substring(0, 300));
      btn.disabled = false;
      btn.innerHTML = refreshIcon;
      return;
    }

    const data = await resp.json();
    console.log('Scraper rezultat:', data);

    if (data.error) {
      alert('Scraper napaka:\n' + data.error + '\n' + (data.file || ''));
    } else {
      // Pokaži rezultat v sync statusu
      const syncEl = document.querySelector('.sync-status');
      if (syncEl) syncEl.innerHTML = `<div class="sync-dot"></div> Končano: ${data.novi || 0} novih razpisov`;
    }

    // Osveži kartice
    await loadRazpisi();

  } catch(e) {
    console.error('fetch napaka:', e);
    alert('Napaka pri dostopu do scraperja:\n' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = refreshIcon;
  }
}

// ── Chat ────────────────────────────────────────────────────────
function toggleChat() {
  document.getElementById('chatPanel').classList.toggle('open');
}

function openChatForRazpis(id, naslov) {
  chatRazpisId    = id;
  chatRazpisTitle = naslov;
  const ctx = document.getElementById('chatContext');
  ctx.style.display = 'block';
  document.getElementById('chatContextTitle').textContent = naslov;
  document.getElementById('chatPanel').classList.add('open');
  document.getElementById('chatInput').focus();
}

function handleChatKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChatMessage();
  }
}

async function sendChatMessage() {
  const inp = document.getElementById('chatInput');
  const msg = inp.value.trim();
  if (!msg) return;

  inp.value = '';
  appendMsg('user', msg);

  // Typing indicator
  const typId = 'typing-' + Date.now();
  appendTyping(typId);

  try {
    const resp = await fetch('api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg, razpis_id: chatRazpisId }),
    });

    const rawText = await resp.text();

    let data;
    try {
      data = JSON.parse(rawText);
    } catch(parseErr) {
      // PHP napaka pred JSON outputom
      removeTyping(typId);
      appendMsg('ai', '⚠️ PHP napaka: ' + rawText.substring(0, 300));
      return;
    }

    removeTyping(typId);
    if (data.response) {
      appendMsg('ai', data.response);
    } else {
      appendMsg('ai', '⚠️ ' + (data.error || 'Neznan odgovor od strežnika.'));
    }
  } catch(e) {
    removeTyping(typId);
    appendMsg('ai', '⚠️ Napaka pri povezavi: ' + e.message);
  }
}

function appendMsg(role, text) {
  const wrap = document.getElementById('chatMessages');
  const div = document.createElement('div');
  div.className = role === 'user' ? 'msg msg-user' : 'msg msg-ai';
  if (role === 'ai') {
    div.innerHTML = `<div class="chat-ai-label">AI Svetovalec</div>${esc(text).replace(/\n/g,'<br>')}`;
  } else {
    div.textContent = text;
  }
  wrap.appendChild(div);
  wrap.scrollTop = wrap.scrollHeight;
}

function appendTyping(id) {
  const wrap = document.getElementById('chatMessages');
  const div  = document.createElement('div');
  div.id = id;
  div.className = 'msg msg-ai';
  div.innerHTML = '<div class="spinner" style="width:16px;height:16px;border-width:2px;"></div>';
  wrap.appendChild(div);
  wrap.scrollTop = wrap.scrollHeight;
}

function removeTyping(id) {
  const el = document.getElementById(id);
  if (el) el.remove();
}
</script>
</body>
</html>
