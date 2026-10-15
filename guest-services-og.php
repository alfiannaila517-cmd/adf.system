<?php
/**
 * Gambar pratinjau link Layanan Tamu (1200x630) — tampil di WhatsApp saat link dikirim.
 * Bukan flyer: kartu sambutan hotel (logo, nama hotel, daftar layanan aktif). ?l=id|en.
 * Hasil di-cache di uploads/guest-services/og_<hash>.png; berubah otomatis bila nama hotel / logo / layanan berubah.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/WhatsAppHelper.php';
require_once __DIR__ . '/includes/GuestServices.php';

$lang = 'en'; // gambar pratinjau selalu berbahasa Inggris (dipahami semua tamu)
$db = Database::getInstance();
$s = GuestServices::settings($db);

$hotel = '';
try {
    $hotel = (string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? '');
} catch (\Throwable $e) {
}
if ($hotel === '' && defined('BUSINESS_NAME')) $hotel = BUSINESS_NAME;

// Layanan aktif -> label
$L = $lang === 'en'
    ? ['hi' => 'WELCOME TO', 'tag' => 'Services & information for your stay', 'wifi' => 'Free WiFi', 'trip' => 'Island Trips', 'motor' => 'Motorbike', 'car' => 'Car Rental', 'taxi' => 'Taxi']
    : ['hi' => 'SELAMAT DATANG DI', 'tag' => 'Layanan & informasi selama menginap', 'wifi' => 'WiFi', 'trip' => 'Trip', 'motor' => 'Sewa Motor', 'car' => 'Sewa Mobil', 'taxi' => 'Taxi'];
$pills = [];
if (trim($s['gs_wifi_ssid']) !== '') $pills[] = $L['wifi'];
if (array_filter(GuestServices::flyers($s), fn($f) => $f['cat'] === 'trip')) $pills[] = $L['trip'];
if ($s['gs_motor_enabled'] === '1') $pills[] = $L['motor'];
if ($s['gs_car_enabled'] === '1') $pills[] = $L['car'];
if ($s['gs_taxi_enabled'] === '1') $pills[] = $L['taxi'];

// Logo (logo portal sarapan bila ada, kalau tidak logo bisnis)
$logoSrc = '';
try {
    $pl = trim((string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_portal_logo_path'")['setting_value'] ?? ''));
    if ($pl !== '') $logoSrc = strpos($pl, 'http') === 0 ? $pl : GuestServices::baseUrl() . '/' . ltrim($pl, '/');
    else {
        require_once __DIR__ . '/includes/business_helper.php';
        if (function_exists('getBusinessLogo')) $logoSrc = (string)getBusinessLogo();
    }
} catch (\Throwable $e) {
}

$dir = __DIR__ . '/uploads/guest-services';
$key = md5(json_encode([$lang, $hotel, $logoSrc, $pills, 'v5']));
$cache = "$dir/og_$key.png";
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
if (is_file($cache)) {
    readfile($cache);
    exit;
}
if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
    http_response_code(404); // tanpa GD/FreeType: tidak ada gambar (halaman tetap jalan)
    exit;
}

$W = 1200;
$H = 630;
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
imagesavealpha($im, true);

// Latar gradasi navy
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H;
    $c = imagecolorallocate($im, (int)(15 + (29 - 15) * $t), (int)(39 + (78 - 39) * $t), (int)(68 + (137 - 68) * $t));
    imageline($im, 0, $y, $W, $y, $c);
}
$gold = imagecolorallocate($im, 227, 198, 143);
$goldSoft = imagecolorallocatealpha($im, 227, 198, 143, 100);
$white = imagecolorallocate($im, 255, 255, 255);
$mute = imagecolorallocatealpha($im, 255, 255, 255, 40);
// Hiasan: lingkaran lembut + bingkai emas tipis
imagefilledellipse($im, 1080, 90, 520, 520, imagecolorallocatealpha($im, 255, 255, 255, 120));
imagefilledellipse($im, 1180, 560, 380, 380, imagecolorallocatealpha($im, 227, 198, 143, 118));
imagerectangle($im, 28, 28, $W - 29, $H - 29, $goldSoft);

$reg = __DIR__ . '/assets/fonts/Poppins-Regular.ttf';
$semi = __DIR__ . '/assets/fonts/Poppins-SemiBold.ttf';

// Teks dengan jarak huruf
$spaced = function (string $text, int $x, int $y, int $size, $color, string $font, int $space) use ($im): void {
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        imagettftext($im, $size, 0, $x, $y, $color, $font, $ch);
        $bb = imagettfbbox($size, 0, $font, $ch);
        $x += ($bb[2] - $bb[0]) + $space + ($ch === ' ' ? 6 : 0);
    }
};

// Logo bulat: dipangkas ke isi logonya (tanpa tepi putih) lalu memenuhi lingkaran; cincin emas di luar
$cx = 190;
$cy = 170;
$r = 98;
imagefilledellipse($im, $cx, $cy, ($r + 9) * 2, ($r + 9) * 2, $gold);
imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $white);
$lg = false;
if ($logoSrc !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 4], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $raw = @file_get_contents($logoSrc, false, $ctx);
    $lg = $raw ? @imagecreatefromstring($raw) : false;
}
if ($lg) {
    $lw = imagesx($lg);
    $lh = imagesy($lg);
    // kotak isi logo: piksel yang bukan putih / transparan
    $x0 = $lw; $y0 = $lh; $x1 = -1; $y1 = -1;
    $step = max(1, (int)floor(max($lw, $lh) / 400));
    for ($yy = 0; $yy < $lh; $yy += $step) {
        for ($xx = 0; $xx < $lw; $xx += $step) {
            $p = imagecolorat($lg, $xx, $yy);
            $alpha = ($p >> 24) & 127;
            $rr = ($p >> 16) & 255; $gg = ($p >> 8) & 255; $bb2 = $p & 255;
            if ($alpha < 100 && ($rr < 235 || $gg < 235 || $bb2 < 235)) {
                if ($xx < $x0) $x0 = $xx; if ($xx > $x1) $x1 = $xx; if ($yy < $y0) $y0 = $yy; if ($yy > $y1) $y1 = $yy;
            }
        }
    }
    if ($x1 < 0) { $x0 = 0; $y0 = 0; $x1 = $lw - 1; $y1 = $lh - 1; }
    $side = max($x1 - $x0 + 1, $y1 - $y0 + 1);
    $sx = (int)round(($x0 + $x1) / 2 - $side / 2);
    $sy = (int)round(($y0 + $y1) / 2 - $side / 2);
    $d = $r * 2;
    $sq = imagecreatetruecolor($d, $d);
    imagefill($sq, 0, 0, $white);
    imagecopyresampled($sq, $lg, 0, 0, $sx, $sy, $d, $d, $side, $side);
    // salin hanya piksel di dalam lingkaran
    for ($yy = 0; $yy < $d; $yy++) {
        for ($xx = 0; $xx < $d; $xx++) {
            $dx = $xx - $r + .5; $dy = $yy - $r + .5;
            if ($dx * $dx + $dy * $dy <= $r * $r - 1) imagesetpixel($im, $cx - $r + $xx, $cy - $r + $yy, imagecolorat($sq, $xx, $yy));
        }
    }
    imagedestroy($sq);
    imagedestroy($lg);
} else {
    $ini = mb_strtoupper(mb_substr($hotel, 0, 1));
    $bb = imagettfbbox(60, 0, $semi, $ini);
    imagettftext($im, 60, 0, $cx - (int)(($bb[2] - $bb[0]) / 2), $cy + 22, imagecolorallocate($im, 15, 39, 68), $semi, $ini);
}

// Teks
$spaced($L['hi'], 96, 340, 22, $gold, $semi, 5);
$size = 66;
while ($size > 34) {
    $bb = imagettfbbox($size, 0, $semi, $hotel);
    if (($bb[2] - $bb[0]) <= $W - 192) break;
    $size -= 2;
}
imagettftext($im, $size, 0, 92, 340 + 18 + $size, $white, $semi, $hotel);
imagettftext($im, 26, 0, 96, 340 + 18 + $size + 52, $mute, $reg, $L['tag']);

// Daftar layanan
$x = 96;
$y = 535;
foreach ($pills as $p) {
    $bb = imagettfbbox(22, 0, $semi, $p);
    $w = ($bb[2] - $bb[0]) + 48;
    $hh = 52;
    // pil: dua setengah lingkaran + persegi
    $fill = imagecolorallocate($im, 52, 98, 152); // warna solid agar sambungan pil tidak terlihat
    imagefilledrectangle($im, $x + $hh / 2, $y, $x + $w - $hh / 2, $y + $hh, $fill);
    imagefilledellipse($im, (int)($x + $hh / 2), $y + (int)($hh / 2), $hh, $hh, $fill);
    imagefilledellipse($im, (int)($x + $w - $hh / 2), $y + (int)($hh / 2), $hh, $hh, $fill);
    imagefilledellipse($im, $x + 24, $y + 26, 9, 9, $gold);
    imagettftext($im, 20, 0, $x + 40, $y + 35, $white, $semi, $p);
    $x += $w + 14;
    if ($x > $W - 200) break;
}

if (!is_dir($dir)) @mkdir($dir, 0755, true);
// bersihkan cache lama (hash berbeda)
foreach (glob("$dir/og_*.png") ?: [] as $old) {
    if (filemtime($old) < time() - 7 * 86400) @unlink($old);
}
@imagepng($im, $cache, 6);
imagepng($im);
imagedestroy($im);
