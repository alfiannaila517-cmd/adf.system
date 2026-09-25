<?php
$pageTitle = 'Langganan';
$currentNav = 'harga.php';
require __DIR__ . '/includes/content-store.php';
require __DIR__ . '/includes/orders-store.php';
require __DIR__ . '/includes/pakasir-client.php';
$siteContent = adf_load_content();

$productIndex = (int) ($_GET['product'] ?? $_POST['product'] ?? -1);
$product = $siteContent['products'][$productIndex] ?? null;
$error = '';

if (!$product || (int) $product['price'] <= 0) {
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="page-section">
        <div class="container">
            <div style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.35);color:#f87171;padding:14px 18px;border-radius:10px;max-width:560px;">Paket tidak ditemukan atau tidak tersedia untuk langganan online. Silakan <a href="kontak.php">hubungi kami</a>.</div>
        </div>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');

    if ($name === '' || $whatsapp === '') {
        $error = 'Nama dan nomor WhatsApp wajib diisi.';
    } elseif (!adf_pakasir_is_configured()) {
        $error = 'Pembayaran online belum diaktifkan. Silakan hubungi kami langsung untuk berlangganan.';
    } else {
        $orderId = 'ADF' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
        $amount = (int) $product['price'];

        $result = adf_pakasir_create_payment_link($orderId, $amount);
        if ($result === null) {
            $error = 'Gagal membuat transaksi pembayaran. Silakan coba lagi atau hubungi kami.';
        } else {
            adf_orders_add([
                'order_id' => $orderId,
                'txn_id' => $result['txn_id'],
                'product_title' => $product['title'],
                'amount' => $amount,
                'name' => $name,
                'email' => $email,
                'whatsapp' => $whatsapp,
                'status' => 'pending',
                'created_at' => date('c'),
                'completed_at' => null,
            ]);
            header('Location: ' . $result['payment_link']);
            exit;
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="hero" style="padding:60px 0;">
    <div class="container">
        <h1 style="font-size:2.1rem;">Langganan Paket <?php echo htmlspecialchars($product['title']); ?></h1>
        <p>Rp <?php echo number_format((int) $product['price'], 0, ',', '.'); ?> / bulan &mdash; Isi data di bawah untuk melanjutkan ke pembayaran.</p>
    </div>
</section>

<section class="page-section">
    <div class="container" style="max-width:480px;">
        <?php if ($error): ?>
            <div style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.35);color:#f87171;padding:14px 18px;border-radius:10px;margin-bottom:24px;font-size:0.9rem;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post" class="contact-form">
            <input type="hidden" name="product" value="<?php echo (int) $productIndex; ?>">
            <label for="name">Nama Lengkap</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>

            <label for="email">Email (opsional)</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">

            <label for="whatsapp">Nomor WhatsApp</label>
            <input type="text" id="whatsapp" name="whatsapp" value="<?php echo htmlspecialchars($_POST['whatsapp'] ?? ''); ?>" placeholder="08xxxxxxxxxx" required>

            <button type="submit" class="btn btn-primary">Lanjutkan ke Pembayaran</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
