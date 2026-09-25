<?php
$pageTitle = 'Kontak';
$currentNav = 'kontak.php';
require __DIR__ . '/includes/content-store.php';
$siteContent = adf_load_content();
$contactEmail = $siteContent['contact']['email'] ?: CONTACT_EMAIL;
$contactAddress = $siteContent['contact']['address'] ?: CONTACT_ADDRESS;
$contactWhatsapp = $siteContent['contact']['whatsapp'];
require __DIR__ . '/includes/header.php';

$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
$formError = isset($_GET['error']) ? $_GET['error'] : '';
?>

<section class="page-section simple-page">
    <div class="container" style="max-width:960px;">
        <h1>Hubungi Kami</h1>
        <p>Punya pertanyaan seputar produk, harga, atau ingin konsultasi kebutuhan sistem bisnis Anda? Silakan hubungi kami.</p>

        <div class="contact-grid">
            <div class="contact-card">
                <div class="icon">✉️</div>
                <strong>Email</strong>
                <p><a href="mailto:<?php echo htmlspecialchars($contactEmail); ?>"><?php echo htmlspecialchars($contactEmail); ?></a></p>
            </div>
            <?php if ($contactWhatsapp !== ''): ?>
            <div class="contact-card">
                <div class="icon">💬</div>
                <strong>WhatsApp</strong>
                <p><a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $contactWhatsapp)); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($contactWhatsapp); ?></a></p>
            </div>
            <?php endif; ?>
            <div class="contact-card">
                <div class="icon">📍</div>
                <strong>Alamat</strong>
                <p><?php echo htmlspecialchars($contactAddress); ?></p>
            </div>
            <div class="contact-card">
                <div class="icon">🕒</div>
                <strong>Jam Layanan</strong>
                <p>Senin – Sabtu, 09.00 – 17.00 WIB</p>
            </div>
        </div>

        <?php if ($sent): ?>
            <div style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.35);color:#4ade80;padding:14px 18px;border-radius:10px;margin-bottom:24px;font-size:0.9rem;">
                Terima kasih, pesan Anda berhasil terkirim. Kami akan segera menghubungi Anda kembali.
            </div>
        <?php elseif ($formError): ?>
            <div style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.35);color:#f87171;padding:14px 18px;border-radius:10px;margin-bottom:24px;font-size:0.9rem;">
                <?php echo htmlspecialchars($formError); ?>
            </div>
        <?php endif; ?>

        <h2>Kirim Pesan</h2>
        <form class="contact-form" action="process-contact.php" method="post" style="max-width:560px;">
            <label for="name">Nama</label>
            <input type="text" id="name" name="name" required maxlength="120">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required maxlength="160">

            <label for="message">Pesan</label>
            <textarea id="message" name="message" rows="5" required maxlength="2000"></textarea>

            <button type="submit" class="btn btn-primary">Kirim Pesan</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
