<?php
/** Kamus EN: Tagihan Langganan (modules/subscription/index.php, pay.php). */
return [
    'text' => [
        'Tagihan Langganan' => 'Subscription Bills', 'Langganan ADF System' => 'ADF System Subscription', 'Status paket, tagihan, dan pembayaran langganan sistem.' => 'Plan status, bills, and subscription payments.',
        'Biaya / bulan' => 'Fee / month', 'Langganan aktif' => 'Subscription active', 'Langganan aktif sampai' => 'Subscription active until', 'Riwayat Tagihan' => 'Bill History', 'Tagihan tambahan' => 'Additional bill', 'Langganan bulanan' => 'Monthly subscription',
        'Pembayaran via Pakasir (QRIS / Virtual Account). Status lunas terkonfirmasi otomatis dalam 1–2 menit.' => 'Payment via Pakasir (QRIS / Virtual Account). Paid status is confirmed automatically within 1–2 minutes.',
        'Hubungi Developer' => 'Contact Developer', 'Koneksi (Developer)' => 'Connection (Developer)', 'Atur Koneksi' => 'Connection Settings', 'Client Key' => 'Client Key', 'Client Token' => 'Client Token',
        'Akses sistem sedang dikunci. Hubungi developer untuk membuka kembali. Langganan aktif sampai' => 'System access is locked. Contact the developer to unlock it. Subscription active until',
        'Jatuh tempo pertama' => 'First due date', 'Klien di ADF' => 'Client in ADF', 'Koneksi ke adfsystem.store' => 'Connection to adfsystem.store', 'Langganan belum diaktifkan untuk bisnis ini.' => 'Subscription has not been activated for this business.',
        'Salin Client Key & Token dari adfsystem.store → Klien Langganan.' => 'Copy the Client Key & Token from adfsystem.store → Subscription Clients.', 'Simpan & Sinkron' => 'Save & Sync', 'Sinkron Sekarang' => 'Sync Now',
        'Sinkron terakhir' => 'Last sync', 'Status Sinkron' => 'Sync Status', 'Status kunci' => 'Lock status', 'URL Sinkronisasi' => 'Sync URL', 'Tidak dikunci' => 'Not locked', 'mis. narayana-hotel' => 'e.g. narayana-hotel',
        'Cek Status' => 'Check Status', 'Biaya layanan' => 'Service fee', 'Buka aplikasi m-banking / e-wallet, pilih' => 'Open your m-banking / e-wallet app, choose', 'Buka halaman pembayaran Pakasir' => 'Open the Pakasir payment page',
        'Buka m-banking / ATM, pilih' => 'Open m-banking / ATM, choose', 'Ganti metode pembayaran' => 'Change payment method',
        'Jangan tutup halaman ini sampai muncul konfirmasi. Kalau sudah membayar lalu menutup halaman, status tetap diperbarui otomatis.' => 'Do not close this page until the confirmation appears. If you have paid and closed the page, the status is still updated automatically.',
        'Kembali ke Tagihan' => 'Back to Bills', 'Lunas Jatuh tempo' => 'Paid Due date', 'Masukkan nomor di atas, bayar tepat' => 'Enter the number above, pay exactly', 'Metode lain' => 'Other methods',
        'Pembayaran Berhasil' => 'Payment Successful', 'Pembayaran Tagihan · ADF System' => 'Bill Payment · ADF System', 'Pilih metode pembayaran' => 'Choose a payment method', 'Salin nomor VA' => 'Copy VA number',
        'Selesaikan pembayaran — halaman ini otomatis terkonfirmasi.' => 'Complete the payment — this page confirms automatically.', 'Tagihan ini sudah lunas' => 'This bill is already paid',
        'Terima kasih. Bukti pembayaran sudah dikirim ke email.' => 'Thank you. The payment receipt has been sent to your email.', 'Total bayar' => 'Total to pay', 'Transfer → Virtual Account' => 'Transfer → Virtual Account',
        'sudah lunas. Bukti pembayaran dikirim ke email.' => 'is paid. The payment receipt was sent to email.', 'TAGIHAN LEWAT JATUH TEMPO' => 'OVERDUE BILL', 'TAGIHAN PERLU DIBAYAR' => 'BILL DUE FOR PAYMENT',
        'Halaman ini khusus owner / admin.' => 'This page is for owners / admins only.', 'Koneksi tersimpan dan berhasil sinkron dengan ADF System.' => 'Connection saved and synced with ADF System successfully.',
        'Sinkron gagal. Lihat keterangan di bawah.' => 'Sync failed. See the details below.', 'Gagal membuat pembayaran. Coba metode lain atau ulangi beberapa saat lagi.' => 'Failed to create the payment. Try another method or try again shortly.',
        'Sesi kedaluwarsa, silakan pilih lagi.' => 'Session expired, please choose again.',
    ],
    'js' => ['kedaluwarsa — ganti metode' => 'expired — change method'],
    'patterns' => [
        '/^Langganan (\d{4}-\d{2})$/u' => 'Subscription $1', '/^Menunggu pembayaran… berlaku (.+)$/u' => 'Waiting for payment… valid $1', '/^Langganan aktif sampai (.+)$/u' => 'Subscription active until $1',
        '/^(\d+) tagihan lain$/u' => '$1 other bills',
    ],
];
