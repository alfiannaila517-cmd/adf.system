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
    }
    header('Location: cloudbeds.php' . (in_array($act, ['save_key', 'save_type_map'], true) ? '?test=1' : ''));
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
                <h3>Sumber booking & komisi</h3>
                <p class="cbx-sub">Komisi Cloudbeds dibandingkan dengan fee OTA di sistem (dipakai untuk harga bersih di buku kas).</p>
                <table class="cbx-tbl">
                    <thead><tr><th>Cloudbeds</th><th>Komisi</th><th>Di sistem</th><th>Fee</th></tr></thead>
                    <tbody>
                        <?php foreach ($test['sources'] as $s):
                            $ls = null;
                            foreach ($localSources as $l) {
                                $a = $norm($l['source_name']);
                                $b = $norm($s['name']);
                                if ($a !== '' && $b !== '' && (strpos($b, $a) !== false || strpos($a, $b) !== false || $norm($l['source_key']) === $b)) { $ls = $l; break; }
                            }
                            $diff = $ls && $s['commission'] !== null && abs((float)$ls['fee_percent'] - (float)$s['commission']) > 0.01; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['name']); ?></td>
                                <td><?php echo $s['commission'] !== null ? rtrim(rtrim(number_format($s['commission'], 2, ',', ''), '0'), ',') . '%' : '—'; ?></td>
                                <td><?php echo $ls ? htmlspecialchars($ls['source_name']) : '<span class="cbx-pill warn">Belum ada</span>'; ?></td>
                                <td><?php echo $ls ? rtrim(rtrim(number_format((float)$ls['fee_percent'], 2, ',', ''), '0'), ',') . '%' . ($diff ? ' <span class="cbx-pill warn">beda</span>' : '') : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="cbx-card">
            <div class="cbx-note">
                <b>Langkah berikutnya:</b> bila tipe & nomor kamar sudah <b>Cocok</b>, tahap 2 menyambungkan booking OTA dari Cloudbeds ke Reservasi/Kalender secara otomatis.
                Yang masih <b>Tidak ada / beda</b> sebaiknya disamakan dulu (nama tipe kamar atau nomor kamar) di Cloudbeds atau di Pengaturan Front Desk.
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
