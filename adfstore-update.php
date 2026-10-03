<?php

/**
 * Salin kode adfsystem-site/ (repo, sudah ter-update lewat "Update from Remote") ke folder addon
 * domain adfsystem.store, TANPA lewat "Deploy HEAD Commit" cPanel.
 *
 * Dipakai saat Deploy diblokir cPanel ("No uncommitted changes exist on the checked-out branch"),
 * mis. karena uploads/webhook-debug.log berubah tiap kali absen fingerprint.
 *
 * Aman untuk data live: folder data/ (content.json, orders.json, users.json, klien langganan)
 * TIDAK disentuh, dan file di uploads/ hanya disalin kalau belum ada di tujuan.
 *
 * URL: https://adfsystem.online/adfstore-update.php?token=1bb3f4729e04cd22387d8800
 */

if (!hash_equals('1bb3f4729e04cd22387d8800', (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$source = realpath(__DIR__ . '/adfsystem-site');
$target = __DIR__ . '/adfsystem.store';

if ($source === false || !is_dir($source)) {
    exit("Folder sumber adfsystem-site tidak ditemukan.\n");
}
if (!is_dir($target)) {
    exit("Folder tujuan $target tidak ditemukan.\n");
}

$copied = 0;
$skipped = 0;
$failed = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');

    // Data live (pengaturan konten, transaksi, user, klien) dikelola dari admin di server: jangan ditimpa.
    if ($relative === 'data' || strpos($relative, 'data/') === 0) {
        continue;
    }

    $dest = $target . '/' . $relative;
    if ($item->isDir()) {
        if (!is_dir($dest)) {
            @mkdir($dest, 0755, true);
        }
        continue;
    }

    // Upload gambar: hanya tambah yang belum ada, jangan timpa upload yang dibuat dari admin live.
    if (strpos($relative, 'uploads/') === 0 && file_exists($dest)) {
        $skipped++;
        continue;
    }

    if (file_exists($dest) && md5_file($dest) === md5_file($item->getPathname())) {
        $skipped++;
        continue;
    }

    if (@copy($item->getPathname(), $dest)) {
        $copied++;
        echo "Disalin: $relative\n";
        if (function_exists('opcache_invalidate') && substr($relative, -4) === '.php') {
            @opcache_invalidate($dest, true);
        }
    } else {
        $failed++;
        echo "GAGAL : $relative\n";
    }
}

echo "\nSelesai: $copied file diperbarui, $skipped sudah sama/dilewati, $failed gagal.\n";
echo $failed === 0 ? "OK - buka https://adfsystem.store/admin dan tekan Ctrl+F5.\n" : "Ada file yang gagal disalin, cek izin folder.\n";
