<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
ensurePwfOfficeTables($pdo);

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $uploadDir = BASE_PATH . '/uploads/pwf-settings/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if ($action === 'save_theme') {
        $theme = in_array($_POST['ui_theme'] ?? '', ['light','dark']) ? $_POST['ui_theme'] : 'light';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_ui_theme',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$theme,$theme]);
        $msg = 'Theme updated. Page will reload.';
        header('Location: settings.php?saved=theme'); exit;

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPwd  = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $userId  = $_SESSION['user_id'] ?? 0;
        if (!$userId) { $msg = 'Session expired.'; $msgType = 'error'; }
        elseif (strlen($newPwd) < 6) { $msg = 'Min 6 characters.'; $msgType = 'error'; }
        elseif ($newPwd !== $confirm) { $msg = 'Passwords do not match.'; $msgType = 'error'; }
        else {
            try {
                $mpdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $row = $mpdo->prepare('SELECT password FROM users WHERE id=?');
                $row->execute([$userId]); $row = $row->fetch();
                if ($row && password_verify($current, $row['password'])) {
                    $mpdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPwd, PASSWORD_DEFAULT), $userId]);
                    $msg = 'Password changed successfully.';
                } else { $msg = 'Current password is incorrect.'; $msgType = 'error'; }
            } catch (Exception $e) { $msg = 'Error: '.htmlspecialchars($e->getMessage()); $msgType = 'error'; }
        }

    } elseif ($action === 'upload_logo') {
        $file = $_FILES['logo_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext,['jpg','jpeg','png','svg','webp'])) { $msg='Invalid format.'; $msgType='error'; }
            elseif ($file['size'] > 2*1024*1024) { $msg='Max 2 MB.'; $msgType='error'; }
            else {
                $fname = 'logo_'.time().'.'.$ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir.$fname)) {
                    $wp = BASE_URL.'/uploads/pwf-settings/'.$fname;
                    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_login_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$wp,$wp]);
                    $msg = 'Logo uploaded.';
                } else { $msg='Upload failed.'; $msgType='error'; }
            }
        } else { $msg='Select a file.'; $msgType='error'; }

    } elseif ($action === 'upload_wallpaper') {
        $file = $_FILES['wallpaper_file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext,['jpg','jpeg','png','webp'])) { $msg='Format: JPG, PNG, WebP.'; $msgType='error'; }
            elseif ($file['size'] > 8*1024*1024) { $msg='Max 8 MB.'; $msgType='error'; }
            else {
                $fname = 'wallpaper_'.time().'.'.$ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir.$fname)) {
                    $wp = BASE_URL.'/uploads/pwf-settings/'.$fname;
                    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_login_wallpaper',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$wp,$wp]);
                    $msg = 'Wallpaper uploaded.';
                } else { $msg='Upload failed.'; $msgType='error'; }
            }
        } else { $msg='Select a file.'; $msgType='error'; }

    } elseif ($action === 'save_text') {
        foreach (['pwf_company_name','pwf_company_tagline','pwf_company_address','pwf_company_phone'] as $k) {
            if (isset($_POST[$k])) {
                $v = trim($_POST[$k]);
                $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$v,$v]);
            }
        }
        $msg = 'Company info saved.';

    } elseif ($action === 'reset_logo') {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_logo'");
        $msg = 'Logo reset.';
    } elseif ($action === 'reset_wallpaper') {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_wallpaper'");
        $msg = 'Wallpaper reset.';
    }
}

$skeys = ['pwf_login_logo','pwf_login_wallpaper','pwf_company_name','pwf_company_tagline','pwf_company_address','pwf_company_phone','pwf_ui_theme'];
$srows = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('".implode("','",$skeys)."')")->fetchAll(PDO::FETCH_KEY_PAIR);
$s = []; foreach ($skeys as $k) $s[$k] = $srows[$k] ?? '';
$currentTheme = $s['pwf_ui_theme'] ?: 'light';
if (isset($_GET['saved'])) $msg = $msg ?: 'Saved.';

pwfOfficeHeader('Settings', 'settings');
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType==='error'?'danger':'success' ?>" style="margin-bottom:16px"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- THEME -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-palette me-2" style="color:var(--gold)"></i>UI Theme</div>
  <div class="pwf-card-body">
    <form method="post">
      <input type="hidden" name="_action" value="save_theme">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <label style="cursor:pointer">
          <input type="radio" name="ui_theme" value="light" <?= $currentTheme==='light'?'checked':'' ?> style="display:none" onchange="previewTheme('light')">
          <div id="cardLight" style="border:2px solid <?= $currentTheme==='light'?'var(--gold)':'var(--border)' ?>;border-radius:10px;overflow:hidden;transition:border-color .2s">
            <div style="background:#F8F6F3;padding:10px 12px 8px">
              <div style="background:#fff;border-radius:5px;height:7px;width:70%;margin-bottom:4px"></div>
              <div style="background:#fff;border-radius:5px;height:5px;width:45%"></div>
            </div>
            <div style="background:#fff;padding:6px 10px;display:flex;align-items:center;gap:6px;border-top:1px solid #E7E5E4">
              <div style="width:7px;height:7px;border-radius:50%;background:#B8860B"></div>
              <span style="font-size:11px;font-weight:600;color:#1C1917">Light</span>
            </div>
          </div>
        </label>
        <label style="cursor:pointer">
          <input type="radio" name="ui_theme" value="dark" <?= $currentTheme==='dark'?'checked':'' ?> style="display:none" onchange="previewTheme('dark')">
          <div id="cardDark" style="border:2px solid <?= $currentTheme==='dark'?'var(--gold)':'var(--border)' ?>;border-radius:10px;overflow:hidden;transition:border-color .2s">
            <div style="background:#111113;padding:10px 12px 8px">
              <div style="background:#1C1C1F;border-radius:5px;height:7px;width:70%;margin-bottom:4px"></div>
              <div style="background:#1C1C1F;border-radius:5px;height:5px;width:45%"></div>
            </div>
            <div style="background:#161618;padding:6px 10px;display:flex;align-items:center;gap:6px;border-top:1px solid #2C2C30">
              <div style="width:7px;height:7px;border-radius:50%;background:#D4A017"></div>
              <span style="font-size:11px;font-weight:600;color:#ECECEC">Dark</span>
            </div>
          </div>
        </label>
      </div>
      <button class="btn" type="submit" style="width:100%"><i class="bi bi-check-circle"></i> Apply Theme</button>
    </form>
  </div>
</div>

<!-- CHANGE PASSWORD -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-shield-lock me-2" style="color:var(--gold)"></i>Change Password</div>
  <div class="pwf-card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="_action" value="change_password">
      <div class="pwf-form-group"><label>Current Password</label>
        <input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="pwf-form-group"><label>New Password</label>
        <input class="input" type="password" name="new_password" id="pwdNew" required minlength="6" autocomplete="new-password"></div>
      <div class="pwf-form-group"><label>Confirm New Password</label>
        <input class="input" type="password" name="confirm_password" id="pwdConfirm" required minlength="6" autocomplete="new-password" oninput="checkPwd()">
        <div id="pwdMsg" style="font-size:11px;margin-top:3px;min-height:16px"></div>
      </div>
      <button class="btn" type="submit" style="width:100%"><i class="bi bi-key"></i> Update Password</button>
    </form>
  </div>
</div>

<!-- LOGO -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-image me-2" style="color:var(--gold)"></i>Company Logo</div>
  <div class="pwf-card-body">
    <div style="text-align:center;margin-bottom:14px">
      <?php if ($s['pwf_login_logo']): ?>
        <div style="background:var(--nav-hover);padding:14px;border-radius:10px;display:inline-block">
          <img src="<?= htmlspecialchars($s['pwf_login_logo']) ?>" style="max-height:64px;max-width:148px;object-fit:contain">
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:5px">Current logo</div>
      <?php else: ?>
        <div style="background:var(--nav-hover);border-radius:10px;width:80px;height:80px;display:inline-flex;align-items:center;justify-content:center;font-size:32px">🪵</div>
        <div style="font-size:11px;color:var(--muted);margin-top:5px">Default icon</div>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="upload_logo">
      <div class="pwf-form-group"><label>Upload New Logo</label>
        <input class="input" type="file" name="logo_file" accept=".jpg,.jpeg,.png,.svg,.webp" required>
        <div style="font-size:11px;color:var(--muted);margin-top:3px">JPG, PNG, SVG, WebP — max 2 MB</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn" type="submit" style="flex:1"><i class="bi bi-upload"></i> Upload</button>
        <?php if ($s['pwf_login_logo']): ?>
          <button class="btn btn-outline-secondary" type="button"
            onclick="if(confirm('Reset logo?')){document.getElementById('frmResetLogo').submit()}">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($s['pwf_login_logo']): ?>
    <form method="post" id="frmResetLogo" style="display:none"><input type="hidden" name="_action" value="reset_logo"></form>
    <?php endif; ?>
  </div>
</div>

<!-- WALLPAPER -->
<div class="pwf-card">
  <div class="pwf-card-header"><i class="bi bi-card-image me-2" style="color:var(--gold)"></i>Login Wallpaper</div>
  <div class="pwf-card-body">
    <div style="margin-bottom:14px">
      <?php if ($s['pwf_login_wallpaper']): ?>
        <img src="<?= htmlspecialchars($s['pwf_login_wallpaper']) ?>" style="width:100%;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
        <div style="font-size:11px;color:var(--muted);margin-top:4px">Current wallpaper</div>
      <?php else: ?>
        <div style="background:radial-gradient(ellipse at 70% 20%,#1a3a2e,#0a0e1a);height:80px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;color:rgba(255,255,255,.4)">Default gradient</div>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="upload_wallpaper">
      <div class="pwf-form-group"><label>Upload Wallpaper</label>
        <input class="input" type="file" name="wallpaper_file" accept=".jpg,.jpeg,.png,.webp" required>
        <div style="font-size:11px;color:var(--muted);margin-top:3px">JPG, PNG, WebP — max 8 MB</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn" type="submit" style="flex:1"><i class="bi bi-upload"></i> Upload</button>
        <?php if ($s['pwf_login_wallpaper']): ?>
          <button class="btn btn-outline-secondary" type="button"
            onclick="if(confirm('Reset wallpaper?')){document.getElementById('frmResetWp').submit()}">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($s['pwf_login_wallpaper']): ?>
    <form method="post" id="frmResetWp" style="display:none"><input type="hidden" name="_action" value="reset_wallpaper"></form>
    <?php endif; ?>
  </div>
</div>

<!-- COMPANY INFO -->
<div class="pwf-card" style="grid-column:1/-1">
  <div class="pwf-card-header"><i class="bi bi-building me-2" style="color:var(--gold)"></i>Company Information</div>
  <div class="pwf-card-body">
    <form method="post">
      <input type="hidden" name="_action" value="save_text">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="pwf-form-group"><label>Company Name</label><input class="input" name="pwf_company_name" value="<?= htmlspecialchars($s['pwf_company_name'] ?: 'Prapen Wood Furniture') ?>"></div>
        <div class="pwf-form-group"><label>Tagline / Slogan</label><input class="input" name="pwf_company_tagline" value="<?= htmlspecialchars($s['pwf_company_tagline']) ?>"></div>
        <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="pwf_company_phone" value="<?= htmlspecialchars($s['pwf_company_phone']) ?>"></div>
        <div class="pwf-form-group"><label>Address</label><input class="input" name="pwf_company_address" value="<?= htmlspecialchars($s['pwf_company_address']) ?>"></div>
      </div>
      <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Info</button>
    </form>
  </div>
</div>

</div>

<script>
function checkPwd() {
    const a = document.getElementById('pwdNew').value;
    const b = document.getElementById('pwdConfirm').value;
    const el = document.getElementById('pwdMsg');
    if (!b) { el.textContent=''; return; }
    if (a===b) { el.style.color='var(--success)'; el.textContent='✓ Match'; }
    else { el.style.color='var(--danger)'; el.textContent='✗ Do not match'; }
}
function previewTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    document.getElementById('cardLight').style.borderColor = t==='light' ? 'var(--gold)' : 'var(--border)';
    document.getElementById('cardDark').style.borderColor  = t==='dark'  ? 'var(--gold)' : 'var(--border)';
}
</script>
<?php pwfOfficeFooter();
