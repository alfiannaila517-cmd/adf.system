<?php

/**
 * Ikon aplikasi Staff Portal (PNG persegi, ukuran persis sesuai ?size=) — sama untuk semua bisnis.
 *   ?size=180|192|512
 * Desain mengikuti ikon ADF Console / ADF Store: latar hitam #0b0e14, logo ADF putih (assets/img/adf-logo-white.png),
 * garis oranye #ff6a1a, lalu tulisan "STAFF PORTAL". Isi berada di zona aman 80% → cocok untuk purpose "any" & "maskable"
 * (Android) maupun apple-touch-icon (iOS membulatkan sudut sendiri). Hasil disimpan di folder temp.
 */
define('APP_ACCESS', true);

$size = (int)($_GET['size'] ?? 192);
$size = in_array($size, [180, 192, 512], true) ? $size : 192;

$logoFile = realpath(__DIR__ . '/../../assets/img/adf-logo-white.png');
$font = realpath(__DIR__ . '/../../assets/fonts/Poppins-SemiBold.ttf');
$cacheFile = sys_get_temp_dir() . '/adf-staff-icon-adf4-' . $size . '.png';

header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
if (!function_exists('imagecreatetruecolor') || !$logoFile || !$font) {
    header('Location: absen-icon.php?size=' . ($size === 180 ? 192 : $size));
    exit;
}
if (is_file($cacheFile)) {
    header('Content-Type: image/png');
    readfile($cacheFile);
    exit;
}

// Digambar 3× lebih besar lalu diperkecil → tepi huruf halus
$S = $size * 3;
$im = imagecreatetruecolor($S, $S);
imagealphablending($im, true);
imagefill($im, 0, 0, imagecolorallocate($im, 11, 14, 20));

// Logo ADF: potong ke bagian yang terlihat (abaikan tepi transparan), tinggi 34% kanvas
$logo = imagecreatefrompng($logoFile);
$lw = imagesx($logo);
$lh = imagesy($logo);
[$x0, $y0, $x1, $y1] = [$lw, $lh, 0, 0];
for ($y = 0; $y < $lh; $y += 2) {
    for ($x = 0; $x < $lw; $x += 2) {
        if (((imagecolorat($logo, $x, $y) >> 24) & 127) < 100) {
            [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
        }
    }
}
// Hanya simbol "A": berhenti saat lebar baris tiba-tiba menyempit (mulai tulisan kecil "ARIEF DSY" yang tak terbaca di ikon)
$maxSpan = 0;
for ($y = $y0; $y <= $y1; $y++) {
    [$l, $r] = [$lw, -1];
    for ($x = $x0; $x <= $x1; $x++) {
        if (((imagecolorat($logo, $x, $y) >> 24) & 127) < 100) {
            [$l, $r] = [min($l, $x), max($r, $x)];
        }
    }
    $span = $r - $l + 1;
    if ($maxSpan > 0 && $span < $maxSpan * 0.8) {
        // mundur sedikit: baris teratas tulisan menempel di kaki huruf A
        $y1 = $y - 1 - (int)ceil(($y - $y0) * 0.02);
        break;
    }
    $maxSpan = max($maxSpan, $span);
}
[$x0, $x1] = [$lw, 0];
for ($y = $y0; $y <= $y1; $y++) {
    for ($x = 0; $x < $lw; $x++) {
        if (((imagecolorat($logo, $x, $y) >> 24) & 127) < 100) {
            [$x0, $x1] = [min($x0, $x), max($x1, $x)];
        }
    }
}
$cw = $x1 - $x0 + 1;
$ch = $y1 - $y0 + 1;
$dh = (int)($S * 0.34);
$dw = (int)($cw * $dh / $ch);
$top = (int)($S * 0.2);
imagecopyresampled($im, $logo, (int)(($S - $dw) / 2), $top, $x0, $y0, $dw, $dh, $cw, $ch);

// Garis oranye
$orange = imagecolorallocate($im, 255, 106, 26);
$lineY = $top + $dh + (int)($S * 0.065);
imagefilledrectangle($im, (int)($S * 0.4), $lineY, (int)($S * 0.6), $lineY + (int)($S * 0.014), $orange);

// "STAFF PORTAL" putih berspasi lebar, di tengah
$white = imagecolorallocate($im, 255, 255, 255);
$text = 'STAFF PORTAL';
$fs = $S * 0.054;
$gap = $S * 0.01;
$widths = [];
foreach (str_split($text) as $c) {
    $b = imagettfbbox($fs, 0, $font, $c === ' ' ? 'I' : $c);
    $widths[] = $c === ' ' ? ($b[2] - $b[0]) * 1.2 : $b[2] - $b[0];
}
$x = ($S - (array_sum($widths) + $gap * (strlen($text) - 1))) / 2;
$baseY = (int)($lineY + $S * 0.12);
foreach (str_split($text) as $i => $c) {
    if ($c !== ' ') {
        imagettftext($im, $fs, 0, (int)$x, $baseY, $white, $font, $c);
    }
    $x += $widths[$i] + $gap;
}

$out = imagecreatetruecolor($size, $size);
imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
@imagepng($out, $cacheFile, 6);
header('Content-Type: image/png');
imagepng($out, null, 6);
