<?php

/**
 * Uji cepat kerangka inti. Jalankan dari terminal (folder cli/ tidak bisa dibuka dari web):
 *   php cli/selftest.php
 * Keluar dengan kode 1 bila ada yang gagal.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? "  OK    " : "  GAGAL ") . $label . PHP_EOL;
    if (!$ok) $fail++;
}

echo "Hooks" . PHP_EOL;
Hooks::reset();
check(Hooks::filter('x.price', 100) === 100, 'filter tanpa pendaftar mengembalikan nilai asli');
Hooks::on('x.price', fn (int $v) => $v + 1, 20);
Hooks::on('x.price', fn (int $v) => $v * 2, 5);
check(Hooks::filter('x.price', 10) === 21, 'prioritas kecil dijalankan dulu ((10*2)+1 = 21)');
$seen = [];
Hooks::on('x.done', function (string $a) use (&$seen) { $seen[] = $a; });
Hooks::action('x.done', 'ok');
check($seen === ['ok'], 'action menjalankan pendaftar dengan argumen');
Hooks::reset();

echo "Tenant" . PHP_EOL;
$all = Tenant::all();
check(count($all) >= 1, 'minimal satu tenant terbaca (' . implode(', ', array_map(fn ($t) => $t->slug, $all)) . ')');
check(!in_array('_template-hotel', array_map(fn ($t) => $t->slug, $all), true), 'cetakan berawalan "_" tidak ikut terbaca sebagai bisnis');
$n = Tenant::activate('narayana-hotel');
check($n->type() === 'hotel' && $n->database() === 'adf_narayana_hotel', 'narayana-hotel: jenis & database benar');
check($n->feature('cloudbeds') && !$n->feature('tidak_ada'), 'fitur on/off terbaca, fitur tak dikenal = mati');
check(!$n->hasModule('project'), "modul 'project' tidak aktif untuk Narayana (pengganti if-nama-bisnis di header)");
check($n->get('theme.icon') === '🏨', 'get() dengan notasi titik');
check(Tenant::current()?->slug === 'narayana-hotel', 'Tenant::current() menunjuk bisnis aktif');
try {
    Tenant::load('../config');
    check(false, 'slug berbahaya ditolak');
} catch (InvalidArgumentException $e) {
    check(true, 'slug berbahaya (../) ditolak');
}

echo "Folder" . PHP_EOL;
check(is_dir(ADF_TENANTS), 'tenants/ ada');
echo "  info  storage = " . ADF_STORAGE . PHP_EOL;
echo "  info  secrets = " . ADF_SECRETS . ' (kunci: ' . (implode(', ', Secrets::keys()) ?: 'belum ada') . ')' . PHP_EOL;

echo PHP_EOL . ($fail === 0 ? 'SEMUA LULUS' : "{$fail} GAGAL") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
