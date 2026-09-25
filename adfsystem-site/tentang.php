<?php
$pageTitle = 'Tentang Kami';
$currentNav = 'tentang.php';
require __DIR__ . '/includes/header.php';
?>

<section class="page-section simple-page">
    <div class="container" style="max-width:820px;">
        <h1>Tentang ADF System</h1>
        <p>
            <strong><?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?></strong> adalah tim
            <?php echo htmlspecialchars(COMPANY_DESC_SHORT); ?> yang mengembangkan
            <strong>ADF System</strong> — sebuah platform manajemen bisnis all-in-one yang digunakan untuk
            mengelola operasional hotel, penginapan, biro perjalanan wisata, kafe, dan berbagai jenis usaha lainnya.
        </p>
        <p>
            Produk ADF System (dapat diakses melalui <a href="https://adfsystem.online" target="_blank" rel="noopener">adfsystem.online</a>)
            lahir dari kebutuhan nyata di lapangan: banyak pelaku usaha yang masih mencatat booking, tagihan, dan
            keuangan secara manual atau menggunakan beberapa aplikasi terpisah yang tidak saling terhubung.
            Kami membangun satu sistem terpadu agar seluruh proses — mulai dari reservasi, invoice, keuangan,
            payroll, hingga website resmi bisnis — bisa dikelola dari satu tempat.
        </p>

        <h2>Apa yang Kami Kerjakan</h2>
        <ul>
            <li>Pengembangan &amp; pemeliharaan platform manajemen bisnis ADF System</li>
            <li>Kustomisasi sistem sesuai kebutuhan operasional masing-masing klien</li>
            <li>Pembuatan website builder otomatis untuk setiap bisnis pengguna</li>
            <li>Konsultasi IT dan pengembangan sistem custom di luar produk utama</li>
        </ul>

        <h2>Komitmen Kami</h2>
        <p>
            Kami berkomitmen untuk terus mengembangkan ADF System agar semakin relevan dengan kebutuhan
            pelaku usaha di Indonesia, dengan mengutamakan keandalan sistem, keamanan data pelanggan, dan
            dukungan teknis yang responsif.
        </p>

        <p>
            Ingin berdiskusi lebih lanjut mengenai kebutuhan sistem bisnis Anda?
            <a href="kontak.php">Hubungi kami di sini</a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
