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
    $error = 'Invalid username or password.';
}

$wallpaperUrl = '';
$logoUrl = '';
$companyName = 'Prapen Wood Furniture';
try {
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
    $pwfDb = $isProduction ? 'adfb2574_pwf' : 'adf_pwf';
    $pwfPdo = new PDO("mysql:host=".DB_HOST.";dbname=$pwfDb;charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--gold:#D4A017;--gold-lt:#F5C842}

body{
  font-family:'Inter',sans-serif;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:flex-end;
  padding: 0 6vw 0 0;
  background:#0a0e1a;
  position:relative;
  overflow:hidden
}

/* WALLPAPER */
.bg{position:fixed;inset:0;z-index:0}
.bg-img{
  position:absolute;inset:0;
  <?php if($wallpaperUrl): ?>
  background:url('<?= $wallpaperUrl ?>') center/cover no-repeat;
  <?php else: ?>
  background:radial-gradient(ellipse at 55% 40%,#1a3a2e 0%,#0a0e1a 60%,#0f172a 100%);
  <?php endif; ?>
}
.bg-overlay{
  position:absolute;inset:0;
  background:rgba(5,8,18,<?= $wallpaperUrl ? '.28' : '.15' ?>);
}

/* CARD — wide rectangle */
.card{
  position:relative;z-index:2;
  width: min(420px, 90vw);
  background:rgba(8,11,22,.86);
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.1);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 28px 72px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.04);
}

/* LOGO HEADER BAR */
.card-head{
  padding: 24px 28px 20px;
  background: rgba(255,255,255,.035);
  border-bottom: 1px solid rgba(255,255,255,.07);
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:10px;
  text-align:center;
}
.logo-box{
  width:64px;height:64px;border-radius:14px;
  background:#fff;
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;flex-shrink:0;
  box-shadow:0 4px 18px rgba(0,0,0,.36)
}
.logo-box img{width:100%;height:100%;object-fit:contain;padding:4px}
.logo-icon{font-size:30px;line-height:1}
.brand-name{font-size:15px;font-weight:700;color:var(--gold-lt);letter-spacing:.3px;text-transform:uppercase}
.brand-sub{font-size:10.5px;color:rgba(255,255,255,.36);margin-top:1px}

/* FORM BODY */
.card-body{padding:24px 28px 26px}

.title{font-size:17px;font-weight:700;color:#fff;margin-bottom:3px}
.subtitle{font-size:11.5px;color:rgba(255,255,255,.32);margin-bottom:20px}

.field{margin-bottom:13px}
.field label{
  display:block;font-size:10px;font-weight:600;
  color:rgba(255,255,255,.38);letter-spacing:.6px;
  text-transform:uppercase;margin-bottom:5px
}
.input-wrap{position:relative}
.field input{
  width:100%;padding:10px 14px;
  background:rgba(255,255,255,.07);
  border:1px solid rgba(255,255,255,.1);
  border-radius:8px;color:#fff;font-size:13.5px;
  outline:none;font-family:inherit;
  transition:border-color .2s,box-shadow .2s
}
.field input::placeholder{color:rgba(255,255,255,.2)}
.field input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,160,23,.1)}

/* eye button */
.eye-btn{
  position:absolute;right:11px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;
  color:rgba(212,160,23,.7);padding:3px;
  display:flex;align-items:center;
  transition:color .2s
}
.eye-btn:hover{color:var(--gold)}
.eye-btn svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* submit button */
.btn{
  width:100%;padding:11px;margin-top:4px;
  background:linear-gradient(135deg,var(--gold) 0%,#9a6d00 100%);
  border:none;border-radius:8px;
  color:#0a0e1a;font-size:13px;font-weight:700;
  cursor:pointer;letter-spacing:.4px;text-transform:uppercase;
  font-family:inherit;transition:opacity .2s,transform .1s;
  box-shadow:0 4px 16px rgba(212,160,23,.26)
}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.btn:active{transform:translateY(0)}

/* error */
.err{
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.22);
  border-radius:7px;padding:9px 12px;
  color:#fca5a5;font-size:12px;margin-bottom:13px
}

/* footer */
.card-foot{
  padding:11px 28px;
  border-top:1px solid rgba(255,255,255,.06);
  font-size:10px;color:rgba(255,255,255,.18);
  text-align:center;
  background:rgba(0,0,0,.15)
}

@media(max-width:500px){
  body{justify-content:center;padding:16px}
  .card{width:100%;max-width:420px}
}
</style>
</head>
<body>
<div class="bg">
  <div class="bg-img"></div>
  <div class="bg-overlay"></div>
</div>

<div class="card">

  <!-- LOGO HEADER -->
  <div class="card-head">
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

  <!-- FORM -->
  <div class="card-body">
    <div class="title">Welcome Back</div>
    <div class="subtitle">Sign in to continue</div>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="field">
        <label>Username</label>
        <input name="username" type="text" autocomplete="username" required placeholder="Enter username">
      </div>
      <div class="field">
        <label>Password</label>
        <div class="input-wrap">
          <input name="password" type="password" id="pwdInput" autocomplete="current-password" required placeholder="••••••••" style="padding-right:36px">
          <button type="button" class="eye-btn" onclick="togglePwd()" title="Show / hide">
            <svg id="iconEye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="iconEyeOff" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>
      <button class="btn" type="submit">Sign In &rarr;</button>
    </form>
  </div>

  <!-- FOOTER -->
  <div class="card-foot">© 2026 <?= $companyName ?> · PWF Office v1.0</div>

</div>

<script>
function togglePwd() {
    const inp = document.getElementById('pwdInput');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    document.getElementById('iconEye').style.display    = show ? 'none' : '';
    document.getElementById('iconEyeOff').style.display = show ? ''     : 'none';
}
</script>
</body>
</html>
