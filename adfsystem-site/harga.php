<?php
$pageTitle = 'Harga';
$currentNav = 'harga.php';
require __DIR__ . '/includes/content-store.php';
$siteContent = adf_load_content();
require __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:60px 0;">
    <div class="container">
        <h1 style="font-size:2.1rem;">Paket Harga</h1>
        <p>Paket berlangganan bulanan, fleksibel sesuai skala bisnis Anda. Harga di bawah adalah harga referensi awal dan dapat disesuaikan setelah konsultasi.</p>
    </div>
</section>

<section class="page-section">
    <div class="container">
        <div class="pricing-grid">
            <?php foreach ($siteContent['products'] as $i => $product): ?>
            <div class="price-card<?php echo !empty($product['featured']) ? ' featured' : ''; ?>">
                <h3><?php echo htmlspecialchars($product['title']); ?></h3>
                <p class="tagline"><?php echo htmlspecialchars($product['tagline']); ?></p>
                <?php if ((int) $product['price'] > 0): ?>
                <div class="price">Rp <?php echo number_format((int) $product['price'], 0, ',', '.'); ?> <small>/ bulan</small></div>
                <?php else: ?>
                <div class="price">Hubungi Kami</div>
                <?php endif; ?>
                <ul>
                    <?php foreach ($product['features'] ?? [] as $feature): ?>
                    <li><?php echo htmlspecialchars($feature); ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($product['notes'] ?? [] as $note): ?>
                    <li class="note"><?php echo htmlspecialchars($note); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ((int) $product['price'] > 0): ?>
                <a href="checkout.php?product=<?php echo (int) $i; ?>" class="btn <?php echo !empty($product['featured']) ? 'btn-primary' : 'btn-outline'; ?>" style="text-align:center;">Langganan</a>
                <?php else: ?>
                <a href="kontak.php" class="btn btn-outline" style="text-align:center;">Konsultasi</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="text-align:center;color:var(--text-muted);margin-top:34px;font-size:0.9rem;">
            Semua harga belum termasuk PPN yang berlaku. Biaya implementasi awal dapat berbeda tergantung kompleksitas kebutuhan bisnis.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
