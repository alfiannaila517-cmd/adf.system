<?php
$pageTitle = 'Syarat & Ketentuan';
$currentNav = 'syarat-ketentuan.php';
require __DIR__ . '/includes/header.php';
?>

<section class="page-section simple-page">
    <div class="container" style="max-width:820px;">
        <h1>Syarat &amp; Ketentuan Layanan</h1>
        <p><em>Terakhir diperbarui: <?php echo date('d F Y'); ?></em></p>

        <p>
            Dokumen ini mengatur syarat dan ketentuan penggunaan layanan ADF System yang disediakan oleh
            <strong><?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?></strong> ("kami", "penyedia layanan").
            Dengan mendaftar dan/atau menggunakan layanan kami, Anda ("pengguna") dianggap telah membaca,
            memahami, dan menyetujui seluruh isi Syarat &amp; Ketentuan ini.
        </p>

        <h2>1. Definisi Layanan</h2>
        <p>
            ADF System adalah platform perangkat lunak berbasis web (Software as a Service) yang menyediakan
            fitur manajemen bisnis, termasuk namun tidak terbatas pada: manajemen hotel/penginapan, manajemen
            trip/travel, website builder, kalender booking, invoice otomatis, payroll, dan pelaporan keuangan.
        </p>

        <h2>2. Pendaftaran &amp; Akun</h2>
        <ul>
            <li>Pengguna wajib memberikan data yang benar dan akurat saat mendaftar.</li>
            <li>Pengguna bertanggung jawab menjaga kerahasiaan kredensial akun (username &amp; password).</li>
            <li>Kami berhak menangguhkan atau menghentikan akun yang terindikasi melakukan penyalahgunaan layanan.</li>
        </ul>

        <h2>3. Langganan &amp; Pembayaran</h2>
        <ul>
            <li>Layanan disediakan dalam bentuk paket berlangganan bulanan sebagaimana tercantum pada halaman <a href="harga.php">Harga</a>.</li>
            <li>Pembayaran dilakukan di muka untuk periode langganan berjalan melalui metode pembayaran yang tersedia.</li>
            <li>Keterlambatan pembayaran dapat mengakibatkan pembatasan atau penangguhan akses sementara terhadap layanan.</li>
            <li>Perubahan harga paket akan diinformasikan terlebih dahulu kepada pengguna sebelum berlaku.</li>
        </ul>

        <h2>4. Hak &amp; Kewajiban Pengguna</h2>
        <ul>
            <li>Pengguna wajib menggunakan layanan sesuai dengan hukum yang berlaku di Indonesia.</li>
            <li>Pengguna dilarang menggunakan layanan untuk aktivitas ilegal, penipuan, atau pelanggaran hak pihak ketiga.</li>
            <li>Data yang dimasukkan ke dalam sistem sepenuhnya menjadi tanggung jawab pengguna.</li>
        </ul>

        <h2>5. Batasan Tanggung Jawab</h2>
        <p>
            Kami berupaya menjaga ketersediaan dan keandalan layanan, namun tidak menjamin layanan akan bebas
            dari gangguan, kesalahan, atau kerusakan teknis. Kami tidak bertanggung jawab atas kerugian tidak
            langsung yang timbul akibat penggunaan layanan, sepanjang diizinkan oleh hukum yang berlaku.
        </p>

        <h2>6. Perubahan Ketentuan</h2>
        <p>
            Kami dapat memperbarui Syarat &amp; Ketentuan ini dari waktu ke waktu. Perubahan akan dipublikasikan
            pada halaman ini dan berlaku efektif sejak tanggal pembaruan.
        </p>

        <h2>7. Kontak</h2>
        <p>
            Pertanyaan mengenai Syarat &amp; Ketentuan ini dapat disampaikan melalui
            <a href="mailto:<?php echo htmlspecialchars(CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CONTACT_EMAIL); ?></a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
