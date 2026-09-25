<?php
/** Include after setting $pageTitle and $currentNav (relative filename, e.g. 'index.php'). */
require_once __DIR__ . '/site-config.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/content-store.php';
$pageTitle = $pageTitle ?? SITE_NAME;
$currentNav = $currentNav ?? '';
$adfAdminLoggedIn = adf_admin_is_logged_in();
$siteLogo = adf_load_content()['branding']['logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> — <?php echo htmlspecialchars(SITE_NAME); ?></title>
<meta name="description" content="ADF System — platform manajemen bisnis all-in-one: manajemen hotel, manajemen trip/travel, website builder, kalender booking, invoice otomatis, payroll, dan laporan keuangan dalam satu sistem.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1'; ?>">
</head>
<body>

<header class="site-header">
    <div class="container">
        <a href="index.php" class="brand brand-logo"><?php if (!empty($siteLogo)): ?><img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars(SITE_NAME); ?>" class="brand-icon brand-icon-img"><?php endif; ?><span class="brand-wordmark"><span class="brand-text">Ad<span class="brand-accent">F</span></span><span class="brand-suffix">system</span></span></a>
        <nav class="nav-links" id="navLinks">
            <?php echo nav_link('index.php', 'Beranda', $currentNav); ?>
            <?php echo nav_link('layanan.php', 'Layanan', $currentNav); ?>
            <?php echo nav_link('harga.php', 'Harga', $currentNav); ?>
            <?php echo nav_link('tentang.php', 'Tentang Kami', $currentNav); ?>
            <?php echo nav_link('kontak.php', 'Kontak', $currentNav); ?>
            <?php if ($adfAdminLoggedIn): ?>
                <a href="admin/index.php" class="btn btn-primary">Dashboard Admin</a>
            <?php else: ?>
                <a href="admin/login.php" class="btn btn-primary">Login Admin</a>
            <?php endif; ?>
        </nav>
        <button class="nav-toggle" id="navToggle" aria-label="Buka menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>
<script>
document.getElementById('navToggle').addEventListener('click', function () {
    var nav = document.getElementById('navLinks');
    var isOpen = nav.classList.toggle('open');
    this.classList.toggle('active', isOpen);
    this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});
</script>
