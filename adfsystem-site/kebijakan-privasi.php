<?php
$pageTitle = 'Kebijakan Privasi';
$currentNav = 'kebijakan-privasi.php';
require __DIR__ . '/includes/header.php';
?>

<section class="page-section simple-page">
    <div class="container" style="max-width:820px;">
        <h1>Kebijakan Privasi</h1>
        <p><em>Terakhir diperbarui: <?php echo date('d F Y'); ?></em></p>

        <p>
            <strong><?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?></strong> menghormati privasi pengguna
            layanan ADF System. Kebijakan ini menjelaskan bagaimana kami mengumpulkan, menggunakan, menyimpan,
            dan melindungi data pribadi Anda.
        </p>

        <h2>1. Data yang Kami Kumpulkan</h2>
        <ul>
            <li>Data identitas: nama, alamat email, nomor telepon, nama bisnis.</li>
            <li>Data transaksi: riwayat langganan, invoice, dan status pembayaran.</li>
            <li>Data operasional bisnis yang diinput pengguna ke dalam sistem (mis. data booking, tamu, keuangan).</li>
            <li>Data teknis: alamat IP, jenis perangkat/browser, dan log aktivitas untuk keperluan keamanan.</li>
        </ul>

        <h2>2. Tujuan Penggunaan Data</h2>
        <ul>
            <li>Menyediakan dan mengoperasikan layanan ADF System.</li>
            <li>Memproses pembayaran dan penerbitan invoice.</li>
            <li>Memberikan dukungan teknis dan komunikasi terkait layanan.</li>
            <li>Meningkatkan kualitas dan keamanan sistem.</li>
        </ul>

        <h2>3. Penyimpanan &amp; Keamanan Data</h2>
        <p>
            Data disimpan pada server yang dilindungi dengan kontrol akses, dan kami menerapkan praktik
            keamanan yang wajar untuk mencegah akses, perubahan, atau pengungkapan data secara tidak sah.
            Meskipun demikian, tidak ada sistem yang sepenuhnya bebas risiko, dan kami senantiasa berupaya
            meminimalkan risiko keamanan yang ada.
        </p>

        <h2>4. Pembagian Data kepada Pihak Ketiga</h2>
        <p>
            Kami tidak menjual data pribadi pengguna kepada pihak ketiga. Data dapat dibagikan kepada
            penyedia layanan pendukung (misalnya penyedia payment gateway atau layanan email) sebatas yang
            diperlukan untuk menjalankan layanan, serta apabila diwajibkan oleh hukum yang berlaku.
        </p>

        <h2>5. Hak Pengguna</h2>
        <ul>
            <li>Mengakses dan memperbarui data pribadi yang tersimpan dalam akun.</li>
            <li>Meminta penghapusan data sesuai ketentuan yang berlaku setelah masa langganan berakhir.</li>
            <li>Menghubungi kami terkait pertanyaan seputar penggunaan data pribadi.</li>
        </ul>

        <h2>6. Perubahan Kebijakan</h2>
        <p>
            Kebijakan Privasi ini dapat diperbarui sewaktu-waktu. Perubahan akan dipublikasikan pada halaman
            ini dengan tanggal pembaruan terbaru.
        </p>

        <h2>7. Kontak</h2>
        <p>
            Pertanyaan mengenai Kebijakan Privasi ini dapat disampaikan melalui
            <a href="mailto:<?php echo htmlspecialchars(CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CONTACT_EMAIL); ?></a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
