<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/subscription-manual-invoices-store.php';
require_once __DIR__ . '/../includes/site-config.php';
require_once __DIR__ . '/../includes/smtp-mailer.php';
require_once __DIR__ . '/../includes/pakasir-client.php';
adf_admin_require_role('admin');

$clientKey = trim($_GET['client'] ?? $_POST['client_key'] ?? '');
$client = $clientKey !== '' ? adf_subscription_client_find($clientKey) : null;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        adf_manual_invoice_delete((string) ($_POST['id'] ?? ''));
        header('Location: subscription-manual-invoice.php?client=' . urlencode($clientKey));
        exit;
    } elseif (!$client) {
        $error = 'Klien tidak ditemukan.';
    } else {
        $description = trim($_POST['description'] ?? '');
        $amount = (float) str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $dueDate = trim($_POST['due_date'] ?? '');

        if ($description === '' || $amount <= 0 || $dueDate === '') {
            $error = 'Deskripsi, jumlah, dan jatuh tempo wajib diisi.';
        } else {
            $invoiceEntry = adf_manual_invoice_create($clientKey, $description, $amount, $dueDate);

            $paymentLink = null;
            $pakasirSlug = trim((string) ($client['pakasir_slug'] ?? ''));
            $pakasirApiKey = trim((string) ($client['pakasir_api_key'] ?? ''));
            if ($pakasirSlug !== '' && $pakasirApiKey !== '') {
                $orderId = 'manual-' . $invoiceEntry['id'];
                $paymentResult = adf_pakasir_create_payment_link($orderId, (int) round($amount), $pakasirSlug, $pakasirApiKey);
                if ($paymentResult) {
                    $paymentLink = $paymentResult['payment_link'];
                } else {
                    error_log('subscription-manual-invoice pakasir link failed: ' . adf_pakasir_last_error());
                }
            }

            $notifyEmail = trim((string) ($client['notify_email'] ?? ''));
            if ($notifyEmail !== '' && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
                $clientName = (string) ($client['client_name'] ?? $clientKey);
                $formattedAmount = 'Rp ' . number_format($amount, 0, ',', '.');
                $dueDateDisplay = date('d M Y', strtotime($dueDate));
                $subject = 'Tagihan Baru: ' . $description . ' - ' . $clientName;
                $textBody = "Halo {$clientName},\n\n"
                    . "Ada tagihan baru (di luar biaya langganan bulanan rutin) yang diterbitkan untuk Anda:\n\n"
                    . "Deskripsi    : {$description}\n"
                    . "Jumlah       : {$formattedAmount}\n"
                    . "Jatuh Tempo  : {$dueDateDisplay}\n\n"
                    . "Tagihan ini terpisah dari biaya langganan bulanan otomatis dan sudah bisa dilihat/dibayar "
                    . "di menu Tagihan Langganan pada sistem Anda."
                    . ($paymentLink ? "\n\nBayar sekarang: {$paymentLink}" : '')
                    . "\n\nSalam,\n" . SITE_NAME;
                $htmlBody = '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;color:#1e293b;">'
                    . '<div style="background:#0f172a;padding:20px 24px;border-radius:8px 8px 0 0;">'
                    . '<h2 style="color:#fff;margin:0;font-size:18px;">' . htmlspecialchars(SITE_NAME) . '</h2>'
                    . '</div>'
                    . '<div style="border:1px solid #e2e8f0;border-top:none;padding:24px;border-radius:0 0 8px 8px;">'
                    . '<p style="margin:0 0 12px;">Halo <strong>' . htmlspecialchars($clientName) . '</strong>,</p>'
                    . '<p style="margin:0 0 16px;">Ada tagihan baru <strong>di luar biaya langganan bulanan rutin</strong> yang diterbitkan untuk Anda:</p>'
                    . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
                    . '<tr><td style="padding:6px 0;color:#64748b;">Deskripsi</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($description) . '</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b;">Jumlah</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($formattedAmount) . '</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b;">Jatuh Tempo</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . htmlspecialchars($dueDateDisplay) . '</td></tr>'
                    . '</table>'
                    . ($paymentLink
                        ? '<p style="text-align:center;margin:0 0 16px;"><a href="' . htmlspecialchars($paymentLink) . '" style="display:inline-block;background:#16a34a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;">Bayar Tagihan Sekarang</a></p>'
                        : '')
                    . '<p style="margin:0;">Tagihan ini sudah bisa dilihat dan dibayar di menu Tagihan Langganan pada sistem Anda.</p>'
                    . '<p style="margin:20px 0 0;color:#64748b;font-size:12px;">Salam,<br>' . htmlspecialchars(SITE_NAME) . '</p>'
                    . '</div></div>';
                $sentOk = adf_smtp_send_html($notifyEmail, $subject, $htmlBody, $textBody);
                if (!$sentOk) {
                    error_log('subscription-manual-invoice mail failed: ' . adf_mail_last_error());
                }
            }

            header('Location: subscription-manual-invoice.php?client=' . urlencode($clientKey) . '&saved=1');
            exit;
        }
    }
}

$manualInvoices = $clientKey !== '' ? adf_manual_invoices_for_client($clientKey) : [];
usort($manualInvoices, static fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$csrf = adf_admin_csrf_token();
$saved = isset($_GET['saved']);

$adminPageTitle = 'Tagih Manual';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide">
    <h1>Tagih Manual</h1>
    <p class="admin-lead">
        Buat tagihan tambahan di luar tagihan langganan bulanan rutin (mis. biaya tambah modul/fitur).
        Tagihan ini akan otomatis muncul di sistem klien seperti tagihan rutin — bisa dilihat, dikunci jika lewat jatuh tempo, dan dibayar via Pakasir.
    </p>

    <?php if (!$client): ?>
        <div class="admin-alert admin-alert-error">Pilih klien terlebih dahulu dari halaman <a href="subscription-clients.php">Klien Langganan</a>.</div>
    <?php else: ?>
        <h2 class="admin-subheading">Klien: <?php echo htmlspecialchars($client['client_name'] ?? $clientKey); ?></h2>

        <?php if ($error): ?>
            <div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($saved): ?>
            <div class="admin-alert admin-alert-success">Tagihan manual berhasil dibuat.</div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
            <label>Deskripsi Tagihan
                <input type="text" name="description" required placeholder="Mis. Biaya Tambah Modul Laporan">
            </label>
            <label>Jumlah (Rp)
                <input type="text" name="amount" required placeholder="500000">
            </label>
            <label>Jatuh Tempo
                <input type="date" name="due_date" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
            </label>
            <button type="submit" class="btn btn-primary">Buat Tagihan</button>
        </form>

        <h2 class="admin-subheading">Riwayat Tagihan Manual</h2>
        <div class="payment-table-wrap">
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>Deskripsi</th>
                        <th>Jumlah</th>
                        <th>Jatuh Tempo</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manualInvoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($inv['description'] ?? '-'); ?></td>
                            <td class="payment-amount">Rp <?php echo number_format((float) ($inv['amount'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars(!empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : '-'); ?></td>
                            <td><?php echo htmlspecialchars(!empty($inv['created_at']) ? date('d M Y H:i', strtotime($inv['created_at'])) : '-'); ?></td>
                            <td class="payment-actions">
                                <form method="post" onsubmit="return confirm('Hapus tagihan manual ini? Jika sudah tersinkron ke klien, tagihan yang sudah ada di sistem klien tidak akan ikut terhapus.');" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($manualInvoices)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;">Belum ada tagihan manual.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>