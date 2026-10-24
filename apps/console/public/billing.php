<?php

/**
 * Klien Langganan: tarif & tagihan bulanan semua sistem bisnis, satu kartu per grup.
 * Baris bisnis → tab Langganan bisnis itu (tarif, tagihan bulanan, tagihan manual, koneksi). Biaya tambahan
 * bulanan (mis. website) dikelola di bagian bawah tiap kartu grup.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$isAdmin = $me['role'] === 'admin';
$money = fn ($v) => (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,.\-]/', '', (string)$v));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $bid = (int)($_POST['business_id'] ?? 0);
    $aid = (int)($_POST['addon_id'] ?? 0);
    try {
        if (!csrf_ok() || !$isAdmin) {
            throw new RuntimeException(!$isAdmin ? 'Hanya admin yang bisa mengubah tagihan.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
        }
        if ($action === 'addon_add') {
            $biz = Biz::business($bid) ?? throw new RuntimeException('Bisnis tidak ditemukan.');
            $name = trim((string)($_POST['name'] ?? ''));
            if (mb_strlen($name) < 2) {
                throw new RuntimeException('Nama biaya tambahan wajib diisi (mis. Website Narayana).');
            }
            $db->prepare('INSERT INTO subscription_addons (business_id, name, monthly_fee) VALUES (?, ?, ?)')->execute([$bid, mb_substr($name, 0, 120), $money($_POST['fee'] ?? 0)]);
            Auth::audit('billing.addon_add', "{$biz['name']}: {$name}");
            flash("Biaya tambahan {$name} ditambahkan ke {$biz['name']}.");
        } elseif ($action === 'addon_update') {
            $db->prepare('UPDATE subscription_addons SET name = ?, monthly_fee = ?, active = ? WHERE id = ?')
                ->execute([mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: 'Tambahan', $money($_POST['fee'] ?? 0), !empty($_POST['active']) ? 1 : 0, $aid]);
            Auth::audit('billing.addon_update', "#{$aid}");
            flash('Biaya tambahan disimpan.');
        } elseif ($action === 'addon_delete') {
            $db->prepare('DELETE FROM subscription_addons WHERE id = ?')->execute([$aid]);
            Auth::audit('billing.addon_delete', "#{$aid}");
            flash('Biaya tambahan dihapus.');
        }
    } catch (Throwable $e) {
        flash($e instanceof PDOException ? 'Tabel biaya tambahan belum dibuat — jalankan migrate di server.' : $e->getMessage(), 'err');
    }
    redirect('/billing.php' . ($bid ? '#g' . (int)(Biz::business($bid)['client_id'] ?? 0) : ''));
}

// Semua bisnis + langganan, dikelompokkan per grup
$rows = $db->query('SELECT b.id, b.name, b.type, b.status, b.client_id, c.name AS client_name, c.is_demo,
        s.id AS sub_id, s.base_fee, s.per_guest_fee, s.start_date, s.due_date_override, s.locked, s.last_sync_at
    FROM businesses b JOIN clients c ON c.id = b.client_id LEFT JOIN subscriptions s ON s.business_id = b.id
    WHERE b.status <> \'archived\' ORDER BY c.is_demo, c.name, b.name')->fetchAll();
$unpaid = [];
foreach ($db->query("SELECT * FROM subscription_charges WHERE status = 'unpaid' ORDER BY due_date")->fetchAll() as $ch) {
    $unpaid[(int)$ch['business_id']][] = $ch;
}
$manualOpen = [];
foreach ($db->query("SELECT business_id, COUNT(*) n FROM invoices WHERE kind = 'manual' AND status = 'unpaid' GROUP BY business_id")->fetchAll() as $m) {
    $manualOpen[(int)$m['business_id']] = (int)$m['n'];
}
$addons = Biz::addons();
$today = new DateTimeImmutable('today');
$groups = [];
$sum = ['monthly' => 0.0, 'unpaid' => 0.0, 'unpaid_n' => 0, 'overdue' => 0, 'soon' => 0];
foreach ($rows as $r) {
    $bid = (int)$r['id'];
    $r['addons'] = $addons[$bid] ?? [];
    $active = array_filter($r['addons'], fn ($a) => (int)$a['active'] === 1);
    $r['monthly'] = (float)$r['base_fee'] + array_sum(array_map(fn ($a) => (float)$a['monthly_fee'], $active));
    $r['unpaid_total'] = array_sum(array_map([Biz::class, 'chargeTotal'], $unpaid[$bid] ?? []));
    $r['unpaid_n'] = count($unpaid[$bid] ?? []);
    $r['manual_n'] = $manualOpen[$bid] ?? 0;
    $r['due'] = $r['sub_id'] ? Biz::nextDue($r, $unpaid[$bid][0]['due_date'] ?? null) : null;
    $r['days'] = $r['due'] ? (int)$today->diff($r['due'])->format('%r%a') : null;
    // Satu status yang mudah dibaca: [kode, label, warna pill]
    $r['state'] = match (true) {
        $r['status'] !== 'active' => ['off', Biz::STATUS[$r['status']] ?? $r['status'], ''],
        !$r['sub_id'] || (float)$r['base_fee'] <= 0 => ['nopkg', 'Belum ada paket', ''],
        (bool)$r['locked'] => ['locked', 'Terkunci', 'bad'],
        $r['unpaid_n'] > 0 && $r['days'] !== null && $r['days'] < 0 => ['overdue', 'Lewat tempo', 'bad'],
        $r['unpaid_n'] > 0 => ['unpaid', 'Belum lunas', 'warn'],
        default => ['ok', 'Lunas', 'ok'],
    };
    $cid = (int)$r['client_id'];
    $groups[$cid]['name'] = $r['client_name'];
    $groups[$cid]['demo'] = (bool)$r['is_demo'];
    $groups[$cid]['rows'][] = $r;
    if (!$r['is_demo']) {
        $sum['monthly'] += $r['status'] === 'active' ? $r['monthly'] : 0;
        $sum['unpaid'] += $r['unpaid_total'];
        $sum['unpaid_n'] += $r['unpaid_n'];
        $sum['overdue'] += $r['state'][0] === 'overdue' ? 1 : 0;
        $sum['soon'] += $r['days'] !== null && $r['days'] >= 0 && $r['days'] <= 7 ? 1 : 0;
    }
}
$showDemo = !empty($_GET['demo']);
$FILTERS = ['all' => 'Semua', 'tagih' => 'Perlu ditagih', 'nopkg' => 'Belum ada paket'];
$filter = isset($FILTERS[$_GET['f'] ?? '']) ? (string)$_GET['f'] : 'all';
$keep = fn (array $r) => match ($filter) {
    'tagih' => in_array($r['state'][0], ['unpaid', 'overdue', 'locked'], true) || $r['manual_n'] > 0,
    'nopkg' => $r['state'][0] === 'nopkg',
    default => true,
};
$dueText = function (?int $d): string {
    if ($d === null) {
        return '';
    }
    return $d < 0 ? 'lewat ' . abs($d) . ' hari' : ($d === 0 ? 'hari ini' : $d . ' hari lagi');
};

View::start('Klien Langganan', 'billing', $me);
?>
<style>
    .kl-strip { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border: 1px solid var(--line); border-radius: 12px; background: var(--card); margin-bottom: 12px; overflow: hidden; }
    .kl-strip > div { padding: 9px 14px; min-width: 0; }
    .kl-strip > div + div { border-left: 1px solid var(--line2); }
    .kl-strip small { display: block; font-size: 10.5px; color: var(--mu); text-transform: uppercase; letter-spacing: .06em; }
    .kl-strip b { font-size: 14.5px; font-variant-numeric: tabular-nums; }
    .kl-strip span { font-size: 11px; color: var(--mu); }
    .kl-filter { display: flex; gap: 6px; margin: 0 0 12px; flex-wrap: wrap; }
    .kl-filter a { padding: 4px 11px; border-radius: 99px; border: 1px solid var(--line); color: var(--ink2); font-size: 12px; }
    .kl-filter a.on { border-color: var(--brand); color: #fff; background: var(--goldbg); }
    .kl-grp { background: var(--card); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 12px; overflow: hidden; }
    .kl-gh { display: flex; align-items: center; gap: 8px; padding: 9px 14px; border-bottom: 1px solid var(--line2); background: var(--card2); }
    .kl-gh .nm { font-weight: 650; font-size: 13px; color: var(--ink); }
    .kl-gh .ct { font-size: 11.5px; color: var(--mu); }
    .kl-gh .tot { margin-left: auto; font-weight: 700; font-size: 13px; font-variant-numeric: tabular-nums; }
    .kl-gh .tot small { font-weight: 500; color: var(--mu); font-size: 11px; }
    .kl-row { display: grid; grid-template-columns: minmax(0, 2.2fr) minmax(0, 1.1fr) minmax(0, 1fr) 150px 16px; gap: 12px; align-items: center; padding: 9px 14px; color: var(--ink); }
    .kl-row + .kl-row { border-top: 1px solid var(--line2); }
    .kl-row:hover { background: rgba(255, 255, 255, .025); }
    .kl-nm { min-width: 0; }
    .kl-nm b { display: block; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kl-nm small { display: block; font-size: 11px; color: var(--mu); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kl-chip { display: inline-block; font-size: 10px; padding: 0 6px; border-radius: 5px; background: var(--card2); border: 1px solid var(--line); color: var(--ink2); margin: 2px 4px 0 0; }
    .kl-due b { display: block; font-size: 12px; font-weight: 500; }
    .kl-due small { font-size: 10.5px; color: var(--mu); }
    .kl-due small.late { color: var(--bad); }
    .kl-amt { text-align: right; font-weight: 650; font-size: 12.5px; font-variant-numeric: tabular-nums; }
    .kl-amt small { display: block; font-size: 10.5px; font-weight: 500; color: var(--warn); white-space: nowrap; }
    .kl-go { color: var(--mu); }
    .kl-go .ico { width: 14px; height: 14px; }
    .kl-add { border-top: 1px solid var(--line2); }
    .kl-add > summary { padding: 7px 14px; font-size: 11.5px; color: var(--mu); cursor: pointer; }
    .kl-add form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; padding: 3px 14px 8px; }
    .kl-add input, .kl-add select { width: auto; padding: 4px 8px; font-size: 12px; }
    @media (max-width: 760px) {
        .kl-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .kl-strip > div:nth-child(3) { border-left: 0; }
        .kl-strip > div:nth-child(n+3) { border-top: 1px solid var(--line2); }
        .kl-row { grid-template-columns: minmax(0, 1fr) auto; gap: 4px 10px; }
        .kl-row .kl-due { grid-column: 1; }
        .kl-row .kl-st { grid-column: 2; grid-row: 1; justify-self: end; }
        .kl-row .kl-amt { grid-column: 2; grid-row: 2; }
        .kl-row .kl-go { display: none; }
    }
</style>

<div class="pagehead" style="margin-bottom:10px">
    <div><h1>Klien Langganan</h1><p>Tagihan bulanan tiap sistem bisnis. Klik bisnis untuk tarif, riwayat tagihan, tagihan manual &amp; koneksi.</p></div>
    <?php if ($isAdmin): ?><a class="btn sm" href="/invoice-edit.php">+ Invoice baru</a><?php endif; ?>
</div>

<div class="kl-strip">
    <div><small>Tagihan / bulan</small><b><?= Biz::rp($sum['monthly']) ?></b></div>
    <div><small>Belum lunas</small><b style="color:<?= $sum['unpaid'] > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= Biz::rp($sum['unpaid']) ?></b> <span><?= $sum['unpaid_n'] ?> tagihan</span></div>
    <div><small>Lewat tempo</small><b style="color:<?= $sum['overdue'] ? 'var(--bad)' : 'var(--ink)' ?>"><?= $sum['overdue'] ?></b> <span>bisnis</span></div>
    <div><small>Jatuh tempo ≤ 7 hari</small><b><?= $sum['soon'] ?></b> <span>bisnis</span></div>
</div>

<div class="kl-filter">
    <?php foreach ($FILTERS as $k => $label): ?>
        <a class="<?= $filter === $k ? 'on' : '' ?>" href="/billing.php<?= $k === 'all' ? '' : '?f=' . $k ?><?= $showDemo ? ($k === 'all' ? '?' : '&') . 'demo=1' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php $shown = 0;
foreach ($groups as $cid => $g):
    if ($g['demo'] && !$showDemo) {
        continue;
    }
    $list = array_values(array_filter($g['rows'], $keep));
    if (!$list) {
        continue;
    }
    $shown++;
    $gMonthly = array_sum(array_map(fn ($r) => $r['status'] === 'active' ? $r['monthly'] : 0, $g['rows'])); ?>
    <div class="kl-grp" id="g<?= (int)$cid ?>">
        <div class="kl-gh">
            <a class="nm" href="/client.php?id=<?= (int)$cid ?>"><?= e($g['name']) ?></a>
            <?= $g['demo'] ? '<span class="pill gold">DEMO</span>' : '' ?>
            <span class="ct"><?= count($g['rows']) ?> bisnis</span>
            <span class="tot"><?= Biz::rp($gMonthly) ?> <small>/ bulan</small></span>
        </div>
        <?php foreach ($list as $r): $bid = (int)$r['id']; [, $tIcon] = Biz::type((string)$r['type']); [$sk, $sl, $sc] = $r['state']; ?>
            <a class="kl-row" href="/business.php?id=<?= $bid ?>&tab=langganan">
                <span class="kl-nm">
                    <b><?= $tIcon ?> <?= e($r['name']) ?></b>
                    <small><?= $r['sub_id'] && (float)$r['base_fee'] > 0 ? 'Paket ' . Biz::rp($r['base_fee']) . ((float)$r['per_guest_fee'] > 0 ? ' + ' . Biz::rp($r['per_guest_fee']) . '/tamu' : '') : 'Belum ada paket — klik untuk mengatur' ?></small>
                    <?php foreach ($r['addons'] as $a): ?><span class="kl-chip"<?= $a['active'] ? '' : ' style="opacity:.5"' ?>>+ <?= e($a['name']) ?> <?= Biz::rp($a['monthly_fee']) ?></span><?php endforeach; ?>
                    <?php if ($r['manual_n']): ?><span class="kl-chip" style="border-color:var(--warn);color:var(--warn)"><?= $r['manual_n'] ?> tagihan manual</span><?php endif; ?>
                </span>
                <span class="kl-due"><?php if ($r['due']): ?><b><?= e($r['due']->format('d M Y')) ?></b><small class="<?= $r['days'] < 0 && $r['unpaid_n'] ? 'late' : '' ?>"><?= e($dueText($r['days'])) ?></small><?php else: ?><small>–</small><?php endif; ?></span>
                <span class="kl-st"><span class="pill <?= $sc ?>"><?= e($sl) ?></span></span>
                <span class="kl-amt"><?= Biz::rp($r['monthly']) ?><?= $r['unpaid_total'] > 0 ? '<small>' . Biz::rp($r['unpaid_total']) . ' belum dibayar</small>' : '' ?></span>
                <span class="kl-go"><?= View::icon('chev') ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($isAdmin && !$g['demo']): ?>
            <details class="kl-add">
                <summary>Biaya tambahan bulanan (mis. website, modul)</summary>
                <?php foreach ($g['rows'] as $r): foreach ($r['addons'] as $a): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="addon_update"><input type="hidden" name="addon_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="business_id" value="<?= (int)$r['id'] ?>">
                        <span class="muted" style="font-size:11.5px;min-width:120px"><?= e($r['name']) ?></span>
                        <input name="name" value="<?= e($a['name']) ?>" style="width:170px">
                        <input name="fee" inputmode="numeric" value="<?= e(number_format((float)$a['monthly_fee'], 0, ',', '.')) ?>" style="width:100px">
                        <label style="display:flex;gap:4px;align-items:center;margin:0;font-size:11.5px"><input type="checkbox" name="active" value="1" style="width:auto" <?= $a['active'] ? 'checked' : '' ?>> aktif</label>
                        <button class="btn sm">Simpan</button>
                        <button class="btn danger sm" name="action" value="addon_delete" onclick="return confirm('Hapus biaya tambahan <?= e($a['name']) ?>?')">Hapus</button>
                    </form>
                <?php endforeach; endforeach; ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="addon_add">
                    <select name="business_id"><?php foreach ($g['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
                    <input name="name" placeholder="nama biaya tambahan" style="width:170px">
                    <input name="fee" inputmode="numeric" placeholder="Rp / bulan" style="width:100px">
                    <button class="btn sm">+ Tambah</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$shown): ?><div class="card muted" style="text-align:center"><?= $filter === 'all' ? 'Belum ada bisnis. Tambahkan lewat menu <a href="/clients.php">Bisnis &amp; Sistem</a>.' : 'Tidak ada bisnis di filter ini.' ?></div><?php endif; ?>
<?php if (array_filter($groups, fn ($g) => $g['demo'])): ?>
    <p style="margin:0;font-size:12px"><a href="?<?= $showDemo ? '' : 'demo=1' ?>"><?= $showDemo ? 'Sembunyikan grup demo' : 'Tampilkan grup demo' ?></a></p>
<?php endif; ?>
<?php View::end();
