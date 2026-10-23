<?php

/**
 * Ikon aplikasi Staff Portal per bisnis (PNG persegi, ukuran persis sesuai ?size=).
 *   ?b=<slug>&size=180|192|512
 * Desain: latar gradasi warna tema bisnis + cahaya lembut, cincin emas tipis, monogram putih (Poppins SemiBold)
 * dan tulisan kecil "STAFF". Isi berada di zona aman 80% → sama bagusnya untuk purpose "any" & "maskable" (Android)
 * maupun apple-touch-icon (iOS membulatkan sudut sendiri). Hasil disimpan di folder temp agar tidak digambar ulang.
 */
define('APP_ACCESS', true);

$size = (int)($_GET['size'] ?? 192);
$size = in_array($size, [180, 192, 512], true) ? $size : 192;
$slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim((string)($_GET['b'] ?? ''))));

$name = 'Staff Portal';
$type = '';
$c1 = '#1e1b4b';
$c2 = '#2563eb';
$cfgFile = __DIR__ . '/../../config/businesses/' . $slug . '.php';
if ($slug !== '' && is_file($cfgFile)) {
    $cfg = require $cfgFile;
    $name = (string)($cfg['name'] ?? $name);
    $type = (string)($cfg['business_type'] ?? '');
    $c1 = (string)($cfg['theme']['color_secondary'] ?? $c1);
    $c2 = (string)($cfg['theme']['color_primary'] ?? $c2);
}

/** '#rrggbb' → [r, g, b]; warna tidak valid → biru tua. */
function iconRgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return [30, 41, 90];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Monogram: singkatan huruf besar (CQC, PWF) dipakai apa adanya, selain itu huruf awal dua kata pertama. */
function iconMonogram(string $name): string
{
    $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name)), -1, PREG_SPLIT_NO_EMPTY) ?: ['S'];
    if (preg_match('/^[A-Z0-9]{2,4}$/', $words[0])) {
        return $words[0];
    }
    $m = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $m .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $m;
}

[$r1, $g1, $b1] = iconRgb($c1);
[$r2, $g2, $b2] = iconRgb($c2);
// Bagian gelap selalu di kiri atas → kontras monogram putih terjaga
if (($r1 * 299 + $g1 * 587 + $b1 * 114) > ($r2 * 299 + $g2 * 587 + $b2 * 114)) {
    [$r1, $g1, $b1, $r2, $g2, $b2] = [$r2, $g2, $b2, $r1, $g1, $b1];
}
$mono = iconMonogram($name);

$font = realpath(__DIR__ . '/../../assets/fonts/Poppins-SemiBold.ttf');
$cacheKey = md5(implode('|', ['v3', $size, $mono, $c1, $c2, $type]));
$cacheFile = sys_get_temp_dir() . '/adf-staff-icon-' . $cacheKey . '.png';

header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
if (!function_exists('imagecreatetruecolor') || !$font) {
    header('Location: absen-icon.php?size=' . ($size === 180 ? 192 : $size));
    exit;
}
if (is_file($cacheFile)) {
    header('Content-Type: image/png');
    readfile($cacheFile);
    exit;
}

// Digambar 3× lebih besar lalu diperkecil → tepi halus (GD tidak punya antialias untuk lingkaran tebal)
$S = $size * 3;
$im = imagecreatetruecolor($S, $S);
imagealphablending($im, true);

// 1) Gradasi diagonal: gelap (kiri atas) → warna utama (kanan bawah)
for ($k = 0; $k <= 2 * $S; $k++) {
    $t = $k / (2 * $S);
    $t = $t * $t * (3 - 2 * $t); // ease agar transisi lembut
    $col = imagecolorallocate($im, (int)($r1 + ($r2 - $r1) * $t), (int)($g1 + ($g2 - $g1) * $t), (int)($b1 + ($b2 - $b1) * $t));
    imageline($im, $k, 0, 0, $k, $col);
    imageline($im, $k + 1, 0, 0, $k + 1, $col);
}

// 2) Cahaya sangat lembut dari kiri atas (lingkaran besar bertumpuk, masing-masing hampir transparan)
for ($i = 0; $i < 16; $i++) {
    $rad = (int)($S * (1.3 - $i * 0.06));
    imagefilledellipse($im, 0, 0, $rad, $rad, imagecolorallocatealpha($im, 255, 255, 255, 125));
}

// 3) Cincin emas tipis (zona aman: diameter 64% kanvas) — elips 1px bertumpuk agar tebal merata
$cx = (int)($S / 2);
$cy = (int)($S / 2);
$ringD = (int)($S * 0.64);
$ringW = max(3, (int)($S * 0.011));
$gold = imagecolorallocate($im, 233, 196, 106);
for ($w = 0; $w < $ringW; $w++) {
    imageellipse($im, $cx, $cy, $ringD + $w, $ringD + $w, $gold);
    imageellipse($im, $cx, $cy, $ringD - $w, $ringD - $w, $gold);
}

// 3b) Hiasan emas kecil di atas monogram sesuai jenis bisnis: hotel = tiga bintang, lainnya = satu belah ketupat
$star = function (float $x, float $y, float $r) use ($im, $gold): void {
    $pts = [];
    for ($p = 0; $p < 10; $p++) {
        $a = -M_PI / 2 + $p * M_PI / 5;
        $rr = $p % 2 ? $r * 0.42 : $r;
        $pts[] = (int)round($x + cos($a) * $rr);
        $pts[] = (int)round($y + sin($a) * $rr);
    }
    imagefilledpolygon($im, $pts, $gold);
};
$decoY = $cy - $S * 0.2;
if ($type === 'hotel') {
    foreach ([-1, 0, 1] as $d) {
        $star($cx + $d * $S * 0.05, $decoY + ($d === 0 ? -$S * 0.008 : 0), $S * ($d === 0 ? 0.024 : 0.019));
    }
} else {
    $dr = $S * 0.018;
    imagefilledpolygon($im, [(int)$cx, (int)($decoY - $dr), (int)($cx + $dr), (int)$decoY, (int)$cx, (int)($decoY + $dr), (int)($cx - $dr), (int)$decoY], $gold);
}

// 4) Monogram putih di tengah cincin (ukuran menyesuaikan jumlah huruf)
$white = imagecolorallocate($im, 255, 255, 255);
$fs = $S * (strlen($mono) >= 3 ? 0.15 : 0.19);
$box = imagettfbbox($fs, 0, $font, $mono);
$tw = $box[2] - $box[0];
$th = $box[1] - $box[7];
imagettftext($im, $fs, 0, (int)($cx - $tw / 2 - $box[0]), (int)($cy + $th / 2 - $S * 0.03), $white, $font, $mono);

// 5) Garis emas pendek + "STAFF" berspasi lebar di bawah monogram
$lineY = (int)($cy + $S * 0.085);
imagefilledrectangle($im, (int)($cx - $S * 0.05), $lineY, (int)($cx + $S * 0.05), $lineY + max(2, (int)($S * 0.006)), $gold);
$sub = 'STAFF';
$sfs = $S * 0.042;
$gap = $S * 0.018;
$widths = [];
foreach (str_split($sub) as $ch) {
    $b = imagettfbbox($sfs, 0, $font, $ch);
    $widths[] = $b[2] - $b[0];
}
$total = array_sum($widths) + $gap * (strlen($sub) - 1);
$x = $cx - $total / 2;
$soft = imagecolorallocatealpha($im, 255, 255, 255, 25);
foreach (str_split($sub) as $i => $ch) {
    imagettftext($im, $sfs, 0, (int)$x, (int)($lineY + $S * 0.075), $soft, $font, $ch);
    $x += $widths[$i] + $gap;
}

$out = imagecreatetruecolor($size, $size);
imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);

@imagepng($out, $cacheFile, 6);
header('Content-Type: image/png');
imagepng($out, null, 6);

