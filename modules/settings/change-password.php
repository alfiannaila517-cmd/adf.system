<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Ganti Password';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Semua field harus diisi!';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Password baru dan konfirmasi password tidak cocok!';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password minimal 6 karakter!';
    } else {
        // Verify old password
        try {
            $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$currentUser['id']]);
            
            if (!$user || !password_verify($oldPassword, $user['password'])) {
                $error = 'Password lama tidak sesuai!';
            } else {
                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $username = $user['username'];
                
                // 1. Update in current business database
                $db->update('users', ['password' => $hashedPassword], ['id' => $currentUser['id']]);
                
                // 2. Sync password to MASTER database and ALL business databases
                try {
                    // Determine master database name
                    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
                    $masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
                    
                    // Connect to master database
                    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
                    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    // Update password in master database (by username to match same user)
                    $masterStmt = $masterPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $masterStmt->execute([$hashedPassword, $username]);
                    
                    // Get all businesses to sync password across all databases
                    $bizStmt = $masterPdo->query("SELECT database_name FROM businesses WHERE is_active = 1");
                    $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($businesses as $biz) {
                        try {
                            // Map database name for production
                            $bizDbName = $biz['database_name'];
                            if ($isProduction) {
                                $dbMapping = [
                                    'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
                                    'adf_benscafe' => 'adfb2574_Adf_Bens'
                                ];
                                if (isset($dbMapping[$bizDbName])) {
                                    $bizDbName = $dbMapping[$bizDbName];
                                }
                            }
                            
                            // Connect to business database and update password
                            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $bizDbName, DB_USER, DB_PASS);
                            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            
                            $bizStmt = $bizPdo->prepare("UPDATE users SET password = ? WHERE username = ?");
                            $bizStmt->execute([$hashedPassword, $username]);
                        } catch (Exception $e) {
                            // Skip if database not accessible
                        }
                    }
                } catch (Exception $e) {
                    // Continue even if sync fails - password updated in current db
                }
                
                $success = 'Password berhasil diubah di semua database! Silakan login kembali dengan password baru.';
                
                // Log activity
                try {
                    $db->insert('activity_logs', [
                        'user_id' => $currentUser['id'],
                        'action' => 'change_password',
                        'description' => 'User mengubah password (synced to all databases)',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="cp-wrap">
    <a href="index.php" class="cp-back"><i data-feather="arrow-left"></i> Settings</a>

    <div class="cp-card">
        <div class="cp-head">
            <span class="cp-ic"><i data-feather="lock"></i></span>
            <div>
                <h4>Ganti Password</h4>
                <p>Masukkan password lama, lalu password baru.</p>
            </div>
        </div>

        <div class="cp-body">
            <?php if ($error): ?>
                <div class="cp-alert err"><i data-feather="alert-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="cp-alert ok"><i data-feather="check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div>
            <?php endif; ?>

            <div class="cp-user">
                <span class="cp-av"><?php echo htmlspecialchars(strtoupper(substr((string)$currentUser['full_name'], 0, 1))); ?></span>
                <span class="cp-user-t">
                    <b><?php echo htmlspecialchars($currentUser['full_name']); ?></b>
                    <small>@<?php echo htmlspecialchars($currentUser['username']); ?> &bull; <?php echo htmlspecialchars(ucfirst((string)$currentUser['role'])); ?></small>
                </span>
            </div>

            <form method="POST" action="" id="cpForm" autocomplete="off">
                <label class="cp-field">
                    <span>Password lama</span>
                    <div class="cp-input">
                        <input type="password" name="old_password" required placeholder="Password saat ini" autocomplete="current-password">
                        <button type="button" class="cp-eye" tabindex="-1" aria-label="Tampilkan password"><i data-feather="eye"></i></button>
                    </div>
                </label>
                <label class="cp-field">
                    <span>Password baru</span>
                    <div class="cp-input">
                        <input type="password" name="new_password" required minlength="6" placeholder="Minimal 6 karakter" autocomplete="new-password">
                        <button type="button" class="cp-eye" tabindex="-1" aria-label="Tampilkan password"><i data-feather="eye"></i></button>
                    </div>
                </label>
                <label class="cp-field">
                    <span>Konfirmasi password baru</span>
                    <div class="cp-input">
                        <input type="password" name="confirm_password" required placeholder="Ulangi password baru" autocomplete="new-password">
                        <button type="button" class="cp-eye" tabindex="-1" aria-label="Tampilkan password"><i data-feather="eye"></i></button>
                    </div>
                    <em id="cpMatch" class="cp-hint" hidden>Password baru dan konfirmasi belum sama</em>
                </label>

                <div class="cp-actions">
                    <button type="submit" class="cp-btn primary"><i data-feather="check"></i> Ganti Password</button>
                    <a href="index.php" class="cp-btn">Batal</a>
                </div>
            </form>

            <p class="cp-note"><i data-feather="info"></i> Lupa password lama? Hubungi developer untuk reset. Gunakan kombinasi huruf, angka, dan simbol (disarankan 8+ karakter).</p>
        </div>
    </div>
</div>

<style>
    .cp-wrap { max-width: 420px; margin: 1.25rem auto 2rem; padding: 0 .25rem; }
    .cp-wrap, .cp-wrap * { box-sizing: border-box; }
    .cp-back { display: inline-flex; align-items: center; gap: 5px; margin: 0 0 .6rem .15rem; font-size: .74rem; font-weight: 600; text-decoration: none !important; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    .cp-back:hover { color: #1d4ed8 !important; -webkit-text-fill-color: #1d4ed8 !important; }
    .cp-back svg, .cp-back i { width: 14px; height: 14px; }
    .cp-card { border-radius: 16px; overflow: hidden; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 14px 30px -18px rgba(15, 23, 42, .25); }
    .cp-head { display: flex; align-items: center; gap: 12px; padding: .85rem 1.1rem; border-bottom: 1px solid #eef2f7; }
    .cp-ic { width: 36px; height: 36px; border-radius: 11px; flex-shrink: 0; display: grid; place-items: center; background: linear-gradient(135deg, #1e3a8a, #2563eb); }
    .cp-ic svg, .cp-ic i { width: 17px; height: 17px; stroke: #fff; color: #fff; }
    .cp-head h4 { margin: 0; font-size: .92rem; font-weight: 800; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    .cp-head p { margin: 2px 0 0; font-size: .7rem; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    .cp-body { padding: 1rem 1.1rem 1.1rem; }
    .cp-user { display: flex; align-items: center; gap: 10px; padding: .55rem .7rem; margin-bottom: .9rem; border-radius: 12px; background: #f8fafc; border: 1px solid #eef2f7; }
    .cp-av { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center; background: linear-gradient(135deg, #1e3a8a, #2563eb); font-size: .8rem; font-weight: 800; color: #fff !important; -webkit-text-fill-color: #fff !important; }
    .cp-user-t { display: flex; flex-direction: column; min-width: 0; }
    .cp-user-t b { font-size: .8rem; font-weight: 700; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    .cp-user-t small { font-size: .68rem; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    .cp-field { display: block; margin-bottom: .75rem; }
    .cp-field > span { display: block; margin-bottom: 4px; font-size: .66rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #475569 !important; -webkit-text-fill-color: #475569 !important; }
    .cp-input { position: relative; }
    .cp-input input { width: 100%; height: 38px; padding: 0 38px 0 12px; border-radius: 10px; border: 1px solid #cbd5e1; background: #ffffff; font-size: .84rem; font-weight: 600; color: #0f172a !important; -webkit-text-fill-color: #0f172a !important; }
    .cp-input input::placeholder { color: #94a3b8; font-weight: 500; -webkit-text-fill-color: #94a3b8; }
    .cp-input input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
    .cp-eye { position: absolute; right: 4px; top: 4px; width: 30px; height: 30px; border: 0; border-radius: 8px; background: transparent; cursor: pointer; display: grid; place-items: center; color: #94a3b8; }
    .cp-eye:hover { background: rgba(148, 163, 184, .16); color: #475569; }
    .cp-eye svg, .cp-eye i { width: 15px; height: 15px; stroke: currentColor; }
    .cp-hint[hidden] { display: none !important; }
    .cp-head h4, .cp-head p { text-shadow: none !important; }
    .cp-hint { display: block; margin-top: 4px; font-size: .68rem; font-style: normal; font-weight: 600; color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; }
    .cp-actions { display: flex; gap: 8px; margin-top: 1rem; }
    .cp-btn { height: 38px; padding: 0 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #ffffff; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: .78rem; font-weight: 700; text-decoration: none !important; cursor: pointer; color: #334155 !important; -webkit-text-fill-color: #334155 !important; }
    .cp-btn:hover { background: #f1f5f9; }
    .cp-btn.primary { flex: 1; background: #1e3a8a; border-color: #1e3a8a; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }
    .cp-btn.primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
    .cp-btn svg, .cp-btn i { width: 15px; height: 15px; stroke: currentColor; }
    .cp-alert { display: flex; align-items: flex-start; gap: 8px; margin-bottom: .85rem; padding: .55rem .7rem; border-radius: 10px; font-size: .74rem; font-weight: 600; line-height: 1.4; }
    .cp-alert svg, .cp-alert i { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
    .cp-alert.err { background: #fef2f2; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    .cp-alert.ok { background: #ecfdf5; color: #047857 !important; -webkit-text-fill-color: #047857 !important; }
    .cp-alert span { color: inherit !important; -webkit-text-fill-color: currentColor !important; }
    .cp-note { display: flex; gap: 7px; margin: .9rem 0 0; padding-top: .8rem; border-top: 1px solid #eef2f7; font-size: .68rem; line-height: 1.5; color: #64748b !important; -webkit-text-fill-color: #64748b !important; }
    .cp-note svg, .cp-note i { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; stroke: #94a3b8; }

    body[data-theme="dark"] .cp-card { background: #111a2e; border-color: rgba(148, 163, 184, .2); box-shadow: 0 14px 30px -18px rgba(0, 0, 0, .7); }
    body[data-theme="dark"] .cp-head, body[data-theme="dark"] .cp-note { border-color: rgba(148, 163, 184, .14); }
    body[data-theme="dark"] .cp-head h4, body[data-theme="dark"] .cp-user-t b { color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important; }
    body[data-theme="dark"] .cp-head p, body[data-theme="dark"] .cp-user-t small, body[data-theme="dark"] .cp-note, body[data-theme="dark"] .cp-back { color: #94a3b8 !important; -webkit-text-fill-color: #94a3b8 !important; }
    body[data-theme="dark"] .cp-user { background: rgba(255, 255, 255, .04); border-color: rgba(148, 163, 184, .14); }
    body[data-theme="dark"] .cp-field > span { color: #94a3b8 !important; -webkit-text-fill-color: #94a3b8 !important; }
    body[data-theme="dark"] .cp-input input { background: #0f172a; border-color: rgba(148, 163, 184, .3); color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important; }
    body[data-theme="dark"] .cp-input input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, .2); }
    body[data-theme="dark"] .cp-eye:hover { background: rgba(255, 255, 255, .08); color: #e2e8f0; }
    body[data-theme="dark"] .cp-btn { background: rgba(255, 255, 255, .06); border-color: rgba(148, 163, 184, .28); color: #e2e8f0 !important; -webkit-text-fill-color: #e2e8f0 !important; }
    body[data-theme="dark"] .cp-btn:hover { background: rgba(255, 255, 255, .12); }
    body[data-theme="dark"] .cp-btn.primary { background: #2563eb; border-color: #2563eb; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }
    body[data-theme="dark"] .cp-btn.primary:hover { background: #3b82f6; border-color: #3b82f6; }
    body[data-theme="dark"] .cp-alert.err { background: rgba(239, 68, 68, .14); color: #fca5a5 !important; -webkit-text-fill-color: #fca5a5 !important; }
    body[data-theme="dark"] .cp-alert.ok { background: rgba(16, 185, 129, .14); color: #6ee7b7 !important; -webkit-text-fill-color: #6ee7b7 !important; }
    body[data-theme="dark"] .cp-hint { color: #fca5a5 !important; -webkit-text-fill-color: #fca5a5 !important; }
</style>

<script>
    (function() {
        var form = document.getElementById('cpForm');
        if (!form) return;
        // tampilkan / sembunyikan password
        form.addEventListener('click', function(e) {
            var b = e.target.closest('.cp-eye');
            if (!b) return;
            var inp = b.parentNode.querySelector('input');
            inp.type = inp.type === 'password' ? 'text' : 'password';
        });
        // konfirmasi harus sama sebelum dikirim
        var np = form.elements['new_password'], cf = form.elements['confirm_password'], hint = document.getElementById('cpMatch');
        function check() {
            var bad = cf.value !== '' && np.value !== cf.value;
            hint.hidden = !bad;
            return !bad;
        }
        np.addEventListener('input', check);
        cf.addEventListener('input', check);
        form.addEventListener('submit', function(e) { if (!check()) { e.preventDefault(); cf.focus(); } });
    })();
</script>
<?php require_once '../../includes/footer.php'; ?>
