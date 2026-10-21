<?php

/** Audit log: semua perubahan yang dilakukan lewat Console (siapa, apa, kapan, dari IP mana). Khusus admin. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$db = Auth::db();
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 50;

$where = '';
$args = [];
if ($q !== '') {
    $where = 'WHERE username LIKE ? OR action LIKE ? OR detail LIKE ?';
    $args = array_fill(0, 3, '%' . $q . '%');
}
$st = $db->prepare("SELECT COUNT(*) FROM console_audit_log {$where}");
$st->execute($args);
$total = (int)$st->fetchColumn();
$st = $db->prepare("SELECT * FROM console_audit_log {$where} ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();
$pages = max(1, (int)ceil($total / $per));

View::start('Audit log', 'audit', $me);
?>
<div class="crumb"><a href="/settings.php">Pengaturan</a> ›</div>
<div class="pagehead">
    <div><h1>Audit log</h1><p><?= number_format($total, 0, ',', '.') ?> catatan perubahan lewat Console.</p></div>
    <form method="get" style="display:flex;gap:8px;min-width:260px"><input name="q" value="<?= e($q) ?>" placeholder="Cari akun, aksi, atau isi…"></form>
</div>
<div class="card">
    <table class="stack">
        <thead><tr><th>Waktu</th><th>Akun</th><th>Aksi</th><th>Rincian</th><th>IP</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?= e(date('d M Y H:i', strtotime((string)$r['created_at']))) ?></td>
                    <td><b><?= e($r['username'] ?? '-') ?></b></td>
                    <td><code><?= e($r['action']) ?></code></td>
                    <td><?= e((string)($r['detail'] ?? '')) ?></td>
                    <td class="muted" style="font-size:12px"><?= e((string)($r['ip'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="muted">Tidak ada catatan<?= $q !== '' ? ' yang cocok' : '' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if ($pages > 1): ?>
        <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:12px">
            <?php if ($page > 1): ?><a class="btn ghost sm" href="?p=<?= $page - 1 ?>&q=<?= urlencode($q) ?>">‹ Sebelumnya</a><?php endif; ?>
            <span class="muted" style="align-self:center;font-size:12.5px">halaman <?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn ghost sm" href="?p=<?= $page + 1 ?>&q=<?= urlencode($q) ?>">Berikutnya ›</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php View::end();
