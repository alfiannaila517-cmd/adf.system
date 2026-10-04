<?php
// Read-only monthly Gudang Nasita bill recap for the CURRENT active business (Tagihan menu, tab Gudang).
// Angka diambil dari gudangMonthlyBillBreakdown() — sumber yang sama dengan halaman Tagihan Bisnis
// Gudang, halaman Tagihan Bisnis & Gudang di outlet, dan proses bayar.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_functions.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$slug = gudangNormalizeBizSlug((string)($_SESSION['active_business_id'] ?? ''));
if (!in_array($slug, array_column(gudangTrackedBizList(), 'slug'), true)) {
    echo json_encode(['success' => false, 'message' => 'Tagihan Gudang tidak tersedia untuk bisnis ini']);
    exit;
}

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

try {
    $bill = gudangMonthlyBillBreakdown($slug, $month);
    $transferNumbers = array_unique(array_merge(
        array_column($bill['gudang_items'], 'number'),
        array_column($bill['from_biz_items'], 'number')
    ));
    $qty = array_sum(array_column($bill['gudang_items'], 'quantity')) + array_sum(array_column($bill['from_biz_items'], 'quantity'));
    $isPaid = $bill['paid'] !== null;

    echo json_encode([
        'success' => true,
        'recap' => [
            'month'          => $month,
            'transfer_count' => count($transferNumbers),
            'transfer_qty'   => $qty,
            'transfer_nilai' => $bill['gudang_total'] + $bill['from_biz_total'],
            'tkbm_share'     => $bill['tkbm_share'],
            // Bulan yang sudah lunas menampilkan jumlah yang benar-benar dibayar.
            'total'          => $isPaid ? $bill['paid']['amount'] : $bill['total'],
            'is_paid'        => $isPaid,
            'paid_at'        => $isPaid ? date('d M Y H:i', strtotime((string)$bill['paid']['paid_at'])) : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('get-gudang-bill-recap error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal memuat tagihan gudang']);
}
