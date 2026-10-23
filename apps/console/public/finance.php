<?php

/**
 * Keuangan ADF: uang masuk (Pakasir otomatis, website, lunas manual) & keluar per bulan, grafik setahun,
 * perkiraan saldo di Pakasir, dan catatan manual (Finance). ?m=YYYY-MM  ?edit=<id catatan>
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
$back = '/finance.php?m=' . $m;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect($back);
    }
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            Finance::deleteEntry((int)($_POST['entry_id'] ?? 0));
            Auth::audit('finance.delete', '#' . (int)($_POST['entry_id'] ?? 0));
            flash('Catatan dihapus.');
        } else {
            $eid = (int)($_POST['entry_id'] ?? 0) ?: null;
            Finance::saveEntry($_POST, $eid, (string)$me['username']);
            Auth::audit('finance.save', ($_POST['kind'] ?? '') . ' ' . ($_POST['amount'] ?? ''));
            flash('Catatan disimpan.');
            $back = '/finance.php?m=' . substr((string)($_POST['entry_date'] ?? $m . '-01'), 0, 7);
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($back);
}

$from = $m . '-01';
$to = date('Y-m-t', strtotime($from));
$rows = Finance::rows($from, $to);
$in = array_sum(array_column($rows, 'in'));
$out = array_sum(array_column($rows, 'out'));
$auto = array_sum(array_map(fn ($r) => $r['src'] !== 'manual' ? $r['in'] : 0, $rows));
$wdMonth = array_sum(array_map(fn ($r) => $r['kind'] === 'withdraw' ? (float)$r['entry']['amount'] : 0, $rows));
$year = Finance::year((int)substr($m, 0, 4));
$pk = Finance::pakasirBalance();
$maxBar = max(1, ...array_map(fn ($y) => max($y['in'], $y['out']), $year));

$edit = null;
if ($eid = (int)($_GET['edit'] ?? 0)) {
    $st = Auth::db()->prepare('SELECT * FROM finance_entries WHERE id = ?');
    $st->execute([$eid]);
    $edit = $st->fetch() ?: null;
}
$f = $edit ?? ['id' => 0, 'entry_date' => date('Y-m') === $m ? date('Y-m-d') : $from, 'kind' => 'out', 'category' => '', 'amount' => '', 'method' => '', 'note' => ''];

$monthName = fn (string $ym) => ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
$shift = fn (int $d) => date('Y-m', strtotime($from . " {$d} month"));
$SRC = ['pakasir' => ['Online', 'gold'], 'website' => ['Website', 'gold'], 'manual' => ['Manual', '']];

View::start('Keuangan', 'finance', $me);
?>
<style>
    .fin-month { display: flex; align-items: center; gap: 6px; }
    .fin-month a.btn { padding: 4px 10px; }
    .fin-month b { min-width: 120px; text-align: center; font-size: 13px; }
    .fin-chart { display: grid; grid-template-columns: repeat(12, 1fr); gap: 6px; align-items: end; height: 120px; margin-top: 6px; }
    .fin-chart a { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 3px; color: var(--mu); font-size: 10px; }
    .fin-chart .bars { display: flex; gap: 2px; align-items: flex-end; height: 92px; }
    .fin-chart .bars i { display: block; width: 9px; border-radius: 3px 3px 0 0; min-height: 2px; }
    .fin-chart a.on { color: var(--ink); font-weight: 700; }
    .fin-in { color: var(--ok); } .fin-out { color: var(--bad); }
</style>
<div class="pagehead">
    <div><h1>Keuangan</h1><p>Pembukuan ADF: pembayaran online (Mayar / Pakasir) & website tercatat otomatis, ditambah catatan manual.</p></div>
    <div class="fin-month">
        <a class="btn ghost sm" href="/finance.php?m=<?= $shift(-1) ?>" aria-label="Bulan sebelumnya">‹</a>
        <b><?= e($monthName($m)) ?></b>
        <a class="btn ghost sm" href="/finance.php?m=<?= $shift(1) ?>" aria-label="Bulan berikutnya">›</a>
        <?php if ($m !== date('Y-m')): ?><a class="btn ghost sm" href="/finance.php">Bulan ini</a><?php endif; ?>
    </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:12px">
    <div class="card" style="margin:0;padding:12px 14px"><small>Uang masuk</small><div class="stat fin-in" style="margin-top:3px"><?= Biz::rp($in) ?></div><small><?= Biz::rp($auto) ?> otomatis · <?= Biz::rp($in - $auto) ?> manual</small></div>
    <div class="card" style="margin:0;padding:12px 14px"><small>Pengeluaran</small><div class="stat fin-out" style="margin-top:3px"><?= Biz::rp($out) ?></div></div>
    <div class="card" style="margin:0;padding:12px 14px"><small>Bersih bulan ini</small><div class="stat" style="margin-top:3px;color:<?= $in - $out >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= Biz::rp($in - $out) ?></div></div>
    <div class="card" style="margin:0;padding:12px 14px"><small>Saldo di payment gateway (perkiraan)</small><div class="stat" style="margin-top:3px"><?= Biz::rp($pk['balance']) ?></div><small>masuk <?= Biz::rp($pk['in']) ?> − ditarik <?= Biz::rp($pk['withdrawn']) ?></small></div>
</div>

<div class="card">
    <h2 style="display:flex;justify-content:space-between"><span>Tahun <?= e(substr($m, 0, 4)) ?></span><span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0"><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--ok)"></i> masuk &nbsp; <i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--bad)"></i> keluar</span></h2>
    <div class="fin-chart">
        <?php foreach ($year as $i => $y): $ym = substr($m, 0, 4) . '-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
            <a href="/finance.php?m=<?= $ym ?>" class="<?= $ym === $m ? 'on' : '' ?>" title="<?= e($monthName($ym)) ?>: masuk <?= e(Biz::rp($y['in'])) ?>, keluar <?= e(Biz::rp($y['out'])) ?>">
                <span class="bars"><i style="height:<?= round($y['in'] / $maxBar * 92) ?>px;background:var(--ok)"></i><i style="height:<?= round($y['out'] / $maxBar * 92) ?>px;background:var(--bad)"></i></span>
                <?= ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$i] ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(280px,1fr);align-items:start">
    <div class="card" style="padding:0;overflow-x:auto;margin:0">
        <table class="stack">
            <thead><tr><th>Tanggal</th><th>Keterangan</th><th style="text-align:right">Masuk</th><th style="text-align:right">Keluar</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): [$sl, $sc] = $SRC[$r['src']]; ?>
                    <tr>
                        <td style="white-space:nowrap"><?= e(date('d M', strtotime($r['date']))) ?></td>
                        <td><?= e($r['title']) ?> <span class="pill <?= $sc ?>"><?= $r['kind'] === 'withdraw' ? 'Tarik dana' : $sl ?></span><?= $r['who'] !== '' ? '<br><small class="muted">' . e($r['who']) . '</small>' : '' ?></td>
                        <td style="text-align:right;white-space:nowrap" class="fin-in"><?= $r['in'] ? Biz::rp($r['in']) : '' ?></td>
                        <td style="text-align:right;white-space:nowrap" class="fin-out"><?= $r['out'] ? Biz::rp($r['out']) : ($r['kind'] === 'withdraw' ? '<small class="muted">' . Biz::rp($r['entry']['amount']) . ' ke rekening</small>' : '') ?></td>
                        <td style="white-space:nowrap;text-align:right">
                            <?php if ($r['entry']): ?>
                                <a class="btn ghost sm" href="/finance.php?m=<?= $m ?>&edit=<?= (int)$r['entry']['id'] ?>">Ubah</a>
                                <form method="post" style="display:inline" onsubmit="return confirm('Hapus catatan ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="entry_id" value="<?= (int)$r['entry']['id'] ?>"><button class="btn ghost sm">Hapus</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="5" class="muted" style="text-align:center;padding:18px">Belum ada transaksi di bulan ini.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <form class="card" method="post" style="margin:0" id="catat">
        <?= csrf_field() ?><input type="hidden" name="entry_id" value="<?= (int)$f['id'] ?>">
        <h2><?= $edit ? 'Ubah catatan' : '+ Catat manual' ?></h2>
        <div class="field"><label>Jenis</label><select name="kind" id="finKind"><?php foreach (Finance::KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="row2">
            <div class="field"><label>Tanggal</label><input type="date" name="entry_date" value="<?= e((string)$f['entry_date']) ?>"></div>
            <div class="field"><label>Jumlah (Rp)</label><input name="amount" inputmode="numeric" value="<?= $f['amount'] !== '' ? e(number_format((float)$f['amount'], 0, ',', '.')) : '' ?>" placeholder="mis. 150.000" required></div>
        </div>
        <div class="field"><label>Kategori</label><input name="category" list="finCats" value="<?= e((string)$f['category']) ?>" placeholder="pilih atau ketik"></div>
        <datalist id="finCats"></datalist>
        <div class="field"><label>Metode</label><input name="method" value="<?= e((string)$f['method']) ?>" placeholder="tunai, transfer BCA, …"></div>
        <div class="field"><label>Catatan</label><input name="note" value="<?= e((string)$f['note']) ?>" maxlength="300"></div>
        <button class="btn sm">Simpan</button>
        <?php if ($edit): ?><a class="btn ghost sm" href="/finance.php?m=<?= $m ?>">Batal</a><?php endif; ?>
        <p class="muted" style="margin:10px 0 0;font-size:11.5px;line-height:1.5">Pembayaran online (Mayar / Pakasir) & website <b>tidak perlu dicatat</b> — sudah otomatis. Catat di sini: uang masuk tunai/transfer, pengeluaran, dan <b>Tarik dana</b> (saat saldo Mayar / Pakasir dipindah ke rekening — tidak dihitung pendapatan).</p>
    </form>
</div>
<script>
    // Daftar kategori mengikuti jenis
    (function () {
        var cats = <?= json_encode(Finance::CATEGORIES, JSON_UNESCAPED_UNICODE) ?>;
        var kind = document.getElementById('finKind'), dl = document.getElementById('finCats');
        function fill() { dl.innerHTML = (cats[kind.value] || []).map(function (c) { return '<option value="' + c.replace(/"/g, '&quot;') + '">'; }).join(''); }
        kind.addEventListener('change', fill);
        fill();
    })();
</script>
<?php View::end();
