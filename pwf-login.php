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
    $error = 'Login gagal. Username atau password tidak valid.';
}

// Ambil wallpaper & logo dari settings bisnis PWF
$wallpaperUrl = '';
$logoUrl = '';
try {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
    $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
    $pwfPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $pwfDb . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $wall = $pwfPdo->query("SELECT setting_value FROM settings WHERE setting_key='pwf_login_wallpaper' LIMIT 1")->fetch();
    $logo = $pwfPdo->query("SELECT setting_value FROM settings WHERE setting_key='pwf_login_logo' LIMIT 1")->fetch();
    if ($wall && !empty($wall['setting_value'])) $wallpaperUrl = htmlspecialchars($wall['setting_value']);
    if ($logo && !empty($logo['setting_value'])) $logoUrl = htmlspecialchars($logo['setting_value']);
} catch (Exception $e) { /* DB belum ada atau settings kosong, gunakan default */ }
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PWF Office – Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--gold:#d4a017;--gold-light:#f5c842}
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
.wallpaper-overlay{position:absolute;inset:0;background:rgba(8,12,25,.65);backdrop-filter:blur(2px)}
.grain{position:fixed;inset:0;z-index:1;opacity:.035;
  background-image:repeating-linear-gradient(90deg,transparent,transparent 2px,rgba(212,160,23,.5) 2px,rgba(212,160,23,.5) 3px);
  pointer-events:none}
.container{
  position:relative;z-index:2;width:min(900px,96vw);
  display:grid;grid-template-columns:1.15fr 1fr;
  background:rgba(10,14,26,.9);border-radius:20px;overflow:hidden;
  border:1px solid rgba(212,160,23,.22);
  box-shadow:0 32px 80px rgba(0,0,0,.75),0 0 0 1px rgba(255,255,255,.04)
}
/* LEFT */
.lp{padding:48px 40px;
  background:linear-gradient(160deg,rgba(26,58,46,.92) 0%,rgba(10,14,26,.97) 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  border-right:1px solid rgba(212,160,23,.12)}
.logo-row{display:flex;align-items:center;gap:14px;margin-bottom:30px}
.logo-wrap{
  width:64px;height:64px;border-radius:14px;
  background:linear-gradient(135deg,var(--gold),#8a6000);
  display:flex;align-items:center;justify-content:center;
  font-size:32px;overflow:hidden;flex-shrink:0;
  box-shadow:0 4px 22px rgba(212,160,23,.45)}
.logo-wrap img{width:100%;height:100%;object-fit:cover}
.brand-name{font-size:22px;font-weight:900;color:var(--gold-light);line-height:1.2}
.brand-sub{font-size:12px;color:rgba(255,255,255,.48);margin-top:3px}
.tagline{font-size:27px;font-weight:700;color:#fff;line-height:1.4;margin-bottom:14px}
.tagline em{font-style:normal;color:var(--gold-light)}
.desc{font-size:13.5px;color:rgba(255,255,255,.56);line-height:1.7}
.mods{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:22px}
.mod{font-size:12px;color:rgba(255,255,255,.48);display:flex;align-items:center;gap:7px}
.dot{width:6px;height:6px;border-radius:50%;background:var(--gold);flex-shrink:0}
.addr{margin-top:26px;padding:14px;background:rgba(255,255,255,.04);
  border-radius:10px;border-left:3px solid var(--gold)}
.addr p{font-size:12px;color:rgba(255,255,255,.46);line-height:1.65}
/* RIGHT */
.rp{padding:48px 40px;background:rgba(5,8,18,.93);display:flex;flex-direction:column;justify-content:center}
.ltitle{font-size:22px;font-weight:700;color:#fff;margin-bottom:5px}
.lsub{font-size:13px;color:rgba(255,255,255,.38);margin-bottom:28px}
.field{margin-bottom:17px}
.field label{font-size:11px;color:rgba(255,255,255,.48);font-weight:600;
  letter-spacing:.6px;text-transform:uppercase;display:block;margin-bottom:7px}
.field input{
  width:100%;padding:13px 16px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
  border-radius:10px;color:#fff;font-size:14px;
  outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit}
.field input::placeholder{color:rgba(255,255,255,.25)}
.field input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,160,23,.14)}
.btn{
  width:100%;padding:14px;margin-top:4px;
  background:linear-gradient(135deg,var(--gold),#8a6000);
  border:none;border-radius:10px;color:#0a0e1a;
  font-size:15px;font-weight:700;cursor:pointer;
  letter-spacing:.5px;transition:opacity .2s,transform .12s;font-family:inherit}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.err{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.34);
  border-radius:10px;padding:11px 14px;color:#fca5a5;font-size:13px;margin-bottom:14px}
.fnote{margin-top:24px;font-size:11px;color:rgba(255,255,255,.2);text-align:center}
@media(max-width:700px){
  .container{grid-template-columns:1fr}
  .lp,.rp{padding:32px 26px}
  .mods{grid-template-columns:1fr}
  .tagline{font-size:22px}
}
</style>
</head>
<body>
<div class="wallpaper">
  <div class="wallpaper-bg"></div>
  <div class="wallpaper-overlay"></div>
</div>
<div class="grain"></div>
<div class="container">

  <!-- LEFT BRANDING PANEL -->
  <div class="lp">
    <div>
      <div class="logo-row">
        <div class="logo-wrap">
          <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" alt="Logo PWF">
          <?php else: ?>
            🪵
          <?php endif; ?>
        </div>
        <div>
          <div class="brand-name">PWF OFFICE</div>
          <div class="brand-sub">Management System</div>
        </div>
      </div>
      <div class="tagline">Crafting <em>Excellence</em>,<br>Tracking Every Step</div>
      <div class="desc">
        Sistem manajemen internal Prapen Wood Furniture untuk monitoring order,
        assignment tukang, progress pengerjaan, hingga ekspor container internasional.
      </div>
      <div class="mods">
        <div class="mod"><span class="dot"></span>Data Customer</div>
        <div class="mod"><span class="dot"></span>Order &amp; Blueprint</div>
        <div class="mod"><span class="dot"></span>Data Tukang</div>
        <div class="mod"><span class="dot"></span>Progress Harian</div>
        <div class="mod"><span class="dot"></span>QC &amp; Finishing</div>
        <div class="mod"><span class="dot"></span>Export Container</div>
      </div>
    </div>
    <div class="addr">
      <p>Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan<br>
         Kec. Batealit, Kab. Jepara, Jawa Tengah 59400</p>
    </div>
  </div>

  <!-- RIGHT LOGIN PANEL -->
  <div class="rp">
    <div class="ltitle">Selamat Datang</div>
    <div class="lsub">Masuk ke PWF Office System</div>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="field">
        <label>Username</label>
        <input name="username" type="text" autocomplete="username"
               required placeholder="Masukkan username">
      </div>
      <div class="field">
        <label>Password</label>
        <input name="password" type="password" autocomplete="current-password"
               required placeholder="••••••••">
      </div>
      <button class="btn" type="submit">MASUK &rarr;</button>
    </form>

    <div class="fnote">
      &copy; <?= date('Y') ?> Prapen Wood Furniture &middot; PWF Office v1.0
    </div>
  </div>

</div>
</body>
</html>
