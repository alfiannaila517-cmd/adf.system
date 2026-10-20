<?php

/** Dashboard Console: ringkasan kondisi server, bisnis terdaftar, dan aktivitas terakhir. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once ADF_CORE . '/ServerHealth.php';

$user = Auth::requireLogin();

$state = ServerHealth::lastState();
$problems = $state['problems'] ?? [];
$checked = (int)($state['checked'] ?? 0);
$bill = ServerHealth::billing();
$tenants = [];
try {
    $tenants = Tenant::all();
} catch (Throwable $e) {
    error_log('console dashboard tenants: ' . $e->getMessage());
}
$recent = Auth::db()->query('SELECT username, action, detail, created_at FROM console_audit_log ORDER BY id DESC LIMIT 8')->fetchAll();

View::start('Dashboard', 'dashboard', $user);
if (isset($_GET['denied'])) {
    echo '<div class="alert err">Halaman itu khusus admin.</div>';
}
?>
<h1>Selamat datang, <?= e($user['username']) ?></h1>
<p class="muted" style="margin:0 0 14px"><?= e(date('l, d M Y · H:i')) ?> WIB</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
    <div class="card">
        <h2>Server</h2>
        <div style="font-size:15px;font-weight:600;color:<?= $problems ? 'var(--bad)' : 'var(--ok)' ?>"><?= $problems ? count($problems) . ' perlu perhatian' : 'Semua normal' ?></div>
        <p class="muted" style="margin:6px 0 0;font-size:13px">Cek otomatis terakhir: <?= $checked ? e(date('d M H:i', $checked)) : 'belum ada' ?></p>
        <?php foreach ($problems as $p): ?><p style="margin:6px 0 0;font-size:13px;color:var(--bad)">• <?= e($p['msg'] ?? '') ?></p><?php endforeach; ?>
    </div>
    <div class="card">
        <h2>Bisnis</h2>
        <div style="font-size:15px;font-weight:600"><?= count($tenants) ?> terdaftar</div>
        <p class="muted" style="margin:6px 0 0;font-size:13px"><?= e(implode(', ', array_map(fn ($t) => $t->name(), $tenants)) ?: '-') ?></p>
    </div>
    <?php if ($bill): ?>
        <div class="card">
            <h2>Tagihan VPS</h2>
            <div style="font-size:15px;font-weight:600;color:<?= $bill['level'] === 'ok' ? 'var(--ink)' : ($bill['level'] === 'soon' ? 'var(--warn)' : 'var(--bad)') ?>"><?= $bill['days'] === 0 ? 'Hari ini' : $bill['days'] . ' hari lagi' ?></div>
            <p class="muted" style="margin:6px 0 0;font-size:13px"><?= e($bill['due']->format('d M Y')) ?><?= $bill['price'] ? ' · Rp ' . number_format($bill['price'], 0, ',', '.') : '' ?></p>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Aktivitas terakhir</h2>
    <?php if (!$recent): ?>
        <p class="muted" style="margin:0">Belum ada aktivitas.</p>
    <?php else: ?>
        <table class="stack">
            <thead><tr><th>Waktu</th><th>Akun</th><th>Aktivitas</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr><td class="muted"><?= e(date('d M H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username'] ?? '-') ?></td><td><?= e($r['action']) ?><?= $r['detail'] ? ' <span class="muted">· ' . e($r['detail']) . '</span>' : '' ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php View::end();
