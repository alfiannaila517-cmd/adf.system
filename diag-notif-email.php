<?php

/**
 * Diagnostic: tests the "Notifikasi Email Booking Baru" pipeline end-to-end by actually
 * sending a real test email to the first configured admin address, showing the exact
 * error if it fails. Login required. Delete after use if desired.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';
require_once __DIR__ . '/includes/EmailHelper.php';
require_once __DIR__ . '/includes/SmtpMailer.php';

$auth = new Auth();
$auth->requireLogin();

$pdo = getSunseaConnection();
$activeBusinessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ($_SESSION['active_business_id'] ?? '(unknown)');

$emails = sunseaNotifAdminEmails($pdo);
$rawSetting = sunseaSetting($pdo, 'notif_admin_emails', '');

$db = Database::getInstance();
$emailConfig = EmailHelper::resolveConfig($db);

$sendResult = '';
$sendError = '';
$sendTrace = '';

if (isset($_GET['send_test']) && $emails && $emailConfig) {
    try {
        $mailer = new SmtpMailer(
            $emailConfig['host'],
            (int)($emailConfig['smtp_port'] ?? 465),
            $emailConfig['smtp_encryption'] ?? 'ssl',
            $emailConfig['user'],
            $emailConfig['pass']
        );
        $mailer->send($emails[0], 'TEST Notifikasi Booking - Diagnostic', '<p>Ini email test dari diag-notif-email.php. Kalau ini sampai, pengiriman SMTP berfungsi.</p>', sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore'));
        $sendResult = 'Email test BERHASIL dikirim ke ' . $emails[0] . '. Cek inbox (dan folder Spam) alamat itu.';
    } catch (Throwable $e) {
        $sendError = $e->getMessage();
        $sendTrace = $e->getTraceAsString();
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Diagnostic Notifikasi Email</title></head>
<body style="font-family:monospace;padding:20px;">
    <h2>Diagnostic: Notifikasi Email Booking Baru</h2>
    <p><b>Active business (session):</b> <?php echo htmlspecialchars($activeBusinessId); ?></p>

    <p><b>Setting notif_admin_emails (raw):</b></p>
    <pre><?php echo htmlspecialchars($rawSetting) ?: '(KOSONG)'; ?></pre>
    <p><b>Alamat admin valid yang terdeteksi:</b></p>
    <pre><?php echo $emails ? htmlspecialchars(implode("\n", $emails)) : '(TIDAK ADA - ini penyebabnya kalau kosong)'; ?></pre>

    <p><b>EmailHelper::resolveConfig() (kredensial SMTP/IMAP Email Kantor):</b></p>
    <?php if ($emailConfig === null): ?>
        <p style="color:red;font-weight:bold;">✘ NULL - belum ada pengaturan email tersimpan untuk bisnis ini (buka Email Kantor &rarr; Pengaturan Email dulu). Ini penyebab notifikasi tidak terkirim.</p>
    <?php else: ?>
        <pre>host: <?php echo htmlspecialchars($emailConfig['host'] ?? ''); ?>
user: <?php echo htmlspecialchars($emailConfig['user'] ?? ''); ?>
smtp_port: <?php echo htmlspecialchars((string)($emailConfig['smtp_port'] ?? '')); ?>
smtp_encryption: <?php echo htmlspecialchars($emailConfig['smtp_encryption'] ?? ''); ?>
pass: <?php echo $emailConfig['pass'] ? '(terisi, ' . strlen($emailConfig['pass']) . ' karakter)' : '(KOSONG)'; ?></pre>
    <?php endif; ?>

    <?php if ($emails && $emailConfig): ?>
        <p><a href="?send_test=1" style="display:inline-block;padding:10px 20px;background:#0f766e;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;">Kirim Email Test Sekarang ke <?php echo htmlspecialchars($emails[0]); ?></a></p>
    <?php endif; ?>

    <?php if ($sendResult !== ''): ?>
        <p style="color:green;font-weight:bold;">✔ <?php echo htmlspecialchars($sendResult); ?></p>
    <?php elseif ($sendError !== ''): ?>
        <p style="color:red;font-weight:bold;">✘ GAGAL kirim. Pesan error asli (biasanya dari sini penyebab pastinya ketahuan - mis. salah password SMTP, port/encryption salah, dsb):</p>
        <pre style="background:#fee;padding:12px;border:1px solid #f88;"><?php echo htmlspecialchars($sendError); ?></pre>
        <pre style="background:#f4f4f4;padding:12px;font-size:11px;"><?php echo htmlspecialchars($sendTrace); ?></pre>
    <?php endif; ?>
</body>
</html>
