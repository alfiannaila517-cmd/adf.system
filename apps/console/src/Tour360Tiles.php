<?php

declare(strict_types=1);

/**
 * Tiles resolusi tinggi untuk Tour 360° (seperti Street View): foto equirectangular → 6 sisi kubus (ffmpeg v360) →
 * potongan 512 px per tingkat ketajaman. Website (Marzipano CubeGeometry) hanya memuat potongan yang sedang dilihat,
 * jadi tetap tajam saat di-zoom, juga di HP yang tidak kuat memuat satu gambar 8192 px.
 *
 * Hasil di $outDir:  preview.jpg (6 sisi 256 px berurutan b,d,f,l,r,u ke bawah) + <z>/<sisi>/<baris>/<kolom>.jpg
 *   z = 1.. (tingkat; 0 = preview), sisi = f r b l u d, ukuran tingkat 512, 1024, 2048, … sampai ukuran sisi.
 * Ukuran sisi ≈ lebar foto / 4 (dibulatkan ke kelipatan 512, maks 4096): 8192 → 2048, 12000 → 3072.
 */
final class Tour360Tiles
{
    public const TILE = 512;
    /** arah tiap sisi kubus: [yaw, pitch] untuk ffmpeg v360 (pitch positif = atas) */
    private const FACES = ['f' => [0, 0], 'r' => [90, 0], 'b' => [180, 0], 'l' => [-90, 0], 'u' => [0, 90], 'd' => [0, -90]];

    /** Lokasi ffmpeg (null = belum terpasang: sudo apt install ffmpeg) */
    public static function ffmpeg(): ?string
    {
        $cands = [class_exists('Secrets') ? (string)Secrets::get('FFMPEG_BIN', '') : '', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
        foreach ($cands as $p) {
            if ($p !== '' && is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Ukuran sisi kubus dari lebar foto equirectangular (≈ lebar/4, tanpa memperbesar lebih dari 10%).
     * Hanya ukuran yang bisa dibagi menjadi tingkat-tingkat yang saling kelipatan (syarat Marzipano).
     */
    public static function faceSize(int $width): int
    {
        $best = 1024;
        foreach ([1024, 1536, 2048, 3072, 4096] as $s) {
            if ($s <= $width / 4 * 1.1) {
                $best = $s;
            }
        }
        return $best;
    }

    /** @return int[] ukuran tiap tingkat, tiap tingkat kelipatan tingkat sebelumnya: 2048 → [512, 1024, 2048], 3072 → [512, 1536, 3072] */
    public static function levels(int $face): array
    {
        $out = [$face];
        while ($out[0] > self::TILE) {
            $s = $out[0];
            $next = null;
            foreach ([2, 3] as $d) {
                if ($s % $d === 0 && ($s / $d) % self::TILE === 0) {
                    $next = intdiv($s, $d);
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            array_unshift($out, $next);
        }
        return $out;
    }

    /**
     * Buat tiles dari $src ke $outDir (dibuat di folder sementara lalu ditukar, jadi website tidak pernah melihat setengah jadi).
     * @return array{face:int, levels:int[]}
     */
    public static function build(string $src, string $outDir, string $ffmpeg): array
    {
        $info = @getimagesize($src);
        if (!$info) {
            throw new RuntimeException('Foto sumber tidak terbaca: ' . $src);
        }
        $face = self::faceSize((int)$info[0]);
        $levels = self::levels($face);
        $tmp = $outDir . '.tmp' . getmypid();
        self::rmrf($tmp);
        if (!@mkdir($tmp, 0775, true)) {
            throw new RuntimeException('Tidak bisa membuat folder ' . $tmp);
        }
        ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $preview = imagecreatetruecolor(256, 256 * 6);
        $order = ['b', 'd', 'f', 'l', 'r', 'u']; // urutan bawaan pratinjau kubus Marzipano
        try {
            foreach (self::FACES as $f => [$yaw, $pitch]) {
                // perantara PNG (RGB tanpa kompresi warna) — JPG dari ffmpeg membuat warna sedikit pudar
                $faceFile = "{$tmp}/face-{$f}.png";
                $vf = "v360=input=e:output=flat:h_fov=90:v_fov=90:yaw={$yaw}:pitch={$pitch}:w={$face}:h={$face}:interp=lanczos,format=rgb24";
                $cmd = escapeshellarg($ffmpeg) . ' -y -loglevel error -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg($vf) . ' ' . escapeshellarg($faceFile) . ' 2>&1';
                exec($cmd, $outLines, $code);
                if ($code !== 0 || !is_file($faceFile)) {
                    throw new RuntimeException('ffmpeg gagal (sisi ' . $f . '): ' . implode(' ', array_slice($outLines, -3)));
                }
                $im = imagecreatefrompng($faceFile);
                foreach ($levels as $i => $size) {
                    $z = $i + 1;
                    $lv = $size === $face ? $im : self::resize($im, $face, $size);
                    $n = intdiv($size, self::TILE);
                    for ($y = 0; $y < $n; $y++) {
                        @mkdir("{$tmp}/{$z}/{$f}/{$y}", 0775, true);
                        for ($x = 0; $x < $n; $x++) {
                            $t = imagecreatetruecolor(self::TILE, self::TILE);
                            imagecopy($t, $lv, 0, 0, $x * self::TILE, $y * self::TILE, self::TILE, self::TILE);
                            imagejpeg($t, "{$tmp}/{$z}/{$f}/{$y}/{$x}.jpg", 86);
                            imagedestroy($t);
                        }
                    }
                    if ($lv !== $im) {
                        imagedestroy($lv);
                    }
                }
                imagecopyresampled($preview, $im, 0, 256 * array_search($f, $order, true), 0, 0, 256, 256, $face, $face);
                imagedestroy($im);
                @unlink($faceFile);
            }
            imagejpeg($preview, "{$tmp}/preview.jpg", 82);
            imagedestroy($preview);
        } catch (Throwable $e) {
            self::rmrf($tmp);
            throw $e;
        }
        // tukar: lama → .old, baru → final, hapus lama
        $old = $outDir . '.old' . getmypid();
        if (is_dir($outDir)) {
            rename($outDir, $old);
        }
        rename($tmp, $outDir);
        self::rmrf($old);
        return ['face' => $face, 'levels' => $levels];
    }

    private static function resize(GdImage $im, int $from, int $to): GdImage
    {
        $r = imagecreatetruecolor($to, $to);
        imagecopyresampled($r, $im, 0, 0, 0, 0, $to, $to, $from, $from);
        return $r;
    }

    public static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
