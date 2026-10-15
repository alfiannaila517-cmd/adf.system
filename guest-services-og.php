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

$lang = ($_GET['l'] ?? '') === 'en' ? 'en' : 'id';
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
$key = md5(json_encode([$lang, $hotel, $logoSrc, $pills, 'v3']));
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

// Logo bulat: cincin emas + isi putih, logo di-contain di tengah
$cx = 168;
$cy = 168;
$r = 76;
imagefilledellipse($im, $cx, $cy, ($r + 8) * 2, ($r + 8) * 2, $gold);
imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $white);
if ($logoSrc !== '') {
    $ctx = stream_context_create(['http' => ['timeout' => 4], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $raw = @file_get_contents($logoSrc, false, $ctx);
    $lg = $raw ? @imagecreatefromstring($raw) : false;
    if ($lg) {
        $lw = imagesx($lg);
        $lh = imagesy($lg);
        $box = (int)($r * 2 * 0.80);
        $k = min($box / $lw, $box / $lh);
        $nw = max(1, (int)round($lw * $k));
        $nh = max(1, (int)round($lh * $k));
        imagecopyresampled($im, $lg, $cx - (int)($nw / 2), $cy - (int)($nh / 2), 0, 0, $nw, $nh, $lw, $lh);
        imagedestroy($lg);
    }
} else {
    $bb = imagettfbbox(54, 0, $semi, mb_strtoupper(mb_substr($hotel, 0, 1)));
    imagettftext($im, 54, 0, $cx - (int)(($bb[2] - $bb[0]) / 2), $cy + 20, imagecolorallocate($im, 15, 39, 68), $semi, mb_strtoupper(mb_substr($hotel, 0, 1)));
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
