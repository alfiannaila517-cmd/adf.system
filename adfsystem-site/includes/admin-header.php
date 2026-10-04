<?php

/**
 * Shared header for admin panel pages. Include after calling adf_admin_require_login().
 * Expects optional $adminPageTitle and $adminSaved (success message flag).
 */
require_once __DIR__ . '/content-store.php';
$adminPageTitle = $adminPageTitle ?? 'Dashboard';
$adminLogo = adf_load_content()['branding']['logo'] ?? '';
$adminCurrentScript = basename($_SERVER['SCRIPT_NAME']);
$adminUser = adf_admin_current_user() ?? [];
$adminRole = $adminUser['role'] ?? 'staff';

/**
 * Inline line icon (Lucide-style, 24x24 stroke) so the panel has consistent icons
 * without emoji or an external icon font.
 */
function adf_admin_icon(string $name, int $size = 16): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'blocks' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3Z"/><path d="M9 8h6M9 12h6"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'logout' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><circle cx="16" cy="13.5" r="1.2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    ];
    $inner = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="adm-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

// Renders a sidebar nav link, marking it active when it matches the current page.
function adf_admin_nav(string $href, string $label, string $icon = ''): void
{
    global $adminCurrentScript;
    $active = $adminCurrentScript === $href;
    echo '<a href="' . htmlspecialchars($href) . '" class="admin-nav-link' . ($active ? ' active' : '') . '">'
        . ($icon !== '' ? adf_admin_icon($icon) : '')
        . '<span>' . htmlspecialchars($label) . '</span></a>';
}

$websiteContentPages = [
    'edit-logo.php' => 'Logo',
    'edit-hero.php' => 'Hero Beranda',
    'edit-modules.php' => 'Modul',
    'edit-layanan.php' => 'Layanan',
    'edit-products.php' => 'Paket Harga',
    'edit-portfolio.php' => 'Portofolio',
    'edit-clients.php' => 'Klien',
    'edit-contact.php' => 'Kontak',
];
$isWebsiteContentActive = isset($websiteContentPages[$adminCurrentScript]);
$adminSection = $isWebsiteContentActive ? 'Konten Website' : 'Admin';
$adminInitial = strtoupper(substr((string) ($adminUser['username'] ?? 'A'), 0, 1));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require __DIR__ . '/pwa-head.php'; ?>
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($adminPageTitle); ?> — Admin ADF System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1'; ?>">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin.css') ?: '1'; ?>">
    <link rel="stylesheet" href="../assets/css/global-loader.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/global-loader.css') ?: '1'; ?>">
    <script src="../assets/js/global-loader.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/global-loader.js') ?: '1'; ?>"></script>
</head>

<body class="admin-body">
    <div class="admin-shell">
        <input type="checkbox" id="adminSidebarToggle" class="admin-sidebar-toggle-input">
        <aside class="admin-sidebar">
            <a href="index.php" class="admin-sidebar-brand">
                <?php if (!empty($adminLogo)): ?>
                    <img src="../<?php echo htmlspecialchars($adminLogo); ?>" alt="Logo" class="admin-brand-logo">
                <?php else: ?>
                    <span class="admin-brand-mark">AF</span>
                <?php endif; ?>
                <span class="admin-brand-text">
                    <strong>ADF<span>system</span></strong>
                    <small>Admin Panel</small>
                </span>
            </a>

            <nav class="admin-sidebar-nav">
                <?php adf_admin_nav('index.php', 'Dashboard', 'dashboard'); ?>

                <div class="admin-nav-group-title">Bisnis</div>
                <?php adf_admin_nav('orders.php', 'Transaksi', 'receipt'); ?>
                <?php adf_admin_nav('customers.php', 'Pelanggan', 'users'); ?>
                <?php if ($adminRole === 'admin'): ?>
                    <?php adf_admin_nav('subscription-clients.php', 'Klien Langganan', 'link'); ?>
                <?php endif; ?>

                <div class="admin-nav-group-title">Website</div>
                <details class="admin-nav-dropdown" <?php echo $isWebsiteContentActive ? ' open' : ''; ?>>
                    <summary class="admin-nav-link admin-nav-dropdown-summary">
                        <?php echo adf_admin_icon('globe'); ?><span>Konten Website</span><?php echo adf_admin_icon('chevron', 14); ?>
                    </summary>
                    <div class="admin-nav-dropdown-body">
                        <?php foreach ($websiteContentPages as $pageFile => $pageLabel): ?>
                            <?php adf_admin_nav($pageFile, $pageLabel); ?>
                        <?php endforeach; ?>
                    </div>
                </details>

                <?php if ($adminRole === 'admin'): ?>
                    <div class="admin-nav-group-title">Pengaturan</div>
                    <?php adf_admin_nav('edit-payment.php', 'Payment Gateway', 'card'); ?>
                    <?php adf_admin_nav('users.php', 'Pengguna', 'user'); ?>
                    <?php adf_admin_nav('google-settings.php', 'Login Google', 'lock'); ?>
                    <?php adf_admin_nav('telegram-settings.php', 'Notifikasi Telegram', 'mail'); ?>

                    <!-- Masuk Developer Panel lewat tiket sekali pakai (tanpa login ulang), buka di tab baru -->
                    <div class="admin-nav-group-title">Developer</div>
                    <?php foreach ([
                        ['index.php', 'Developer Panel', 'dashboard'],
                        ['businesses.php', 'Bisnis & Database', 'building'],
                        ['index.php?section=user-setup', 'User & Akses', 'users'],
                    ] as [$devFile, $devLabel, $devIcon]): ?>
                        <form method="post" action="developer-sso.php" target="_blank" style="margin:0;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(adf_admin_csrf_token()); ?>">
                            <input type="hidden" name="next" value="<?php echo htmlspecialchars($devFile); ?>">
                            <button type="submit" class="admin-nav-link" style="width:100%;background:none;border:0;text-align:left;cursor:pointer;font:inherit;">
                                <?php echo adf_admin_icon($devIcon); ?><span><?php echo $devLabel; ?></span><?php echo adf_admin_icon('external', 12); ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <div class="admin-sidebar-footer">
                <?php adf_admin_nav('change-password.php', 'Ubah Password', 'lock'); ?>
                <a href="logout.php" class="admin-nav-link admin-logout"><?php echo adf_admin_icon('logout'); ?><span>Keluar</span></a>
            </div>
        </aside>
        <label for="adminSidebarToggle" class="admin-sidebar-backdrop"></label>
        <div class="admin-content-wrap">
            <header class="admin-topbar">
                <label for="adminSidebarToggle" class="admin-sidebar-toggle" aria-label="Menu"><?php echo adf_admin_icon('menu', 18); ?></label>
                <div class="admin-breadcrumb">
                    <span><?php echo htmlspecialchars($adminSection); ?></span>
                    <?php echo adf_admin_icon('chevron', 12); ?>
                    <strong><?php echo htmlspecialchars($adminPageTitle); ?></strong>
                </div>
                <div class="admin-topbar-actions">
                    <a href="../index.php" target="_blank" rel="noopener" class="admin-topbar-link" title="Lihat website"><?php echo adf_admin_icon('external', 14); ?><span>Lihat Website</span></a>
                    <div class="admin-user-chip">
                        <span class="admin-user-avatar"><?php echo htmlspecialchars($adminInitial); ?></span>
                        <span class="admin-user-meta">
                            <strong><?php echo htmlspecialchars((string) ($adminUser['username'] ?? 'admin')); ?></strong>
                            <small><?php echo htmlspecialchars(ucfirst($adminRole)); ?></small>
                        </span>
                    </div>
                </div>
            </header>
            <main class="admin-main">
