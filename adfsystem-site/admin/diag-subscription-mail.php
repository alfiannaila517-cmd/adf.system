<?php

/**
 * Diagnostic: checks the manual-invoice/payment notification email pipeline
 * (mail-config.php SMTP creds + each client's notify_email) and can send a
 * real test email so the exact SMTP error (if any) is visible. Delete after use.
 */

require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/site-config.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';
adf_admin_require_role('admin');

$mailConfigPath = __DIR__ . '/../includes/mail-config.php';
$mailConfigExists = is_file($mailConfigPath);
$smtpConfigured = defined('SMTP_HOST') && defined('SMTP_USER') && defined('SMTP_PASS');

$clients = adf_subscription_clients_load();

$sendResult = '';
$sendError = '';
$testClientKey = trim($_GET['send_test'] ?? '');

if ($testClientKey !== '') {
    $client = adf_subscription_client_find($testClientKey);
    $notifyEmail = trim((string) ($client['notify_email'] ?? ''));
    if (!$client) {
        $sendError = 'Client tidak ditemukan.';
    } elseif ($notifyEmail === '' || !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
        $sendError = 'notify_email klien ini kosong/tidak valid — isi dulu di halaman Klien Langganan.';
    } else {
        $ok = adf_smtp_send_html(
            $notifyEmail,
            'TEST Email Diagnostic - ' . SITE_NAME,
            '<p>Ini email test dari diag-subscription-mail.php. Kalau ini sampai, pengiriman SMTP untuk notifikasi tagihan berfungsi.</p>',
            'Ini email test dari diag-subscription-mail.php.'
        );
        if ($ok) {
            $sendResult = 'Email test BERHASIL dikirim ke ' . $notifyEmail . '. Cek inbox (dan folder Spam) alamat itu.';
        } else {
            $sendError = adf_mail_last_error();
        }
    }
}

$adminPageTitle = 'Diagnostic Email Tagihan';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide">
    <h1>Diagnostic: Email Notifikasi Tagihan Langganan</h1>

    <p><b>includes/mail-config.php ada?</b>
        <?php echo $mailConfigExists ? '&#10003; Ada' : '&#10007; TIDAK ADA - ini penyebabnya kalau email tidak pernah terkirim. Salin dari mail-config.sample.php lalu isi kredensial mailbox via cPanel File Manager.'; ?>
    </p>
    <p><b>Konstanta SMTP_HOST/SMTP_USER/SMTP_PASS terdefinisi?</b>
        <?php echo $smtpConfigured ? '&#10003; Ya' : '&#10007; Tidak'; ?>
    </p>

    <?php if ($sendResult !== ''): ?>
        <div class="admin-alert admin-alert-success"><?php echo htmlspecialchars($sendResult); ?></div>
    <?php elseif ($sendError !== ''): ?>
        <div class="admin-alert admin-alert-error">Gagal kirim: <?php echo htmlspecialchars($sendError); ?></div>
    <?php endif; ?>

    <h2 class="admin-subheading">Klien Langganan</h2>
    <table class="payment-table">
        <thead>
            <tr>
                <th>Client Key</th>
                <th>Nama</th>
                <th>notify_email</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['client_key'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['client_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['notify_email'] ?? '') ?: '(KOSONG)'; ?></td>
                    <td><a href="?send_test=<?php echo urlencode($c['client_key'] ?? ''); ?>" class="btn btn-primary">Kirim Test</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($clients)): ?>
                <tr><td colspan="4" style="text-align:center;">Belum ada klien.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
