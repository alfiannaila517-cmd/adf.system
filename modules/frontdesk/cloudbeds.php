<?php

/**
 * FRONT DESK - INTEGRASI CLOUDBEDS (tahap 1: koneksi & pemetaan, hanya baca)
 * Simpan API key (terenkripsi), tes koneksi, lalu bandingkan tipe kamar / kamar / sumber booking
 * Cloudbeds dengan data sistem. Belum ada sinkron yang mengubah data di sini maupun di Cloudbeds.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudbedsClient.php';
require_once '../../includes/CloudbedsSync.php';
require_once '../../includes/CloudbedsRates.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/dashboard.php');
    exit;
}

$db = Database::getInstance();
$cb = new CloudbedsClient($db);

// ---- Simpan / hapus key (PRG). Key baru diuji dulu; hanya disimpan bila Cloudbeds menerimanya. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_key') {
        $key = trim((string)($_POST['api_key'] ?? ''), " \t\n\r\0\x0B\"'");
        if ($key === '' || strpos($key, 'cbat_') !== 0) {
            setFlash('error', 'API key harus diawali "cbat_".');
        } else {
            $test = $cb->testConnection($key);
            if (!$test['ok']) {
                setFlash('error', 'Key tidak disimpan — Cloudbeds menolak: ' . htmlspecialchars($test['steps'][0]['detail'] ?? 'tidak diketahui'));
            } else {
                $cb->saveSetting('cloudbeds_api_key', $key);
                if (!empty($test['property']['id'])) {
                    $cb->saveSetting('cloudbeds_property_id', $test['property']['id']);
                }
                setFlash($cb->isConfigured() ? 'success' : 'error', $cb->isConfigured()
                    ? 'API key tersimpan &amp; terhubung ke ' . htmlspecialchars($test['property']['name'] ?: 'properti Cloudbeds') . '.'
                    : 'Key diterima Cloudbeds tetapi gagal disimpan ke database (tabel settings).');
            }
        }
    } elseif ($act === 'remove_key') {
        $cb->saveSetting('cloudbeds_api_key', '');
        setFlash('success', 'API key Cloudbeds dihapus dari sistem.');
    } elseif ($act === 'save_type_map') {
        $cb->saveRoomTypeMap((array)($_POST['type_map'] ?? []));
        setFlash('success', 'Pemetaan tipe kamar tersimpan.');
    } elseif ($act === 'run_sync') {
        $sf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['sf'] ?? '') ? $_POST['sf'] : date('Y-m-d', strtotime('-3 days'));
        $st = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['st'] ?? '') ? $_POST['st'] : date('Y-m-d', strtotime('+60 days'));
        $res = (new CloudbedsSync($db, $cb))->apply($sf, $st, (int)($_SESSION['user_id'] ?? 0));
        if (!$res['ok']) {
            setFlash('error', 'Sinkron gagal: ' . htmlspecialchars($res['detail']));
        } else {
            $d = $res['done'];
            setFlash($d['errors'] ? 'error' : 'success', 'Sinkron selesai: ' . $d['create'] . ' booking baru, ' . $d['link'] . ' ditautkan, ' . $d['cancel'] . ' dibatalkan, ' . $d['block'] . ' blok kamar' . ($d['unblock'] ? ', ' . $d['unblock'] . ' blok dicabut' : '') . ($d['push_status'] + $d['push_create'] + $d['push_block'] + $d['push_pay'] ? ', dikirim ke Cloudbeds: ' . $d['push_status'] . ' status, ' . $d['push_create'] . ' booking, ' . $d['push_block'] . ' blok, ' . $d['push_pay'] . ' pembayaran' : '')
                . ($res['counts']['warn'] ? ', ' . $res['counts']['warn'] . ' perlu dicek' : '')
                . ($d['errors'] ? '<br>Gagal: ' . htmlspecialchars(implode(' | ', $d['errors'])) : '') . '.');
        }
        header('Location: cloudbeds.php?plan=1&sf=' . urlencode($sf) . '&st=' . urlencode($st));
        exit;
    } elseif ($act === 'refresh_rates') {
        $res = (new CloudbedsRates($db, $cb))->refresh(date('Y-m-d'), date('Y-m-d', strtotime('+90 days')));
        setFlash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Harga & ketersediaan diperbarui dari Cloudbeds (' . (int)$res['rows'] . ' malam × tipe kamar).'
            : 'Gagal mengambil harga: ' . htmlspecialchars($res['detail']));
        header('Location: cloudbeds.php?rates=1#rates');
        exit;
    } elseif ($act === 'toggle_pay') {
        $on = !empty($_POST['pay_on']);
        $cb->saveSetting('cloudbeds_pay_enabled', $on ? '1' : '0');
        if ($on) {
            // Hanya pembayaran yang dicatat mulai sekarang (yang lama mungkin sudah diketik manual di Cloudbeds)
            $cb->saveSetting('cloudbeds_pay_since', date('Y-m-d H:i:s'));
        }
        setFlash('success', $on ? 'Kirim pembayaran ke Cloudbeds diaktifkan.' : 'Kirim pembayaran ke Cloudbeds dimatikan.');
        header('Location: cloudbeds.php');
        exit;
    } elseif ($act === 'toggle_push') {
        $on = !empty($_POST['push_on']);
        $cb->saveSetting('cloudbeds_push_enabled', $on ? '1' : '0');
        if ($on) {
            // Hanya booking direct yang dibuat mulai sekarang yang dikirim (yang lama sudah diketik manual di Cloudbeds)
            $cb->saveSetting('cloudbeds_push_since', date('Y-m-d H:i:s'));
        }
        setFlash('success', $on ? 'Kirim ke Cloudbeds diaktifkan: check-in/out & booking direct baru dikirim saat sinkron.' : 'Kirim ke Cloudbeds dimatikan.');
        header('Location: cloudbeds.php');
        exit;
    } elseif ($act === 'toggle_auto') {
        $on = !empty($_POST['auto_on']);
        $cb->saveSetting('cloudbeds_auto_sync', $on ? '1' : '0');
        setFlash('success', $on ? 'Sinkron otomatis diaktifkan (berjalan sesuai jadwal cron).' : 'Sinkron otomatis dimatikan.');
        header('Location: cloudbeds.php');
        exit;
    } elseif ($act === 'save_source_map') {
        $cb->saveSourceMap((array)($_POST['source_map'] ?? []));
        setFlash('success', 'Pemetaan sumber booking tersimpan.');
    }
    header('Location: cloudbeds.php' . (in_array($act, ['save_key', 'save_type_map', 'save_source_map'], true) ? '?test=1' : ''));
    exit;
}

// ---- Tes koneksi & data pemetaan (hanya baca) ----
$test = null;
if ($cb->isConfigured() && isset($_GET['test'])) {
    $test = $cb->testConnection();
    if ($test['ok'] && !empty($test['property']['id']) && $test['property']['id'] !== $cb->propertyId()) {
        $cb->saveSetting('cloudbeds_property_id', $test['property']['id']);
    }
}

// Data sistem untuk dibandingkan
$localRooms = $db->fetchAll("SELECT r.id, r.room_number, COALESCE(rt.type_name, '') AS type_name
    FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id ORDER BY rt.type_name, r.room_number") ?: [];
$localTypes = [];
foreach ($localRooms as $lr) {
    $localTypes[$lr['type_name']] = ($localTypes[$lr['type_name']] ?? 0) + 1;
}
$localSources = [];
try {
    $localSources = $db->fetchAll("SELECT source_key, source_name, source_type, fee_percent FROM booking_sources WHERE is_active = 1 ORDER BY source_name") ?: [];
} catch (\Throwable $e) {
}

$norm = function ($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
};
// Nomor kamar dari nama kamar Cloudbeds ("Room 201", "201", "201 - Queen")
$roomNo = function ($name) {
    return preg_match('/\d{2,4}/', (string)$name, $m) ? $m[0] : trim((string)$name);
};

$pageTitle = 'Integrasi Cloudbeds';
include '../../includes/header.php';
?>

<style>
    body[data-theme] .main-content .cbx { max-width: 1200px; margin: 0 auto; padding-bottom: 1.5rem; }
    body[data-theme] .main-content .cbx-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .75rem; }
    body[data-theme] .main-content .cbx-head h1 { margin: 0; font-size: .95rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .cbx-head h1 small { display: block; font-size: .68rem !important; font-weight: 500; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .cbx-card { border-radius: 14px; background: var(--fd-card); border: 1px solid var(--fd-edge); box-shadow: var(--fd-shadow); padding: .85rem 1rem; margin-bottom: .75rem; }
    body[data-theme] .main-content .cbx-card h3 { margin: 0 0 .15rem; font-size: .82rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .cbx-card > p.cbx-sub { margin: 0 0 .7rem; padding-bottom: .55rem; border-bottom: 1px solid var(--fd-line); font-size: .68rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .cbx-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .75rem; align-items: start; }
    body[data-theme] .main-content .cbx label.cbx-label { display: block; margin: 0 0 .3rem; font-size: .6rem !important; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .cbx .cbx-input { width: 100%; padding: .45rem .65rem; border-radius: 9px; border: 1px solid var(--fd-input-border); background: var(--fd-input-bg) !important; color: var(--fd-text) !important; font-size: .76rem !important; font-family: inherit; }
    body[data-theme] .main-content .cbx .cbx-hint { display: block; margin-top: .3rem; font-size: .64rem !important; color: var(--fd-muted) !important; line-height: 1.5; }
    body[data-theme] .main-content .cbx .cbx-row { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; margin-top: .6rem; }
    body[data-theme] .main-content .cbx .cbx-btn { display: inline-flex; align-items: center; gap: .35rem; height: 30px; padding: 0 .8rem; border-radius: 8px; border: 1px solid transparent; cursor: pointer; font-size: .7rem !important; font-weight: 600; text-decoration: none; color: #fff !important; -webkit-text-fill-color: #fff !important; background: linear-gradient(135deg, var(--fd-accent), var(--fd-accent-2)); }
    body[data-theme] .main-content .cbx .cbx-btn.ghost { background: var(--fd-tile); border-color: var(--fd-line); color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    body[data-theme] .main-content .cbx .cbx-btn.danger { background: transparent; border-color: rgba(220, 38, 38, .35); color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    body[data-theme] .main-content .cbx .cbx-status { display: flex; align-items: center; gap: .6rem; padding: .6rem .7rem; border-radius: 10px; background: var(--fd-tile); border: 1px solid var(--fd-line); font-size: .74rem; color: var(--fd-text) !important; }
    body[data-theme] .main-content .cbx .cbx-status small { display: block; font-size: .64rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .cbx .cbx-dot { width: 10px; height: 10px; border-radius: 50%; background: #94a3b8; flex-shrink: 0; }
    body[data-theme] .main-content .cbx .cbx-dot.on { background: #22c55e; box-shadow: 0 0 0 4px rgba(34, 197, 94, .18); }
    body[data-theme] .main-content .cbx .cbx-dot.off { background: #ef4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, .15); }
    body[data-theme] .main-content .cbx table.cbx-tbl { width: 100%; border-collapse: collapse; }
    body[data-theme] .main-content .cbx .cbx-tbl th { padding: .45rem .6rem; background: var(--fd-accent) !important; color: #fff !important; font-size: .56rem !important; letter-spacing: .07em; text-transform: uppercase; text-align: left; }
    body[data-theme] .main-content .cbx .cbx-tbl td { padding: .4rem .6rem; border-top: 1px solid var(--fd-line); font-size: .72rem !important; color: var(--fd-text-2) !important; vertical-align: top; }
    body[data-theme] .main-content .cbx .cbx-pill { display: inline-block; padding: .05rem .5rem; border-radius: 999px; font-size: .6rem; font-weight: 700; white-space: nowrap; }
    body[data-theme] .main-content .cbx .cbx-pill.ok { background: rgba(5, 150, 105, .12); color: #047857 !important; }
    body[data-theme] .main-content .cbx .cbx-pill.warn { background: rgba(217, 119, 6, .14); color: #b45309 !important; }
    body[data-theme] .main-content .cbx .cbx-pill.bad { background: rgba(220, 38, 38, .1); color: #b91c1c !important; }
    body[data-theme] .main-content .cbx .cbx-steps { display: flex; flex-direction: column; gap: .35rem; }
    body[data-theme] .main-content .cbx .cbx-step { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .72rem; color: var(--fd-text) !important; }
    body[data-theme] .main-content .cbx .cbx-step span:last-child { font-size: .64rem; color: var(--fd-muted) !important; text-align: right; }
    body[data-theme] .main-content .cbx .cbx-note { padding: .55rem .7rem; border-radius: 10px; background: rgba(37, 99, 235, .07); border: 1px solid rgba(37, 99, 235, .18); font-size: .68rem; line-height: 1.55; color: var(--fd-text-2) !important; }
    @media (max-width: 960px) { body[data-theme] .main-content .cbx-grid { grid-template-columns: 1fr; } }
</style>

<div class="cbx">
    <div class="cbx-head">
        <h1>Integrasi Cloudbeds<small>Tahap 1 — koneksi & pemetaan kamar (hanya membaca, belum ada sinkron)</small></h1>
        <a class="cbx-btn ghost" href="settings.php">‹ Pengaturan Front Desk</a>
    </div>

    <div class="cbx-grid">
        <div class="cbx-card">
            <h3>API Key</h3>
            <p class="cbx-sub">Dari Cloudbeds → Marketplace → API Credentials. Disimpan terenkripsi, tidak pernah ditampilkan lagi.</p>
            <div class="cbx-status">
                <span class="cbx-dot <?php echo $cb->isConfigured() ? ($test ? ($test['ok'] ? 'on' : 'off') : '') : 'off'; ?>"></span>
                <div>
                    <?php if ($cb->isConfigured()): ?>
                        <b>Key tersimpan</b> <code><?php echo htmlspecialchars($cb->keyHint()); ?></code>
                        <small>Property ID: <?php echo htmlspecialchars($cb->propertyId() ?: '— (isi otomatis saat tes)'); ?></small>
                    <?php else: ?>
                        <b>Belum terhubung</b><small>Masukkan API key Cloudbeds di bawah.</small>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" autocomplete="off" style="margin-top:.75rem">
                <input type="hidden" name="act" value="save_key">
                <label class="cbx-label" for="cbKey"><?php echo $cb->isConfigured() ? 'Ganti API key' : 'API key'; ?></label>
                <input type="password" class="cbx-input" id="cbKey" name="api_key" placeholder="cbat_…" autocomplete="new-password" spellcheck="false">
                <span class="cbx-hint">Key diuji ke Cloudbeds lebih dulu; hanya disimpan bila diterima.</span>
                <div class="cbx-row">
                    <button type="submit" class="cbx-btn">Simpan &amp; Tes</button>
                    <?php if ($cb->isConfigured()): ?>
                        <a class="cbx-btn ghost" href="cloudbeds.php?test=1">Tes koneksi</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($cb->isConfigured()): ?>
                <form method="post" onsubmit="return confirm('Hapus API key Cloudbeds dari sistem?')" style="margin-top:.5rem">
                    <input type="hidden" name="act" value="remove_key">
                    <button type="submit" class="cbx-btn danger">Hapus key</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="cbx-card">
            <h3>Hasil tes koneksi</h3>
            <p class="cbx-sub">Hanya membaca data dari Cloudbeds. Tidak ada yang diubah.</p>
            <?php if (!$test): ?>
                <div class="cbx-note"><?php echo $cb->isConfigured() ? 'Klik <b>Tes koneksi</b> untuk mengambil data properti, kamar dan sumber booking.' : 'Simpan API key untuk mulai.'; ?></div>
            <?php else: ?>
                <?php if (!empty($test['property'])): ?>
                    <div class="cbx-status" style="margin-bottom:.6rem">
                        <span class="cbx-dot on"></span>
                        <div><b><?php echo htmlspecialchars($test['property']['name'] ?: 'Properti'); ?></b>
                            <small>Property ID <?php echo htmlspecialchars($test['property']['id']); ?><?php echo $test['property']['currency'] ? ' · ' . htmlspecialchars(is_string($test['property']['currency']) ? $test['property']['currency'] : '') : ''; ?><?php echo $test['property']['count'] > 1 ? ' · ' . (int)$test['property']['count'] . ' properti di key ini' : ''; ?></small></div>
                    </div>
                <?php endif; ?>
                <div class="cbx-steps">
                    <?php foreach ($test['steps'] as $st): ?>
                        <div class="cbx-step"><span><span class="cbx-pill <?php echo $st['ok'] ? 'ok' : 'bad'; ?>"><?php echo $st['ok'] ? 'OK' : 'Gagal'; ?></span> <?php echo htmlspecialchars($st['name']); ?></span><span><?php echo $st['ok'] ? '' : htmlspecialchars($st['detail']); ?></span></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($test && $test['ok']): ?>
        <?php
        // Pemetaan tipe kamar: tersimpan, atau saran otomatis (nama sama / nama sistem terkandung + unit sama)
        $savedMap = $cb->roomTypeMap();
        $suggested = CloudbedsClient::suggestRoomTypeMap($test['room_types'], $localTypes);
        $typeMap = [];
        foreach ($test['room_types'] as $t) {
            $typeMap[$t['id']] = $savedMap[$t['id']] ?? ($suggested[$t['id']] ?? '');
        }
        $hasUnsaved = false;
        foreach ($test['room_types'] as $t) {
            if (($savedMap[$t['id']] ?? '') !== $typeMap[$t['id']]) $hasUnsaved = true;
        }
        $mappedLocal = array_count_values(array_filter($typeMap));
        // Kamar: cocokkan nomor kamar; tipe dibandingkan lewat pemetaan
        $cbRoomByNo = [];
        foreach ($test['rooms'] as $r) {
            $cbRoomByNo[$roomNo($r['name'])] = $r;
        }
        $localRoomNos = array_column($localRooms, 'room_number');
        $roomsOk = 0;
        foreach ($localRooms as $lr) {
            $c = $cbRoomByNo[(string)$lr['room_number']] ?? null;
            if ($c && ($typeMap[$c['type_id']] ?? '') === $lr['type_name']) $roomsOk++;
        }
        ?>
        <div class="cbx-card">
            <h3>Pemetaan tipe kamar</h3>
            <p class="cbx-sub">Pasangkan setiap tipe kamar Cloudbeds dengan tipe kamar di sistem. Nama tidak harus sama — pasangan ini dipakai saat booking OTA masuk.</p>
            <form method="post">
                <input type="hidden" name="act" value="save_type_map">
                <table class="cbx-tbl">
                    <thead><tr><th>Cloudbeds</th><th>Unit</th><th>Tipe di sistem</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($test['room_types'] as $t):
                            $sel = $typeMap[$t['id']];
                            $cnt = $sel !== '' ? (int)($localTypes[$sel] ?? 0) : 0; ?>
                            <tr>
                                <td><b><?php echo htmlspecialchars($t['name']); ?></b><?php echo $t['short'] ? ' <small>(' . htmlspecialchars($t['short']) . ')</small>' : ''; ?></td>
                                <td><?php echo (int)$t['units']; ?></td>
                                <td>
                                    <select class="cbx-input" name="type_map[<?php echo htmlspecialchars($t['id']); ?>]" style="max-width:240px">
                                        <option value="">— belum dipasangkan —</option>
                                        <?php foreach ($localTypes as $ln => $lc): ?>
                                            <option value="<?php echo htmlspecialchars($ln); ?>" <?php echo $ln === $sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($ln ?: '(tanpa tipe)'); ?> · <?php echo (int)$lc; ?> kamar</option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($sel === ''): ?><span class="cbx-pill bad">Belum dipasangkan</span>
                                    <?php elseif (($mappedLocal[$sel] ?? 0) > 1): ?><span class="cbx-pill warn">Dipakai 2×</span>
                                    <?php elseif ($cnt !== (int)$t['units']): ?><span class="cbx-pill warn">Jumlah beda (<?php echo $cnt; ?>)</span>
                                    <?php else: ?><span class="cbx-pill ok">Cocok</span><?php endif; ?>
                                    <?php if (!isset($savedMap[$t['id']]) && $sel !== ''): ?><span class="cbx-pill" style="background:rgba(37,99,235,.1);color:#1d4ed8">saran</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($localTypes as $ln => $lc): if (!isset($mappedLocal[$ln])): ?>
                            <tr><td>—</td><td></td><td><?php echo htmlspecialchars($ln ?: '(tanpa tipe)'); ?> · <?php echo (int)$lc; ?> kamar</td><td><span class="cbx-pill warn">Tidak ada di Cloudbeds</span></td></tr>
                        <?php endif; endforeach; ?>
                    </tbody>
                </table>
                <div class="cbx-row">
                    <button type="submit" class="cbx-btn">Simpan pemetaan</button>
                    <?php if ($hasUnsaved): ?><span class="cbx-hint" style="margin:0">Ada saran yang belum disimpan — periksa lalu klik <b>Simpan pemetaan</b>.</span><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="cbx-grid">
            <div class="cbx-card">
                <h3>Pemetaan nomor kamar</h3>
                <p class="cbx-sub"><?php echo $roomsOk; ?> dari <?php echo count($localRooms); ?> kamar sistem cocok dengan Cloudbeds (nomor kamar + tipe sesuai pemetaan).</p>
                <table class="cbx-tbl">
                    <thead><tr><th>Kamar sistem</th><th>Cloudbeds</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($localRooms as $lr): $c = $cbRoomByNo[(string)$lr['room_number']] ?? null; ?>
                            <tr>
                                <td><b><?php echo htmlspecialchars($lr['room_number']); ?></b> <small><?php echo htmlspecialchars($lr['type_name']); ?></small></td>
                                <td><?php echo $c ? htmlspecialchars($c['name']) . ' <small>' . htmlspecialchars($c['type']) . '</small>' : '—'; ?></td>
                                <td><?php if (!$c): ?><span class="cbx-pill bad">Tidak ada</span><?php elseif (($typeMap[$c['type_id']] ?? '') !== $lr['type_name']): ?><span class="cbx-pill warn">Tipe beda</span><?php else: ?><span class="cbx-pill ok">Cocok</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($test['rooms'] as $r): if (!in_array($roomNo($r['name']), $localRoomNos, true)): ?>
                            <tr><td>—</td><td><?php echo htmlspecialchars($r['name']); ?> <small><?php echo htmlspecialchars($r['type']); ?></small></td><td><span class="cbx-pill warn">Hanya di Cloudbeds</span></td></tr>
                        <?php endif; endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="cbx-card">
                <h3>Pemetaan sumber booking</h3>
                <p class="cbx-sub">Pasangkan sumber Cloudbeds dengan sumber di sistem. Fee OTA di sistem dipakai untuk harga bersih & buku kas (komisi di Cloudbeds belum diatur).</p>
                <?php
                $savedSrc = $cb->sourceMap();
                $sugSrc = CloudbedsClient::suggestSourceMap($test['sources'], $localSources);
                $localByKey = [];
                foreach ($localSources as $l) {
                    $localByKey[$l['source_key']] = $l;
                }
                $srcUnsaved = false;
                ?>
                <form method="post">
                    <input type="hidden" name="act" value="save_source_map">
                    <table class="cbx-tbl">
                        <thead><tr><th>Cloudbeds</th><th>Pembayaran</th><th>Sumber di sistem</th><th>Fee</th></tr></thead>
                        <tbody>
                            <?php foreach ($test['sources'] as $s):
                                $sel = $savedSrc[$s['id']] ?? ($sugSrc[$s['id']] ?? '');
                                if (!isset($savedSrc[$s['id']]) && $sel !== '') $srcUnsaved = true;
                                $ct = CloudbedsClient::collectType($s['name']);
                                $ls = $localByKey[$sel] ?? null; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td><?php if ($ct === 'channel'): ?><span class="cbx-pill" style="background:rgba(37,99,235,.1);color:#1d4ed8">OTA terima uang</span>
                                        <?php elseif ($ct === 'hotel'): ?><span class="cbx-pill" style="background:rgba(5,150,105,.12);color:#047857">Bayar ke hotel</span>
                                        <?php else: ?><small>—</small><?php endif; ?></td>
                                    <td>
                                        <select class="cbx-input" name="source_map[<?php echo htmlspecialchars($s['id']); ?>]" style="max-width:200px">
                                            <option value="">— belum dipasangkan —</option>
                                            <?php foreach ($localSources as $l): ?>
                                                <option value="<?php echo htmlspecialchars($l['source_key']); ?>" <?php echo $l['source_key'] === $sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['source_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!isset($savedSrc[$s['id']]) && $sel !== ''): ?><span class="cbx-pill" style="background:rgba(37,99,235,.1);color:#1d4ed8">saran</span><?php endif; ?>
                                    </td>
                                    <td><?php echo $ls ? rtrim(rtrim(number_format((float)$ls['fee_percent'], 2, ',', ''), '0'), ',') . '%' : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="cbx-row">
                        <button type="submit" class="cbx-btn">Simpan pemetaan sumber</button>
                        <?php if ($srcUnsaved): ?><span class="cbx-hint" style="margin:0">Ada saran yang belum disimpan.</span><?php endif; ?>
                    </div>
                </form>
                <p class="cbx-hint"><b>Bayar ke hotel</b> (Hotel Collect): tamu membayar di hotel, dicatat seperti booking langsung. <b>OTA terima uang</b> (Channel Collect): uang dari OTA, masuk kas bersih setelah fee saat check-in. Sumber yang tidak dipasangkan dicatat sebagai sumber lain.</p>
                <details style="margin-top:.5rem"><summary class="cbx-hint" style="cursor:pointer">Contoh data mentah sumber booking</summary>
                    <pre style="white-space:pre-wrap;font-size:.62rem;max-height:220px;overflow:auto"><?php echo htmlspecialchars(json_encode($test['sources_raw_sample'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                </details>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($cb->isConfigured()):
        // ---- Pratinjau reservasi Cloudbeds (hanya baca) ----
        $pvFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['pv_from'] ?? '') ? $_GET['pv_from'] : date('Y-m-d', strtotime('-3 days'));
        $pvTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['pv_to'] ?? '') ? $_GET['pv_to'] : date('Y-m-d', strtotime('+30 days'));
        $pv = isset($_GET['preview']) ? $cb->previewReservations($pvFrom, $pvTo) : null;
        // Booking sistem di rentang yang sama, untuk dicocokkan (tanggal sama + nama mirip)
        $localBk = [];
        if ($pv && $pv['ok']) {
            $localBk = $db->fetchAll("SELECT b.booking_code, b.status, b.booking_source, DATE(b.check_in_date) ci, DATE(b.check_out_date) co, g.guest_name, r.room_number
                FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id
                WHERE DATE(b.check_in_date) BETWEEN ? AND ?", [$pvFrom, $pvTo]) ?: [];
        }
        $nameKey = function ($s) {
            $w = array_filter(preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', ' ', (string)$s))), fn($x) => strlen($x) >= 3 && !in_array($x, ['mr', 'mrs', 'ms', 'pax'], true));
            return array_values($w);
        };
    ?>
        <div class="cbx-card">
            <h3>Pratinjau reservasi Cloudbeds</h3>
            <p class="cbx-sub">Hanya membaca. Menunjukkan booking Cloudbeds dan apakah sudah ada di sistem — dasar untuk sinkron otomatis tahap 2.</p>
            <form method="get" class="cbx-row" style="margin-top:0">
                <input type="hidden" name="preview" value="1">
                <label class="cbx-label" style="margin:0">Check-in dari</label>
                <input type="date" class="cbx-input" name="pv_from" value="<?php echo htmlspecialchars($pvFrom); ?>" style="width:150px">
                <label class="cbx-label" style="margin:0">sampai</label>
                <input type="date" class="cbx-input" name="pv_to" value="<?php echo htmlspecialchars($pvTo); ?>" style="width:150px">
                <button type="submit" class="cbx-btn">Tampilkan</button>
            </form>
            <?php if ($pv && !$pv['ok']): ?>
                <div class="cbx-note" style="margin-top:.6rem;border-color:#fecaca;color:#b91c1c">Gagal: <?php echo htmlspecialchars($pv['detail']); ?></div>
            <?php elseif ($pv): ?>
                <?php
                $nNew = 0;
                foreach ($pv['items'] as &$it) {
                    $it['match'] = null;
                    $k = $nameKey($it['guest']);
                    foreach ($localBk as $lb) {
                        if ($lb['ci'] === $it['checkin'] && $lb['co'] === $it['checkout'] && $k && array_intersect($k, $nameKey($lb['guest_name']))) {
                            $it['match'] = $lb;
                            break;
                        }
                    }
                    if (!$it['match'] && !in_array(strtolower($it['status']), ['canceled', 'cancelled', 'no_show'], true)) $nNew++;
                }
                unset($it);
                // Detail (kamar & total) hanya untuk yang aktif & belum ada di sistem — maks 20 panggilan
                $typeMapPv = $cb->roomTypeMap();
                $localRoomByNo = [];
                foreach ($localRooms as $lr) { $localRoomByNo[(string)$lr['room_number']] = $lr; }
                $detailSample = null;
                $nDetail = 0;
                foreach ($pv['items'] as &$it) {
                    $it['detail'] = null;
                    if ($it['match'] || in_array(strtolower($it['status']), ['canceled', 'cancelled', 'no_show'], true) || $nDetail >= 20) continue;
                    $nDetail++;
                    $it['detail'] = $cb->reservationDetail($it['id']);
                    if ($detailSample === null && $it['detail']['ok']) $detailSample = $it['detail']['raw'];
                }
                unset($it);
                ?>
                <p class="cbx-hint" style="margin:.6rem 0"><?php echo count($pv['items']); ?> reservasi di Cloudbeds · <b><?php echo $nNew; ?></b> aktif belum ada di sistem.</p>
                <div style="overflow-x:auto">
                    <table class="cbx-tbl">
                        <thead><tr><th>Cloudbeds</th><th>Tamu</th><th>Tanggal</th><th>Sumber</th><th>Status</th><th>Kamar → sistem</th><th>Total</th><th>Di sistem</th></tr></thead>
                        <tbody>
                            <?php foreach ($pv['items'] as $it): ?>
                                <tr>
                                    <td><small><?php echo htmlspecialchars($it['id']); ?></small><?php echo $it['third_party_id'] ? '<br><small>' . htmlspecialchars($it['third_party_id']) . '</small>' : ''; ?></td>
                                    <td><b><?php echo htmlspecialchars($it['guest']); ?></b></td>
                                    <td><?php echo htmlspecialchars(date('d M', strtotime($it['checkin'])) . ' – ' . date('d M', strtotime($it['checkout']))); ?></td>
                                    <td><?php echo htmlspecialchars($it['source']); ?></td>
                                    <td><?php echo htmlspecialchars($it['status']); ?></td>
                                    <td><?php if (!empty($it['detail']['rooms'])): foreach ($it['detail']['rooms'] as $dr):
                                            $no = preg_match('/\d{2,4}/', $dr['room_name'], $mm) ? $mm[0] : '';
                                            $lt = $typeMapPv[$dr['type_id']] ?? ''; ?>
                                            <div><?php if ($dr['assigned'] && isset($localRoomByNo[$no])): ?><span class="cbx-pill ok">Room <?php echo htmlspecialchars($no); ?></span>
                                                <?php elseif ($dr['assigned']): ?><span class="cbx-pill bad"><?php echo htmlspecialchars($dr['room_name']); ?> ?</span>
                                                <?php else: ?><span class="cbx-pill warn">Belum dapat kamar</span><?php endif; ?>
                                                <small><?php echo htmlspecialchars($lt ?: $dr['type_name']); ?></small></div>
                                        <?php endforeach; elseif ($it['detail'] && !$it['detail']['ok']): ?><small style="color:#b91c1c"><?php echo htmlspecialchars($it['detail']['detail']); ?></small>
                                        <?php else: ?><small>—</small><?php endif; ?></td>
                                    <td><?php $tot = $it['detail']['total'] ?? $it['total'];
                                        echo $tot !== null ? 'Rp ' . number_format($tot, 0, ',', '.') : '—';
                                        if (isset($it['detail']['balance']) && $it['detail']['balance'] !== null) echo '<br><small>sisa Rp ' . number_format($it['detail']['balance'], 0, ',', '.') . '</small>'; ?></td>
                                    <td><?php if ($it['match']): ?><span class="cbx-pill ok"><?php echo htmlspecialchars($it['match']['booking_code']); ?></span> <small>Room <?php echo htmlspecialchars((string)$it['match']['room_number']); ?></small>
                                        <?php elseif (in_array(strtolower($it['status']), ['canceled', 'cancelled', 'no_show'], true)): ?><span class="cbx-pill">batal</span>
                                        <?php else: ?><span class="cbx-pill warn">Belum ada</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <details style="margin-top:.5rem"><summary class="cbx-hint" style="cursor:pointer">Contoh data mentah 1 reservasi (untuk tahap 2)</summary>
                    <pre style="white-space:pre-wrap;font-size:.62rem;max-height:260px;overflow:auto"><?php
                        $sample = ['daftar' => $pv['raw_sample'], 'detail' => $detailSample];
                        // Data pribadi disamarkan di contoh: email & telepon
                        if (is_array($sample)) {
                            array_walk_recursive($sample, function (&$v, $k) {
                                if (is_string($v) && preg_match('/email|phone|cell/i', (string)$k)) $v = '***';
                            });
                        }
                        echo htmlspecialchars(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($cb->isConfigured()):
        // ---- Sinkron Cloudbeds → sistem (pratinjau lalu jalankan) ----
        $sf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sf'] ?? '') ? $_GET['sf'] : date('Y-m-d', strtotime('-3 days'));
        $st = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['st'] ?? '') ? $_GET['st'] : date('Y-m-d', strtotime('+60 days'));
        $syncer = new CloudbedsSync($db, $cb);
        $plan = isset($_GET['plan']) ? $syncer->plan($sf, $st) : null;
        $syncLog = $syncer->recentLog(8);
        $typeLabel = ['create' => ['Buat booking', 'ok'], 'link' => ['Tautkan', ''], 'cancel' => ['Batalkan', 'bad'], 'block' => ['Blok kamar', 'warn'], 'unblock' => ['Cabut blok', ''], 'push_status' => ['Kirim status', 'ok'], 'push_create' => ['Kirim booking', 'ok'], 'push_delblock' => ['Hapus blok', 'ok'], 'push_putblock' => ['Ubah blok', 'ok'], 'push_newblock' => ['Kirim blok', 'ok'], 'push_payment' => ['Kirim bayar', 'ok'], 'warn' => ['Perlu dicek', 'warn']];
    ?>
        <div class="cbx-card">
            <h3>Sinkron Cloudbeds → Sistem</h3>
            <p class="cbx-sub">Booking OTA dari Cloudbeds masuk ke Reservasi &amp; Kalender. Selalu tampilkan pratinjau dulu; tidak ada yang berubah sebelum <b>Jalankan sinkron</b>.</p>
            <?php
            $autoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_auto_sync'");
            $autoOn = ($autoRow['setting_value'] ?? '0') === '1';
            $lastRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_last_auto_sync'");
            $lastAuto = json_decode((string)($lastRow['setting_value'] ?? ''), true);
            $lastAge = !empty($lastAuto['at']) ? (time() - strtotime($lastAuto['at'])) : null;
            ?>
            <div class="cbx-status" style="margin-bottom:.75rem;flex-wrap:wrap">
                <span class="cbx-dot <?php echo $autoOn ? ($lastAge !== null && $lastAge < 1800 ? ($lastAuto['ok'] ? 'on' : 'off') : '') : ''; ?>"></span>
                <div style="flex:1;min-width:220px">
                    <b>Sinkron otomatis: <?php echo $autoOn ? 'AKTIF' : 'mati'; ?></b>
                    <small>
                        <?php if (!empty($lastAuto['at'])): ?>
                            Terakhir <?php echo htmlspecialchars(date('d M H:i', strtotime($lastAuto['at']))); ?> — <?php echo htmlspecialchars($lastAuto['summary'] ?? ''); ?>
                            <?php if ($autoOn && $lastAge !== null && $lastAge > 1800): ?><br><span style="color:#b45309">Lebih dari 30 menit tidak berjalan — cek Cron Job di cPanel.</span><?php endif; ?>
                        <?php else: ?>
                            Belum pernah berjalan otomatis<?php echo $autoOn ? ' — pastikan Cron Job di cPanel sudah dibuat.' : '.'; ?>
                        <?php endif; ?>
                    </small>
                </div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="act" value="toggle_auto">
                    <?php if (!$autoOn): ?><input type="hidden" name="auto_on" value="1"><?php endif; ?>
                    <button type="submit" class="cbx-btn <?php echo $autoOn ? 'ghost' : ''; ?>"><?php echo $autoOn ? 'Matikan' : 'Aktifkan'; ?></button>
                </form>
            </div>
            <?php
            $pushRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_enabled'");
            $pushOn = ($pushRow['setting_value'] ?? '0') === '1';
            $pushSince = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_since'");
            ?>
            <div class="cbx-status" style="margin-bottom:.75rem;flex-wrap:wrap">
                <span class="cbx-dot <?php echo $pushOn ? 'on' : ''; ?>"></span>
                <div style="flex:1;min-width:220px">
                    <b>Kirim ke Cloudbeds: <?php echo $pushOn ? 'AKTIF' : 'mati'; ?></b>
                    <small>Check-in / check-out dari sistem, booking direct baru (walk-in, telepon, website) dibuat & ditempatkan di kamar yang sama, dan pembatalannya.
                        <?php if ($pushOn && !empty($pushSince['setting_value'])): ?>Booking direct dibuat sejak <?php echo htmlspecialchars(date('d M Y H:i', strtotime($pushSince['setting_value']))); ?>.<?php endif; ?></small>
                </div>
                <form method="post" style="margin:0" <?php echo $pushOn ? '' : 'onsubmit="return confirm(\'Aktifkan kirim ke Cloudbeds? Mulai sekarang booking direct baru tidak perlu diketik lagi di Cloudbeds.\')"'; ?>>
                    <input type="hidden" name="act" value="toggle_push">
                    <?php if (!$pushOn): ?><input type="hidden" name="push_on" value="1"><?php endif; ?>
                    <button type="submit" class="cbx-btn <?php echo $pushOn ? 'ghost' : ''; ?>"><?php echo $pushOn ? 'Matikan' : 'Aktifkan'; ?></button>
                </form>
            </div>
            <?php
            $pErrRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_last_push_error'");
            $pOkRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_last_push_ok'");
            $pErr = json_decode((string)($pErrRow['setting_value'] ?? ''), true);
            $pOkAt = (string)($pOkRow['setting_value'] ?? '');
            if (!empty($pErr['at']) && ($pOkAt === '' || $pErr['at'] > $pOkAt)): ?>
                <div class="cbx-note" style="margin-bottom:.75rem;border-color:#fecaca;background:rgba(220,38,38,.06);color:#991b1b">
                    <b>Kiriman ke Cloudbeds gagal</b> (<?php echo htmlspecialchars(date('d M H:i', strtotime($pErr['at']))); ?>):<br>
                    <?php foreach ((array)($pErr['errors'] ?? []) as $pe): ?>• <?php echo htmlspecialchars($pe); ?><br><?php endforeach; ?>
                    <small>Akan dicoba lagi otomatis. Kirim pesan ini ke admin sistem bila terus muncul.</small>
                </div>
            <?php endif; ?>
            <?php
            $payRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_enabled'");
            $payOn = ($payRow['setting_value'] ?? '0') === '1';
            $paySince = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_since'");
            ?>
            <div class="cbx-status" style="margin-bottom:.75rem;flex-wrap:wrap">
                <span class="cbx-dot <?php echo $payOn ? 'on' : ''; ?>"></span>
                <div style="flex:1;min-width:220px">
                    <b>Kirim pembayaran ke Cloudbeds: <?php echo $payOn ? 'AKTIF' : 'mati'; ?></b>
                    <small>DP, pelunasan & bayar saat check-in yang dicatat di sistem ikut masuk folio reservasi Cloudbeds (titik merah sisa tagihan hilang). Pembayaran OTA otomatis tidak dikirim.
                        <?php if ($payOn && !empty($paySince['setting_value'])): ?>Pembayaran dicatat sejak <?php echo htmlspecialchars(date('d M Y H:i', strtotime($paySince['setting_value']))); ?>.<?php endif; ?></small>
                </div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="act" value="toggle_pay">
                    <?php if (!$payOn): ?><input type="hidden" name="pay_on" value="1"><?php endif; ?>
                    <button type="submit" class="cbx-btn <?php echo $payOn ? 'ghost' : ''; ?>"><?php echo $payOn ? 'Matikan' : 'Aktifkan'; ?></button>
                </form>
            </div>
            <details style="margin:-.25rem 0 .75rem"><summary class="cbx-hint" style="cursor:pointer">Cara memasang Cron Job (sekali saja)</summary>
                <div class="cbx-note" style="margin-top:.4rem">
                    cPanel → <b>Cron Jobs</b> → Add New Cron Job → Common Settings: <b>Once Per Five Minutes</b> (*/5 * * * *) → Command:<br>
                    <code style="display:block;margin-top:.35rem;padding:.4rem .5rem;border-radius:6px;background:rgba(15,23,42,.06);word-break:break-all;user-select:all">/usr/local/bin/php <?php echo htmlspecialchars(dirname(dirname(__DIR__))); ?>/cron/cloudbeds-sync.php >> <?php echo htmlspecialchars(dirname(dirname(dirname(__DIR__)))); ?>/cloudbeds_sync_log.txt 2>&amp;1</code>
                    Aturannya sama dengan tombol <b>Jalankan sinkron</b> (check-in 14 hari lalu s/d 120 hari ke depan); yang "Perlu dicek" tidak dijalankan dan tetap terlihat di pratinjau.
                </div>
            </details>
            <form method="get" class="cbx-row" style="margin-top:0">
                <input type="hidden" name="plan" value="1">
                <label class="cbx-label" style="margin:0">Check-in dari</label>
                <input type="date" class="cbx-input" name="sf" value="<?php echo htmlspecialchars($sf); ?>" style="width:150px">
                <label class="cbx-label" style="margin:0">sampai</label>
                <input type="date" class="cbx-input" name="st" value="<?php echo htmlspecialchars($st); ?>" style="width:150px">
                <button type="submit" class="cbx-btn ghost">Pratinjau sinkron</button>
            </form>
            <?php if ($plan && !$plan['ok']): ?>
                <div class="cbx-note" style="margin-top:.6rem;border-color:#fecaca;color:#b91c1c">Gagal: <?php echo htmlspecialchars($plan['detail']); ?></div>
            <?php elseif ($plan): $c = $plan['counts']; ?>
                <div class="cbx-row">
                    <span class="cbx-pill ok"><?php echo (int)$c['create']; ?> booking baru</span>
                    <span class="cbx-pill"><?php echo (int)$c['link']; ?> ditautkan</span>
                    <span class="cbx-pill bad"><?php echo (int)$c['cancel']; ?> dibatalkan</span>
                    <span class="cbx-pill"><?php echo (int)$c['block']; ?> blok kamar<?php echo $c['unblock'] ? ' · ' . (int)$c['unblock'] . ' dicabut' : ''; ?></span>
                    <?php if ($c['push_status'] + $c['push_create'] + $c['push_block'] + $c['push_pay'] > 0): ?><span class="cbx-pill ok">→ Cloudbeds: <?php echo (int)$c['push_status']; ?> status · <?php echo (int)$c['push_create']; ?> booking · <?php echo (int)$c['push_block']; ?> blok · <?php echo (int)$c['push_pay']; ?> bayar</span><?php endif; ?>
                    <span class="cbx-pill warn"><?php echo (int)$c['warn']; ?> perlu dicek</span>
                </div>
                <?php if ($plan['actions']): ?>
                    <div style="overflow-x:auto;margin-top:.6rem">
                        <table class="cbx-tbl">
                            <thead><tr><th>Aksi</th><th>Reservasi Cloudbeds</th><th>Keterangan</th></tr></thead>
                            <tbody>
                                <?php foreach ($plan['actions'] as $a): $tl = $typeLabel[$a['type']]; ?>
                                    <tr>
                                        <td><span class="cbx-pill <?php echo $tl[1]; ?>"><?php echo $tl[0]; ?></span></td>
                                        <td><b><?php echo htmlspecialchars($a['label']); ?></b><br><small>#<?php echo htmlspecialchars($a['cb']); ?></small></td>
                                        <td><?php echo htmlspecialchars($a['msg']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($c['create'] + $c['link'] + $c['cancel'] + $c['block'] + $c['unblock'] + $c['push_status'] + $c['push_create'] + $c['push_block'] + $c['push_pay'] > 0): ?>
                    <form method="post" class="cbx-row" data-msg="<?php echo htmlspecialchars('Jalankan sinkron sekarang? ' . (int)$c['create'] . ' booking baru, ' . (int)$c['link'] . ' ditautkan, ' . (int)$c['cancel'] . ' dibatalkan, ' . (int)$c['block'] . ' blok kamar, ' . (int)$c['unblock'] . ' blok dicabut, ' . (int)$c['push_status'] . ' status, ' . (int)$c['push_create'] . ' booking, ' . (int)$c['push_block'] . ' blok & ' . (int)$c['push_pay'] . ' pembayaran dikirim ke Cloudbeds.'); ?>" onsubmit="return confirm(this.dataset.msg)">
                        <input type="hidden" name="act" value="run_sync">
                        <input type="hidden" name="sf" value="<?php echo htmlspecialchars($sf); ?>">
                        <input type="hidden" name="st" value="<?php echo htmlspecialchars($st); ?>">
                        <button type="submit" class="cbx-btn">Jalankan sinkron</button>
                        <span class="cbx-hint" style="margin:0">"Perlu dicek" tidak dijalankan — selesaikan manual.</span>
                    </form>
                <?php else: ?>
                    <p class="cbx-hint">Tidak ada yang perlu dijalankan.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($syncLog): ?>
                <details style="margin-top:.7rem"><summary class="cbx-hint" style="cursor:pointer">Riwayat sinkron</summary>
                    <table class="cbx-tbl" style="margin-top:.4rem">
                        <thead><tr><th>Waktu</th><th>Rentang</th><th>Baru</th><th>Taut</th><th>Batal</th><th>Dicek</th></tr></thead>
                        <tbody>
                            <?php foreach ($syncLog as $lg): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('d M H:i', strtotime($lg['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars($lg['range_from'] . ' → ' . $lg['range_to']); ?></td>
                                    <td><?php echo (int)$lg['created']; ?></td>
                                    <td><?php echo (int)$lg['linked']; ?></td>
                                    <td><?php echo (int)$lg['cancelled']; ?></td>
                                    <td><?php echo (int)$lg['warnings']; ?><?php echo $lg['detail'] ? ' <span class="cbx-pill bad" title="' . htmlspecialchars($lg['detail']) . '">error</span>' : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($cb->isConfigured()):
        // ---- Harga & ketersediaan dari Cloudbeds ----
        $rateSvc = new CloudbedsRates($db, $cb);
        $rFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['rf'] ?? '') ? $_GET['rf'] : date('Y-m-d');
        $rDays = 14;
        $rDates = [];
        for ($i = 0; $i < $rDays; $i++) $rDates[] = date('Y-m-d', strtotime($rFrom . " +$i days"));
        $rGrid = $rateSvc->grid($rDates[0], end($rDates));
        $rLast = $rateSvc->lastFetched();
        $netKey = (string)($_GET['net'] ?? '');
        $netFee = 0.0;
        $netName = '';
        foreach ($localSources as $ls) {
            if ($ls['source_key'] === $netKey) { $netFee = (float)$ls['fee_percent']; $netName = $ls['source_name']; }
        }
        $rp = fn($v) => $v === null ? '—' : number_format($v / 1000, 0, ',', '.') . 'k';
    ?>
        <div class="cbx-card" id="rates">
            <h3>Harga &amp; ketersediaan Cloudbeds</h3>
            <p class="cbx-sub">Harga per malam & sisa kamar dari Cloudbeds (sumber harga OTA). Dipakai otomatis sebagai harga kamar di form reservasi baru. Diperbarui tiap jam oleh cron.</p>
            <div class="cbx-row" style="margin-top:0;justify-content:space-between">
                <form method="get" class="cbx-row" style="margin:0">
                    <input type="hidden" name="rates" value="1">
                    <label class="cbx-label" style="margin:0">Mulai</label>
                    <input type="date" class="cbx-input" name="rf" value="<?php echo htmlspecialchars($rFrom); ?>" style="width:150px">
                    <label class="cbx-label" style="margin:0">Harga bersih</label>
                    <select class="cbx-input" name="net" style="width:170px">
                        <option value="">Harga Cloudbeds (kotor)</option>
                        <?php foreach ($localSources as $ls): if ((float)$ls['fee_percent'] <= 0) continue; ?>
                            <option value="<?php echo htmlspecialchars($ls['source_key']); ?>" <?php echo $ls['source_key'] === $netKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($ls['source_name']); ?> −<?php echo rtrim(rtrim(number_format((float)$ls['fee_percent'], 2, ',', ''), '0'), ','); ?>%</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="cbx-btn ghost">Tampilkan</button>
                </form>
                <form method="post" style="margin:0">
                    <input type="hidden" name="act" value="refresh_rates">
                    <button type="submit" class="cbx-btn">Perbarui dari Cloudbeds</button>
                </form>
            </div>
            <p class="cbx-hint" style="margin:.4rem 0 .6rem">Terakhir diperbarui: <?php echo $rLast ? htmlspecialchars(date('d M Y H:i', strtotime($rLast))) : 'belum pernah — klik Perbarui'; ?><?php echo $netName ? ' · menampilkan harga bersih ' . htmlspecialchars($netName) . ' (setelah fee ' . rtrim(rtrim(number_format($netFee, 2, ',', ''), '0'), ',') . '%)' : ''; ?></p>
            <?php if (!$rGrid): ?>
                <div class="cbx-note">Belum ada data harga untuk tanggal ini. Klik <b>Perbarui dari Cloudbeds</b>.</div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="cbx-tbl" style="min-width:900px">
                        <thead><tr><th>Tipe kamar</th>
                            <?php foreach ($rDates as $d): $w = (int)date('N', strtotime($d)); ?>
                                <th style="text-align:center;<?php echo $w >= 6 ? 'background:#7f1d1d !important' : ''; ?>"><?php echo date('D', strtotime($d)); ?><br><?php echo date('d/m', strtotime($d)); ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($rGrid as $tname => $days): ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($tname); ?></b><?php $anyPlan = current($days)['plan'] ?? ''; echo $anyPlan ? '<br><small>' . htmlspecialchars($anyPlan) . '</small>' : ''; ?></td>
                                    <?php foreach ($rDates as $d): $c = $days[$d] ?? null;
                                        $rate = $c['rate'] ?? null;
                                        if ($rate !== null && $netFee > 0) $rate = $rate * (1 - $netFee / 100);
                                        $av = $c['available'] ?? null; ?>
                                        <td style="text-align:center;white-space:nowrap">
                                            <b><?php echo $rp($rate); ?></b><br>
                                            <?php if ($av === null): ?><small>—</small>
                                            <?php elseif ($av <= 0): ?><span class="cbx-pill bad">penuh</span>
                                            <?php else: ?><span class="cbx-pill <?php echo $av <= 1 ? 'warn' : 'ok'; ?>"><?php echo $av; ?> sisa</span><?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="cbx-hint">Harga dalam ribuan rupiah (850k = Rp 850.000). Tipe kamar bertanda # belum dipasangkan di Pemetaan tipe kamar.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
