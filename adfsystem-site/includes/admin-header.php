<?php
/**
 * Shared header for admin panel pages. Include after calling adf_admin_require_login().
 * Expects optional $adminPageTitle and $adminSaved (success message flag).
 */
require_once __DIR__ . '/content-store.php';
$adminPageTitle = $adminPageTitle ?? 'Dashboard';
$adminLogo = adf_load_content()['branding']['logo'] ?? '';
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
<header class="admin-header">
    <div class="admin-header-inner">
        <a href="index.php" class="brand brand-logo"><?php if (!empty($adminLogo)): ?><img src="../<?php echo htmlspecialchars($adminLogo); ?>" alt="Logo" class="brand-icon brand-icon-img"><?php else: ?><svg class="brand-icon" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="1" y="1" width="38" height="38" rx="10" fill="url(#adfGradAdmin)" />
                <text x="20" y="27" text-anchor="middle" font-family="Poppins, sans-serif" font-weight="800" font-size="18" fill="#fff">AF</text>
                <defs>
                    <linearGradient id="adfGradAdmin" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#ff6a1a" />
                        <stop offset="1" stop-color="#ea580c" />
                    </linearGradient>
                </defs>
            </svg><?php endif; ?><span class="brand-wordmark"><span class="brand-text">Ad<span class="brand-accent">F</span></span><span class="brand-suffix">system</span></span> <small>Admin</small></a>
        <nav class="admin-nav">
            <a href="index.php">Dashboard</a>
            <a href="edit-logo.php">Logo</a>
            <a href="edit-hero.php">Hero</a>
            <a href="edit-modules.php">Modul</a>
            <a href="edit-layanan.php">Layanan</a>
            <a href="edit-products.php">Harga</a>
            <a href="edit-portfolio.php">Portofolio</a>
            <a href="edit-clients.php">Klien</a>
            <a href="edit-payment.php">Pembayaran</a>
            <a href="orders.php">Pesanan</a>
            <a href="edit-contact.php">Kontak</a>
            <a href="change-password.php">Ubah Password</a>
            <a href="../index.php" target="_blank" rel="noopener">Lihat Website &rarr;</a>
            <a href="logout.php" class="admin-logout">Keluar</a>
        </nav>
    </div>
</header>
<main class="admin-main">
