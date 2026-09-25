<?php
$pageTitle = 'Kebijakan Refund';
$currentNav = 'kebijakan-refund.php';
require __DIR__ . '/includes/header.php';
?>

<section class="page-section simple-page">
    <div class="container" style="max-width:820px;">
        <h1>Kebijakan Refund &amp; Pembatalan</h1>
        <p><em>Terakhir diperbarui: <?php echo date('d F Y'); ?></em></p>

        <p>
            Kebijakan ini menjelaskan ketentuan pengembalian dana (refund) dan pembatalan langganan untuk
            layanan ADF System yang disediakan oleh <strong><?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?></strong>.
        </p>

        <h2>1. Masa Uji Coba</h2>
        <p>
            Kami mendorong calon pengguna untuk melakukan konsultasi dan/atau demo terlebih dahulu melalui
            halaman <a href="kontak.php">Kontak</a> sebelum berlangganan, guna memastikan layanan sesuai
            dengan kebutuhan bisnis.
        </p>

        <h2>2. Ketentuan Refund</h2>
        <ul>
            <li>Permintaan refund dapat diajukan maksimal 7 (tujuh) hari kalender sejak tanggal pembayaran, apabila layanan belum digunakan secara aktif.</li>
            <li>Refund tidak berlaku apabila akun telah digunakan secara aktif (mis. data transaksi/booking sudah dibuat) selama periode langganan berjalan.</li>
            <li>Biaya implementasi, kustomisasi, atau pengembangan khusus (custom development) yang telah dikerjakan tidak dapat dikembalikan.</li>
            <li>Proses refund yang disetujui akan diproses maksimal 14 (empat belas) hari kerja ke rekening/metode pembayaran asal.</li>
        </ul>

        <h2>3. Pembatalan Langganan</h2>
        <ul>
            <li>Pengguna dapat membatalkan langganan kapan saja melalui pengajuan ke <a href="mailto:<?php echo htmlspecialchars(CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CONTACT_EMAIL); ?></a>.</li>
            <li>Pembatalan tidak menghapus kewajiban pembayaran untuk periode langganan yang sedang berjalan.</li>
            <li>Akses layanan akan tetap aktif hingga akhir periode langganan yang telah dibayar.</li>
        </ul>

        <h2>4. Pengecualian</h2>
        <p>
            Kami berhak menolak permintaan refund apabila ditemukan indikasi penyalahgunaan layanan atau
            pelanggaran terhadap <a href="syarat-ketentuan.php">Syarat &amp; Ketentuan</a> kami.
        </p>

        <h2>5. Kontak</h2>
        <p>
            Untuk pengajuan refund atau pembatalan, silakan hubungi kami melalui
            <a href="mailto:<?php echo htmlspecialchars(CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CONTACT_EMAIL); ?></a>.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
