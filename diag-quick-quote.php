<?php

/**
 * Diagnostic: replays the exact same DB writes as travel-site/home.php's "Minta Penawaran
 * Cepat" (quick_quote) form, inside a transaction that is always rolled back at the end,
 * to reveal the real PDO/PHP error message without needing cPanel error logs.
 * Login required. Delete after use if desired.
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

$log = [];
$errorMsg = '';
$errorTrace = '';

try {
    sunseaEnsurePackageItemsSchema($pdo);
    $log[] = 'sunseaEnsurePackageItemsSchema OK';

    $pkgRow = $pdo->query("SELECT id, name, duration_days, base_price, min_pax, max_pax, itinerary FROM trip_packages WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if (!$pkgRow) {
        throw new Exception('Tidak ada trip_packages aktif untuk dites - buat 1 paket dulu di menu Paket Wisata.');
    }
    $log[] = 'Paket dites: ' . $pkgRow['name'] . ' (id=' . $pkgRow['id'] . ')';

    $pdo->beginTransaction();

    $qName = 'DIAG TEST ' . date('His');
    $qPhone = '628000000000';
    $qDate = date('Y-m-d', strtotime('+7 days'));
    $qPax = 2;
    $qPackageId = (int)$pkgRow['id'];

    $custStmt = $pdo->prepare("SELECT id FROM customers WHERE (phone = ? OR whatsapp = ?) AND name = ? LIMIT 1");
    $custStmt->execute([$qPhone, $qPhone, $qName]);
    $qCustomerId = (int)($custStmt->fetchColumn() ?: 0);
    $log[] = 'Cek customer existing OK';

    if ($qCustomerId <= 0) {
        $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
        $nextNum = 1;
        if ($lastCode && preg_match('/(\d+)$/', $lastCode, $mCode)) {
            $nextNum = (int)$mCode[1] + 1;
        }
        $newCode = 'SS-CUST-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO customers (code, name, type, email, phone, whatsapp, country) VALUES (?,?,?,?,?,?,?)")
            ->execute([$newCode, $qName, 'individual', '', $qPhone, $qPhone, 'Indonesia']);
        $qCustomerId = (int)$pdo->lastInsertId();
        $log[] = 'INSERT customers OK (id=' . $qCustomerId . ')';
    }

    $qFixedPax = sunseaPackageFixedPax($pkgRow);
    if ($qFixedPax > 0) {
        $qPax = $qFixedPax;
    }

    $qEndDate = $qDate;
    $qDurationDays = (int)($pkgRow['duration_days'] ?? 0);
    if ($qDurationDays > 1) {
        $qEndDate = date('Y-m-d', strtotime($qDate . ' + ' . ($qDurationDays - 1) . ' days'));
    }

    $qBasePrice = (float)($pkgRow['base_price'] ?? 0);
    $qSubtotal = $qFixedPax > 0 ? $qBasePrice : $qBasePrice * $qPax;
    $qItemQty = $qFixedPax > 0 ? 1 : $qPax;
    $qItemUnit = $qFixedPax > 0 ? 'paket' : 'org';

    $qNo = sunseaNextNumber($pdo, 'quotation');
    $log[] = 'sunseaNextNumber OK (' . $qNo . ')';
    $qNotes = "[DIAG TEST] Permintaan penawaran cepat.\nPaket: " . $pkgRow['name'];
    $qItinerary = $pkgRow['itinerary'] ?? '';
    $pdo->prepare("INSERT INTO quotations (quotation_no, customer_id, package_id, trip_date, trip_end_date, itinerary, pax_count, subtotal, total_amount, notes, valid_until, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$qNo, $qCustomerId, $qPackageId, $qDate, $qEndDate, $qItinerary, $qPax, $qSubtotal, $qSubtotal, $qNotes, date('Y-m-d', strtotime('+7 days')), 'website']);
    $qId = (int)$pdo->lastInsertId();
    $log[] = 'INSERT quotations OK (id=' . $qId . ')';

    $pdo->prepare("INSERT INTO quotation_items (quotation_id, item_type, description, qty, unit, unit_price, subtotal, sort_order) VALUES (?,?,?,?,?,?,?,0)")
        ->execute([$qId, 'other', $pkgRow['name'], $qItemQty, $qItemUnit, $qBasePrice, $qSubtotal]);
    $log[] = 'INSERT quotation_items OK';

    sunseaNotifyAdminNewQuotation($pdo, $qId);
    $log[] = 'sunseaNotifyAdminNewQuotation() dipanggil tanpa fatal error (cek log di bawah utk detail internalnya)';

    $pdo->rollBack();
    $log[] = 'ROLLBACK - data test dihapus, tidak disimpan permanen.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Diagnostic Quick Quote</title></head>
<body style="font-family:monospace;padding:20px;">
    <h2>Diagnostic: Form "Minta Penawaran Cepat" (home.php)</h2>
    <p><b>Active business (session):</b> <?php echo htmlspecialchars($activeBusinessId); ?></p>
    <p><b>Langkah yang berhasil dijalankan:</b></p>
    <pre><?php echo htmlspecialchars(implode("\n", $log)); ?></pre>
    <?php if ($errorMsg !== ''): ?>
        <p style="color:red;font-weight:bold;">✘ GAGAL di sini. Pesan error asli:</p>
        <pre style="background:#fee;padding:12px;border:1px solid #f88;"><?php echo htmlspecialchars($errorMsg); ?></pre>
        <p><b>Trace:</b></p>
        <pre style="background:#f4f4f4;padding:12px;font-size:11px;"><?php echo htmlspecialchars($errorTrace); ?></pre>
    <?php else: ?>
        <p style="color:green;font-weight:bold;">✔ Semua langkah berhasil tanpa error. Kemungkinan masalah booking asli disebabkan hal lain (mis. data spesifik yang tamu isi, atau cache halaman lama).</p>
    <?php endif; ?>
</body>
</html>
