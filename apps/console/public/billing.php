<?php

/** Tagihan: langganan per bisnis (dikelompokkan per grup) + biaya tambahan seperti website. */

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
                throw new RuntimeException('Nama tambahan wajib diisi (mis. Website Narayana).');
            }
            $db->prepare('INSERT INTO subscription_addons (business_id, name, monthly_fee) VALUES (?, ?, ?)')->execute([$bid, mb_substr($name, 0, 120), $money($_POST['fee'] ?? 0)]);
            Auth::audit('billing.addon_add', "{$biz['name']}: {$name}");
            flash("Tambahan {$name} ditambahkan ke {$biz['name']}.");
        } elseif ($action === 'addon_update') {
            $db->prepare('UPDATE subscription_addons SET name = ?, monthly_fee = ?, active = ? WHERE id = ?')
                ->execute([mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: 'Tambahan', $money($_POST['fee'] ?? 0), !empty($_POST['active']) ? 1 : 0, $aid]);
            Auth::audit('billing.addon_update', "#{$aid}");
            flash('Tambahan disimpan.');
        } elseif ($action === 'addon_delete') {
            $db->prepare('DELETE FROM subscription_addons WHERE id = ?')->execute([$aid]);
            Auth::audit('billing.addon_delete', "#{$aid}");
            flash('Tambahan dihapus.');
        } elseif ($action === 'base_fee') {
            $biz = Biz::business($bid) ?? throw new RuntimeException('Bisnis tidak ditemukan.');
            if (!Biz::subscription($bid)) {
                // Langganan dibuat otomatis saat biaya pertama diisi (kunci sinkron dibuat seperti di halaman bisnis)
                $key = $biz['slug'];
                for ($i = 2; $db->query('SELECT 1 FROM subscriptions WHERE client_key = ' . $db->quote($key))->fetchColumn(); $i++) {
                    $key = $biz['slug'] . '-' . $i;
                }
                $db->prepare('INSERT INTO subscriptions (business_id, client_key, client_token, display_name, start_date) VALUES (?, ?, ?, ?, CURDATE())')
                    ->execute([$bid, $key, bin2hex(random_bytes(24)), $biz['name']]);
                Auth::audit('subscription.create', $biz['name']);
            }
            $db->prepare('UPDATE subscriptions SET base_fee = ? WHERE business_id = ?')->execute([$money($_POST['base_fee'] ?? 0), $bid]);
            Auth::audit('subscription.update', "{$biz['name']}: biaya paket");
            flash("Biaya paket {$biz['name']} disimpan.");
        }
    } catch (Throwable $e) {
        flash($e instanceof PDOException ? 'Tabel tagihan tambahan belum dibuat — jalankan migrate di server.' : $e->getMessage(), 'err');
    }
    redirect('/billing.php' . ($bid ? '#b' . $bid : ''));
}

// Semua bisnis aktif + langganan, dikelompokkan per grup
$rows = $db->query('SELECT b.id, b.name, b.type, b.status, b.client_id, c.name AS client_name, c.is_demo,
        s.id AS sub_id, s.base_fee, s.per_guest_fee, s.start_date, s.due_date_override, s.locked
    FROM businesses b JOIN clients c ON c.id = b.client_id LEFT JOIN subscriptions s ON s.business_id = b.id
    WHERE b.status <> \'archived\' ORDER BY c.is_demo, c.name, b.name')->fetchAll();
$unpaid = [];
foreach ($db->query('SELECT * FROM subscription_charges WHERE status = \'unpaid\' ORDER BY due_date')->fetchAll() as $ch) {
    $unpaid[(int)$ch['business_id']][] = $ch;
}
$addons = Biz::addons();
$groups = [];
$sum = ['monthly' => 0.0, 'unpaid' => 0.0, 'unpaid_n' => 0, 'soon' => 0];
$today = new DateTimeImmutable('today');
foreach ($rows as $r) {
    $bid = (int)$r['id'];
    $ad = array_filter($addons[$bid] ?? [], fn ($a) => (int)$a['active'] === 1);
    $r['addons'] = $addons[$bid] ?? [];
    $r['monthly'] = (float)$r['base_fee'] + array_sum(array_map(fn ($a) => (float)$a['monthly_fee'], $ad));
    $r['unpaid_total'] = array_sum(array_map([Biz::class, 'chargeTotal'], $unpaid[$bid] ?? []));
    $r['unpaid_n'] = count($unpaid[$bid] ?? []);
    $r['due'] = $r['sub_id'] ? Biz::nextDue($r, $unpaid[$bid][0]['due_date'] ?? null) : null;
    $groups[(int)$r['client_id']]['name'] = $r['client_name'];
    $groups[(int)$r['client_id']]['demo'] = (bool)$r['is_demo'];
    $groups[(int)$r['client_id']]['rows'][] = $r;
    if (!$r['is_demo']) {
        $sum['monthly'] += $r['status'] === 'active' ? $r['monthly'] : 0;
        $sum['unpaid'] += $r['unpaid_total'];
        $sum['unpaid_n'] += $r['unpaid_n'];
        $sum['soon'] += $r['due'] && $r['due'] <= $today->modify('+7 days') ? 1 : 0;
    }
}

$showDemo = !empty($_GET['demo']);
View::start('Tagihan', 'billing', $me);
?>
<style>
    /* Tagihan ringkas: satu tabel, grup = baris judul tipis, isian muncul saat diklik */
    .kpis { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .kpis div { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 8px 14px; min-width: 150px; }
    .kpis small { display: block; color: var(--mu); font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; }
    .kpis b { font-size: 15px; }
    table.bill td { padding: 7px 10px; }
    table.bill tr.grp td { background: #0f131b; border-top: 1px solid var(--line); padding: 8px 10px; }
    table.bill tr.grp:first-child td { border-top: 0; }
    table.bill tr.add td { padding: 3px 10px 3px 34px; font-size: 12px; color: var(--ink2); border-top: 0; }
    .edit summary { list-style: none; cursor: pointer; display: inline-flex; gap: 6px; align-items: center; }
    .edit summary::before { display: none; }
    .edit summary .pen { color: var(--mu); font-size: 11px; }
    .edit[open] summary .pen { color: var(--brand); }
    .edit form { display: flex; gap: 5px; margin-top: 5px; flex-wrap: wrap; }
    .edit input, .edit select { width: auto; padding: 4px 8px; font-size: 12px; }
</style>

<div class="pagehead" style="margin-bottom:10px">
    <div><h1>Tagihan</h1><p>Biaya langganan tiap bisnis per bulan, dikelompokkan per grup.</p></div>
    <?php if ($isAdmin): ?><a class="btn" href="/invoice-edit.php">+ Invoice baru</a><?php endif; ?>
</div>
<nav class="tabs" style="margin-top:0"><a class="on" href="/billing.php">Langganan</a><a href="/invoices.php">Invoice</a></nav>

<div class="kpis">
    <div><small>Pendapatan / bulan</small><b><?= Biz::rp($sum['monthly']) ?></b></div>
    <div><small>Belum lunas</small><b style="color:<?= $sum['unpaid'] > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= Biz::rp($sum['unpaid']) ?></b> <span class="muted" style="font-size:11px"><?= $sum['unpaid_n'] ?> tagihan</span></div>
    <div><small>Jatuh tempo ≤ 7 hari</small><b><?= $sum['soon'] ?></b> <span class="muted" style="font-size:11px">bisnis</span></div>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table class="bill">
            <thead><tr><th style="padding-top:10px">Bisnis</th><th style="padding-top:10px">Paket / bulan</th><th style="padding-top:10px">Jatuh tempo</th><th style="padding-top:10px">Status</th><th style="padding-top:10px;text-align:right">Total / bulan</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $cid => $g): if ($g['demo'] && !$showDemo) continue;
                $gMonthly = array_sum(array_map(fn ($r) => $r['status'] === 'active' ? $r['monthly'] : 0, $g['rows']));
                $gUnpaid = array_sum(array_column($g['rows'], 'unpaid_total')); ?>
                <tr class="grp">
                    <td colspan="3">
                        <a href="/client.php?id=<?= (int)$cid ?>" style="color:var(--ink);font-weight:650"><?= e($g['name']) ?></a>
                        <?= $g['demo'] ? '<span class="pill gold">DEMO</span>' : '' ?>
                        <span class="muted" style="font-size:11.5px">· <?= count($g['rows']) ?> bisnis</span>
                        <?php if ($isAdmin && !$g['demo']): ?>
                            <a class="btn ghost sm" style="margin-left:6px" href="/invoice-edit.php?client=<?= (int)$cid ?>&period=<?= date('Y-m') ?>">Invoice <?= e(Invoice::date(date('Y-m-01'), false)) ?></a>
                        <?php endif; ?>
                    </td>
                    <td><?= $gUnpaid > 0 ? '<span class="pill warn">' . Biz::rp($gUnpaid) . ' belum lunas</span>' : '' ?></td>
                    <td style="text-align:right;font-weight:650"><?= Biz::rp($gMonthly) ?></td>
                </tr>
                <?php foreach ($g['rows'] as $r): $bid = (int)$r['id']; [$tLabel, $tIcon] = Biz::type((string)$r['type']); ?>
                    <tr id="b<?= $bid ?>">
                        <td style="padding-left:18px"><a href="/business.php?id=<?= $bid ?>&tab=langganan" style="color:var(--ink)"><?= $tIcon ?> <?= e($r['name']) ?></a></td>
                        <td>
                            <?php if ($isAdmin): ?>
                                <details class="edit"><summary><?= $r['sub_id'] ? Biz::rp($r['base_fee']) : '<span class="muted">atur paket</span>' ?> <span class="pen">✎</span></summary>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="base_fee"><input type="hidden" name="business_id" value="<?= $bid ?>">
                                        <input name="base_fee" inputmode="numeric" value="<?= $r['sub_id'] ? e(number_format((float)$r['base_fee'], 0, ',', '.')) : '' ?>" placeholder="Rp / bulan" style="width:110px">
                                        <button class="btn sm">Simpan</button></form>
                                </details>
                            <?php else: ?><?= $r['sub_id'] ? Biz::rp($r['base_fee']) : '-' ?><?php endif; ?>
                            <?= (float)$r['per_guest_fee'] > 0 ? '<div class="muted" style="font-size:11px">+ ' . Biz::rp($r['per_guest_fee']) . ' / tamu</div>' : '' ?>
                        </td>
                        <td class="muted"><?= $r['due'] ? e($r['due']->format('d M Y')) : '–' ?></td>
                        <td>
                            <?php if ($r['status'] !== 'active'): ?><span class="pill"><?= e(Biz::STATUS[$r['status']] ?? $r['status']) ?></span>
                            <?php elseif (!$r['sub_id']): ?><span class="pill">belum ada paket</span>
                            <?php elseif ($r['locked']): ?><span class="pill bad">terkunci</span>
                            <?php elseif ($r['unpaid_n']): ?><span class="pill warn"><?= Biz::rp($r['unpaid_total']) ?> belum lunas</span>
                            <?php else: ?><span class="pill ok">lunas</span><?php endif; ?>
                        </td>
                        <td style="text-align:right"><?= Biz::rp($r['monthly']) ?></td>
                    </tr>
                    <?php foreach ($r['addons'] as $a): ?>
                        <tr class="add">
                            <td colspan="4">
                                <?php if ($isAdmin): ?>
                                    <details class="edit"><summary>└ <?= e($a['name']) ?> <?= $a['active'] ? '' : '<span class="pill">nonaktif</span>' ?> <span class="pen">✎</span></summary>
                                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="addon_update"><input type="hidden" name="addon_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="business_id" value="<?= $bid ?>">
                                            <input name="name" value="<?= e($a['name']) ?>" style="width:170px">
                                            <input name="fee" inputmode="numeric" value="<?= e(number_format((float)$a['monthly_fee'], 0, ',', '.')) ?>" style="width:100px">
                                            <label style="display:flex;gap:4px;align-items:center;margin:0;font-size:11.5px"><input type="checkbox" name="active" value="1" style="width:auto" <?= $a['active'] ? 'checked' : '' ?>> aktif</label>
                                            <button class="btn sm">Simpan</button>
                                            <button class="btn danger sm" name="action" value="addon_delete" onclick="return confirm('Hapus tambahan <?= e($a['name']) ?>?')">Hapus</button>
                                        </form>
                                    </details>
                                <?php else: ?>└ <?= e($a['name']) ?><?php endif; ?>
                            </td>
                            <td style="text-align:right"><?= Biz::rp($a['monthly_fee']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($isAdmin && !$g['demo']): ?>
                    <tr class="add">
                        <td colspan="5">
                            <details class="edit"><summary><span class="pen">＋</span> biaya tambahan (mis. website, modul)</summary>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="addon_add">
                                    <select name="business_id"><?php foreach ($g['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
                                    <input name="name" placeholder="nama tambahan" style="width:170px">
                                    <input name="fee" inputmode="numeric" placeholder="Rp / bulan" style="width:100px">
                                    <button class="btn sm">Tambah</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$groups): ?><tr><td colspan="5" class="muted">Belum ada bisnis. Tambahkan lewat menu <a href="/clients.php">Bisnis</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if (array_filter($groups, fn ($g) => $g['demo'])): ?>
    <p style="margin:0;font-size:12px"><a href="?<?= $showDemo ? '' : 'demo=1' ?>"><?= $showDemo ? 'Sembunyikan grup demo' : 'Tampilkan grup demo' ?></a></p>
<?php endif; ?>
<?php View::end();
