<?php

/**
 * Gambar lokasi Tour 360° untuk pratinjau di Console (dari folder website di VPS). Lewat Console supaya satu alamat
 * (WebGL Marzipano butuh gambar dari alamat yang sama). ?id=<bisnis website>&s=<id lokasi>&z=4096|8192|thumb
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

Auth::requireLogin();
$biz = Biz::business((int)($_GET['id'] ?? 0));
$size = in_array($_GET['z'] ?? '', ['4096', '8192', 'full', 'thumb'], true) ? (string)$_GET['z'] : 'thumb';
$file = null;
if ($biz && Website::available($biz)) {
    foreach (Tour360::load(Website::db($biz)) as $s) {
        if ($s['id'] === (string)($_GET['s'] ?? '')) {
            $file = Tour360::file($s, $size);
        }
    }
}
if (!$file) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
readfile($file);
