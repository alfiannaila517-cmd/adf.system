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
    <?php else: ?>
        <div class="si-head">
            <div>
                <h1>Tagihan: <?php echo htmlspecialchars($client['client_name'] ?? $clientKey); ?></h1>
                <p>Biaya bulanan <?php echo $rp((float) ($client['base_fee'] ?? 0)); ?> + <?php echo $rp((float) ($client['per_guest_fee'] ?? 0)); ?> per pax.
                    Dihitung otomatis dari booking di kalender klien. Ubah tarif di <a href="subscription-clients.php?edit=<?php echo urlencode($clientKey); ?>">Edit Klien</a>.</p>
            </div>
            <div class="si-head-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>"><input type="hidden" name="action" value="recompute"><button class="btn" type="submit">Hitung Ulang</button></form>
                <a class="btn" href="subscription-manual-invoice.php?client=<?php echo urlencode($clientKey); ?>">+ Tagihan Manual</a>
            </div>
        </div>

        <?php if ($msg): ?><div class="admin-alert admin-alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

        <div class="si-grid">
            <div class="si-card">
                <div class="si-title">Pax dilaporkan klien</div>
                <table class="si-table">
                    <thead><tr><th>Periode</th><th>Pax</th><th>Dilaporkan</th></tr></thead>
                    <tbody>
                    <?php foreach ($usage as $p => $u): ?>
                        <tr><td><?php echo htmlspecialchars((string) $p); ?></td><td><?php echo (int) ($u['guests'] ?? 0); ?></td><td><?php echo !empty($u['at']) ? htmlspecialchars(date('d M H:i', strtotime($u['at']))) : '-'; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$usage): ?><tr><td colspan="3" class="si-empty">Klien belum melaporkan data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <form method="post" class="si-inline">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
                    <input type="hidden" name="action" value="from_period">
                    <label>Hitung otomatis mulai periode
                        <input type="month" name="from_period" value="<?php echo htmlspecialchars((string) ($meta['from_period'] ?? date('Y-m'))); ?>"></label>
                    <button class="btn" type="submit">Simpan</button>
                </form>
                <div class="si-note">Sinkron terakhir: <?php echo !empty($meta['last_sync']) ? htmlspecialchars(date('d M Y H:i', strtotime($meta['last_sync']))) : 'belum pernah'; ?></div>
            </div>

            <div class="si-card si-wide">
                <div class="si-title">Tagihan</div>
                <div class="si-scroll">
                <table class="si-table">
                    <thead><tr><th>Periode / Ref</th><th>Rincian</th><th class="si-r">Total</th><th>Status</th><th>Jatuh Tempo</th><th class="si-r">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv):
                        $st = (string) $inv['status'];
                        $total = adf_ledger_total($inv);
                        $fixedAuto = !empty($inv['edited']);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($inv['period']); ?></strong><small><?php echo htmlspecialchars($inv['ref']); ?><?php echo $inv['kind'] === 'topup' ? ' · susulan' : ''; ?></small></td>
                            <td>
                                <?php if ((float) $inv['base_fee'] > 0): ?>Bulanan <?php echo $rp((float) $inv['base_fee']); ?><br><?php endif; ?>
                                <?php if ((int) $inv['guest_count'] > 0): ?><?php echo (int) $inv['guest_count']; ?> pax × <?php echo $rp((float) $inv['per_guest_fee']); ?> = <?php echo $rp((int) $inv['guest_count'] * (float) $inv['per_guest_fee']); ?><br><?php endif; ?>
                                <?php if ((float) ($inv['adjustment'] ?? 0) != 0.0): ?>Penyesuaian <?php echo $rp((float) $inv['adjustment']); ?><br><?php endif; ?>
                                <?php if (!empty($inv['note'])): ?><small><?php echo htmlspecialchars($inv['note']); ?></small><?php endif; ?>
                                <?php if ($fixedAuto): ?><small>diedit manual</small><?php elseif (!empty($inv['locked']) && $st === 'unpaid'): ?><small>link bayar sudah dibuat (nominal terkunci)</small><?php endif; ?>
                            </td>
                            <td class="si-r"><strong><?php echo $rp($total); ?></strong></td>
                            <td><span class="si-badge si-<?php echo $st; ?>"><?php echo ['unpaid' => 'Belum dibayar', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'][$st] ?? $st; ?></span>
                                <?php if ($st === 'paid' && !empty($inv['paid_at'])): ?><small><?php echo htmlspecialchars(date('d M Y', strtotime($inv['paid_at']))); ?></small><?php endif; ?></td>
                            <td><?php echo !empty($inv['due_date']) ? htmlspecialchars(date('d M Y', strtotime($inv['due_date']))) : '-'; ?></td>
                            <td class="si-r">
                                <?php if ($st === 'unpaid'): ?>
                                    <details class="si-edit">
                                        <summary class="si-btn">Edit</summary>
                                        <form method="post" class="si-editform">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <label>Biaya bulanan <input type="text" name="base_fee" value="<?php echo (int) $inv['base_fee']; ?>"></label>
                                            <label>Jumlah pax <input type="text" name="guest_count" value="<?php echo (int) $inv['guest_count']; ?>"></label>
                                            <label>Penyesuaian (+/− Rp) <input type="text" name="adjustment" value="<?php echo (float) ($inv['adjustment'] ?? 0); ?>"></label>
                                            <label>Jatuh tempo <input type="date" name="due_date" value="<?php echo htmlspecialchars((string) ($inv['due_date'] ?? '')); ?>"></label>
                                            <label class="si-full">Catatan <input type="text" name="note" maxlength="200" value="<?php echo htmlspecialchars((string) ($inv['note'] ?? '')); ?>"></label>
                                            <button class="btn btn-primary" type="submit">Simpan</button>
                                        </form>
                                    </details>
                                    <?php if ($fixedAuto): ?>
                                        <form method="post" class="si-f"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>"><input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>"><input type="hidden" name="action" value="auto"><button class="si-btn" type="submit">Otomatis</button></form>
                                    <?php endif; ?>
                                    <form method="post" class="si-f" onsubmit="return confirm('Tandai invoice ini LUNAS (dibayar di luar Pakasir)?');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>"><input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>"><input type="hidden" name="action" value="paid"><button class="si-btn si-green" type="submit">Lunas</button></form>
                                    <form method="post" class="si-f" onsubmit="return confirm('Batalkan invoice ini? Pax & biaya di dalamnya dianggap dibebaskan dan tidak ditagih ulang.');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="client_key" value="<?php echo htmlspecialchars($clientKey); ?>"><input type="hidden" name="id" value="<?php echo htmlspecialchars($inv['id']); ?>"><input type="hidden" name="action" value="cancel"><button class="si-btn si-red" type="submit">Batalkan</button></form>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$invoices): ?><tr><td colspan="6" class="si-empty">Belum ada tagihan. Muncul otomatis setelah sistem klien sinkron.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                <div class="si-note">Membatalkan = membebaskan pax/biaya itu (tidak dibuat ulang). Invoice yang diedit tidak ikut berubah saat pax bertambah; pax baru ditagih di invoice susulan.</div>
            </div>
        </div>
    <?php endif; ?>
</div>
<style>
    .si-page { font-size: 12.5px; }
    .si-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-end; margin-bottom: 12px; flex-wrap: wrap; }
    .si-head h1 { font-size: 17px; margin: 0 0 2px; }
    .si-head p { margin: 0; font-size: 12px; opacity: .65; }
    .si-head-actions { display: flex; gap: 6px; }
    .si-grid { display: grid; grid-template-columns: 280px 1fr; gap: 12px; align-items: start; }
    .si-card { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: 10px 12px; }
    .si-title { font-weight: 600; margin-bottom: 6px; }
    .si-table { width: 100%; border-collapse: collapse; }
    .si-table th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; opacity: .55; padding: 6px 8px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .si-table td { padding: 7px 8px; border-bottom: 1px solid rgba(255,255,255,.05); vertical-align: top; font-size: 12px; }
    .si-table td small { display: block; opacity: .55; font-size: 10.5px; }
    .si-r { text-align: right !important; white-space: nowrap; }
    .si-scroll { overflow-x: auto; }
    .si-empty { text-align: center; opacity: .5; padding: 16px !important; }
    .si-badge { font-size: 10.5px; font-weight: 600; padding: 2px 7px; border-radius: 99px; display: inline-block; }
    .si-unpaid { background: rgba(251,191,36,.15); color: #fbbf24; }
    .si-paid { background: rgba(34,197,94,.14); color: #4ade80; }
    .si-cancelled { background: rgba(148,163,184,.18); color: #94a3b8; }
    .si-btn { display: inline-block; padding: 3px 8px; font-size: 11px; border-radius: 6px; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.04); color: inherit; cursor: pointer; list-style: none; }
    .si-btn.si-green { color: #4ade80; border-color: rgba(74,222,128,.35); }
    .si-btn.si-red { color: #f87171; border-color: rgba(248,113,113,.35); }
    .si-f { display: inline; margin: 0; }
    .si-edit { display: inline-block; text-align: left; position: relative; }
    .si-edit > summary::-webkit-details-marker { display: none; }
    .si-editform { position: absolute; right: 0; top: 100%; z-index: 5; width: 300px; background: #111827; border: 1px solid rgba(255,255,255,.15); border-radius: 10px; padding: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 6px; box-shadow: 0 10px 30px rgba(0,0,0,.4); }
    .si-editform label { font-size: 10.5px; display: block; }
    .si-editform input { width: 100%; padding: 4px 6px; font-size: 12px; }
    .si-full, .si-editform button { grid-column: 1 / -1; }
    .si-inline { display: flex; gap: 6px; align-items: flex-end; margin-top: 8px; flex-wrap: wrap; }
    .si-inline label { font-size: 10.5px; }
    .si-note { font-size: 11px; opacity: .55; margin-top: 8px; }
    @media (max-width: 900px) { .si-grid { grid-template-columns: 1fr; } }
</style>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
