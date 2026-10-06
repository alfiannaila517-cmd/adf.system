<?php

/**
 * FRONT DESK - Kirim PDF Laporan Harian ke WhatsApp (Fonnte) dalam 1 klik.
 * POST -> JSON { ok, sent, failed, results: [{target, ok, detail}] }
 */

define('APP_ACCESS', true);
define('LAPORAN_PDF_RETURN', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/WhatsAppHelper.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'detail' => 'Akses ditolak']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'detail' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();
$wa = new WhatsAppHelper($db);
$targets = $wa->reportTargets();

$respond = static function (array $data): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};

// mode=link: hanya buat link PDF (untuk Bagikan manual lewat wa.me), tanpa gateway.
$linkOnly = ($_POST['mode'] ?? '') === 'link';

if (!$linkOnly && (!$wa->isConfigured() || !$targets)) {
    $respond(['ok' => false, 'detail' => 'WhatsApp belum diatur. Isi token & tujuan laporan di Pengaturan → WhatsApp.']);
}

// Buat PDF (laporan-pdf.php mengisi $bytes, $fileName, $waText lalu kembali ke sini).
try {
    require __DIR__ . '/laporan-pdf.php';
} catch (\Throwable $e) {
    $respond(['ok' => false, 'detail' => 'Gagal membuat PDF: ' . $e->getMessage()]);
}

try {
    $pub = WhatsAppHelper::publishTempFile($bytes, 'pdf', 'laporan-' . $today); // dihapus otomatis setelah 2 hari
} catch (\Throwable $e) {
    $respond(['ok' => false, 'detail' => $e->getMessage()]);
}
if ($linkOnly) {
    $respond(['ok' => true, 'url' => $pub['url'], 'text' => rtrim($waText) . "

📄 *PDF Laporan:*
" . $pub['url'] . "
_(link berlaku 2 hari)_"]);
}
@set_time_limit(30 + 60 * count($targets));

// Link PDF selalu dicantumkan di teks: sebagian paket gateway tidak meneruskan lampiran.
$waText = rtrim($waText) . "

📄 *PDF Laporan:*
" . $pub['url'] . "
_(link berlaku 2 hari)_";

$results = [];
foreach ($targets as $t) {
    $r = $wa->send($t, $waText, null, $fileName, 'report', 'Laporan ' . $today, $pub['url']);
    $results[] = ['target' => $t, 'ok' => $r['ok'], 'detail' => $r['detail']];
}

$sent = count(array_filter($results, fn($r) => $r['ok']));
$respond([
    'ok' => $sent > 0,
    'sent' => $sent,
    'failed' => count($results) - $sent,
    'results' => $results,
    'detail' => $sent === count($results) ? 'Terkirim ke ' . $sent . ' tujuan' : ($sent . ' terkirim, ' . (count($results) - $sent) . ' gagal'),
]);
