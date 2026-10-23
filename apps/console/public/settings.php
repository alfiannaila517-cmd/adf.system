<?php

/** Semua menu (dipakai sebagai "Lainnya" di HP, karena menu bawah hanya muat 4) — kelompok sama dengan menu samping. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$isAdmin = $me['role'] === 'admin';
// Halaman tambahan yang tidak ada di menu samping
$extra = array_values(array_filter([
    $isAdmin ? ['card', 'Pengaturan invoice', '/invoice-settings.php'] : null,
    ['key', 'Ubah password', '/account.php'],
    ['out', 'Keluar', '/logout.php'],
]));

View::start('Menu', 'settings', $me);
?>
<div class="pagehead"><div><h1>Menu</h1><p>Semua halaman Console.</p></div></div>
<?php
$groups = [];
$cur = '';
foreach (View::navFor($me) as $n) {
    if (is_string($n)) {
        $cur = $n;
        continue;
    }
    $groups[$cur][] = [$n[2], $n[1], $n[3]];
}
$groups['Akun'] = $extra;
foreach ($groups as $title => $items): ?>
    <?php if ($title !== ''): ?><div class="nav-label" style="padding:12px 2px 6px"><?= e($title) ?></div><?php endif; ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
        <?php foreach ($items as [$icon, $label, $url]): ?>
            <a class="tile" href="<?= e($url) ?>" style="display:flex;align-items:center;gap:10px;padding:10px 12px">
                <span style="display:inline-grid;place-items:center;width:32px;height:32px;border-radius:9px;background:var(--goldbg);color:var(--gold);flex-shrink:0"><?= View::icon($icon) ?></span>
                <b style="font-size:12.5px"><?= e($label) ?></b>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php View::end();
