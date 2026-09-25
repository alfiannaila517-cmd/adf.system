<?php
require_once __DIR__ . '/../includes/admin-auth.php';
adf_admin_require_login();

$adminPageTitle = 'Dashboard';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Dashboard Admin</h1>
    <p class="admin-lead">Kelola konten yang tampil di website ADF System dari sini.</p>

    <div class="admin-card-grid">
        <a class="admin-panel-card" href="edit-logo.php">
            <div class="icon-badge c-pink">🎨</div>
            <h3>Logo Perusahaan</h3>
            <p>Upload logo perusahaan untuk ditampilkan di header website &amp; admin.</p>
        </a>
        <a class="admin-panel-card" href="edit-hero.php">
            <div class="icon-badge c-orange">🏠</div>
            <h3>Hero Beranda</h3>
            <p>Ubah judul &amp; deskripsi utama di halaman depan.</p>
        </a>
        <a class="admin-panel-card" href="edit-modules.php">
            <div class="icon-badge c-blue">🧩</div>
            <h3>Produk &amp; Modul Utama</h3>
            <p>Tambah, ubah, atau hapus daftar modul/fitur yang ditampilkan di beranda.</p>
        </a>
        <a class="admin-panel-card" href="edit-layanan.php">
            <div class="icon-badge c-teal">🛠️</div>
            <h3>Halaman Layanan</h3>
            <p>Ubah judul halaman dan daftar modul yang tampil di halaman Layanan.</p>
        </a>
        <a class="admin-panel-card" href="edit-products.php">
            <div class="icon-badge c-orange">💳</div>
            <h3>Paket Harga</h3>
            <p>Tambah, ubah, atau hapus paket langganan yang tampil di halaman Harga.</p>
        </a>
        <a class="admin-panel-card" href="edit-portfolio.php">
            <div class="icon-badge c-purple">🗂️</div>
            <h3>Portofolio Produk</h3>
            <p>Tambah, ubah, atau hapus kartu produk/website yang ditampilkan.</p>
        </a>
        <a class="admin-panel-card" href="edit-clients.php">
            <div class="icon-badge c-orange">🏢</div>
            <h3>Logo Perusahaan Klien</h3>
            <p>Tambah, ubah, atau hapus logo perusahaan yang sudah memakai jasa ADF System.</p>
        </a>
        <a class="admin-panel-card" href="edit-payment.php">
            <div class="icon-badge c-purple">💰</div>
            <h3>Pengaturan Pembayaran</h3>
            <p>Hubungkan payment gateway Pakasir agar pelanggan bisa bayar langganan otomatis.</p>
        </a>
        <a class="admin-panel-card" href="orders.php">
            <div class="icon-badge c-blue">🧾</div>
            <h3>Pesanan Langganan</h3>
            <p>Lihat daftar pesanan/langganan dari pelanggan beserta status pembayarannya.</p>
        </a>
        <a class="admin-panel-card" href="edit-contact.php">
            <div class="icon-badge c-green">✉️</div>
            <h3>Info Kontak</h3>
            <p>Ubah email, nomor WhatsApp, dan alamat yang tampil di halaman Kontak.</p>
        </a>
        <a class="admin-panel-card" href="change-password.php">
            <div class="icon-badge c-blue">🔒</div>
            <h3>Ubah Password</h3>
            <p>Ganti password login panel admin website ini.</p>
        </a>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
