<?php

/** Server: kondisi VPS saat ini (sama dengan laporan Telegram & halaman monitor HP), backup, dan tagihan VPS. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$f = ServerHealth::facts();
$problems = ServerHealth::problems($f);
$bill = ServerHealth::billing();
$b = $f['backup'] ?? null;
$offsite = null;
$offFile = rtrim((string)Secrets::get('BACKUP_DIR', '/srv/adf/backups'), '/') . '/offsite.json';
if (is_readable($offFile)) {
    $offsite = json_decode((string)file_get_contents($offFile), true);
}
$bar = static function (int $pct, int $warn): string {
    $color = $pct >= $warn ? 'var(--bad)' : ($pct >= $warn - 15 ? 'var(--warn)' : 'var(--ok)');
    return '<div style="height:7px;border-radius:99px;background:var(--line2);overflow:hidden;margin-top:8px"><div style="height:100%;width:' . max(2, min(100, $pct)) . '%;background:' . $color . '"></div></div>';
};

View::start('Server', 'server', $me);
?>
<div class="crumb"><a href="/settings.php">Pengaturan</a> ›</div>
<div class="pagehead">
    <div><h1><?= e(ServerHealth::serverName()) ?></h1><p>Diperiksa otomatis tiap 5 menit; masalah dikirim ke Telegram. Halaman ini membaca kondisi saat dibuka.</p></div>
    <span class="pill <?= $problems ? 'bad' : 'ok' ?>" style="font-size:13px;padding:5px 12px"><?= $problems ? count($problems) . ' masalah' : 'Semua normal' ?></span>
</div>

<?php if ($problems): ?>
    <div class="card" style="border-color:rgba(192,57,43,.3)"><h2 style="color:var(--bad)">Perlu perhatian</h2>
        <?php foreach ($problems as $msg): ?><p style="margin:0 0 4px">• <?= e($msg) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<div class="row2" style="margin-bottom:14px">
    <div class="card" style="margin:0"><h2>Disk</h2><div class="stat"><?= (int)$f['disk_pct'] ?>% <small>terpakai</small></div><?= $bar((int)$f['disk_pct'], ServerHealth::DISK_WARN) ?>
        <p class="muted" style="margin:8px 0 0;font-size:12.5px">sisa <?= e((string)$f['disk_free_gb']) ?> GB dari <?= e((string)$f['disk_total_gb']) ?> GB</p></div>
    <div class="card" style="margin:0"><h2>RAM</h2><div class="stat"><?= (int)$f['mem_pct'] ?>% <small>terpakai</small></div><?= $bar((int)$f['mem_pct'], ServerHealth::MEM_WARN) ?>
        <p class="muted" style="margin:8px 0 0;font-size:12.5px">total <?= e((string)$f['mem_total_gb']) ?> GB · swap <?= (int)$f['swap_used_mb'] ?> MB</p></div>
    <div class="card" style="margin:0"><h2>Beban CPU</h2><div class="stat"><?= e(number_format((float)$f['load'], 2)) ?> <small>/ <?= (int)$f['cpus'] ?> core</small></div>
        <p class="muted" style="margin:8px 0 0;font-size:12.5px">rata-rata 5 menit · hidup <?= e((string)$f['uptime_days']) ?> hari</p></div>
</div>

<div class="row2">
    <div class="card">
        <h2>Layanan</h2>
        <table class="sm"><tbody>
            <?php foreach ($f['services'] as $unit => $st): ?>
                <tr><td><?= e(ServerHealth::SERVICES[$unit] ?? $unit) ?></td><td style="text-align:right"><span class="pill <?= $st === 'active' ? 'ok' : 'bad' ?>"><?= $st === 'active' ? 'berjalan' : e($st) ?></span></td></tr>
            <?php endforeach; ?>
            <tr><td>Website <?= e($f['site_host']) ?></td><td style="text-align:right"><span class="pill <?= $f['web_ok'] ? 'ok' : 'bad' ?>"><?= $f['web_ok'] ? 'menjawab' : 'tidak menjawab' ?></span></td></tr>
            <tr><td>Sertifikat SSL</td><td style="text-align:right"><?= $f['ssl_days'] === null ? '<span class="pill">-</span>' : '<span class="pill ' . ($f['ssl_days'] <= ServerHealth::SSL_WARN_DAYS ? 'warn' : 'ok') . '">' . (int)$f['ssl_days'] . ' hari lagi</span>' ?></td></tr>
            <tr><td>Update keamanan</td><td style="text-align:right"><?= $f['security_updates'] === null ? '<span class="pill">-</span>' : '<span class="pill ' . ($f['security_updates'] ? 'warn' : 'ok') . '">' . (int)$f['security_updates'] . '</span>' ?><?= $f['reboot'] ? ' <span class="pill warn">perlu restart</span>' : '' ?></td></tr>
        </tbody></table>
    </div>
    <div class="card">
        <h2>Backup</h2>
        <?php if ($b === null): ?>
            <p class="muted" style="margin:0">Status backup belum terbaca.</p>
        <?php else: ?>
            <table class="sm"><tbody>
                <tr><td>Backup harian</td><td style="text-align:right"><span class="pill <?= !empty($b['ok']) && $b['age_h'] <= ServerHealth::BACKUP_MAX_AGE_H ? 'ok' : 'bad' ?>"><?= !empty($b['ok']) ? 'OK' : 'gagal' ?></span></td></tr>
                <tr><td>Terakhir</td><td style="text-align:right"><?= e(date('d M Y H:i', strtotime((string)($b['finished'] ?? 'now')))) ?> <span class="muted">(<?= (int)$b['age_h'] ?> jam lalu)</span></td></tr>
                <tr><td>Isi</td><td style="text-align:right"><?= (int)($b['databases'] ?? 0) ?> database · <?= e((string)($b['size_mb'] ?? 0)) ?> MB</td></tr>
                <tr><td>Salinan di luar VPS</td><td style="text-align:right"><?= $offsite ? '<span class="pill ' . (!empty($offsite['ok']) ? 'ok' : 'bad') . '">' . (!empty($offsite['ok']) ? 'OK' : 'gagal') . '</span> <span class="muted">' . e(date('d M H:i', strtotime((string)($offsite['finished'] ?? 'now')))) . '</span>' : '<span class="pill">belum terbaca</span>' ?></td></tr>
            </tbody></table>
            <?php if (empty($b['ok'])): ?><p style="margin:8px 0 0;color:var(--bad);font-size:12.5px"><?= e((string)($b['message'] ?? '')) ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($bill): ?>
        <div class="card">
            <h2>Tagihan VPS</h2>
            <div class="stat"><?= $bill['days'] === 0 ? 'Hari ini' : $bill['days'] . ' <small>hari lagi</small>' ?></div>
            <p class="muted" style="margin:6px 0 0;font-size:13px"><?= e($bill['due']->format('d M Y')) ?><?= $bill['price'] ? ' · ' . Biz::rp($bill['price']) : '' ?><?= $bill['plan'] ? ' · ' . e($bill['plan']) : '' ?></p>
        </div>
    <?php endif; ?>
</div>
<?php View::end();
