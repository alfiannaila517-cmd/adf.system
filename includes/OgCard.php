<?php

/**
 * Kartu pratinjau link (1200x630) untuk WhatsApp: logo bulat, teks sambutan, daftar label — tema navy/emas.
 * Dipakai guest-services-og.php (Layanan Tamu) dan go-breakfast-og.php (Portal Sarapan).
 *
 * Opsi: eyebrow, title, tagline, pills[], logo (URL/path), deco ('circles'|'breakfast'), cacheDir, cacheKey, initial.
 * Gambar di-cache sebagai PNG; berubah otomatis bila cacheKey berubah. Tanpa GD/FreeType → 404 (halaman tetap jalan).
 */
class OgCard
{
    public static function output(array $o): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        $dir = $o['cacheDir'];
        $cache = $dir . '/og_' . md5($o['cacheKey']) . '.png';
        if (is_file($cache)) {
            readfile($cache);
            return;
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            http_response_code(404);
            return;
        }
        $im = self::draw($o);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        foreach (glob("$dir/og_*.png") ?: [] as $old) {
            if (filemtime($old) < time() - 7 * 86400) @unlink($old);
        }
        @imagepng($im, $cache, 6);
        imagepng($im);
        imagedestroy($im);
    }

    private static function draw(array $o)
    {
        $W = 1200;
        $H = 630;
        $root = dirname(__DIR__);
        $reg = $root . '/assets/fonts/Poppins-Regular.ttf';
        $semi = $root . '/assets/fonts/Poppins-SemiBold.ttf';
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        // Latar gradasi navy
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imageline($im, 0, $y, $W, $y, imagecolorallocate($im, (int)(15 + 14 * $t), (int)(39 + 39 * $t), (int)(68 + 69 * $t)));
        }
        $gold = imagecolorallocate($im, 227, 198, 143);
        $white = imagecolorallocate($im, 255, 255, 255);
        $mute = imagecolorallocatealpha($im, 255, 255, 255, 40);
        imagefilledellipse($im, 1080, 90, 520, 520, imagecolorallocatealpha($im, 255, 255, 255, 120));
        imagefilledellipse($im, 1180, 560, 380, 380, imagecolorallocatealpha($im, 227, 198, 143, 118));
        imagerectangle($im, 28, 28, $W - 29, $H - 29, imagecolorallocatealpha($im, 227, 198, 143, 100));

        if (($o['deco'] ?? '') === 'breakfast') self::plate($im, $gold);

        // Logo bulat: dipangkas ke isinya (tanpa tepi putih), memenuhi lingkaran, cincin emas di luar
        $cx = 190;
        $cy = 170;
        $r = 98;
        imagefilledellipse($im, $cx, $cy, ($r + 9) * 2, ($r + 9) * 2, $gold);
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $white);
        $lg = false;
        if (!empty($o['logo'])) {
            $ctx = stream_context_create(['http' => ['timeout' => 4], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $raw = @file_get_contents($o['logo'], false, $ctx);
            $lg = $raw ? @imagecreatefromstring($raw) : false;
        }
        if ($lg) {
            self::paintLogo($im, $lg, $cx, $cy, $r, $white);
            imagedestroy($lg);
        } else {
            $ini = mb_strtoupper(mb_substr((string)($o['initial'] ?? $o['title']), 0, 1));
            $bb = imagettfbbox(60, 0, $semi, $ini);
            imagettftext($im, 60, 0, $cx - (int)(($bb[2] - $bb[0]) / 2), $cy + 22, imagecolorallocate($im, 15, 39, 68), $semi, $ini);
        }

        // Teks
        $x = 96;
        foreach (preg_split('//u', $o['eyebrow'], -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            imagettftext($im, 22, 0, $x, 340, $gold, $semi, $ch);
            $bb = imagettfbbox(22, 0, $semi, $ch);
            $x += ($bb[2] - $bb[0]) + 5 + ($ch === ' ' ? 6 : 0);
        }
        $size = 66;
        while ($size > 34) {
            $bb = imagettfbbox($size, 0, $semi, $o['title']);
            if (($bb[2] - $bb[0]) <= $W - 192) break;
            $size -= 2;
        }
        imagettftext($im, $size, 0, 92, 340 + 18 + $size, $white, $semi, $o['title']);
        imagettftext($im, 26, 0, 96, 340 + 18 + $size + 52, $mute, $reg, $o['tagline']);

        // Label
        $x = 96;
        $y = 535;
        $fill = imagecolorallocate($im, 52, 98, 152); // solid agar sambungan pil tidak terlihat
        foreach ($o['pills'] as $p) {
            $bb = imagettfbbox(22, 0, $semi, $p);
            $w = ($bb[2] - $bb[0]) + 48;
            $hh = 52;
            imagefilledrectangle($im, $x + $hh / 2, $y, $x + $w - $hh / 2, $y + $hh, $fill);
            imagefilledellipse($im, (int)($x + $hh / 2), $y + (int)($hh / 2), $hh, $hh, $fill);
            imagefilledellipse($im, (int)($x + $w - $hh / 2), $y + (int)($hh / 2), $hh, $hh, $fill);
            imagefilledellipse($im, $x + 24, $y + 26, 9, 9, $gold);
            imagettftext($im, 20, 0, $x + 40, $y + 35, $white, $semi, $p);
            $x += $w + 14;
            if ($x > $W - 200) break;
        }
        return $im;
    }

    /** Hiasan sarapan: piring dengan garpu & pisau (garis halus emas) di sisi kanan atas. */
    private static function plate($im, $gold): void
    {
        $soft = imagecolorallocatealpha($im, 255, 255, 255, 108);
        $ring = imagecolorallocatealpha($im, 227, 198, 143, 70);
        $line = imagecolorallocatealpha($im, 227, 198, 143, 62);
        $px = 930;
        $py = 175;
        imagefilledellipse($im, $px, $py, 250, 250, $soft);
        imagesetthickness($im, 4);
        imageellipse($im, $px, $py, 250, 250, $ring);
        imageellipse($im, $px, $py, 170, 170, $ring);
        imagesetthickness($im, 6);
        // garpu
        $fx = 770;
        foreach ([-14, 0, 14] as $dx) imageline($im, $fx + $dx, 80, $fx + $dx, 150, $line);
        imageline($im, $fx - 14, 150, $fx + 14, 150, $line);
        imageline($im, $fx, 150, $fx, 270, $line);
        // pisau
        $kx = 1090;
        imagefilledpolygon($im, [$kx - 12, 80, $kx + 4, 96, $kx + 4, 190, $kx - 12, 190], $line);
        imageline($im, $kx - 4, 190, $kx - 4, 270, $line);
        imagesetthickness($im, 1);
    }

    /** Salin logo ke lingkaran: pangkas ke kotak isi (bukan putih/transparan), skala ke diameter, hanya piksel di dalam lingkaran. */
    private static function paintLogo($im, $lg, int $cx, int $cy, int $r, $white): void
    {
        $lw = imagesx($lg);
        $lh = imagesy($lg);
        $x0 = $lw; $y0 = $lh; $x1 = -1; $y1 = -1;
        $step = max(1, (int)floor(max($lw, $lh) / 400));
        for ($yy = 0; $yy < $lh; $yy += $step) {
            for ($xx = 0; $xx < $lw; $xx += $step) {
                $p = imagecolorat($lg, $xx, $yy);
                $alpha = ($p >> 24) & 127;
                if ($alpha < 100 && ((($p >> 16) & 255) < 235 || (($p >> 8) & 255) < 235 || ($p & 255) < 235)) {
                    if ($xx < $x0) $x0 = $xx;
                    if ($xx > $x1) $x1 = $xx;
                    if ($yy < $y0) $y0 = $yy;
                    if ($yy > $y1) $y1 = $yy;
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
        for ($yy = 0; $yy < $d; $yy++) {
            for ($xx = 0; $xx < $d; $xx++) {
                $dx = $xx - $r + .5;
                $dy = $yy - $r + .5;
                if ($dx * $dx + $dy * $dy <= $r * $r - 1) imagesetpixel($im, $cx - $r + $xx, $cy - $r + $yy, imagecolorat($sq, $xx, $yy));
            }
        }
        imagedestroy($sq);
    }
}
