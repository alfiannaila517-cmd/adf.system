<?php
/**
 * PWF Office Management Console
 * Manage users, settings, password, logo, dan konfigurasi lainnya
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

// Check access - only owner/developer can access
$isOwner = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['owner', 'developer']);
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            background: #f5f3f0;
            color: #1c1511;
            padding: 20px
        }
        .container {
            max-width: 1200px;
            margin: 0 auto
        }
        .header {
            margin-bottom: 30px
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px
        }
        .header h1 i {
            font-size: 32px;
            color: #B8860B
        }
        .header p {
            color: #666;
            font-size: 14px
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 30px
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #E7E5E4;
            box-shadow: 0 2px 4px rgba(0, 0, 0, .05)
        }
        .stat-card i {
            font-size: 24px;
            color: #B8860B;
            margin-bottom: 12px
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: #1c1511;
            margin-bottom: 4px
        }
        .stat-card .label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: .5px
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 30px
        }
        .menu-card {
            background: white;
            border: 2px solid #E7E5E4;
            border-radius: 12px;
            padding: 24px;
            transition: all .2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit
        }
        .menu-card:hover {
            border-color: #B8860B;
            box-shadow: 0 8px 16px rgba(184, 134, 11, .1);
            transform: translateY(-2px)
        }
        .menu-card i {
            font-size: 40px;
            color: #B8860B;
            margin-bottom: 16px;
            display: block
        }
        .menu-card h3 {
            font-size: 16px;
            margin-bottom: 8px;
            color: #1c1511
        }
        .menu-card p {
            font-size: 13px;
            color: #666;
            line-height: 1.5
        }
        .alert {
            background: #FFF7ED;
            border: 1px solid #FED7AA;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            color: #C2410C;
            font-size: 13px
        }
        .alert i {
            margin-right: 8px
        }
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
