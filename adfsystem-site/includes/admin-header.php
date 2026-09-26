<?php
/**
 * Shared header for admin panel pages. Include after calling adf_admin_require_login().
 * Expects optional $adminPageTitle and $adminSaved (success message flag).
 */
require_once __DIR__ . '/content-store.php';
$adminPageTitle = $adminPageTitle ?? 'Dashboard';
$adminLogo = adf_load_content()['branding']['logo'] ?? '';
$adminCurrentScript = basename($_SERVER['SCRIPT_NAME']);
$adminRole = adf_admin_current_user()['role'] ?? 'staff';

// Renders a sidebar nav link, marking it active when it matches the current page.
function adf_admin_nav(string $href, string $label, string $icon = ''): void
{
    global $adminCurrentScript;
    $active = $adminCurrentScript === $href;
    echo '<a href="' . htmlspecialchars($href) . '" class="admin-nav-link' . ($active ? ' active' : '') . '">'
        . ($icon !== '' ? '<span class="admin-nav-icon">' . $icon . '</span>' : '')
        . htmlspecialchars($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars($adminPageTitle); ?> — Admin ADF System</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1'; ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin.css') ?: '1'; ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <input type="checkbox" id="adminSidebarToggle" class="admin-sidebar-toggle-input">
    <aside class="admin-sidebar">
        <a href="index.php" class="brand brand-logo admin-sidebar-brand"><?php if (!empty($adminLogo)): ?><img src="../<?php echo htmlspecialchars($adminLogo); ?>" alt="Logo" class="brand-icon brand-icon-img"><?php else: ?><svg class="brand-icon" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="1" y="1" width="38" height="38" rx="10" fill="url(#adfGradAdmin)" />
                <text x="20" y="27" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="18" fill="#fff">AF</text>
                <defs>
                    <linearGradient id="adfGradAdmin" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#ff6a1a" />
                        <stop offset="1" stop-color="#ea580c" />
                    </linearGradient>
                </defs>
            </svg><?php endif; ?><span class="brand-wordmark"><span class="brand-text">Ad<span class="brand-accent">F</span></span><span class="brand-suffix">system</span></span> <small>Admin</small></a>

        <nav class="admin-sidebar-nav">
            <div class="admin-nav-group">
                <?php adf_admin_nav('index.php', 'Dashboard', '📊'); ?>
            </div>
            <div class="admin-nav-group">
                <div class="admin-nav-group-title">Konten Website</div>
                <?php adf_admin_nav('edit-logo.php', 'Logo', '🎨'); ?>
                <?php adf_admin_nav('edit-hero.php', 'Hero', '🏠'); ?>
                <?php adf_admin_nav('edit-modules.php', 'Modul', '🧩'); ?>
                <?php adf_admin_nav('edit-layanan.php', 'Layanan', '🛠️'); ?>
                <?php adf_admin_nav('edit-products.php', 'Harga', '💳'); ?>
                <?php adf_admin_nav('edit-portfolio.php', 'Portofolio', '🗂️'); ?>
                <?php adf_admin_nav('edit-clients.php', 'Klien', '🏢'); ?>
                <?php adf_admin_nav('edit-contact.php', 'Kontak', '✉️'); ?>
            </div>
            <div class="admin-nav-group">
                <div class="admin-nav-group-title">Transaksi</div>
                <?php adf_admin_nav('orders.php', 'Pesanan', '🧾'); ?>
                <?php if ($adminRole === 'admin'): ?>
                <?php adf_admin_nav('edit-payment.php', 'Pembayaran', '💰'); ?>
                <?php endif; ?>
            </div>
            <div class="admin-nav-group">
                <div class="admin-nav-group-title">Pelanggan</div>
                <?php adf_admin_nav('customers.php', 'Pelanggan', '👥'); ?>
            </div>
            <?php if ($adminRole === 'admin'): ?>
            <div class="admin-nav-group">
                <div class="admin-nav-group-title">Pengaturan</div>
                <?php adf_admin_nav('users.php', 'Pengguna', '👤'); ?>
            </div>
            <?php endif; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <?php adf_admin_nav('change-password.php', 'Ubah Password', '🔒'); ?>
            <a href="../index.php" target="_blank" rel="noopener" class="admin-nav-link">Lihat Website &rarr;</a>
            <a href="logout.php" class="admin-nav-link admin-logout">Keluar</a>
        </div>
    </aside>
    <label for="adminSidebarToggle" class="admin-sidebar-backdrop"></label>
    <div class="admin-content-wrap">
        <header class="admin-topbar">
            <label for="adminSidebarToggle" class="admin-sidebar-toggle" aria-label="Menu">☰</label>
            <h2 class="admin-topbar-title"><?php echo htmlspecialchars($adminPageTitle); ?></h2>
        </header>
        <main class="admin-main">
