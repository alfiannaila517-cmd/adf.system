<?php

/** Beranda Console: angka utama, kesehatan server (grafik donat disk / RAM / CPU), aktivitas, dan invoice yang menunggu. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$user = Auth::requireLogin();
$db = Biz::db();

// Kondisi server: dibaca langsung, disimpan 60 detik supaya Beranda tetap cepat
$cacheFile = ADF_STORAGE . '/_platform/console-facts.json';
$f = is_readable($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
if (!is_array($f) || (time() - (int)($f['_at'] ?? 0)) > 60) {
    $f = ServerHealth::facts();
    $f['_at'] = time();
    @file_put_contents($cacheFile, json_encode($f));
}
$problems = ServerHealth::problems($f);
$bill = ServerHealth::billing();
$backup = $f['backup'] ?? null;
$offFile = rtrim((string)Secrets::get('BACKUP_DIR', '/srv/adf/backups'), '/') . '/offsite.json';
$offsite = is_readable($offFile) ? json_decode((string)file_get_contents($offFile), true) : null;
$cpuPct = (int)min(100, round((float)$f['load'] / max(1, (int)$f['cpus']) * 100));

// Bisnis nyata (grup demo tidak dihitung)
$biz = $db->query('SELECT b.status, c.id AS cid FROM businesses b JOIN clients c ON c.id = b.client_id WHERE c.is_demo = 0')->fetchAll();
$activeBiz = count(array_filter($biz, fn ($b) => $b['status'] === 'active'));
$groupCount = count(array_unique(array_column($biz, 'cid')));

// Pendapatan bulanan (paket + tambahan bisnis aktif)
$monthly = (float)$db->query('SELECT COALESCE(SUM(s.base_fee), 0) FROM subscriptions s JOIN businesses b ON b.id = s.business_id JOIN clients c ON c.id = b.client_id WHERE b.status = \'active\' AND c.is_demo = 0')->fetchColumn();
foreach (Biz::addons() as $rows) {
    foreach ($rows as $a) {
        $monthly += $a['active'] ? (float)$a['monthly_fee'] : 0;
    }
}

// Invoice yang menunggu pembayaran
$waiting = [];
$waitTotal = 0.0;
foreach ($db->query("SELECT * FROM invoices WHERE status = 'unpaid' ORDER BY due_date LIMIT 50")->fetchAll() as $inv) {
    $st = $db->prepare('SELECT qty, unit_price FROM invoice_items WHERE invoice_id = ?');
    $st->execute([(int)$inv['id']]);
    $inv['items'] = $st->fetchAll();
    $inv['total'] = Invoice::totals($inv)['total'];
    $waitTotal += $inv['total'];
    $waiting[] = $inv;
}

$recent = Auth::db()->query('SELECT username, action, detail, created_at FROM console_audit_log ORDER BY id DESC LIMIT 7')->fetchAll();
$label = static fn (string $a): string => [
    'login' => 'Masuk', 'person.add' => 'Tambah user', 'person.password' => 'Ganti password user', 'person.update' => 'Ubah user', 'person.status' => 'Ubah status user',
    'client.add' => 'Buat grup', 'client.update' => 'Ubah data grup', 'client.branding' => 'Ubah login & domain grup',
    'business.add' => 'Tambah bisnis', 'business.update' => 'Ubah bisnis', 'business.provision' => 'Buat bisnis di sistem',
    'business.modules' => 'Ubah menu & fitur', 'access.add' => 'Beri akses', 'access.update' => 'Ubah akses', 'access.remove' => 'Cabut akses',
    'roles.update' => 'Ubah izin peran', 'legacy.export' => 'Kirim perubahan ke sistem', 'subscription.update' => 'Ubah langganan', 'subscription.create' => 'Buat langganan',
    'billing.addon_add' => 'Tambah biaya tambahan', 'integration.save' => 'Ubah integrasi',
    'staff_portal.create' => 'Buat akun Staff Portal', 'staff_portal.password' => 'Password Staff Portal baru', 'staff_portal.delete' => 'Hapus akun Staff Portal',
    'access.group_owner_add' => 'Tambah pemilik grup', 'access.group_owner_remove' => 'Cabut pemilik grup',
    'invoice.add' => 'Buat invoice', 'invoice.update' => 'Ubah invoice', 'invoice.paid' => 'Invoice lunas', 'invoice.cancelled' => 'Batalkan invoice', 'invoice.settings' => 'Ubah pengaturan invoice',
][$a] ?? $a;

/** Grafik donat SVG: persen di tengah, warna mengikuti batas peringatan. */
$donut = static function (int $pct, int $warn, string $title, string $sub): string {
    $pct = max(0, min(100, $pct));
    $color = $pct >= $warn ? '#f87171' : ($pct >= $warn - 15 ? '#fbbf24' : 'url(#gOk)');
    $c = 2 * M_PI * 40;
    return '<div class="dn"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="#1d2330" stroke-width="9"/>'
        . ($pct === 0 ? '' : '<circle cx="50" cy="50" r="40" fill="none" stroke="' . $color . '" stroke-width="9" stroke-linecap="round" stroke-dasharray="' . round($c * $pct / 100, 2) . ' ' . round($c, 2) . '" transform="rotate(-90 50 50)"/>')
        . '<text x="50" y="49" text-anchor="middle" class="dn-v">' . $pct . '%</text><text x="50" y="63" text-anchor="middle" class="dn-l">' . e($title) . '</text></svg>'
        . '<small>' . e($sub) . '</small></div>';
};
$hour = (int)date('G');
$days = ['Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'];

View::start('Beranda', 'dashboard', $user);
if (isset($_GET['denied'])) {
    echo '<div class="alert err">Halaman itu khusus admin.</div>';
}
?>
<style>
    .kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 10px; }
    .kpi .tile small { font-size: 11px; }
    .srv { display: grid; grid-template-columns: auto 1fr; gap: 18px; align-items: center; }
    .dns { display: flex; gap: 14px; }
    .dn { width: 118px; text-align: center; }
    .dn svg { width: 104px; height: 104px; display: block; margin: 0 auto; }
    .dn-v { fill: #fff; font: 700 17px Inter, sans-serif; }
    .dn-l { fill: #7d8696; font: 600 7.5px Inter, sans-serif; letter-spacing: .08em; text-transform: uppercase; }
    .dn small { display: block; color: var(--mu); font-size: 10.5px; margin-top: 2px; }
    .chk { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 14px; }
    .chk div { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid var(--line2); font-size: 12px; }
    .chk div span:first-child { color: var(--ink2); }
    .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 6px; }
    .two { display: grid; grid-template-columns: 1.6fr 1fr; gap: 10px; }
    @media (max-width: 1000px) { .kpi { grid-template-columns: repeat(2, 1fr); } .srv, .two { grid-template-columns: 1fr; } .dns { justify-content: center; flex-wrap: wrap; } }
    @media (max-width: 560px) { .chk { grid-template-columns: 1fr; } }
</style>
<svg width="0" height="0" style="position:absolute"><defs><linearGradient id="gOk" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff8a4c"/><stop offset="1" stop-color="#ff6a1a"/></linearGradient></defs></svg>

<div class="pagehead" style="margin-bottom:12px">
    <div><h1><?= $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam')) ?>, <?= e($user['username']) ?></h1>
        <p><?= e(($days[date('l')] ?? date('l')) . ', ' . Invoice::date(date('Y-m-d')) . ' · ' . date('H:i')) ?> WIB</p></div>
    <span class="pill <?= $problems ? 'bad' : 'ok' ?>" style="font-size:11.5px;padding:4px 11px"><span class="dot" style="background:currentColor"></span><?= $problems ? count($problems) . ' hal perlu perhatian' : 'Semua sistem normal' ?></span>
</div>

<div class="kpi">
    <a class="tile" href="/clients.php"><small>Bisnis aktif</small><div class="stat" style="margin-top:3px"><?= $activeBiz ?> <small>dalam <?= $groupCount ?> grup</small></div></a>
    <a class="tile" href="/billing.php"><small>Pendapatan per bulan</small><div class="stat" style="margin-top:3px"><?= Biz::rp($monthly) ?></div></a>
    <a class="tile" href="/invoices.php?s=unpaid"><small>Invoice belum dibayar</small><div class="stat" style="margin-top:3px;color:<?= $waiting ? 'var(--warn)' : 'var(--ink)' ?>"><?= Biz::rp($waitTotal) ?> <small><?= count($waiting) ?> invoice</small></div></a>
    <?php if ($bill): ?>
        <a class="tile" href="/server.php"><small>Tagihan VPS</small><div class="stat" style="margin-top:3px;color:<?= $bill['level'] === 'ok' ? 'var(--ink)' : ($bill['level'] === 'soon' ? 'var(--warn)' : 'var(--bad)') ?>"><?= $bill['days'] === 0 ? 'Hari ini' : $bill['days'] . ' <small>hari lagi · ' . e($bill['due']->format('d M')) . '</small>' ?></div></a>
    <?php else: ?>
        <a class="tile" href="/server.php"><small>Server hidup</small><div class="stat" style="margin-top:3px"><?= e((string)$f['uptime_days']) ?> <small>hari</small></div></a>
    <?php endif; ?>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h2 style="margin:0">Server · <?= e(ServerHealth::serverName()) ?></h2>
        <a href="/server.php" style="font-size:12px">Detail</a>
    </div>
    <div class="srv">
        <div class="dns">
            <?= $donut((int)$f['disk_pct'], ServerHealth::DISK_WARN, 'Storage', 'sisa ' . $f['disk_free_gb'] . ' / ' . $f['disk_total_gb'] . ' GB') ?>
            <?= $donut((int)$f['mem_pct'], ServerHealth::MEM_WARN, 'RAM', $f['mem_total_gb'] . ' GB · swap ' . $f['swap_used_mb'] . ' MB') ?>
            <?= $donut($cpuPct, 90, 'CPU', 'beban ' . number_format((float)$f['load'], 2) . ' · ' . $f['cpus'] . ' core') ?>
        </div>
        <div class="chk">
            <?php foreach ($f['services'] as $unit => $st): ?>
                <div><span><span class="dot" style="background:<?= $st === 'active' ? 'var(--ok)' : 'var(--bad)' ?>"></span><?= e(ServerHealth::SERVICES[$unit] ?? $unit) ?></span><span class="pill <?= $st === 'active' ? 'ok' : 'bad' ?>"><?= $st === 'active' ? 'berjalan' : e($st) ?></span></div>
            <?php endforeach; ?>
            <div><span><span class="dot" style="background:<?= $f['web_ok'] ? 'var(--ok)' : 'var(--bad)' ?>"></span>Website</span><span class="pill <?= $f['web_ok'] ? 'ok' : 'bad' ?>"><?= $f['web_ok'] ? 'menjawab' : 'mati' ?></span></div>
            <div><span><span class="dot" style="background:<?= $f['ssl_days'] !== null && $f['ssl_days'] > ServerHealth::SSL_WARN_DAYS ? 'var(--ok)' : 'var(--warn)' ?>"></span>Sertifikat SSL</span><span class="pill"><?= $f['ssl_days'] === null ? '-' : (int)$f['ssl_days'] . ' hari' ?></span></div>
            <?php $bOk = $backup && !empty($backup['ok']) && $backup['age_h'] <= ServerHealth::BACKUP_MAX_AGE_H; ?>
            <div><span><span class="dot" style="background:<?= $bOk ? 'var(--ok)' : 'var(--bad)' ?>"></span>Backup harian</span><span class="pill <?= $bOk ? 'ok' : 'bad' ?>"><?= $backup ? ($backup['ok'] ? (int)$backup['age_h'] . ' jam lalu' : 'gagal') : 'belum terbaca' ?></span></div>
            <div><span><span class="dot" style="background:<?= $offsite && !empty($offsite['ok']) ? 'var(--ok)' : 'var(--mu)' ?>"></span>Salinan di luar VPS</span><span class="pill <?= $offsite && !empty($offsite['ok']) ? 'ok' : '' ?>"><?= $offsite ? (!empty($offsite['ok']) ? 'OK' : 'gagal') : 'belum terbaca' ?></span></div>
            <div><span><span class="dot" style="background:<?= $f['security_updates'] ? 'var(--warn)' : 'var(--ok)' ?>"></span>Update keamanan</span><span class="pill"><?= $f['security_updates'] === null ? '-' : (int)$f['security_updates'] ?><?= $f['reboot'] ? ' · perlu restart' : '' ?></span></div>
            <div><span><span class="dot" style="background:var(--ok)"></span>Server hidup</span><span class="pill"><?= e((string)$f['uptime_days']) ?> hari</span></div>
        </div>
    </div>
    <?php if ($problems): ?>
        <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--line2)"><?php foreach ($problems as $msg): ?><p style="margin:0 0 3px;font-size:12px;color:var(--bad)">• <?= e($msg) ?></p><?php endforeach; ?></div>
    <?php endif; ?>
</div>

<div class="two">
    <div class="card" style="margin:0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <h2 style="margin:0">Aktivitas terakhir</h2>
            <?php if ($user['role'] === 'admin'): ?><a href="/audit.php" style="font-size:12px">Lihat semua</a><?php endif; ?>
        </div>
        <table class="sm"><tbody>
            <?php foreach ($recent as $r): ?>
                <tr><td class="muted" style="white-space:nowrap;width:96px"><?= e(date('d M H:i', strtotime($r['created_at']))) ?></td><td><b><?= e($label((string)$r['action'])) ?></b><?= $r['detail'] ? ' <span class="muted">· ' . e($r['detail']) . '</span>' : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td class="muted">Belum ada aktivitas.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div class="card" style="margin:0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <h2 style="margin:0">Menunggu pembayaran</h2>
            <a href="/invoices.php?s=unpaid" style="font-size:12px">Semua</a>
        </div>
        <table class="sm"><tbody>
            <?php foreach (array_slice($waiting, 0, 6) as $w): $ds = Invoice::displayStatus($w); ?>
                <tr><td><a href="/invoice-view.php?id=<?= (int)$w['id'] ?>" style="color:var(--ink)"><b><?= e($w['customer_name']) ?></b></a><br><span class="muted" style="font-size:11px"><?= e($w['number']) ?> · <?= $ds === 'overdue' ? '<span style="color:var(--bad)">lewat ' . e(date('d M', strtotime($w['due_date']))) . '</span>' : 'tempo ' . e(date('d M', strtotime($w['due_date']))) ?></span></td>
                    <td style="text-align:right;font-weight:600;white-space:nowrap"><?= Biz::rp($w['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$waiting): ?><tr><td class="muted">Tidak ada invoice yang menunggu. 👍</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
<?php View::end();
