<?php
$pageTitle = 'Beranda';
$currentNav = 'index.php';
require __DIR__ . '/includes/content-store.php';
$siteContent = adf_load_content();
require __DIR__ . '/includes/header.php';
?>

<?php
$heroStyle = '';
if (!empty($siteContent['hero']['background'])) {
    $heroStyle = ' style="background-image:linear-gradient(rgba(11,14,19,0.82),rgba(11,14,19,0.9)),url(\'' . htmlspecialchars($siteContent['hero']['background'], ENT_QUOTES) . '\');background-size:cover;background-position:center;"';
}
?>
<section class="hero"<?php echo $heroStyle; ?>>
    <div class="container">
        <h1><?php echo htmlspecialchars($siteContent['hero']['title']); ?></h1>
        <p>
            <?php echo nl2br(htmlspecialchars($siteContent['hero']['subtitle'])); ?>
        </p>
        <div class="cta-row">
            <a href="harga.php" class="btn btn-primary">Lihat Paket Harga</a>
            <a href="kontak.php" class="btn btn-outline">Hubungi Kami</a>
        </div>
        <div class="badge-row">
            <span class="badge">Manajemen Hotel</span>
            <span class="badge">Manajemen Trip/Travel</span>
            <span class="badge">Website Builder</span>
            <span class="badge">Invoice Otomatis</span>
            <span class="badge">Kalender Booking</span>
        </div>
    </div>
</section>

<section class="page-section">
    <div class="container">
        <div class="section-title">
            <h2>Produk &amp; Modul Utama</h2>
            <p>Dikembangkan langsung oleh tim ADF System Developer untuk kebutuhan operasional bisnis di Indonesia.</p>
        </div>
        <div class="grid">
            <?php foreach ($siteContent['modules'] as $module): ?>
            <div class="card">
                <div class="icon-badge <?php echo htmlspecialchars($module['color']); ?>"><?php echo htmlspecialchars($module['icon']); ?></div>
                <div class="card-text">
                <h3><?php echo htmlspecialchars($module['title']); ?></h3>
                <p><?php echo htmlspecialchars($module['desc']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="page-section page-section-alt">
    <div class="container">
        <div class="section-title">
            <h2>Produk &amp; Website yang Telah Kami Bangun</h2>
            <p>Beberapa sistem dan website nyata yang sudah berjalan menggunakan platform ADF System.</p>
        </div>
        <div class="grid">
            <?php foreach ($siteContent['portfolio'] as $item): ?>
            <div class="portfolio-card">
                <?php if (!empty($item['image'])): ?>
                <div class="portfolio-image">
                    <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                </div>
                <?php else: ?>
                <div class="mockup-preview <?php echo htmlspecialchars($item['color']); ?>">
                    <div class="mockup-bar"><span></span><span></span><span></span></div>
                    <div class="mockup-body">
                        <div class="mockup-side"></div>
                        <div class="mockup-main">
                            <div class="mockup-line short"></div>
                            <div class="mockup-line"></div>
                            <div class="mockup-chart"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="icon-badge <?php echo htmlspecialchars($item['color']); ?>"><?php echo htmlspecialchars($item['icon']); ?></div>
                <?php if ($item['tag'] !== ''): ?><span class="tag"><?php echo htmlspecialchars($item['tag']); ?></span><?php endif; ?>
                <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                <p><?php echo htmlspecialchars($item['desc']); ?></p>
                <?php if (!empty($item['link'])): ?>
                <a class="visit-link" href="<?php echo htmlspecialchars($item['link']); ?>" target="_blank" rel="noopener">Kunjungi Website &rarr;</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="text-align:center;color:var(--text-muted);font-size:0.78rem;margin-top:16px;">
            *Preview di atas adalah ilustrasi tampilan dashboard (bukan screenshot langsung), karena sebagian besar bersifat internal/khusus klien dan tidak dapat diakses publik.
        </p>
        <?php if (!empty($siteContent['clients'])): ?>
        <div class="section-title" style="margin-top:36px;">
            <h2>Dipercaya oleh Berbagai Perusahaan</h2>
            <p>Beberapa perusahaan &amp; bisnis yang sudah menggunakan jasa ADF System.</p>
        </div>
        <div class="clients-grid">
            <?php foreach ($siteContent['clients'] as $client): ?>
                <?php if (!empty($client['link'])): ?><a href="<?php echo htmlspecialchars($client['link']); ?>" target="_blank" rel="noopener" class="client-logo-item"><?php else: ?><div class="client-logo-item"><?php endif; ?>
                    <?php if (!empty($client['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($client['logo']); ?>" alt="<?php echo htmlspecialchars($client['name']); ?>">
                    <?php else: ?>
                        <span class="client-name-fallback"><?php echo htmlspecialchars($client['name']); ?></span>
                    <?php endif; ?>
                <?php if (!empty($client['link'])): ?></a><?php else: ?></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="page-section">
    <div class="container">
        <div class="section-title">
            <h2>Cocok untuk Berbagai Jenis Bisnis</h2>
            <p>ADF System dirancang fleksibel untuk berbagai model usaha berikut ini.</p>
        </div>
        <div class="grid">
            <div class="card"><div class="icon-badge c-orange">🏨</div><div class="card-text"><h3>Hotel &amp; Guest House</h3><p>Properti dengan puluhan hingga ratusan kamar.</p></div></div>
            <div class="card"><div class="icon-badge c-teal">🏝️</div><div class="card-text"><h3>Biro Perjalanan Wisata</h3><p>Operator trip/tour dengan banyak paket &amp; jadwal.</p></div></div>
            <div class="card"><div class="icon-badge c-pink">☕</div><div class="card-text"><h3>Kafe &amp; Resto</h3><p>Kasir, invoice, dan pengelolaan stok sederhana.</p></div></div>
            <div class="card"><div class="icon-badge c-blue">🏢</div><div class="card-text"><h3>Bisnis Multi-Cabang</h3><p>Satu akun untuk mengelola beberapa unit usaha sekaligus.</p></div></div>
        </div>
    </div>
</section>

<section class="cta-band">
    <div class="container">
        <h2>Siap Mengelola Bisnis Anda Lebih Rapi?</h2>
        <p>Konsultasikan kebutuhan sistem Anda langsung dengan tim developer kami.</p>
        <a href="kontak.php" class="btn btn-primary" style="background:#fff;color:var(--primary) !important;">Hubungi Kami Sekarang</a>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
