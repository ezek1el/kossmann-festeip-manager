<?php
// ══════════════════════════════════════════════════════════════════════════════
//  KOSSMANN FESTE IP MANAGER  –  Unraid Plugin v1.0
//  Standalone PHP/Vanilla-JS · Server-seitiger API-Proxy
// ══════════════════════════════════════════════════════════════════════════════

const KFIP_VER        = '0.4.7';
const KFIP_CONFIG_DIR = '/boot/config/plugins/kossmann-festeip-manager';
const KFIP_CONFIG     = KFIP_CONFIG_DIR . '/config.json';

function kfip_load(): array {
    if (!file_exists(KFIP_CONFIG)) return [];
    return json_decode(file_get_contents(KFIP_CONFIG), true) ?? [];
}

/**
 * POST-Wert lesen – unterstützt $_POST und php://input (nginx/PHP-FPM-Fallback).
 * Unraid's nginx leitet den Request-Body manchmal nicht in $_POST weiter.
 */
function kfip_post(string $key, string $default = ''): string {
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        return trim((string) $_POST[$key]);
    }
    static $raw = null;
    if ($raw === null) {
        $raw = [];
        $input = file_get_contents('php://input') ?: '';
        if ($input !== '') parse_str($input, $raw);
    }
    return isset($raw[$key]) ? trim((string) $raw[$key]) : $default;
}

function kfip_save(array $data): bool {
    if (!is_dir(KFIP_CONFIG_DIR)) {
        if (!@mkdir(KFIP_CONFIG_DIR, 0755, true) && !is_dir(KFIP_CONFIG_DIR)) return false;
    }
    return file_put_contents(KFIP_CONFIG, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

// ── API-PROXY ─────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    // ob_start: fängt PHP-Warnings/Notices ab, die sonst den JSON-Output korrumpieren
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    try {

    // Zugangsdaten speichern  (GET oder POST – beide Wege werden akzeptiert)
    $op = $_GET['_op'] ?? kfip_post('_op');
    if (in_array($op, ['save_config'], true)) {
        $cid = $_GET['customer_id'] ?? kfip_post('customer_id');
        $pwd = $_GET['password']    ?? kfip_post('password');
        $cid = trim($cid); $pwd = trim($pwd);
        if ($cid === '' || $pwd === '') {
            ob_end_clean();
            echo json_encode(['error' => true, 'message' => 'Kunden-ID und Passwort sind erforderlich.']);
            exit;
        }
        $saved = kfip_save(['customer_id' => $cid, 'password' => $pwd]);
        ob_end_clean();
        if ($saved) {
            echo json_encode(['ok' => true, 'message' => 'Zugangsdaten gespeichert.']);
        } else {
            echo json_encode(['error' => true, 'message' => 'Schreiben nach /boot/config fehlgeschlagen – Berechtigungen prüfen.']);
        }
        exit;
    }

    $cfg = kfip_load();
    $cid = $cfg['customer_id'] ?? '';
    $pwd = $cfg['password']    ?? '';

    if (!$cid || !$pwd) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['error' => true, 'message' => 'Keine Zugangsdaten konfiguriert.']);
        exit;
    }

    $action = preg_replace('/[^a-z_]/', '', $_GET['action'] ?? '');
    $id     = isset($_GET['id'])     ? (int) $_GET['id']     : null;
    $period = isset($_GET['period']) ? (int) $_GET['period'] : 7;

    // Diagnose-Endpunkt: ?api=1&action=ping
    if ($action === 'ping') {
        ob_end_clean();
        echo json_encode(['ok' => true, 'pong' => true, 'php' => PHP_VERSION]);
        exit;
    }

    $allowed = ['list', 'details', 'traffic_graph', 'traffic_table', 'rdns_manage', 'portscan', 'dgwan_manage'];
    if (!in_array($action, $allowed, true)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => "Unbekannte Aktion: {$action}"]);
        exit;
    }

    $url = "https://kossmann.center/api/?module=festeip&action={$action}";
    if ($id !== null)                  $url .= "&id={$id}";
    if ($action === 'traffic_graph')   $url .= "&period={$period}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => "{$cid}:{$pwd}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Unraid-KossmannFesteIP/' . KFIP_VER,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    // Aktionen die POST zur Kossmann-API erfordern.
    // Parameter kommen via GET (umgeht nginx POST-Routing nach emhttp in Unraid 7.x).
    if ($action === 'rdns_manage') {
        $rdns_val = trim($_GET['rdns'] ?? kfip_post('rdns'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'rdns=' . urlencode($rdns_val));
    } elseif ($action === 'portscan') {
        $port_val = (int) ($_GET['port'] ?? kfip_post('port', '0'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'port=' . $port_val);
    } elseif ($action === 'dgwan_manage') {
        $new_ip = trim($_GET['dg_ipv4_neu'] ?? kfip_post('dg_ipv4_neu', ''));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'dg_ipv4_neu=' . urlencode($new_ip));
    }

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    ob_end_clean();
    if ($curlErr) {
        http_response_code(502);
        echo json_encode(['error' => true, 'message' => "cURL-Fehler: {$curlErr}"]);
    } elseif ($httpCode >= 400) {
        http_response_code($httpCode);
        echo json_encode(['error' => true, 'message' => "API HTTP {$httpCode}"]);
    } else {
        echo $response;
    }

    } catch (Throwable $e) {
        // Letzter Fallback: immer valides JSON zurückgeben
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => 'PHP-Ausnahme: ' . $e->getMessage()]);
    }
    exit;
}

// ── PAGE ──────────────────────────────────────────────────────────────────────
$cfg        = kfip_load();
$configured = !empty($cfg['customer_id']) && !empty($cfg['password']);
$customerId = htmlspecialchars($cfg['customer_id'] ?? '');
$selfUrl    = htmlspecialchars($_SERVER['PHP_SELF']);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Kossmann · Feste IP</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#1c1d21;--bg2:#222428;--bg3:#2a2c30;
  --bd:#383a3f;--cy:#1d9ac7;--cyd:#1d9ac718;
  --or:#e69b1e;--ord:#e69b1e18;
  --gn:#56b870;--rd:#d45555;
  --tx:#d9d9d9;--dm:#7e8286;--dmr:#555759;
}
html,body{height:100%;overflow:hidden;}
body{font-family:'Space Mono',monospace;background:var(--bg);color:var(--tx);display:flex;flex-direction:column;}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}

.hdr{display:flex;align-items:center;justify-content:space-between;padding:10px 18px;background:var(--bg2);border-bottom:1px solid var(--bd);flex-shrink:0;position:relative;}
.hdr::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--cy) 30%,var(--or) 70%,transparent);}
.logo-box{width:34px;height:34px;border:1.5px solid var(--cy);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--cy);font-size:17px;box-shadow:0 0 16px var(--cyd);}
.logo-t{font-size:12px;font-weight:700;letter-spacing:.14em;color:var(--cy);}
.logo-s{font-size:9px;color:#9aacb8;letter-spacing:.2em;}

.main{display:grid;grid-template-columns:260px 1fr;flex:1;overflow:hidden;min-height:0;}

.sb{background:var(--bg2);border-right:1px solid var(--bd);overflow-y:auto;padding:10px 6px;}
.sb-hd{padding:6px 10px 10px;font-size:9px;letter-spacing:.22em;color:var(--dm);text-transform:uppercase;}
.ipc{padding:9px 10px;border-radius:5px;cursor:pointer;border:1px solid transparent;margin-bottom:3px;transition:all .12s;}
.ipc:hover{background:#1d9ac706;}
.ipc.active{background:var(--cyd);border-color:#1d9ac744;box-shadow:0 0 14px var(--cyd);}
.ipc .ip-a{font-size:13px;font-weight:700;letter-spacing:.04em;color:white;}
.ipc.active .ip-a{color:var(--cy);}
.ip-lb{font-size:11px;color:var(--dm);padding-left:13px;}
.ip-rd{font-size:10px;color:#5a7a8a;padding-left:13px;margin-top:2px;}

.badge{font-size:9px;padding:2px 6px;border-radius:3px;letter-spacing:.12em;text-transform:uppercase;border:1px solid;margin-left:auto;}
.badge.dg{background:var(--cyd);color:var(--cy);border-color:#1d9ac744;}
.badge.ov{background:var(--ord);color:var(--or);border-color:#e69b1e44;}

.dot{width:6px;height:6px;border-radius:50%;background:var(--gn);box-shadow:0 0 5px var(--gn);display:inline-block;flex-shrink:0;animation:dp 2s infinite;}
@keyframes dp{0%,100%{opacity:1}50%{opacity:.3}}

.ca{display:flex;flex-direction:column;overflow:hidden;background:var(--bg);min-height:0;}
.chd{padding:14px 18px 0;border-bottom:1px solid var(--bd);flex-shrink:0;}
.cbd{flex:1;overflow-y:auto;padding:16px 18px;min-height:0;}

.tabs{display:flex;}
.tab{padding:7px 14px;font-size:10px;letter-spacing:.1em;color:var(--dm);background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;font-family:inherit;text-transform:uppercase;transition:all .1s;}
.tab.active{color:var(--cy);border-bottom-color:var(--cy);}

.igrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1px;background:var(--bd);border:1px solid var(--bd);border-radius:7px;overflow:hidden;margin-bottom:16px;}
.icell{background:var(--bg2);padding:12px 14px;}
.ilbl{font-size:9px;letter-spacing:.18em;color:var(--dm);text-transform:uppercase;margin-bottom:3px;}
.ival{font-size:13px;font-weight:700;word-break:break-all;}

.sec{background:var(--bg2);border:1px solid var(--bd);border-radius:7px;margin-bottom:14px;overflow:hidden;}
.sechd{padding:10px 16px;border-bottom:1px solid var(--bd);font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:var(--dm);display:flex;align-items:center;justify-content:space-between;}
.secb{padding:14px 16px;}

.kfi{background:var(--bg3);border:1px solid var(--bd);border-radius:4px;padding:7px 11px;color:var(--tx);font-size:12px;outline:none;font-family:inherit;transition:border-color .15s;}
.kfi:focus{border-color:var(--cy);}

.btn{padding:7px 14px;border-radius:4px;cursor:pointer;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;font-family:inherit;transition:all .12s;}
.btn-c{border:1px solid #1d9ac755;background:var(--cyd);color:var(--cy);}
.btn-c:hover{background:#1d9ac722;}
.btn-c:disabled{opacity:.5;cursor:default;}
.btn-o{border:1px solid #e69b1e55;background:var(--ord);color:var(--or);}
.btn-o:hover{background:#e69b1e28;}
.btn-o:disabled{opacity:.5;cursor:default;}
.btn-g{padding:4px 9px;font-size:12px;border:1px solid var(--bd);background:transparent;color:var(--dm);}
.btn-g:hover{background:var(--bg3);}

.perbtn{padding:5px 12px;border-radius:4px;font-size:10px;cursor:pointer;font-family:inherit;letter-spacing:.08em;border:1px solid var(--bd);background:transparent;color:var(--dm);transition:all .12s;}
.perbtn.active{border-color:#1d9ac755;background:var(--cyd);color:var(--cy);}

.pq{padding:4px 9px;border-radius:3px;font-size:11px;font-family:inherit;cursor:pointer;border:1px solid var(--bd);background:transparent;color:var(--dm);transition:all .1s;}
.pq:hover{opacity:.8;}
.pq.active{border-color:#1d9ac744;background:var(--cyd);color:var(--cy);}

.msg{padding:8px 12px;border-radius:4px;font-size:12px;display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.msg-ok{background:#56b87010;border:1px solid #56b87030;color:var(--gn);}
.msg-err{background:#d4555510;border:1px solid #d4555530;color:var(--rd);}
.msg-inf{background:var(--cyd);border:1px solid #1d9ac730;color:var(--cy);}

.ktbl{width:100%;border-collapse:collapse;font-size:12px;}
.ktbl th{padding:8px 12px;text-align:left;color:var(--dm);font-size:9px;letter-spacing:.12em;text-transform:uppercase;border-bottom:1px solid var(--bd);font-weight:700;background:var(--bg2);white-space:nowrap;}
.ktbl td{padding:5px 12px;border-bottom:1px solid #1c2a3a22;}
.ktbl tr:hover td{background:#0c121966;}
.tbar{height:5px;min-width:2px;background:linear-gradient(90deg,var(--cy),#1d9ac744);border-radius:2px;}

.gfx-img{width:80%;max-width:900px;border-radius:4px;display:block;margin:0 auto;}
.gfx-ph{height:160px;display:flex;align-items:center;justify-content:center;background:var(--bg3);border-radius:4px;color:var(--dmr);font-size:11px;border:1px dashed var(--bd);gap:8px;}

.ov-bg{display:none;position:fixed;inset:0;background:#00000090;z-index:999;align-items:center;justify-content:center;}
.ov-box{background:var(--bg2);border:1px solid var(--bd);border-radius:10px;padding:24px;width:100%;max-width:420px;}

.spin{width:14px;height:14px;border:2px solid var(--bd);border-top-color:var(--cy);border-radius:50%;animation:sp .7s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes sp{to{transform:rotate(360deg)}}

.fl{display:flex;}.fla{align-items:center;}.flg8{gap:8px;}.flg12{gap:12px;}
.mb10{margin-bottom:10px;}.mb14{margin-bottom:14px;}.mb16{margin-bottom:16px;}
.ctr{display:flex;align-items:center;justify-content:center;height:100%;flex-direction:column;gap:10px;color:var(--dmr);}
</style>
</head>
<body>

<!-- HEADER -->
<div class="hdr">
  <div class="fl fla flg12">
    <div class="logo-box">⬡</div>
    <div>
      <div class="logo-t">KOSSMANN EDV</div>
      <div class="logo-s">FESTE IP · MANAGEMENT · v<?= KFIP_VER ?></div>
    </div>
  </div>
  <div class="fl fla flg8">
    <span id="conn-status" style="font-size:11px;"></span>
    <button class="btn btn-g" onclick="kfip.reload()" title="Aktualisieren">⟳</button>
    <button class="btn btn-g" onclick="kfip.showCreds()" title="Zugangsdaten">⚙ Zugangsdaten</button>
  </div>
</div>

<!-- MAIN -->
<div class="main">
  <div class="sb" id="sidebar">
    <div class="sb-hd" id="ip-count">Feste IPs</div>
    <div id="ip-list"></div>
  </div>
  <div class="ca" id="content-area">
    <div class="ctr">
      <div style="font-size:38px">⬡</div>
      <div id="empty-msg" style="font-size:10px;letter-spacing:.18em;text-transform:uppercase;">Lade...</div>
    </div>
  </div>
</div>

<!-- CREDENTIALS OVERLAY -->
<div class="ov-bg" id="creds-ov" style="display:none;">
  <div class="ov-box">
    <div style="font-size:11px;letter-spacing:.14em;color:var(--cy);text-transform:uppercase;margin-bottom:20px;">
      ⚙ Zugangsdaten konfigurieren
    </div>
    <div class="mb14">
      <div class="ilbl" style="margin-bottom:5px;">Kunden-ID</div>
      <input class="kfi" id="inp-cid" type="text" placeholder="z.B. 10000" style="width:100%;" value="<?= $customerId ?>"/>
    </div>
    <div class="mb16">
      <div class="ilbl" style="margin-bottom:5px;">Passwort</div>
      <input class="kfi" id="inp-pwd" type="password" placeholder="API-Passwort" style="width:100%;"/>
    </div>
    <div id="creds-msg" class="mb14"></div>
    <div class="fl flg8">
      <button class="btn btn-c" onclick="kfip.saveCredentials()">Speichern</button>
      <button class="btn btn-g" onclick="kfip.hideCreds()">Abbrechen</button>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
//  KOSSMANN FESTE IP · Client-seitiger State-Manager
// ═══════════════════════════════════════════════════════════════════════════════

const kfip = (() => {

const API  = '<?= $selfUrl ?>';
const CONF = <?= $configured ? 'true' : 'false' ?>;

// ── STATE ─────────────────────────────────────────────────────────────────────
const S = { configured: CONF, ips:[], sel:null, det:null, tab:'overview', period:'7', rawList:null, rawDet:null };

// Findet das ID-Feld unabhängig vom Namen (id, ip_id, festeip_id, ID, ...)
function findId(obj) {
  if (!obj || typeof obj !== 'object') return null;
  if (obj.id != null && obj.id !== '') return obj.id;            // exakt 'id' bevorzugt
  for (const [k, v] of Object.entries(obj)) {                     // sonst irgendein *id*-Feld
    if (/(^|_)id($|_)/i.test(k) && (typeof v === 'number' || (typeof v === 'string' && v !== ''))) {
      return v;
    }
  }
  return null;
}

// Findet IPv4-Adresse unabhängig vom Feldnamen
function findIp(obj) {
  if (!obj || typeof obj !== 'object') return null;
  const ipRx = /^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/;
  for (const k of ['ip','ipv4','ip_address','public_ip','address','feste_ip','festeip','ipaddr']) {
    if (obj[k] && ipRx.test(String(obj[k]))) return obj[k];
  }
  for (const [,v] of Object.entries(obj)) {
    if (typeof v === 'string' && ipRx.test(v)) return v;
  }
  return null;
}

// Sucht Feld nach Liste möglicher Namen
function findField(obj, keys) {
  if (!obj || typeof obj !== 'object') return null;
  for (const k of keys) if (obj[k] != null && obj[k] !== '') return obj[k];
  return null;
}

// Normalisiert DG vs OVPN unabhängig vom API-Wert
function normalizeType(t) {
  if (!t) return 'dg';
  return /dg|gate|wan/i.test(String(t)) ? 'dg' : 'ovpn';
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s; return d.innerHTML;
}

function setStatus(state, msg='') {
  const el = document.getElementById('conn-status');
  if (state === null) {
    el.innerHTML = '<span class="spin"></span>';
  } else if (state) {
    el.innerHTML = `<span style="color:var(--gn);font-size:11px;">● Verbunden · Kunde ${esc(msg)}</span>`;
  } else {
    el.innerHTML = `<span style="color:var(--rd);font-size:11px;">✗ ${esc(msg||'Fehler')}</span>`;
  }
}

// ── API ───────────────────────────────────────────────────────────────────────
async function call(params, post=null) {
  const url = `${API}?api=1&${new URLSearchParams(params)}`;
  const opt = {};
  if (post) {
    opt.method = 'POST';
    opt.headers = {'Content-Type':'application/x-www-form-urlencoded'};
    opt.body = new URLSearchParams(post);
  }
  const r = await fetch(url, opt);
  const text = await r.text();
  if (!text || !text.trim()) {
    throw new Error(`Leere Antwort vom Server (HTTP ${r.status}). Prüfe: Unraid → Settings → PHP Error Log.`);
  }
  try {
    return JSON.parse(text);
  } catch (_) {
    throw new Error(`Keine JSON-Antwort (HTTP ${r.status}): ${text.slice(0, 200)}`);
  }
}

// ── INIT ──────────────────────────────────────────────────────────────────────
async function init() {
  if (!S.configured) {
    setStatus(false, 'Keine Zugangsdaten');
    document.getElementById('empty-msg').textContent = 'Bitte Zugangsdaten konfigurieren.';
    showCreds();
    return;
  }
  await loadList();
}

async function reload() { S.sel=null; S.ips=[]; await loadList(); }

async function loadList() {
  setStatus(null);
  try {
    const res = await call({action:'list'});
    if (res.error) { setStatus(false, res.message||'API-Fehler'); return; }
    S.ips = Array.isArray(res.data) ? res.data : (Array.isArray(res) ? res : []);
    S.rawList = res;
    const firstCid = findField(S.ips[0],['customer_id','kundenid','kundennr','cid','customer_nr','kdnr']) || '';
    setStatus(true, firstCid || '–');
    renderSidebar();
    if (S.ips.length > 0) selectIP(findId(S.ips[0]));
    else document.getElementById('empty-msg').textContent = 'Keine Feste IPs vorhanden.';
  } catch(e) { setStatus(false, e.message); }
}

// ── SIDEBAR ───────────────────────────────────────────────────────────────────
function renderSidebar() {
  document.getElementById('ip-count').textContent = `Feste IPs · ${S.ips.length}`;
  document.getElementById('ip-list').innerHTML = S.ips.map(ip => {
    const _id   = findId(ip);
    const _ip   = findIp(ip) || '';
    const _tp   = normalizeType(ip.type);
    const _rdns = findField(ip,['rdns','reverse_dns','ptr','rdns_entry','hostname']) || '';
    return `
    <div class="ipc${String(_id)===String(S.sel)?' active':''}" onclick="kfip.selectIP('${_id}')">
      <div class="fl fla flg8 mb10" style="margin-bottom:3px;">
        <span class="ip-a">${esc(_ip)}</span>
        <span class="badge ${_tp==='dg'?'dg':'ov'}">${_tp==='dg'?'DG':'OVPN'}</span>
      </div>
      <div class="ip-lb">${esc(findField(ip,['label','name','bezeichnung','comment']) || _ip)}</div>
      <div class="ip-rd">${esc(_rdns)}</div>
    </div>
  `;}).join('');
}

// ── SELECT IP ─────────────────────────────────────────────────────────────────
async function selectIP(id) {
  S.sel=id; S.tab='overview'; S.det=null;
  renderSidebar();
  showLoading();
  try {
    const res = await call({action:'details', id});
    if (res.error) { showError(res.message); return; }
    S.det = res.data || res;
    S.rawDet = res;
    renderContent();
  } catch(e) { showError(e.message); }
}

function showLoading() {
  const ip = S.ips.find(x=>String(x.id)===String(S.sel));
  document.getElementById('content-area').innerHTML = `
    <div class="ctr"><span class="spin"></span><span style="font-size:11px;color:var(--dm);">Lade ${esc(ip?.ip||'')}…</span></div>`;
}

function showError(msg) {
  document.getElementById('content-area').innerHTML = `
    <div class="ctr"><div style="font-size:24px;color:var(--rd)">✗</div><div style="font-size:12px;color:var(--rd)">${esc(msg)}</div></div>`;
}

// ── CONTENT RENDER ────────────────────────────────────────────────────────────
function renderContent() {
  const ip = S.ips.find(x=>String(x.id)===String(S.sel))||{};
  const d  = S.det || ip;
  const tp = d.type || ip.type || 'dg';

  document.getElementById('content-area').innerHTML = `
    <div class="chd">
      <div class="fl fla flg12 mb16">
        <span style="font-size:20px;font-weight:700;color:white;letter-spacing:.04em;">${esc(ip.ip||d.ip||'')}</span>
        <span class="badge ${tp==='dg'?'dg':'ov'}" style="font-size:10px;padding:3px 8px;">
          ${tp==='dg'?'DG-Gateway':'OpenVPN'}
        </span>
      </div>
      <div class="tabs">
        ${[['overview','⊞ Übersicht'],['traffic','◈ Traffic'],['tools','⚙ Tools']].map(([t,l])=>
          `<button class="tab${S.tab===t?' active':''}" onclick="kfip.setTab('${t}')">${l}</button>`
        ).join('')}
      </div>
    </div>
    <div class="cbd" id="tab-body"></div>
  `;
  renderTab();
}

// ── TABS ──────────────────────────────────────────────────────────────────────
function setTab(t) {
  S.tab=t;
  document.querySelectorAll('.tab').forEach(b=>{
    const lbl = b.textContent;
    b.classList.toggle('active',
      (t==='overview'&&lbl.includes('Übersicht'))||
      (t==='traffic'&&lbl.includes('Traffic'))||
      (t==='tools'&&lbl.includes('Tools'))
    );
  });
  renderTab();
}

function renderTab() {
  if (S.tab==='overview') renderOverview();
  else if (S.tab==='traffic') renderTraffic();
  else renderTools();
}

// ── ÜBERSICHT ─────────────────────────────────────────────────────────────────
function renderOverview() {
  const ip = S.ips.find(x=>String(x.id)===String(S.sel))||{};
  const d  = S.det||ip;
  const tp = normalizeType(d.type||ip.type);

  const _festeIp  = findIp(d) || findIp(ip) || '–';
  const _tp       = normalizeType(d.type || ip.type);
  const _rdns     = findField(d,['rdns','reverse_dns','ptr','rdns_entry','hostname']) ||
                    findField(ip,['rdns','reverse_dns','ptr','rdns_entry','hostname']) || '–';
  const _wanIp    = findField(d,['wan_ip','dg_wan_ip','dg_ipv4','wan','gateway_ip','gw_ip']) || '–';
  const _custId   = findField(d,['customer_id','kundenid','kundennr','cid','customer_nr','kdnr']) ||
                    findField(ip,['customer_id','kundenid','kundennr','cid']) || '–';

  const fields = [
    ['IP-Adresse',  _festeIp,                                              'white'],
    ['Feste-IP-ID', String(findId(d) ?? findId(ip) ?? S.sel ?? '–'),      'var(--cy)'],
    ['Typ',         _tp==='dg'?'DG-Gateway':'OpenVPN',                     _tp==='dg'?'var(--cy)':'var(--or)'],
    ['Reverse-DNS', _rdns,                                                 'white'],
    ['WAN-IP',      _wanIp,                                                'white'],
    ['Kunden-ID',   String(_custId),                                       'white'],
    ['Status',      'Aktiv',                                               'var(--gn)'],
  ];
  if (d.location)   fields.push(['Standort',   d.location,   'white']);
  if (d.bandwidth)  fields.push(['Bandbreite', d.bandwidth,  'white']);
  if (d.created_at) fields.push(['Erstellt',   d.created_at, 'white']);

  document.getElementById('tab-body').innerHTML = `
    <div class="igrid mb16">
      ${fields.map(([l,v,c])=>`
        <div class="icell">
          <div class="ilbl">${l}</div>
          <div class="ival" style="color:${c}">${esc(String(v))}</div>
        </div>`).join('')}
    </div>
    <div class="sec">
      <div class="sechd"><span>⟲ &nbsp;Reverse-DNS verwalten</span></div>
      <div class="secb">
        <div id="rdns-msg"></div>
        <div class="fl flg8">
          <input class="kfi" id="inp-rdns" placeholder="${esc(ip.rdns||d.rdns||'z.B. server.domain.de')}" style="flex:1;"
            onkeydown="if(event.key==='Enter')kfip.submitRdns()"/>
          <button class="btn btn-c" id="btn-rdns" onclick="kfip.submitRdns()">Setzen</button>
        </div>
        <div style="margin-top:8px;font-size:10px;color:var(--dm);">Aktuell: ${esc(ip.rdns||d.rdns||'–')}</div>
      </div>
    </div>

    <details class="sec" style="padding:0;">
      <summary style="padding:10px 16px;cursor:pointer;font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:var(--dmr);user-select:none;">
        ⚙ Debug-Daten (API-Rohantwort)
      </summary>
      <div class="secb" style="border-top:1px solid var(--bd);">
        <div style="font-size:10px;color:var(--dm);margin-bottom:8px;">
          S.sel = <span style="color:var(--cy)">${esc(String(S.sel))}</span>
          (${esc(typeof S.sel)}) &nbsp;|&nbsp;
          findId(Liste)= <span style="color:var(--cy)">${esc(String(findId(ip)))}</span> &nbsp;|&nbsp;
          findId(Details)= <span style="color:var(--cy)">${esc(String(findId(d)))}</span>
        </div>
        <div style="font-size:9px;color:var(--dmr);letter-spacing:.1em;margin:8px 0 4px;">LISTEN-EINTRAG (keys: ${esc(Object.keys(ip).join(', ')||'–')})</div>
        <pre style="background:var(--bg3);border:1px solid var(--bd);border-radius:4px;padding:10px;font-size:11px;overflow-x:auto;color:var(--tx);margin:0 0 10px;white-space:pre-wrap;word-break:break-all;">${esc(JSON.stringify(ip, null, 2))}</pre>
        <div style="font-size:9px;color:var(--dmr);letter-spacing:.1em;margin:8px 0 4px;">DETAILS-ANTWORT (keys: ${esc(Object.keys(d).join(', ')||'–')})</div>
        <pre style="background:var(--bg3);border:1px solid var(--bd);border-radius:4px;padding:10px;font-size:11px;overflow-x:auto;color:var(--tx);margin:0;white-space:pre-wrap;word-break:break-all;">${esc(JSON.stringify(d, null, 2))}</pre>
      </div>
    </details>
  `;
}

async function submitRdns() {
  const val = (document.getElementById('inp-rdns')?.value||'').trim();
  if (!val) return;
  const btn   = document.getElementById('btn-rdns');
  const msgEl = document.getElementById('rdns-msg');
  btn.textContent='…'; btn.disabled=true;
  try {
    const res = await call({action:'rdns_manage', id:S.sel, rdns:val});
    if (res.error) {
      msgEl.innerHTML = `<div class="msg msg-err">✗ ${esc(res.message||'Fehler')}</div>`;
    } else {
      msgEl.innerHTML = `<div class="msg msg-ok">✓ RDNS auf "${esc(val)}" gesetzt.</div>`;
      const ip = S.ips.find(x=>String(x.id)===String(S.sel));
      if (ip) ip.rdns = val;
      if (S.det) S.det.rdns = val;
    }
  } catch(e) {
    msgEl.innerHTML = `<div class="msg msg-err">✗ ${esc(e.message)}</div>`;
  }
  btn.textContent='Setzen'; btn.disabled=false;
}

// ── TRAFFIC ───────────────────────────────────────────────────────────────────
async function renderTraffic() {
  const pLabel = {'1':'24 Stunden','7':'7 Tage','30':'30 Tage'};
  document.getElementById('tab-body').innerHTML = `
    <div class="fl flg8 mb14">
      ${['1:24 Std','7:7 Tage','30:30 Tage'].map(p=>{const[v,l]=p.split(':');
        return `<button class="perbtn${S.period===v?' active':''}" onclick="kfip.setPeriod('${v}')">${l}</button>`;
      }).join('')}
    </div>
    <div class="sec mb14">
      <div class="sechd">
        <span>◈ &nbsp;Traffic-Graph · ${pLabel[S.period]}</span>
        <span style="font-size:9px;display:inline-flex;align-items:center;gap:10px;"><span style="display:inline-flex;align-items:center;gap:5px;color:var(--cy);"><span style="display:inline-block;width:18px;height:2px;background:var(--cy);border-radius:1px;"></span>TCP</span><span style="display:inline-flex;align-items:center;gap:5px;color:var(--or);"><span style="display:inline-block;width:18px;height:0;border-top:2px dashed var(--or);"></span>UDP</span></span>
      </div>
      <div style="padding:12px 16px 8px;">
        <div id="graph-wrap"><div class="gfx-ph"><span class="spin"></span>Lade Graph…</div></div>
      </div>
    </div>
    <div class="sec">
      <div class="sechd"><span>⊟ &nbsp;Traffic-Tabelle · Letzte 30 Tage</span></div>
      <div style="overflow-x:auto;" id="tbl-wrap">
        <div style="padding:20px;text-align:center;"><span class="spin"></span></div>
      </div>
    </div>
  `;
  loadGraph();
  loadTable();
}

async function loadGraph() {
  try {
    const res = await call({action:'traffic_graph', id:S.sel, period:S.period});
    const w = document.getElementById('graph-wrap');
    if (!w) return;
    if (res.error || !res.image) {
      w.innerHTML = `<div class="msg msg-err" style="margin:0;">✗ ${esc(res.message||'Graph nicht verfügbar')}</div>`;
    } else {
      w.innerHTML = `<img class="gfx-img" src="${res.image}" alt="Traffic Graph" style="background:#fff;padding:4px;"/>
        <div style="margin-top:4px;display:flex;justify-content:space-between;font-size:9px;color:var(--dm);">
          <span>${esc(S.period==='1'?'Letzte 24h':S.period==='7'?'Letzte 7 Tage':'Letzte 30 Tage')}</span>
          <span>Heute</span>
        </div>`;
    }
  } catch(e) {
    const w = document.getElementById('graph-wrap');
    if (w) w.innerHTML = `<div class="msg msg-err" style="margin:0;">✗ ${esc(e.message)}</div>`;
  }
}

async function loadTable() {
  try {
    const res = await call({action:'traffic_table', id:S.sel});
    const w = document.getElementById('tbl-wrap');
    if (!w) return;
    if (res.error) { w.innerHTML=`<div class="secb"><div class="msg msg-err">✗ ${esc(res.message||'Fehler')}</div></div>`; return; }

    const rows = Array.isArray(res.data) ? res.data : [];
    const tot  = res.totals || {};

    if (!rows.length) { w.innerHTML='<div class="secb" style="color:var(--dmr);font-size:12px;">Keine Daten verfügbar.</div>'; return; }

    // ── Datum-Feld auto-erkennen ──────────────────────────────────────────────
    const firstRow = rows[0];
    const dateKey  = ['date','datum','day','ts','time','created_at']
                       .find(k => firstRow[k] != null)
                     || Object.keys(firstRow).find(k => typeof firstRow[k]==='string' && /\d{4}-\d{2}-\d{2}/.test(firstRow[k]))
                     || null;

    // ── Numerische Spalten auto-erkennen ─────────────────────────────────────
    const COL_META = {
      tcp:{l:'TCP (GB)',   c:'var(--cy)'},
      udp:{l:'UDP (GB)',   c:'var(--or)'},
      icmp:{l:'ICMP (GB)',  c:'var(--tx)'},
      ip: {l:'IP (GB)',    c:'var(--tx)'},
      total:{l:'Gesamt (GB)',c:'var(--tx)'},
      sum:  {l:'Gesamt (GB)',c:'var(--tx)'},
    };
    const numCols = Object.keys(firstRow).filter(k => {
      if (k === dateKey) return false;
      const v = firstRow[k];
      return typeof v==='number' || (typeof v==='string' && v!=='' && !isNaN(+v));
    });

    // Fallback wenn gar nichts erkannt wird: bekannte Namen ausprobieren
    const activeCols = numCols.length > 0 ? numCols : ['tcp','udp','icmp','total'];
    const maxT = Math.max(...rows.map(r => Math.max(...activeCols.map(k => +(r[k]||0)))), 0.01);
    const barCol = activeCols[0];

    const thCells  = `${dateKey?'<th>Datum</th>':''}${activeCols.map(k=>`<th>${COL_META[k]?.l||k.toUpperCase()+' (GB)'}</th>`).join('')}<th></th>`;
    const totCells = `
      ${dateKey?'<td style="color:var(--cy);border-top:1px solid var(--bd);background:#1d9ac708;font-weight:700;">∑ Gesamt</td>':''}
      ${activeCols.map(k=>`<td style="color:var(--cy);border-top:1px solid var(--bd);background:#1d9ac708;font-weight:700;">${(+(tot[k]||0)).toFixed(k==='icmp'?3:2)}</td>`).join('')}
      <td style="border-top:1px solid var(--bd);background:#1d9ac708;"></td>`;

    w.innerHTML = `
      <table class="ktbl">
        <thead><tr>${thCells}</tr></thead>
        <tbody>
          ${rows.map(r=>`
            <tr>
              ${dateKey?`<td style="color:var(--tx)">${esc(String(r[dateKey]||''))}</td>`:''}
              ${activeCols.map(k=>`<td style="color:${COL_META[k]?.c||'var(--tx)'}">${(+(r[k]||0)).toFixed(k==='icmp'?3:2)}</td>`).join('')}
              <td style="width:90px"><div class="tbar" style="width:${Math.max(2,(+(r[barCol]||0)/maxT)*70)}px"></div></td>
            </tr>`).join('')}
          <tr>${totCells}</tr>
        </tbody>
      </table>`;
  } catch(e) {
    const w = document.getElementById('tbl-wrap');
    if (w) w.innerHTML=`<div class="secb"><div class="msg msg-err">✗ ${esc(e.message)}</div></div>`;
  }
}

function setPeriod(p) {
  S.period=p;
  document.querySelectorAll('.perbtn').forEach(b=>{
    b.classList.toggle('active',
      (p==='1'&&b.textContent.includes('24'))||
      (p==='7'&&b.textContent.includes('7'))||
      (p==='30'&&b.textContent.includes('30'))
    );
  });
  // Refresh graph only (period doesn't affect table)
  const gw = document.getElementById('graph-wrap');
  if (gw) { gw.innerHTML='<div class="gfx-ph"><span class="spin"></span>Lade Graph…</div>'; }
  loadGraph();
}

// ── TOOLS ─────────────────────────────────────────────────────────────────────
function renderTools() {
  const ip = S.ips.find(x=>String(x.id)===String(S.sel))||{};
  const d  = S.det||ip;
  const tp = normalizeType(d.type||ip.type);

  document.getElementById('tab-body').innerHTML = `
    <div class="sec">
      <div class="sechd"><span>⊛ &nbsp;Port-Scanner</span></div>
      <div class="secb">
        <div style="font-size:11px;color:var(--dm);margin-bottom:10px;">
          TCP-Port-Erreichbarkeit über <span style="color:white;">${esc(ip.ip||d.ip||'')}</span> prüfen
        </div>
        <div class="fl flg8 mb14">
          <input class="kfi" id="inp-port" type="number" min="1" max="65535" placeholder="Port (z.B. 443)"
            style="width:140px;" onkeydown="if(event.key==='Enter')kfip.scanPort()"/>
          <button class="btn btn-o" id="btn-scan" onclick="kfip.scanPort()">Prüfen →</button>
        </div>
        <div id="port-result" class="mb14"></div>
        <div style="font-size:9px;color:var(--dmr);letter-spacing:.14em;margin-bottom:7px;">SCHNELLZUGRIFF</div>
        <div class="fl" style="gap:5px;flex-wrap:wrap;" id="pq-wrap">
          ${[['80','HTTP'],['443','HTTPS'],['22','SSH'],['21','FTP'],['25','SMTP'],['53','DNS'],
             ['3306','MySQL'],['5432','PG'],['8080','HTTP-Alt'],['6881','BT']].map(([p,n])=>
            `<button class="pq" onclick="kfip.quickPort('${p}')">${p} <span style="opacity:.45">${n}</span></button>`
          ).join('')}
        </div>
      </div>
    </div>
    ${tp==='dg'?`
    <div class="sec">
      <div class="sechd"><span>⟳ &nbsp;DG-WAN-IP aktualisieren</span></div>
      <div class="secb">
        <div style="font-size:11px;color:var(--dm);margin-bottom:10px;">
          Leitet die öffentliche IP auf eine neue Router-WAN-IP um.<br>
          Aktualisiert RouterOS, Datenbank und PowerDNS – benachrichtigt den Support per E-Mail.<br>
          Aktuelle WAN-IP: <span style="color:white;font-weight:700;">${esc(d.wan_ip||'–')}</span>
        </div>
        <div class="fl flg8 mb10">
          <input class="kfi" id="inp-wan-ip" placeholder="Neue WAN-IP (z.B. 198.51.100.10)"
            style="flex:1;" onkeydown="if(event.key==='Enter')kfip.updateWanIP()"/>
          <button class="btn btn-c" id="btn-wan" onclick="kfip.updateWanIP()">Aktualisieren</button>
        </div>
        <div id="wan-msg"></div>
        <div style="font-size:10px;color:var(--rd);padding:6px 8px;background:#d4555510;border:1px solid #d4555525;border-radius:4px;">
          ⚠ Diese Aktion ändert das Routing im Kernnetz und kann nicht automatisch rückgängig gemacht werden.
        </div>
      </div>
    </div>`:''}
  `;
}

async function scanPort() {
  const val = document.getElementById('inp-port')?.value?.trim();
  if (!val) return;
  const btn = document.getElementById('btn-scan');
  const res = document.getElementById('port-result');
  btn.innerHTML='<span class="spin"></span>'; btn.disabled=true;
  try {
    const r = await call({action:'portscan', id:S.sel, port:val});
    if (r.error) {
      res.innerHTML=`<div class="msg msg-err">✗ ${esc(r.message||'Fehler')}</div>`;
    } else {
      const open = r.open===true || r.status==='open' || r.result==='open';
      res.innerHTML=`<div class="msg ${open?'msg-ok':'msg-err'}">
        ${open?'✓ PORT OFFEN':'✗ PORT GESCHLOSSEN'}
        <span style="opacity:.5;margin-left:12px;font-size:11px;">${esc(String(r.ip||''))}:${esc(String(r.port||val))} · TCP</span>
      </div>`;
    }
  } catch(e) {
    res.innerHTML=`<div class="msg msg-err">✗ ${esc(e.message)}</div>`;
  }
  btn.textContent='Prüfen →'; btn.disabled=false;
}

function quickPort(p) {
  const inp = document.getElementById('inp-port');
  if (inp) inp.value = p;
  document.querySelectorAll('.pq').forEach(b=>b.classList.toggle('active',b.textContent.startsWith(p)));
}

async function updateWanIP() {
  const newIp = (document.getElementById('inp-wan-ip')?.value||'').trim();
  if (!newIp) return;
  if (!confirm(`WAN-IP auf "${newIp}" setzen?\n\nDiese Aktion ändert das Routing im Kernnetz und benachrichtigt den Kossmann-Support per E-Mail.`)) return;
  const btn   = document.getElementById('btn-wan');
  const msgEl = document.getElementById('wan-msg');
  btn.textContent='…'; btn.disabled=true;
  try {
    const r = await call({action:'dgwan_manage', id:S.sel, dg_ipv4_neu:newIp});
    if (r.error) {
      msgEl.innerHTML=`<div class="msg msg-err">✗ ${esc(r.message||'Fehler')}</div>`;
    } else {
      msgEl.innerHTML=`<div class="msg msg-ok">✓ ${esc(r.message||'WAN-IP aktualisiert.')}</div>`;
      if (S.det) S.det.wan_ip = newIp;
    }
  } catch(e) {
    msgEl.innerHTML=`<div class="msg msg-err">✗ ${esc(e.message)}</div>`;
  }
  btn.textContent='Aktualisieren'; btn.disabled=false;
}

// ── CREDENTIALS ───────────────────────────────────────────────────────────────
function showCreds() {
  const ov = document.getElementById('creds-ov');
  ov.style.display='flex';
}

function hideCreds() {
  document.getElementById('creds-ov').style.display='none';
}

async function saveCredentials() {
  const cid = (document.getElementById('inp-cid')?.value||'').trim();
  const pwd = (document.getElementById('inp-pwd')?.value||'').trim();
  const msg = document.getElementById('creds-msg');
  if (!cid||!pwd) { msg.innerHTML='<div class="msg msg-err">✗ Bitte alle Felder ausfüllen.</div>'; return; }
  msg.innerHTML='<div class="msg msg-inf"><span class="spin"></span> Speichere…</div>';
  try {
    // GET statt POST: Umgeht nginx-POST-Routing in Unraid 7.x
    const r = await call({_op:'save_config', customer_id:cid, password:pwd});
    if (r.ok||!r.error) {
      S.configured=true;
      msg.innerHTML='<div class="msg msg-ok">✓ Gespeichert. Lade IP-Liste…</div>';
      setTimeout(()=>{ hideCreds(); loadList(); }, 800);
    } else {
      msg.innerHTML=`<div class="msg msg-err">✗ ${esc(r.message||'Fehler')}</div>`;
    }
  } catch(e) {
    msg.innerHTML=`<div class="msg msg-err">✗ ${esc(e.message)}</div>`;
  }
}

// Overlay per Klick außerhalb schließen
document.getElementById('creds-ov').addEventListener('click', e=>{
  if (e.target===e.currentTarget) hideCreds();
});

// ── START ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);

return { init, reload, selectIP, setTab, setPeriod, submitRdns, scanPort, quickPort, updateWanIP, showCreds, hideCreds, saveCredentials };

})();
</script>
</body>
</html>
