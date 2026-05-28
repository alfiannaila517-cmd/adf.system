<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_helper.php';

$auth = new Auth();
$error = '';

if ($auth->isLoggedIn() && empty($_POST)) {
    @setActiveBusinessId('pwf-furniture');
    header('Location: ' . BASE_URL . '/modules/pwf-office/dashboard.php');
    exit;
}

if (!empty($_POST)) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($auth->login($username, $password)) {
        @setActiveBusinessId('pwf-furniture');
        header('Location: ' . BASE_URL . '/modules/pwf-office/dashboard.php');
        exit;
    }
    $error = 'Login failed. Invalid username or password.';
}

$wallpaperUrl = '';
$logoUrl = '';
$companyName = 'Prapen Wood Furniture';
try {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
    $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
    $pwfPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $pwfDb . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pwfPdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('pwf_login_wallpaper','pwf_login_logo','pwf_company_name')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['pwf_login_wallpaper'])) $wallpaperUrl = htmlspecialchars($rows['pwf_login_wallpaper']);
    if (!empty($rows['pwf_login_logo']))      $logoUrl      = htmlspecialchars($rows['pwf_login_logo']);
    if (!empty($rows['pwf_company_name']))    $companyName  = htmlspecialchars($rows['pwf_company_name']);
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PWF Office – Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--gold:#D4A017;--gold-lt:#F5C842}
body{font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0e1a;position:relative;overflow:hidden}
.wallpaper{position:fixed;inset:0;z-index:0}
.wallpaper-bg{
  position:absolute;inset:0;
  <?php if($wallpaperUrl): ?>
  background:url('<?= $wallpaperUrl ?>') center/cover no-repeat;
  <?php else: ?>
  background:radial-gradient(ellipse at 70% 20%,#1a3a2e 0%,#0a0e1a 55%,#0f172a 100%);
  <?php endif; ?>
}
.wallpaper-overlay{position:absolute;inset:0;background:rgba(8,12,25,<?= $wallpaperUrl ? '.72' : '.55' ?>);backdrop-filter:blur(<?= $wallpaperUrl ? '3px' : '0' ?>)}
.grain{position:fixed;inset:0;z-index:1;opacity:.03;
  background-image:repeating-linear-gradient(90deg,transparent,transparent 2px,rgba(212,160,23,.5) 2px,rgba(212,160,23,.5) 3px);
  pointer-events:none}

.card{
  position:relative;z-index:2;width:min(840px,95vw);
  display:grid;grid-template-columns:1.1fr 1fr;
  background:rgba(10,14,26,.88);border-radius:18px;overflow:hidden;
  border:1px solid rgba(212,160,23,.18);
  box-shadow:0 28px 72px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.03)
}

/* ── LEFT ── */
.lp{
  padding:36px 32px;
  background:linear-gradient(160deg,rgba(22,50,38,.95) 0%,rgba(10,14,26,.98) 100%);
  display:flex;flex-direction:column;gap:20px;
  border-right:1px solid rgba(212,160,23,.1)
}

/* Logo box identical to sidebar */
.logo-box{
  width:56px;height:56px;border-radius:11px;
  background:#fff;
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;flex-shrink:0;
  box-shadow:0 3px 14px rgba(0,0,0,.35)
}
.logo-box img{width:100%;height:100%;object-fit:contain;padding:4px}
.logo-box .logo-icon{font-size:26px;line-height:1}

.brand-row{display:flex;align-items:center;gap:11px}
.brand-name{font-size:14px;font-weight:800;color:var(--gold-lt);letter-spacing:.3px;text-transform:uppercase}
.brand-sub{font-size:10.5px;color:rgba(255,255,255,.38);margin-top:2px}

.tagline{font-size:21px;font-weight:700;color:#fff;line-height:1.4}
.tagline em{font-style:normal;color:var(--gold-lt)}
.desc{font-size:12px;color:rgba(255,255,255,.48);line-height:1.7}

.mods{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.mod{font-size:11px;color:rgba(255,255,255,.42);display:flex;align-items:center;gap:6px}
.dot{width:5px;height:5px;border-radius:50%;background:var(--gold);flex-shrink:0}

.addr{padding:11px 13px;background:rgba(255,255,255,.035);border-radius:9px;border-left:3px solid var(--gold)}
.addr p{font-size:11px;color:rgba(255,255,255,.38);line-height:1.65}

/* ── RIGHT ── */
.rp{padding:36px 32px;background:rgba(5,8,18,.92);display:flex;flex-direction:column;justify-content:center}
.ltitle{font-size:20px;font-weight:700;color:#fff;margin-bottom:4px}
.lsub{font-size:12px;color:rgba(255,255,255,.35);margin-bottom:24px}

.field{margin-bottom:14px}
.field label{font-size:10.5px;color:rgba(255,255,255,.45);font-weight:600;
  letter-spacing:.5px;text-transform:uppercase;display:block;margin-bottom:6px}
.field input{
  width:100%;padding:11px 14px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  border-radius:9px;color:#fff;font-size:13px;
  outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit}
.field input::placeholder{color:rgba(255,255,255,.22)}
.field input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,160,23,.12)}

.btn{
  width:100%;padding:12px;margin-top:4px;
  background:linear-gradient(135deg,var(--gold),#8a6000);
  border:none;border-radius:9px;color:#0a0e1a;
  font-size:13px;font-weight:700;cursor:pointer;
  letter-spacing:.5px;text-transform:uppercase;
  transition:opacity .2s,transform .12s;font-family:inherit}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.btn:active{transform:translateY(0)}

.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);
  border-radius:9px;padding:10px 13px;color:#fca5a5;font-size:12px;margin-bottom:13px}
.fnote{margin-top:20px;font-size:10.5px;color:rgba(255,255,255,.18);text-align:center}

@media(max-width:660px){
  .card{grid-template-columns:1fr}
  .lp,.rp{padding:28px 22px}
  .mods{grid-template-columns:1fr}
  .tagline{font-size:18px}
}
</style>
</head>
<body>
<div class="wallpaper">
  <div class="wallpaper-bg"></div>
  <div class="wallpaper-overlay"></div>
</div>
<div class="grain"></div>
<div class="card">

  <!-- LEFT -->
  <div class="lp">
    <div class="brand-row">
      <div class="logo-box">
        <?php if ($logoUrl): ?>
          <img src="<?= $logoUrl ?>" alt="Logo">
        <?php else: ?>
          <span class="logo-icon">🪵</span>
        <?php endif; ?>
      </div>
      <div>
        <div class="brand-name">PWF Office</div>
        <div class="brand-sub">Management System</div>
      </div>
    </div>

    <div>
      <div class="tagline">Crafting <em>Excellence</em>,<br>Tracking Every Step</div>
    </div>

    <div class="desc">
      Internal management system for <?= $companyName ?> — order monitoring,
      craftsman assignment, daily progress tracking, and international container export.
    </div>

    <div class="mods">
      <div class="mod"><span class="dot"></span>Customer Data</div>
      <div class="mod"><span class="dot"></span>Order &amp; Blueprint</div>
      <div class="mod"><span class="dot"></span>Craftsman Data</div>
      <div class="mod"><span class="dot"></span>Daily Progress</div>
      <div class="mod"><span class="dot"></span>QC &amp; Finishing</div>
      <div class="mod"><span class="dot"></span>Export Container</div>
    </div>

    <div class="addr">
      <p>Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan<br>
         Kec. Batealit, Kab. Jepara, Central Java 59400</p>
    </div>
  </div>

  <!-- RIGHT -->
  <div class="rp">
    <div class="ltitle">Welcome Back</div>
    <div class="lsub">Sign in to PWF Office System</div>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="field">
        <label>Username</label>
        <input name="username" type="text" autocomplete="username" required placeholder="Enter your username">
      </div>
      <div class="field">
        <label>Password</label>
        <input name="password" type="password" autocomplete="current-password" required placeholder="••••••••">
      </div>
      <button class="btn" type="submit">Sign In &rarr;</button>
    </form>

    <div class="fnote">© 2026 <?= $companyName ?> · PWF Office v1.0</div>
  </div>

</div>
</body>
</html>
