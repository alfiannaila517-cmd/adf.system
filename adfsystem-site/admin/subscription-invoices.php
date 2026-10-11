<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
require_once __DIR__ . '/../includes/subscription-ledger-store.php';
adf_admin_require_role('admin');

$clientKey = trim($_GET['client'] ?? $_POST['client_key'] ?? '');
$client = $clientKey !== '' ? adf_subscription_client_find($clientKey) : null;
$self = 'subscription-invoices.php?client=' . urlencode($clientKey);

if ($client && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $_SESSION['adf_ledger_msg'] = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $num = static fn(string $k): float => (float) str_replace(['.', ','], ['', '.'], (string) ($_POST[$k] ?? '0'));
        if (in_array($action, ['cancel', 'paid', 'auto', 'edit'], true)) {
            $msg = adf_ledger_admin_action((string) ($_POST['id'] ?? ''), $action, [
                'base_fee' => $num('base_fee'),
                'guest_count' => (int) ($_POST['guest_count'] ?? 0),
                'adjustment' => (float) str_replace(',', '.', (string) ($_POST['adjustment'] ?? '0')),
                'note' => $_POST['note'] ?? '',
                'due_date' => $_POST['due_date'] ?? '',
            ]);
            $_SESSION['adf_ledger_msg'] = $msg;
        } elseif ($action === 'recompute') {
            adf_ledger_mutate(static function (array &$data) use ($client): void {
                adf_ledger_recompute_all($data, $client);
            });
            $_SESSION['adf_ledger_msg'] = 'Tagihan dihitung ulang dari pax terakhir yang dilaporkan klien.';
        } elseif ($action === 'from_period' && preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['from_period'] ?? ''))) {
            adf_ledger_mutate(static function (array &$data) use ($clientKey): void {
                $data['meta'][$clientKey]['from_period'] = (string) $_POST['from_period'];
            });
            $_SESSION['adf_ledger_msg'] = 'Periode awal hitung otomatis disimpan.';
        }
    }
    header('Location: ' . $self);
    exit;
}

$msg = (string) ($_SESSION['adf_ledger_msg'] ?? '');
unset($_SESSION['adf_ledger_msg']);

$data = adf_ledger_load();
$invoices = $client ? adf_ledger_for_client($data, $clientKey) : [];
$usage = $client ? (array) ($data['usage'][$clientKey] ?? []) : [];
krsort($usage);
$meta = $data['meta'][$clientKey] ?? [];
$csrf = adf_admin_csrf_token();
$rp = static fn(float $n): string => 'Rp ' . number_format($n, 0, ',', '.');

$adminPageTitle = 'Tagihan Klien';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide si-page">
<?php if (!$client): ?>
    <div class="admin-alert admin-alert-error">Pilih klien dari halaman <a href="subscription-clients.php">Klien Langganan</a>.</div>
<?php else:
    $curMonth = date('Y-m');
    $paxNow = (int) ($usage[$curMonth]['guests'] ?? 0);
    $hidden = static function (string $action, string $id = '') use ($csrf, $clientKey): string {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
            . '<input type="hidden" name="client_key" value="' . htmlspecialchars($clientKey) . '">'
            . '<input type="hidden" name="action" value="' . $action . '">'
            . ($id !== '' ? '<input type="hidden" name="id" value="' . htmlspecialchars($id) . '">' : '');
    };
?>
    <div class="si-top">
        <div>
            <a class="si-back" href="subscription-clients.php">&larr; Klien Langganan</a>
            <h1><?php echo htmlspecialchars($client['client_name'] ?? $clientKey); ?></h1>
            <p>Dihitung otomatis dari booking di kalender klien. Tarif diubah di <a href="subscription-clients.php?edit=<?php echo urlencode($clientKey); ?>">Edit Klien</a>.</p>
        </div>
        <div class="si-actions">
            <form method="post"><?php echo $hidden('recompute'); ?><button class="si-btn" type="submit">Hitung Ulang</button></form>
            <a class="si-btn si-primary" href="subscription-manual-invoice.php?client=<?php echo urlencode($clientKey); ?>">+ Tagihan Manual</a>
        </div>
    </div>

    <?php if ($msg): ?><div class="si-flash"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="si-stats">
        <div class="si-stat"><span>Biaya bulanan</span><strong><?php echo $rp((float) ($client['base_fee'] ?? 0)); ?></strong></div>
        <div class="si-stat"><span>Biaya per pax</span><strong><?php echo $rp((float) ($client['per_guest_fee'] ?? 0)); ?></strong></div>
        <div class="si-stat"><span>Pax bulan ini</span><strong><?php echo $paxNow; ?></strong></div>
        <div class="si-stat"><span>Sinkron terakhir</span><strong><?php echo !empty($meta['last_sync']) ? htmlspecialchars(date('d M H:i', strtotime($meta['last_sync']))) : '-'; ?></strong></div>
    </div>

    <div class="si-grid">
        <aside class="si-card">
            <h2>Pax dilaporkan klien</h2>
            <table class="si-table">
                <thead><tr><th>Periode</th><th class="si-r">Pax</th><th class="si-r">Dilaporkan</th></tr></thead>
                <tbody>
                <?php foreach ($usage as $p => $u): ?>
                    <tr><td><?php echo htmlspecialchars((string) $p); ?></td><td class="si-r"><strong><?php echo (int) ($u['guests'] ?? 0); ?></strong></td><td class="si-r si-dim"><?php echo !empty($u['at']) ? htmlspecialchars(date('d M H:i', strtotime($u['at']))) : '-'; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$usage): ?><tr><td colspan="3" class="si-empty">Klien belum melaporkan data.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <form method="post" class="si-from">
                <?php echo $hidden('from_period'); ?>
                <label>Hitung otomatis mulai periode</label>
                <div><input type="month" name="from_period" value="<?php echo htmlspecialchars((string) ($meta['from_period'] ?? date('Y-m'))); ?>"><button class="si-btn" type="submit">Simpan</button></div>
            </form>
        </aside>

        <section class="si-card">
            <h2>Tagihan</h2>
            <div class="si-list">
            <?php foreach ($invoices as $inv):
                $st = (string) $inv['status'];
                $total = adf_ledger_total($inv);
                $stLabel = ['unpaid' => 'Belum dibayar', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'][$st] ?? $st;
                $payload = [
                    'id' => $inv['id'], 'title' => $inv['period'] . ($inv['kind'] === 'topup' ? ' · susulan' : ''),
                    'base_fee' => (int) $inv['base_fee'], 'guest_count' => (int) $inv['guest_count'],
                    'fee' => (float) $inv['per_guest_fee'], 'adjustment' => (float) ($inv['adjustment'] ?? 0),
                    'due_date' => (string) ($inv['due_date'] ?? ''), 'note' => (string) ($inv['note'] ?? ''),
                ];
            ?>
                <article class="si-inv si-inv-<?php echo $st; ?>">
                    <div class="si-inv-main">
                        <div class="si-inv-head">
                            <strong><?php echo htmlspecialchars($inv['period']); ?></strong>
                            <?php if ($inv['kind'] === 'topup'): ?><span class="si-tag">susulan</span><?php endif; ?>
                            <span class="si-badge si-b-<?php echo $st; ?>"><?php echo $stLabel; ?></span>
                            <?php if (!empty($inv['edited'])): ?><span class="si-tag">diedit manual</span><?php endif; ?>
                            <?php if (!empty($inv['locked']) && $st === 'unpaid'): ?><span class="si-tag">nominal terkunci</span><?php endif; ?>
                        </div>
                        <ul class="si-lines">
                            <?php if ((float) $inv['base_fee'] > 0): ?><li><span>Biaya bulanan</span><b><?php echo $rp((float) $inv['base_fee']); ?></b></li><?php endif; ?>
                            <?php if ((int) $inv['guest_count'] > 0): ?><li><span><?php echo (int) $inv['guest_count']; ?> pax × <?php echo $rp((float) $inv['per_guest_fee']); ?></span><b><?php echo $rp((int) $inv['guest_count'] * (float) $inv['per_guest_fee']); ?></b></li><?php endif; ?>
                            <?php if ((float) ($inv['adjustment'] ?? 0) != 0.0): ?><li><span>Penyesuaian</span><b><?php echo ((float) $inv['adjustment'] < 0 ? '−' : '') . $rp(abs((float) $inv['adjustment'])); ?></b></li><?php endif; ?>
                        </ul>
                        <?php if (!empty($inv['note'])): ?><div class="si-note"><?php echo htmlspecialchars($inv['note']); ?></div><?php endif; ?>
                    </div>
                    <div class="si-inv-side">
                        <div class="si-total"><?php echo $rp($total); ?></div>
                        <div class="si-dim"><?php
                            if ($st === 'paid' && !empty($inv['paid_at'])) {
                                echo 'Dibayar ' . htmlspecialchars(date('d M Y', strtotime($inv['paid_at'])));
                            } elseif (!empty($inv['due_date'])) {
                                echo 'Jatuh tempo ' . htmlspecialchars(date('d M Y', strtotime($inv['due_date'])));
                            }
                        ?></div>
                        <?php if ($st === 'unpaid'): ?>
                        <div class="si-row-actions">
                            <button type="button" class="si-btn" data-edit="<?php echo htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>">Edit</button>
                            <?php if (!empty($inv['edited'])): ?><form method="post"><?php echo $hidden('auto', $inv['id']); ?><button class="si-btn" type="submit" title="Hitung otomatis lagi">Otomatis</button></form><?php endif; ?>
                            <form method="post" data-confirm="Tandai invoice ini LUNAS (dibayar di luar Pakasir)?"><?php echo $hidden('paid', $inv['id']); ?><button class="si-btn si-green" type="submit">Lunas</button></form>
                            <form method="post" data-confirm="Batalkan invoice ini? Pax dan biaya di dalamnya dianggap dibebaskan dan tidak ditagih ulang."><?php echo $hidden('cancel', $inv['id']); ?><button class="si-btn si-red" type="submit">Batalkan</button></form>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$invoices): ?><div class="si-empty">Belum ada tagihan. Muncul otomatis setelah sistem klien sinkron.</div><?php endif; ?>
            </div>
            <p class="si-foot">Membatalkan = membebaskan pax/biaya itu (tidak dibuat ulang). Invoice yang diedit tidak ikut berubah saat pax bertambah; pax baru ditagih di invoice susulan.</p>
        </section>
    </div>

    <!-- Dialog edit: di luar daftar supaya tidak terpotong -->
    <dialog id="siModal" class="si-modal">
        <form method="post" id="siEditForm">
            <?php echo $hidden('edit'); ?>
            <input type="hidden" name="id" id="siId">
            <header><h3>Edit tagihan <span id="siTitle"></span></h3><button type="button" class="si-x" data-close aria-label="Tutup">&times;</button></header>
            <div class="si-fields">
                <label>Biaya bulanan (Rp)<input type="text" name="base_fee" id="siBase" inputmode="numeric"></label>
                <label>Jumlah pax<input type="text" name="guest_count" id="siGuests" inputmode="numeric"></label>
                <label>Penyesuaian (± Rp)<input type="text" name="adjustment" id="siAdj" inputmode="numeric" placeholder="-50000 untuk diskon"></label>
                <label>Jatuh tempo<input type="date" name="due_date" id="siDue"></label>
                <label class="si-full">Catatan<input type="text" name="note" id="siNote" maxlength="200" placeholder="Opsional, tampil di sistem klien"></label>
            </div>
            <div class="si-preview">Total: <strong id="siTotal">Rp 0</strong></div>
            <footer><button type="button" class="si-btn" data-close>Batal</button><button type="submit" class="si-btn si-primary">Simpan</button></footer>
        </form>
    </dialog>
<?php endif; ?>
</div>
<style>
    .si-page { font-size: 13px; }
    .si-top { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
    .si-back { font-size: 11.5px; opacity: .6; text-decoration: none; color: inherit; }
    .si-top h1 { font-size: 20px; margin: 4px 0 2px; }
    .si-top p { margin: 0; font-size: 12px; opacity: .6; }
    .si-actions { display: flex; gap: 8px; }
    .si-flash { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); color: #86efac; padding: 9px 12px; border-radius: 9px; margin-bottom: 12px; font-size: 12.5px; }
    .si-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
    .si-stat { background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.08); border-radius: 11px; padding: 10px 14px; }
    .si-stat span { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; opacity: .55; }
    .si-stat strong { font-size: 17px; font-weight: 700; }
    .si-grid { display: grid; grid-template-columns: 290px 1fr; gap: 14px; align-items: start; }
    .si-card { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 14px 16px; }
    .si-card h2 { font-size: 13px; margin: 0 0 10px; font-weight: 600; }
    .si-table { width: 100%; border-collapse: collapse; }
    .si-table th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; opacity: .5; padding: 6px 4px; border-bottom: 1px solid rgba(255,255,255,.08); font-weight: 600; }
    .si-table td { padding: 8px 4px; border-bottom: 1px solid rgba(255,255,255,.05); font-size: 12.5px; }
    .si-r { text-align: right !important; }
    .si-dim { opacity: .55; font-size: 11.5px; }
    .si-empty { text-align: center; opacity: .5; padding: 18px; }
    .si-from { margin-top: 14px; }
    .si-from label { display: block; font-size: 11px; opacity: .6; margin-bottom: 5px; }
    .si-from div { display: flex; gap: 6px; }
    .si-from input { flex: 1; min-width: 0; }
    .si-list { display: flex; flex-direction: column; gap: 10px; }
    .si-inv { display: flex; justify-content: space-between; gap: 18px; padding: 13px 14px; border: 1px solid rgba(255,255,255,.08); border-radius: 11px; background: rgba(255,255,255,.02); }
    .si-inv-unpaid { border-left: 3px solid #fbbf24; }
    .si-inv-paid { border-left: 3px solid #4ade80; }
    .si-inv-cancelled { border-left: 3px solid #64748b; opacity: .7; }
    .si-inv-head { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-bottom: 6px; }
    .si-inv-head strong { font-size: 14px; }
    .si-lines { list-style: none; margin: 0; padding: 0; min-width: 230px; }
    .si-lines li { display: flex; justify-content: space-between; gap: 22px; padding: 2px 0; font-size: 12.5px; }
    .si-lines li span { opacity: .65; }
    .si-note { margin-top: 6px; font-size: 11.5px; opacity: .6; font-style: italic; }
    .si-inv-side { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
    .si-total { font-size: 18px; font-weight: 700; }
    .si-row-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .si-row-actions form { margin: 0; }
    .si-badge, .si-tag { font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 99px; }
    .si-b-unpaid { background: rgba(251,191,36,.15); color: #fbbf24; }
    .si-b-paid { background: rgba(34,197,94,.15); color: #4ade80; }
    .si-b-cancelled { background: rgba(148,163,184,.18); color: #94a3b8; }
    .si-tag { background: rgba(255,255,255,.07); opacity: .8; font-weight: 500; }
    .si-foot { font-size: 11px; opacity: .5; margin: 12px 0 0; }
    .admin-body .si-btn, .si-btn { display: inline-flex; align-items: center; padding: 6px 12px; font-size: 12px; font-weight: 500; line-height: 1.3; border-radius: 8px; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.05); color: inherit; cursor: pointer; text-decoration: none; }
    .si-btn:hover { background: rgba(255,255,255,.1); }
    .si-btn.si-primary { background: #f97316; border-color: #f97316; color: #fff; }
    .si-btn.si-green { color: #4ade80; border-color: rgba(74,222,128,.4); }
    .si-btn.si-red { color: #f87171; border-color: rgba(248,113,113,.4); }
    .si-page input[type=text], .si-page input[type=date], .si-page input[type=month] { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.14); color: inherit; border-radius: 8px; padding: 7px 10px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .si-from input[type=month] { width: auto; }
    /* Dialog edit */
    .si-modal { border: 1px solid rgba(255,255,255,.14); border-radius: 14px; padding: 0; width: min(460px, calc(100vw - 32px)); background: #111827; color: #e5e7eb; box-shadow: 0 24px 60px rgba(0,0,0,.55); }
    .si-modal::backdrop { background: rgba(2,6,23,.65); backdrop-filter: blur(2px); }
    .si-modal header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .si-modal h3 { margin: 0; font-size: 15px; }
    .si-modal h3 span { opacity: .6; font-weight: 400; }
    .si-x { background: none; border: 0; color: inherit; font-size: 22px; line-height: 1; cursor: pointer; opacity: .6; }
    .si-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; padding: 16px 18px 6px; }
    .si-fields label { font-size: 11px; opacity: .85; display: flex; flex-direction: column; gap: 5px; }
    .si-full { grid-column: 1 / -1; }
    .si-modal .si-fields input { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.14); color: inherit; border-radius: 8px; padding: 7px 10px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .si-preview { margin: 6px 18px 0; padding: 10px 12px; background: rgba(255,255,255,.05); border-radius: 9px; font-size: 13px; }
    .si-preview strong { font-size: 16px; float: right; }
    .si-modal footer { display: flex; justify-content: flex-end; gap: 8px; padding: 16px 18px; }
    @media (max-width: 900px) { .si-grid { grid-template-columns: 1fr; } .si-stats { grid-template-columns: repeat(2, 1fr); } .si-inv { flex-direction: column; } .si-inv-side { align-items: flex-start; text-align: left; } .si-row-actions { justify-content: flex-start; } }
</style>
<script>
(function () {
    var modal = document.getElementById('siModal');
    if (!modal) return;
    var el = function (id) { return document.getElementById(id); };
    var fee = 0;
    function num(v) { v = parseFloat(String(v).replace(/[^0-9.\-]/g, '')); return isNaN(v) ? 0 : v; }
    function rp(n) { return (n < 0 ? '-' : '') + 'Rp ' + Math.abs(Math.round(n)).toLocaleString('id-ID'); }
    function preview() { el('siTotal').textContent = rp(num(el('siBase').value) + Math.max(0, Math.floor(num(el('siGuests').value))) * fee + num(el('siAdj').value)); }
    document.querySelectorAll('[data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = JSON.parse(btn.getAttribute('data-edit'));
            fee = d.fee;
            el('siId').value = d.id; el('siTitle').textContent = d.title;
            el('siBase').value = d.base_fee; el('siGuests').value = d.guest_count;
            el('siAdj').value = d.adjustment; el('siDue').value = d.due_date; el('siNote').value = d.note;
            preview();
            if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
        });
    });
    ['siBase', 'siGuests', 'siAdj'].forEach(function (id) { el(id).addEventListener('input', preview); });
    modal.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { modal.close(); }); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.close(); });
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) { if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
    });
})();
</script>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
