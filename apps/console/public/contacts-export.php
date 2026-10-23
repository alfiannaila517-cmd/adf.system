<?php

/**
 * Unduh kontak WhatsApp satu bisnis (khusus admin): ?id=<bisnis>&fmt=vcf|csv&suffix=1&g[]=<label grup>…
 * vcf = dibuka langsung di HP (Impor ke Kontak); csv = Google Contacts (contacts.google.com → Impor).
 * Berisi data pribadi → no-store + dicatat di audit log.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

Auth::requireAdmin();
$biz = Biz::business((int)($_GET['id'] ?? 0));
if (!$biz) {
    http_response_code(404);
    exit('Bisnis tidak ditemukan.');
}
try {
    $sdb = StaffPortal::db($biz);
} catch (Throwable $e) {
    exit(e($e->getMessage()));
}
if (!$sdb) {
    exit('Bisnis ini belum terhubung ke database sistem.');
}

$fmt = ($_GET['fmt'] ?? 'vcf') === 'csv' ? 'csv' : 'vcf';
$suffix = !empty($_GET['suffix']);
$only = array_values(array_filter(array_map('strval', (array)($_GET['g'] ?? []))));
$rows = Contacts::collect($sdb, $only)['rows'];
if (!$rows) {
    exit('Tidak ada kontak untuk grup yang dipilih.');
}
// Penanda bisnis di nama kontak: kata pertama nama bisnis ("Narayana", "Bens")
$bizShort = explode(' ', trim((string)$biz['name']))[0] ?: 'ADF';
Auth::audit('contacts.export', "{$biz['name']}: " . count($rows) . " kontak ({$fmt})" . ($only ? ' · ' . implode(', ', $only) : ''));

$file = 'kontak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$biz['slug'])) . '-' . date('Ymd') . '.' . $fmt;
header('Cache-Control: no-store');
header('Content-Type: ' . ($fmt === 'csv' ? 'text/csv' : 'text/vcard') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
echo $fmt === 'csv' ? Contacts::googleCsv($rows, $bizShort, $suffix) : Contacts::vcard($rows, $bizShort, $suffix);
