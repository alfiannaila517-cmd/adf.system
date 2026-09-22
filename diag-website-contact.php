<?php

/**
 * Diagnostic: shows why the public-website WhatsApp chat widget may not be appearing
 * for the currently active business. Login required. Delete after use if desired.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo = getSunseaConnection();

$activeBusinessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ($_SESSION['active_business_id'] ?? '(unknown)');

$companyPhone = sunseaSetting($pdo, 'company_phone', '');
$companyPhoneExtra = sunseaSetting($pdo, 'company_phone_extra', '');
$waAdminsRaw = sunseaSetting($pdo, 'company_whatsapp_admins', '');
$waAdminsResolved = sunseaWaAdminList($pdo);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Diagnostic Website Contact</title></head>
<body style="font-family:monospace;padding:20px;">
    <h2>Diagnostic: Widget Chat WhatsApp Website</h2>
    <p><b>Active business (session):</b> <?php echo htmlspecialchars($activeBusinessId); ?></p>
    <p><b>company_phone:</b> "<?php echo htmlspecialchars($companyPhone); ?>"</p>
    <p><b>company_phone_extra:</b> "<?php echo htmlspecialchars($companyPhoneExtra); ?>"</p>
    <p><b>company_whatsapp_admins (raw):</b><br><pre><?php echo htmlspecialchars($waAdminsRaw); ?></pre></p>
    <p><b>Resolved admin list (dipakai widget chat):</b></p>
    <pre><?php echo htmlspecialchars(print_r($waAdminsResolved, true)); ?></pre>
    <?php if ($waAdminsResolved): ?>
        <p style="color:green;font-weight:bold;">✔ Data ADA. Widget chat SEHARUSNYA muncul di travel-site/home.php - kalau tidak muncul, kemungkinan masalah cache browser atau deploy belum ke-pull.</p>
    <?php else: ?>
        <p style="color:red;font-weight:bold;">✘ Data KOSONG. Ini sebabnya widget chat tidak muncul - isi "Nomor WhatsApp Admin" atau "Nomor Telepon" di Pengaturan &rarr; tab Perusahaan, lalu simpan.</p>
    <?php endif; ?>
</body>
</html>
