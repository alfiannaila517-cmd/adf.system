<?php
$pageTitle = 'Layanan';
$currentNav = 'layanan.php';
require __DIR__ . '/includes/content-store.php';
$siteContent = adf_load_content();
require __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:60px 0;">
    <div class="container">
        <h1 style="font-size:2.1rem;"><?php echo htmlspecialchars($siteContent['layanan']['hero']['title']); ?></h1>
        <p><?php echo nl2br(htmlspecialchars($siteContent['layanan']['hero']['subtitle'])); ?></p>
    </div>
</section>

<section class="page-section">
    <div class="container">
        <div class="grid">
            <?php foreach ($siteContent['layanan']['items'] as $item): ?>
            <div class="card">
                <div class="icon-badge <?php echo htmlspecialchars($item['color']); ?>"><?php echo htmlspecialchars($item['icon']); ?></div>
                <div class="card-text">
                <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                <p><?php echo htmlspecialchars($item['desc']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="cta-band">
    <div class="container">
        <h2>Butuh Modul Khusus untuk Bisnis Anda?</h2>
        <p>Tim ADF System Developer siap membantu menyesuaikan sistem sesuai kebutuhan operasional Anda.</p>
        <a href="kontak.php" class="btn btn-primary" style="background:#fff;color:var(--primary) !important;">Konsultasi Gratis</a>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
