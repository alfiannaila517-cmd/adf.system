<?php
function pwfOfficeHeader(string $title, string $active = ''): void
{
    $menu = [
        'dashboard' => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2',     'url' => 'dashboard.php'],
        'customers' => ['label' => 'Customers',         'icon' => 'bi-people',           'url' => 'customers.php'],
        'orders'    => ['label' => 'Orders',            'icon' => 'bi-clipboard2-check', 'url' => 'orders.php'],
        'craftsmen' => ['label' => 'Craftsmen',         'icon' => 'bi-hammer',           'url' => 'craftsmen.php'],
        'progress'  => ['label' => 'Progress Tracking', 'icon' => 'bi-bar-chart-line',   'url' => 'progress.php'],
        'shipping'  => ['label' => 'Shipping & Export', 'icon' => 'bi-box-seam',         'url' => 'shipping.php'],
        'settings'  => ['label' => 'Settings',          'icon' => 'bi-gear',             'url' => 'settings.php'],
    ];
    $fullName = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
    $initials = strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 2));


    // Load logo + theme from DB
    $logoUrl = ''; $pwfTheme = 'light';
    try {
        $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
        $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
        $pdo2 = new PDO("mysql:host=".DB_HOST.";dbname=".$pwfDb.";charset=utf8mb4", DB_USER, DB_PASS);
        $dbSettings = $pdo2->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_login_logo','pwf_ui_theme')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($dbSettings['pwf_login_logo'])) $logoUrl = htmlspecialchars($dbSettings['pwf_login_logo']);
        if (!empty($dbSettings['pwf_ui_theme']) && in_array($dbSettings['pwf_ui_theme'],['light','dark'])) $pwfTheme = $dbSettings['pwf_ui_theme'];
    } catch (Exception $e) {}
?>
<!doctype html>
<html lang="en" data-theme="<?= $pwfTheme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · PWF Office</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{
  --gold:#B8860B;--gold-light:#D4A017;--gold-bg:#FEF9EC;--gold-border:#EDD07A;
  --sidebar-w:260px;
  --bg:#F8F6F3;--card:#FFFFFF;--text:#1C1917;--muted:#78716C;
  --border:#E7E5E4;--brand-dark:#1C1511;
  --success:#16A34A;--danger:#DC2626;
  --nav-bg:#fff;--nav-hover:#F5F3F0;--sidebar-logo-bg:#fff;
  --topbar-bg:#fff;--input-bg:#fff;
}
[data-theme="dark"]{
  --bg:#111113;--card:#1C1C1F;--text:#ECECEC;--muted:#8A8A90;
  --border:#2C2C30;--brand-dark:#111113;
  --nav-bg:#161618;--nav-hover:#222226;--sidebar-logo-bg:#1C1C1F;
  --topbar-bg:#161618;--input-bg:#222226;
  --gold-bg:rgba(184,134,11,.18);--gold-border:rgba(212,160,23,.35);
}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);font-size:13px}

/* ── SIDEBAR ─────────────────────────────────────────── */
.pwf-sidebar{background:var(--nav-bg);border-right:1px solid var(--border);
  position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;
  display:flex;flex-direction:column;z-index:100;
  box-shadow:2px 0 12px rgba(0,0,0,.06)
}
.pwf-sidebar-logo{
  padding:16px 16px 12px;
  display:flex;flex-direction:column;align-items:center;gap:7px;
  background:var(--sidebar-logo-bg);border-bottom:1px solid var(--border);flex-shrink:0;text-align:center
}
.pwf-logo-box{
  width:160px;height:72px;border-radius:10px;
  background:var(--card);
  border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:30px;overflow:hidden;flex-shrink:0;
  box-shadow:0 2px 10px rgba(0,0,0,.07)
}
.pwf-logo-box img{width:100%;height:100%;object-fit:contain;padding:8px}
.pwf-brand-text .pwf-brand-name{font-size:11.5px;font-weight:700;color:var(--text);line-height:1.3;letter-spacing:.3px;text-transform:uppercase}
.pwf-brand-text .pwf-brand-sub{font-size:10px;color:var(--muted);margin-top:1px;font-weight:400}

.pwf-nav{flex:1;padding:10px 8px;overflow-y:auto}
.pwf-nav a{
  display:flex;align-items:center;gap:9px;
  padding:8px 12px;border-radius:8px;margin-bottom:1px;
  color:var(--muted);text-decoration:none;font-size:12.5px;font-weight:500;
  transition:background .15s,color .15s;border-left:3px solid transparent
}
.pwf-nav a:hover{background:var(--nav-hover);color:var(--text)}
.pwf-nav a.active{
  background:var(--gold-bg);color:#92600A;border-left-color:var(--gold);
  font-weight:600
}
.pwf-nav a i{font-size:15px;flex-shrink:0}
.pwf-nav .nav-section{
  font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
  color:#C4B8B0;padding:12px 12px 4px;margin-top:2px
}

.pwf-sidebar-footer{
  padding:12px 14px;border-top:1px solid var(--border);
  display:flex;align-items:center;gap:9px;flex-shrink:0
}
.pwf-avatar{
  width:30px;height:30px;border-radius:50%;
  background:linear-gradient(135deg,var(--gold-light),#8A6000);
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:700;color:#fff;flex-shrink:0
}
.pwf-user-name{font-size:12px;font-weight:600;color:var(--text);line-height:1.2}
.pwf-user-role{font-size:10.5px;color:var(--muted)}
.pwf-logout{margin-left:auto;color:var(--muted);font-size:15px;text-decoration:none;transition:color .15s}
.pwf-logout:hover{color:var(--danger)}

/* ── MAIN WRAPPER ────────────────────────────────────── */
.pwf-main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.pwf-topbar{
  height:56px;background:var(--topbar-bg);border-bottom:1px solid var(--border);
  padding:0 24px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:50
}
.pwf-topbar-title{font-size:15.5px;font-weight:700;color:var(--text)}
.pwf-topbar-sub{font-size:11.5px;color:var(--muted);margin-top:1px}
.pwf-topbar-right{display:flex;align-items:center;gap:8px}
.pwf-date-badge{
  font-size:11.5px;color:var(--muted);
  background:var(--bg);border:1px solid var(--border);
  padding:4px 11px;border-radius:20px
}

.pwf-content{padding:24px 28px;flex:1}

/* ── CARDS ────────────────────────────────────────────── */
.pwf-card,.card{
  background:var(--card);border-radius:12px;
  border:1px solid var(--border);
  box-shadow:0 1px 4px rgba(0,0,0,.05)
}
.pwf-card-header,.card-header{
  padding:13px 18px;border-bottom:1px solid var(--border);
  font-weight:600;font-size:13.5px;display:flex;align-items:center;justify-content:space-between
}
.pwf-card-body,.card-body{padding:18px}

/* ── STAT CARDS ──────────────────────────────────────── */
.stat-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.stat-card{
  background:var(--card);border:1px solid var(--border);border-radius:12px;
  padding:20px;display:flex;align-items:center;gap:16px;
  box-shadow:0 1px 4px rgba(0,0,0,.05)
}
.stat-icon{
  width:48px;height:48px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0
}
.stat-val{font-size:26px;font-weight:800;color:var(--text);line-height:1}
.stat-lbl{font-size:12px;color:var(--muted);margin-top:3px}

/* ── TABLE ───────────────────────────────────────────── */
.pwf-table{width:100%;border-collapse:collapse}
.pwf-table thead th{
  padding:8px 12px;font-size:10.5px;font-weight:700;text-transform:uppercase;
  letter-spacing:.5px;color:var(--muted);background:var(--nav-hover);
  border-bottom:1px solid var(--border);border-top:1px solid var(--border)
}
.pwf-table tbody td{
  padding:9px 12px;border-bottom:1px solid var(--border);
  font-size:12.5px;color:var(--text)
}
.pwf-table tbody tr:last-child td{border-bottom:none}
.pwf-table tbody tr:hover td{background:var(--nav-hover)}

/* ── FORM ELEMENTS ───────────────────────────────────── */
.pwf-form-group{margin-bottom:12px}
.pwf-form-group label,.form-label{
  display:block;margin-bottom:4px;font-size:11px;
  font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px
}
.input,.select,.pwf-input,.pwf-select,
input[type=text],input[type=date],input[type=number],input[type=email],
input[type=file],select,textarea{
  width:100%;padding:7px 11px;
  border:1px solid var(--border);border-radius:7px;
  background:var(--input-bg);color:var(--text);font-size:13px;
  font-family:'Inter',sans-serif;outline:none;
  transition:border-color .15s,box-shadow .15s
}
.input:focus,.pwf-input:focus,
input[type=text]:focus,input[type=date]:focus,input[type=number]:focus,
input[type=email]:focus,select:focus,textarea:focus{
  border-color:var(--gold);box-shadow:0 0 0 3px rgba(184,134,11,.12)
}
textarea{resize:vertical;min-height:80px}

/* ── BUTTONS ─────────────────────────────────────────── */
.btn,.pwf-btn{
  padding:7px 16px;border-radius:7px;border:none;cursor:pointer;
  font-size:12.5px;font-weight:600;font-family:'Inter',sans-serif;
  transition:opacity .15s,transform .1s;display:inline-flex;align-items:center;gap:6px
}
.btn,.btn-primary{background:var(--gold);color:#fff}
.btn:hover,.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn.warn,.btn-danger,.btn-outline-danger{background:transparent;border:1px solid #FECACA;color:var(--danger)}
.btn-outline-danger:hover{background:#FEF2F2}
.btn-danger{background:var(--danger)!important;color:#fff!important;border:none!important}
.btn-success{background:var(--success)!important;color:#fff!important;border:none!important}
.btn-outline-secondary{background:transparent;border:1px solid var(--border);color:var(--muted)}
.btn-outline-secondary:hover{background:var(--nav-hover);color:var(--text)}
.btn-sm{padding:5px 10px;font-size:11.5px}
.btn-outline-light{background:transparent;border:1px solid rgba(255,255,255,.3);color:#fff;font-size:12px}
.btn-outline-light:hover{background:rgba(255,255,255,.1)}
.btn-export{background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 11px;font-size:11.5px;border-radius:6px;gap:5px}
.btn-export:hover{background:#F0FDF4;border-color:#BBF7D0;color:var(--success)}

/* ── BADGES & ALERTS ─────────────────────────────────── */
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.bg-secondary{background:#E7E5E4!important;color:var(--muted)!important}
.alert{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:16px}
.alert-success{background:#F0FDF4;border:1px solid #BBF7D0;color:var(--success)}
.alert-danger{background:#FEF2F2;border:1px solid #FECACA;color:var(--danger)}

/* ── STATUS BADGE ────────────────────────────────────── */
.status-badge{
  display:inline-block;padding:3px 10px;border-radius:20px;
  font-size:11.5px;font-weight:600;text-transform:capitalize
}
.status-new,.status-pending{background:#EFF6FF;color:#1D4ED8}
.status-on_progress{background:#FFF7ED;color:#C2410C}
.status-qc{background:#F5F3FF;color:#6D28D9}
.status-ready_ship{background:#F0FDF4;color:#15803D}
.status-shipped{background:#ECFDF5;color:#047857}
.status-completed{background:#F0FDF4;color:#166534}
.status-cancelled{background:#FEF2F2;color:#991B1B}
.status-booked{background:#EFF6FF;color:#1D4ED8}
.status-onboard{background:#FFF7ED;color:#C2410C}
.status-arrived{background:#F0FDF4;color:#15803D}
.status-closed{background:#F5F5F4;color:var(--muted)}

/* ── LEGACY COMPAT ───────────────────────────────────── */
.row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:16px}
.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.mt{margin-top:12px}
.card.stat{padding:20px}
.card.stat h3{margin:0;font-size:26px;font-weight:800}
.card.stat p{margin:4px 0 0;color:var(--muted);font-size:12px}
.small{font-size:12px;color:var(--muted)}
table{width:100%;border-collapse:collapse}
table th,table td{padding:10px 12px;text-align:left;font-size:13.5px;border-bottom:1px solid var(--border)}
table thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:#FAFAF9}
.text-muted{color:var(--muted)!important}
.pwf-content-header{margin-bottom:20px}
.pwf-page-title{font-size:20px;font-weight:700;color:var(--text);margin:0 0 4px}
.pwf-page-sub{font-size:13px;color:var(--muted);margin:0}
.mb-4{margin-bottom:16px!important}
.mb-3{margin-bottom:12px!important}
.mt-2{margin-top:8px!important}
.d-flex{display:flex!important}.gap-2{gap:8px!important}.flex-wrap{flex-wrap:wrap!important}
.col-12{width:100%}.col-md-6{width:50%}.col-md-8{width:66.66%}.col-md-4{width:33.33%}
.col-lg-6{width:50%}
.row.g-4{gap:16px;display:grid;grid-template-columns:repeat(2,1fr);margin:0}
.col-12.col-lg-6:last-child,.col-12{grid-column:1/-1}
.col-lg-6{grid-column:span 1}
.justify-content-between{justify-content:space-between!important}
.align-items-center{align-items:center!important}
.text-center{text-align:center!important}
.form-control{
  width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;
  background:var(--input-bg);color:var(--text);font-size:14px;font-family:'Inter',sans-serif;outline:none
}
.form-control:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(184,134,11,.12)}

@media(max-width:900px){
  .pwf-sidebar{transform:translateX(-100%);transition:transform .25s}
  .pwf-sidebar.open{transform:none}
  .pwf-main{margin-left:0}
  .row,.stat-cards{grid-template-columns:repeat(2,1fr)}
  .grid2{grid-template-columns:1fr}
  .row.g-4{grid-template-columns:1fr}
  .col-md-6,.col-md-8,.col-md-4,.col-lg-6{width:100%}
}
@media(max-width:600px){
  .row,.stat-cards{grid-template-columns:1fr}
  .pwf-content{padding:16px}
  .pwf-topbar{padding:0 16px}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="pwf-sidebar">
  <div class="pwf-sidebar-logo">
    <div class="pwf-logo-box">
      <?php if ($logoUrl): ?><img src="<?= $logoUrl ?>" alt="PWF"><?php else: ?>🪵<?php endif; ?>
    </div>
    <div class="pwf-brand-text">
      <div class="pwf-brand-name">PWF OFFICE</div>
      <div class="pwf-brand-sub">Prapen Wood Furniture</div>
    </div>
  </div>


  <nav class="pwf-nav">
    <div class="nav-section">Main Menu</div>
    <?php foreach ($menu as $key => $m): ?>
      <a href="<?= BASE_URL ?>/modules/pwf-office/<?= $m['url'] ?>"
         class="<?= $active === $key ? 'active' : '' ?>">
        <i class="bi <?= $m['icon'] ?>"></i>
        <?= htmlspecialchars($m['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="pwf-sidebar-footer">
    <div class="pwf-avatar"><?= $initials ?></div>
    <div>
      <div class="pwf-user-name"><?= $fullName ?></div>
      <div class="pwf-user-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Staff') ?></div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="pwf-logout" title="Logout">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="pwf-main">
  <div class="pwf-topbar">
    <div>
      <div class="pwf-topbar-title"><?= htmlspecialchars($title) ?></div>
      <div class="pwf-topbar-sub">PWF Office Management System</div>
    </div>
    <div class="pwf-topbar-right">
      <span class="pwf-date-badge"><i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y') ?></span>
    </div>
  </div>
  <div class="pwf-content">
<?php
}

function pwfOfficeFooter(): void
{
    echo '  </div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>';
}
