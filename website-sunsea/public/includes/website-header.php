<?php

/**
 * Shared public website header. Requires website-bootstrap.php to be included first,
 * and $pageTitle / $activeNav to be set by the calling page.
 */
$activeNav = $activeNav ?? '';
$weFaviconPath = sunseaSetting($pdo, 'website_favicon', '');
// Template asset bundled inside THIS folder (public/assets/website/style.css) - kept as a
// page-relative path (not sunseaAssetUrl/BASE_URL) so the template stays self-contained
// wherever it's copied/deployed, unlike the DB-uploaded favicon/logo below.
$weStyleFile = __DIR__ . '/../assets/website/style.css';
$weStyleV = file_exists($weStyleFile) ? filemtime($weStyleFile) : time();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? $weCompanyName); ?> — <?php echo htmlspecialchars($weCompanyName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription ?? 'Jasa tour & travel Karimunjawa: paket wisata, penginapan, transport, dan island hopping.'); ?>">
    <?php if ($weFaviconPath): ?>
        <link rel="icon" href="<?php echo htmlspecialchars(sunseaAssetUrl($weFaviconPath)); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/website/style.css?v=<?php echo (int)$weStyleV; ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body class="we-body">

    <header class="we-navbar">
        <div class="we-container we-nav-inner">
            <a href="home.php" class="we-logo">
                <?php if ($weLogoSrc): ?>
                    <img src="<?php echo htmlspecialchars($weLogoSrc); ?>" alt="<?php echo htmlspecialchars($weCompanyName); ?>">
                <?php else: ?>
                    🌊
                <?php endif; ?>
                <?php echo htmlspecialchars($weCompanyName); ?>
            </a>

            <button class="we-nav-toggle" onclick="document.getElementById('weNavLinks').classList.toggle('we-open')">☰</button>

            <nav class="we-nav-links" id="weNavLinks">
                <a href="home.php" style="<?php echo $activeNav === 'home' ? 'color:var(--we-ocean)' : ''; ?>">Beranda</a>
                <a href="paket-wisata.php" style="<?php echo $activeNav === 'paket' ? 'color:var(--we-ocean)' : ''; ?>">Paket Wisata</a>
                <a href="galeri.php" style="<?php echo $activeNav === 'galeri' ? 'color:var(--we-ocean)' : ''; ?>">Galeri</a>
                <a href="tentang-kami.php" style="<?php echo $activeNav === 'tentang' ? 'color:var(--we-ocean)' : ''; ?>">Tentang Kami</a>
                <a href="blog.php" style="<?php echo $activeNav === 'blog' ? 'color:var(--we-ocean)' : ''; ?>">Blog</a>
                <a href="kontak.php" class="we-nav-cta">Kontak / Booking</a>
                <a href="/admin" class="we-nav-admin">Login Admin</a>
            </nav>
        </div>
    </header>