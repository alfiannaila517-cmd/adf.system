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

View::start('Tagihan', 'billing', $me);
?>
<div class="pagehead">
    <div><h1>Tagihan</h1><p>Langganan setiap bisnis, ditagih per grup. Biaya tambahan (mis. website) menempel di bisnisnya.</p></div>
</div>

<div class="row2" style="margin-bottom:14px">
    <div class="card" style="margin:0"><h2>Pendapatan per bulan</h2><div class="stat"><?= Biz::rp($sum['monthly']) ?></div><p class="muted" style="margin:4px 0 0;font-size:12.5px">paket + tambahan, bisnis aktif</p></div>
    <div class="card" style="margin:0"><h2>Belum lunas</h2><div class="stat" style="color:<?= $sum['unpaid'] > 0 ? 'var(--warn)' : 'var(--ok)' ?>"><?= Biz::rp($sum['unpaid']) ?></div><p class="muted" style="margin:4px 0 0;font-size:12.5px"><?= $sum['unpaid_n'] ?> tagihan</p></div>
    <div class="card" style="margin:0"><h2>Jatuh tempo 7 hari</h2><div class="stat"><?= $sum['soon'] ?> <small>bisnis</small></div><p class="muted" style="margin:4px 0 0;font-size:12.5px">termasuk yang sudah lewat</p></div>
</div>

<?php foreach ($groups as $cid => $g): $gMonthly = array_sum(array_map(fn ($r) => $r['status'] === 'active' ? $r['monthly'] : 0, $g['rows'])); $gUnpaid = array_sum(array_column($g['rows'], 'unpaid_total')); ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <div><b style="font-size:16px"><?= e($g['name']) ?></b> <?= $g['demo'] ? '<span class="pill gold">DEMO</span>' : '' ?>
                <span class="muted" style="font-size:12.5px">· <?= count($g['rows']) ?> bisnis</span></div>
            <a class="btn ghost sm" href="/client.php?id=<?= (int)$cid ?>">Buka grup</a>
        </div>
        <table class="stack">
            <thead><tr><th>Bisnis</th><th>Paket / bulan</th><th>Jatuh tempo</th><th>Status</th><th style="text-align:right">Total / bulan</th></tr></thead>
            <tbody>
                <?php foreach ($g['rows'] as $r): $bid = (int)$r['id']; [$tLabel, $tIcon] = Biz::type((string)$r['type']); ?>
                    <tr id="b<?= $bid ?>">
                        <td><a href="/business.php?id=<?= $bid ?>&tab=langganan" style="color:var(--ink)"><b><?= $tIcon ?> <?= e($r['name']) ?></b></a><br><span class="muted" style="font-size:12px"><?= e($tLabel) ?></span></td>
                        <td>
                            <?php if ($isAdmin): ?>
                                <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="base_fee"><input type="hidden" name="business_id" value="<?= $bid ?>">
                                    <input class="sm" name="base_fee" inputmode="numeric" value="<?= $r['sub_id'] ? e(number_format((float)$r['base_fee'], 0, ',', '.')) : '' ?>" placeholder="Rp" style="width:120px">
                                    <button class="btn ghost sm" title="Simpan biaya paket">Simpan</button></form>
                            <?php else: ?><?= $r['sub_id'] ? Biz::rp($r['base_fee']) : '-' ?><?php endif; ?>
                            <?= (float)$r['per_guest_fee'] > 0 ? '<span class="muted" style="font-size:11.5px">+ ' . Biz::rp($r['per_guest_fee']) . ' / tamu</span>' : '' ?>
                        </td>
                        <td><?= $r['due'] ? e($r['due']->format('d M Y')) : '<span class="muted">-</span>' ?></td>
                        <td>
                            <?php if ($r['status'] !== 'active'): ?><span class="pill"><?= e(Biz::STATUS[$r['status']] ?? $r['status']) ?></span>
                            <?php elseif (!$r['sub_id']): ?><span class="pill">belum ada paket</span>
                            <?php elseif ($r['locked']): ?><span class="pill bad">terkunci</span>
                            <?php elseif ($r['unpaid_n']): ?><span class="pill warn"><?= Biz::rp($r['unpaid_total']) ?> belum lunas</span>
                            <?php else: ?><span class="pill ok">lunas</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:600"><?= Biz::rp($r['monthly']) ?></td>
                    </tr>
                    <?php foreach ($r['addons'] as $a): ?>
                        <tr>
                            <td colspan="4" style="padding-left:34px">
                                <?php if ($isAdmin): ?>
                                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="addon_update"><input type="hidden" name="addon_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="business_id" value="<?= $bid ?>">
                                        <span class="muted">└ tambahan</span>
                                        <input class="sm" name="name" value="<?= e($a['name']) ?>" style="width:180px">
                                        <input class="sm" name="fee" inputmode="numeric" value="<?= e(number_format((float)$a['monthly_fee'], 0, ',', '.')) ?>" style="width:110px">
                                        <label style="display:flex;gap:5px;align-items:center;margin:0;font-size:12px"><input type="checkbox" name="active" value="1" style="width:auto" <?= $a['active'] ? 'checked' : '' ?>> aktif</label>
                                        <button class="btn ghost sm">Simpan</button>
                                        <button class="btn danger sm" name="action" value="addon_delete" onclick="return confirm('Hapus tambahan <?= e($a['name']) ?>?')">Hapus</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">└</span> <?= e($a['name']) ?> <?= $a['active'] ? '' : '<span class="pill">nonaktif</span>' ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right" class="muted"><?= Biz::rp($a['monthly_fee']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);margin-top:4px;padding-top:12px">
            <?php if ($isAdmin): ?>
                <details>
                    <summary style="cursor:pointer;font-size:13px;font-weight:600">Tambah biaya tambahan</summary>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="addon_add">
                        <select class="sm" name="business_id"><?php foreach ($g['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
                        <input class="sm" name="name" placeholder="mis. Website Narayana" style="width:190px">
                        <input class="sm" name="fee" inputmode="numeric" placeholder="Rp / bulan" style="width:120px">
                        <button class="btn sm">Tambah</button>
                    </form>
                </details>
            <?php else: ?><span></span><?php endif; ?>
            <div style="text-align:right"><span class="muted" style="font-size:12.5px">Total grup per bulan</span><div style="font-size:18px;font-weight:650"><?= Biz::rp($gMonthly) ?></div>
                <?= $gUnpaid > 0 ? '<span class="pill warn">' . Biz::rp($gUnpaid) . ' belum lunas</span>' : '' ?></div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="soonbox">Belum ada bisnis. Tambahkan lewat menu <a href="/clients.php">Bisnis</a>.</div><?php endif; ?>
<?php View::end();
