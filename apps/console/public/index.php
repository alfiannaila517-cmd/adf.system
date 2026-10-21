<?php

/** Beranda Console: ringkasan bisnis, tagihan, server, dan aktivitas terakhir. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = Auth::requireLogin();
$db = Biz::db();

$state = ServerHealth::lastState();
$problems = $state['problems'] ?? [];
$checked = (int)($state['checked'] ?? 0);
$bill = ServerHealth::billing();
$backup = ServerHealth::backupStatus();

// Bisnis nyata (grup demo tidak dihitung)
$biz = $db->query('SELECT b.status, c.id AS cid FROM businesses b JOIN clients c ON c.id = b.client_id WHERE c.is_demo = 0')->fetchAll();
$activeBiz = count(array_filter($biz, fn ($b) => $b['status'] === 'active'));
$groupCount = count(array_unique(array_column($biz, 'cid')));

// Tagihan: belum lunas + perkiraan pendapatan bulanan (paket + tambahan)
$unpaidTotal = 0.0;
$unpaidN = 0;
foreach ($db->query('SELECT ch.* FROM subscription_charges ch JOIN businesses b ON b.id = ch.business_id JOIN clients c ON c.id = b.client_id WHERE ch.status = \'unpaid\' AND c.is_demo = 0')->fetchAll() as $ch) {
    $unpaidTotal += Biz::chargeTotal($ch);
    $unpaidN++;
}
$monthly = (float)$db->query('SELECT COALESCE(SUM(s.base_fee), 0) FROM subscriptions s JOIN businesses b ON b.id = s.business_id JOIN clients c ON c.id = b.client_id WHERE b.status = \'active\' AND c.is_demo = 0')->fetchColumn();
foreach (Biz::addons() as $rows) {
    foreach ($rows as $a) {
        $monthly += $a['active'] ? (float)$a['monthly_fee'] : 0;
    }
}

$recent = Auth::db()->query('SELECT username, action, detail, created_at FROM console_audit_log ORDER BY id DESC LIMIT 8')->fetchAll();
// Nama aktivitas yang mudah dibaca
$label = static fn (string $a): string => [
    'login' => 'Masuk', 'person.add' => 'Tambah user', 'person.password' => 'Ganti password user', 'person.update' => 'Ubah user',
    'client.add' => 'Buat grup', 'client.update' => 'Ubah data grup', 'client.branding' => 'Ubah login & domain grup',
    'business.add' => 'Tambah bisnis', 'business.update' => 'Ubah bisnis', 'business.provision' => 'Buat bisnis di sistem',
    'business.modules' => 'Ubah menu & fitur', 'access.add' => 'Beri akses', 'access.update' => 'Ubah akses', 'access.remove' => 'Cabut akses',
    'roles.update' => 'Ubah izin peran', 'legacy.export' => 'Kirim perubahan ke sistem', 'subscription.update' => 'Ubah langganan',
    'billing.addon_add' => 'Tambah biaya tambahan', 'integration.save' => 'Ubah integrasi',
    'staff_portal.create' => 'Buat akun Staff Portal', 'staff_portal.password' => 'Password Staff Portal baru', 'staff_portal.delete' => 'Hapus akun Staff Portal',
    'access.group_owner_add' => 'Tambah pemilik grup', 'access.group_owner_remove' => 'Cabut pemilik grup', 'subscription.create' => 'Buat langganan',
][$a] ?? $a;

$hour = (int)date('G');
View::start('Beranda', 'dashboard', $user);
if (isset($_GET['denied'])) {
    echo '<div class="alert err">Halaman itu khusus admin.</div>';
}
?>
<div class="pagehead">
    <div><h1><?= $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam')) ?>, <?= e($user['username']) ?></h1>
        <p><?= e(strtr(date('l, d F Y · H:i'), ['Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
            'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'October' => 'Oktober', 'December' => 'Desember'])) ?> WIB</p></div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:14px">
    <a class="tile" href="/clients.php">
        <small>Bisnis aktif</small>
        <div class="stat" style="margin-top:4px"><?= $activeBiz ?> <small>dalam <?= $groupCount ?> grup</small></div>
    </a>
    <a class="tile" href="/billing.php">
        <small>Pendapatan per bulan</small>
        <div class="stat" style="margin-top:4px"><?= Biz::rp($monthly) ?></div>
        <div class="meta"><?= $unpaidN ? '<span class="pill warn">' . Biz::rp($unpaidTotal) . ' belum lunas</span>' : '<span class="pill ok">semua lunas</span>' ?></div>
    </a>
    <a class="tile" href="/server.php">
        <small>Server</small>
        <div class="stat" style="margin-top:4px;color:<?= $problems ? 'var(--bad)' : 'var(--ok)' ?>"><?= $problems ? count($problems) . ' masalah' : 'Normal' ?></div>
        <div class="meta">
            <?php if ($backup !== null): ?><span class="pill <?= !empty($backup['ok']) && $backup['age_h'] <= ServerHealth::BACKUP_MAX_AGE_H ? 'ok' : 'bad' ?>">backup <?= !empty($backup['ok']) ? (int)$backup['age_h'] . ' jam lalu' : 'gagal' ?></span><?php endif; ?>
            <?php if ($checked): ?><span class="pill">dicek <?= e(date('H:i', $checked)) ?></span><?php endif; ?>
        </div>
    </a>
    <?php if ($bill): ?>
        <a class="tile" href="/server.php">
            <small>Tagihan VPS</small>
            <div class="stat" style="margin-top:4px;color:<?= $bill['level'] === 'ok' ? 'var(--ink)' : ($bill['level'] === 'soon' ? 'var(--warn)' : 'var(--bad)') ?>"><?= $bill['days'] === 0 ? 'Hari ini' : $bill['days'] . ' <small>hari lagi</small>' ?></div>
            <div class="meta"><span class="pill"><?= e($bill['due']->format('d M Y')) ?><?= $bill['price'] ? ' · ' . Biz::rp($bill['price']) : '' ?></span></div>
        </a>
    <?php endif; ?>
</div>

<?php if ($problems): ?>
    <div class="card" style="border-color:rgba(192,57,43,.3)">
        <h2 style="color:var(--bad)">Perlu perhatian</h2>
        <?php foreach ($problems as $p): ?><p style="margin:0 0 4px;font-size:13.5px">• <?= e(is_array($p) ? ($p['msg'] ?? '') : (string)$p) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <h2 style="margin:0">Aktivitas terakhir</h2>
        <?php if ($user['role'] === 'admin'): ?><a href="/audit.php" style="font-size:13px">Lihat semua</a><?php endif; ?>
    </div>
    <?php if (!$recent): ?>
        <p class="muted" style="margin:0">Belum ada aktivitas.</p>
    <?php else: ?>
        <table class="stack">
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr><td class="muted" style="width:120px;white-space:nowrap"><?= e(date('d M H:i', strtotime($r['created_at']))) ?></td><td style="width:110px"><b><?= e($r['username'] ?? '-') ?></b></td><td><?= e($label((string)$r['action'])) ?><?= $r['detail'] ? ' <span class="muted">· ' . e($r['detail']) . '</span>' : '' ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php View::end();
