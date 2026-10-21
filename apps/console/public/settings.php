<?php

/** Pengaturan: pintu ke halaman yang jarang dibuka (server, tim ADF, audit log, akun sendiri). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$isAdmin = $me['role'] === 'admin';
$items = [
    ['server', 'Server', 'Kesehatan VPS: disk, RAM, layanan, SSL, backup, tagihan VPS.', '/server.php', false],
    ['team', 'Tim ADF', 'Akun yang bisa masuk Console ini dan perannya.', '/users.php', true],
    ['list', 'Audit log', 'Catatan siapa mengubah apa dan kapan.', '/audit.php', true],
    ['key', 'Akun saya', 'Ganti password akun Console Anda.', '/account.php', false],
];

View::start('Pengaturan', 'settings', $me);
?>
<div class="pagehead"><div><h1>Pengaturan</h1><p>Hal-hal yang jarang diubah.</p></div></div>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
    <?php foreach ($items as [$icon, $label, $desc, $url, $adminOnly]): if ($adminOnly && !$isAdmin) continue; ?>
        <a class="tile" href="<?= e($url) ?>">
            <span style="display:inline-grid;place-items:center;width:38px;height:38px;border-radius:10px;background:var(--goldbg);color:var(--gold)"><?= View::icon($icon) ?></span>
            <b><?= e($label) ?></b>
            <small><?= e($desc) ?></small>
        </a>
    <?php endforeach; ?>
</div>
<?php View::end();
