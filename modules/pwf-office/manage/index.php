<?php
/**
 * PWF Office Management Console
 * Manage users, settings, password, logo, dan konfigurasi lainnya
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access - only owner/developer can access
$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo '<h1>Access Denied</h1><p>Hanya Owner/Developer yang bisa akses PWF Management.</p>';
    exit;
}

require_once __DIR__ . '/../layout.php';

$pdo = getPwfOfficePdo();
$masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Get stats
$userCount = $masterPdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$businessCount = $pdo->query("SELECT COUNT(*) FROM pwf_customers")->fetchColumn();
$orderCount = $pdo->query("SELECT COUNT(*) FROM pwf_orders")->fetchColumn();
$containerCount = $pdo->query("SELECT COUNT(*) FROM pwf_containers")->fetchColumn() ?? 0;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PWF Management Console</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: linear-gradient(135deg, #f5f3f0 0%, #faf9f7 100%); color: #1c1511; padding: 24px; line-height: 1.6; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 40px; }
        .header h1 { font-size: 32px; font-weight: 800; margin-bottom: 8px; display: flex; align-items: center; gap: 14px; background: linear-gradient(135deg, #B8860B 0%, #D4A017 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .header h1 i { font-size: 36px; color: #B8860B; }
        .header p { color: #78716C; font-size: 14px; margin: 0; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: white; padding: 24px; border-radius: 14px; border: 1px solid #E7E5E4; box-shadow: 0 4px 12px rgba(0, 0, 0, .06); transition: all .3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(184, 134, 11, .12); border-color: #D4A017; }
        .stat-card i { font-size: 28px; color: #B8860B; margin-bottom: 14px; display: block; opacity: .9; }
        .stat-card .number { font-size: 36px; font-weight: 800; color: #1c1511; margin-bottom: 6px; }
        .stat-card .label { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: .6px; font-weight: 600; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .menu-card { background: white; border: 2px solid transparent; border-radius: 14px; padding: 28px; transition: all .3s cubic-bezier(.4, 0, .2, 1); cursor: pointer; text-decoration: none; color: inherit; box-shadow: 0 2px 8px rgba(0, 0, 0, .04); }
        .menu-card:hover { transform: translateY(-4px); border-color: #B8860B; box-shadow: 0 12px 24px rgba(184, 134, 11, .15); background: #FFFBF5; }
        .menu-card i { font-size: 44px; color: #B8860B; margin-bottom: 16px; display: block; opacity: .85; }
        .menu-card h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; color: #1c1511; }
        .menu-card p { font-size: 13px; color: #78716C; line-height: 1.6; }
        .alert { background: linear-gradient(135deg, #FEF3E8 0%, #FFF7ED 100%); border: 1px solid #FED7AA; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px; color: #B45309; font-size: 13px; display: flex; align-items: center; gap: 12px; }
        .alert i { font-size: 18px; flex-shrink: 0; }
        .alert strong { font-weight: 700; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-gear-fill"></i>PWF Management Console</h1>
            <p>Kelola password, user, pengaturan, dan konfigurasi PWF Office System</p>
        </div>

        <div class="alert">
            <i class="bi bi-info-circle"></i>
            <strong>Selamat datang!</strong> Console ini memungkinkan Anda mengelola akun, password, user lain, logo, dan pengaturan sistem secara lengkap.
        </div>

        <div class="stats">
            <div class="stat-card">
                <i class="bi bi-people-fill"></i>
                <div class="number"><?= $userCount ?></div>
                <div class="label">User Aktif</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-building"></i>
                <div class="number"><?= $businessCount ?></div>
                <div class="label">Customer</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-box-seam"></i>
                <div class="number"><?= $orderCount ?></div>
                <div class="label">Order</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-archive"></i>
                <div class="number"><?= $containerCount ?></div>
                <div class="label">Container</div>
            </div>
        </div>

        <h2 style="font-size:18px;margin-bottom:16px;color:#1c1511">📋 Menu Manajemen</h2>
        <div class="menu-grid">
            <a href="password.php" class="menu-card">
                <i class="bi bi-key-fill"></i>
                <h3>Ganti Password</h3>
                <p>Ubah password login Anda untuk keamanan yang lebih baik</p>
            </a>

            <a href="users.php" class="menu-card">
                <i class="bi bi-person-badge"></i>
                <h3>Kelola User</h3>
                <p>Buat, edit, atau hapus user dengan pengaturan role dan akses</p>
            </a>

            <a href="settings.php" class="menu-card">
                <i class="bi bi-palette-fill"></i>
                <h3>Pengaturan Branding</h3>
                <p>Ubah logo, wallpaper login, nama perusahaan, dan identitas brand</p>
            </a>

            <a href="permissions.php" class="menu-card">
                <i class="bi bi-shield-lock"></i>
                <h3>Manajemen Akses</h3>
                <p>Atur role, permission, dan akses user ke menu-menu tertentu</p>
            </a>

            <a href="audit.php" class="menu-card">
                <i class="bi bi-clock-history"></i>
                <h3>Audit Log</h3>
                <p>Lihat riwayat login dan perubahan data yang dilakukan user</p>
            </a>

            <a href="backup.php" class="menu-card">
                <i class="bi bi-cloud-arrow-down"></i>
                <h3>Backup & Export</h3>
                <p>Backup data PWF Office atau export data untuk analisis</p>
            </a>
        </div>

        <div style="margin-top:40px;padding:20px;background:#F5F3F0;border-radius:8px;border-left:4px solid #B8860B">
            <h3 style="font-size:14px;margin-bottom:8px;color:#1c1511">💡 Tips Keamanan</h3>
            <ul style="font-size:13px;color:#666;line-height:1.8;margin-left:20px">
                <li>Ganti password secara berkala (minimal 3 bulan sekali)</li>
                <li>Gunakan password yang kuat: kombinasi huruf, angka, dan simbol</li>
                <li>Jangan bagikan password Anda kepada siapapun</li>
                <li>Selalu logout setelah selesai bekerja, terutama di komputer publik</li>
                <li>Pantau audit log untuk aktivitas mencurigakan</li>
            </ul>
        </div>
    </div>
</body>
</html>
