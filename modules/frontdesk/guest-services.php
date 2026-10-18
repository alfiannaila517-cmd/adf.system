<?php

/**
 * FRONT DESK - PENGATURAN LAYANAN TAMU
 * Halaman yang dibuka tamu dari link di pesan welcome check-in. Satu formulir, beberapa tab:
 * Umum (link, Front Office, statistik) · WiFi · Trip · Sewa Motor · Sewa Mobil · Sewa Taxi.
 * Flyer tiap layanan dikelola di tab layanannya; satu tombol Simpan menyimpan semuanya.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WhatsAppHelper.php';
require_once '../../includes/GuestServices.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/dashboard.php');
    exit;
}

$db = Database::getInstance();
GuestServices::ensureTables($db);
$s = GuestServices::settings($db);
$flyers = GuestServices::flyers($s);
$customs = GuestServices::customs($s);
$tabKeys = array_merge(['general', 'wifi', 'trip', 'motor', 'taxi'], array_map(fn($c) => 'x_' . $c['id'], $customs), ['add']);

// ---- Simpan (PRG): semua kolom + operasi flyer + unggahan dalam satu kiriman ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = in_array($_POST['tab'] ?? '', $tabKeys, true) ? $_POST['tab'] : 'general';
    $errors = [];
    $uploaded = 0;
    try {
        $text = ['gs_fo_wa', 'gs_fo_hours', 'gs_trip_title', 'gs_trip_chips', 'gs_wifi_ssid', 'gs_wifi_pass', 'gs_wifi_note',
            'gs_motor_unit', 'gs_motor_notes', 'gs_taxi_unit', 'gs_taxi_notes',
            'gs_trip_title_en', 'gs_trip_chips_en', 'gs_motor_notes_en', 'gs_taxi_notes_en'];
        foreach ($text as $k) GuestServices::save($db, $k, trim((string)($_POST[$k] ?? '')));
        foreach (['gs_motor_price', 'gs_taxi_price', 'gs_trip_price'] as $k) GuestServices::save($db, $k, preg_replace('/[^\d]/', '', (string)($_POST[$k] ?? '')));
        foreach (['gs_enabled', 'gs_motor_enabled', 'gs_taxi_enabled'] as $k) GuestServices::save($db, $k, !empty($_POST[$k]) ? '1' : '0');

        // Foto motor
        if (!empty($_FILES['motor_photo']['name'])) {
            try {
                $new = GuestServices::saveImage($_FILES['motor_photo'], 1000);
                GuestServices::deleteImage($s['gs_motor_photo']);
                GuestServices::save($db, 'gs_motor_photo', $new);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        } elseif (!empty($_POST['remove_motor_photo'])) {
            GuestServices::deleteImage($s['gs_motor_photo']);
            GuestServices::save($db, 'gs_motor_photo', '');
        }

        // Judul flyer yang sudah ada
        foreach ($flyers as $i => &$f) {
            if (isset($_POST['flyer_title'][$i])) $f['title'] = trim((string)$_POST['flyer_title'][$i]);
            if (isset($_POST['flyer_title_en'][$i])) $f['title_en'] = trim((string)$_POST['flyer_title_en'][$i]);
        }
        unset($f);

        // Hapus / naikkan (di dalam kategori yang sama)
        if (preg_match('/^(del|up):(\d+)$/', (string)($_POST['flyer_op'] ?? ''), $m) && isset($flyers[(int)$m[2]])) {
            $i = (int)$m[2];
            if ($m[1] === 'del') {
                GuestServices::deleteImage($flyers[$i]['path']);
                array_splice($flyers, $i, 1);
            } else {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($flyers[$j]['cat'] === $flyers[$i]['cat']) {
                        [$flyers[$j], $flyers[$i]] = [$flyers[$i], $flyers[$j]];
                        break;
                    }
                }
            }
        }

        // Unggahan baru: flyer_up[<kategori>][] (boleh banyak sekaligus)
        foreach ($_FILES['flyer_up']['name'] ?? [] as $cat => $names) {
            if (!isset(GuestServices::FLYER_CATS[$cat]) && !in_array($cat, array_map(fn($c) => 'x_' . $c['id'], $customs), true)) continue;
            foreach ($names as $k => $nm) {
                if ($nm === '') continue;
                $file = ['name' => $nm, 'type' => $_FILES['flyer_up']['type'][$cat][$k], 'tmp_name' => $_FILES['flyer_up']['tmp_name'][$cat][$k],
                    'error' => $_FILES['flyer_up']['error'][$cat][$k], 'size' => $_FILES['flyer_up']['size'][$cat][$k]];
                try {
                    $flyers[] = ['path' => GuestServices::saveImage($file), 'title' => '', 'title_en' => '', 'cat' => $cat];
                    $uploaded++;
                } catch (\RuntimeException $e) {
                    $errors[] = $nm . ': ' . $e->getMessage();
                }
            }
        }
        // Layanan tambahan: ubah, hapus, tambah
        foreach ($customs as &$c) {
            $in = $_POST['custom'][$c['id']] ?? null;
            if (!is_array($in)) continue;
            $c['name'] = trim((string)($in['name'] ?? $c['name']));
            $c['name_en'] = trim((string)($in['name_en'] ?? ''));
            $c['icon'] = in_array($in['icon'] ?? '', GuestServices::CUSTOM_ICONS, true) ? $in['icon'] : $c['icon'];
            $c['enabled'] = !empty($in['enabled']) ? '1' : '0';
            $c['price'] = preg_replace('/[^\d]/', '', (string)($in['price'] ?? ''));
            $c['unit'] = trim((string)($in['unit'] ?? ''));
            $c['notes'] = trim((string)($in['notes'] ?? ''));
            $c['notes_en'] = trim((string)($in['notes_en'] ?? ''));
        }
        unset($c);
        if (preg_match('/^del:([a-z0-9]+)$/', (string)($_POST['custom_op'] ?? ''), $m)) {
            $customs = array_values(array_filter($customs, fn($c) => $c['id'] !== $m[1]));
            foreach ($flyers as $fl) {
                if ($fl['cat'] === 'x_' . $m[1]) GuestServices::deleteImage($fl['path']);
            }
            $flyers = array_values(array_filter($flyers, fn($fl) => $fl['cat'] !== 'x_' . $m[1]));
            $tab = 'general';
        }
        $newName = trim((string)($_POST['new_custom_name'] ?? ''));
        if ($newName !== '') {
            $nid = 's' . base_convert((string)time(), 10, 36) . substr(bin2hex(random_bytes(2)), 0, 3);
            $icon = in_array($_POST['new_custom_icon'] ?? '', GuestServices::CUSTOM_ICONS, true) ? $_POST['new_custom_icon'] : '⭐';
            $customs[] = ['id' => $nid, 'name' => $newName, 'name_en' => trim((string)($_POST['new_custom_name_en'] ?? '')), 'icon' => $icon, 'enabled' => '1',
                'price' => '', 'unit' => '', 'notes' => '', 'notes_en' => ''];
            $tab = 'x_' . $nid;
        }
        GuestServices::save($db, 'gs_custom', json_encode($customs, JSON_UNESCAPED_UNICODE));
        GuestServices::save($db, 'gs_flyers', json_encode($flyers, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
    }
    if ($errors) setFlash('error', implode(' · ', $errors));
    else setFlash('success', 'Pengaturan Layanan Tamu tersimpan.' . ($uploaded ? ' (' . $uploaded . ' flyer diunggah)' : ''));
    header('Location: guest-services.php?tab=' . $tab);
    exit;
}

$activeTab = in_array($_GET['tab'] ?? '', $tabKeys, true) ? $_GET['tab'] : 'general';
$prices = ['motor' => GuestServices::motorPrice($db, $s), 'taxi' => GuestServices::taxiPrice($db, $s)];
$foNumber = GuestServices::foNumber($db, $s);

// Statistik 30 hari terakhir
$stat = ['links' => 0, 'open' => 0, 'wa_trip' => 0, 'wa_motor' => 0, 'wa_taxi' => 0, 'wa_svc' => 0, 'wa_fo' => 0, 'save_flyer' => 0];
try {
    $stat['links'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM guest_service_links WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['c'] ?? 0);
    foreach ($db->fetchAll("SELECT event, COUNT(*) c FROM guest_service_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY event") ?: [] as $r) {
        if (isset($stat[$r['event']])) $stat[$r['event']] = (int)$r['c'];
    }
} catch (\Throwable $e) {
}

// Definisi tab (ikon garis 24px)
$ic = [
    'general' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'wifi' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'trip' => '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
    'motor' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M12 17.5 9 9h4l2 4h3.5"/><path d="M9 9H7"/>',
    'taxi' => '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/><path d="M10 4h4"/>',
];
$tabs = [
    'general' => ['Umum', null],
    'wifi' => ['WiFi', $s['gs_wifi_ssid'] !== ''],
    'trip' => ['Trip', (bool)array_filter($flyers, fn($f) => $f['cat'] === 'trip')],
    'motor' => ['Sewa Motor', $s['gs_motor_enabled'] === '1'],
    'taxi' => ['Sewa Taxi', $s['gs_taxi_enabled'] === '1'],
];
$vehicle = [
    'motor' => ['unit_ph' => 'hari', 'notes_ph' => "Helm disediakan\nBawa KTP / paspor untuk jaminan\nBisa diantar ke hotel", 'word' => 'motor', 'off' => 'Matikan bila tidak menawarkan sewa motor.'],
    'taxi' => ['unit_ph' => 'trip', 'notes_ph' => "Antar-jemput pelabuhan / bandara\nPesan sehari sebelumnya", 'word' => 'taxi', 'off' => 'Matikan bila tidak menawarkan taxi / antar-jemput.'],
];

/** Daftar flyer + tombol unggah untuk satu kategori. */
function gsFlyerBox(array $flyers, string $cat): void
{
    $n = 0;
    echo '<div class="gs-sec-h">Flyer</div>';
    foreach ($flyers as $i => $f) {
        if ($f['cat'] !== $cat) continue;
        $n++; ?>
        <div class="gs-fl">
            <img src="<?php echo htmlspecialchars(GuestServices::fileUrl($f['path'])); ?>" alt="">
            <span class="gs-2t">
                <input class="gs-in" name="flyer_title[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($f['title']); ?>" maxlength="80" placeholder="Judul (Indonesia, opsional)">
                <input class="gs-in" name="flyer_title_en[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($f['title_en']); ?>" maxlength="80" placeholder="Title (English, optional)">
            </span>
            <button type="submit" name="flyer_op" value="up:<?php echo $i; ?>" class="gs-ic" title="Naikkan" formnovalidate>↑</button>
            <button type="submit" name="flyer_op" value="del:<?php echo $i; ?>" class="gs-ic bad" title="Hapus flyer" formnovalidate onclick="return confirm('Hapus flyer ini?')">✕</button>
        </div>
    <?php }
    if (!$n) echo '<div class="gs-empty">Belum ada flyer.</div>';
    ?>
    <label class="gs-up">
        <input type="file" name="flyer_up[<?php echo $cat; ?>][]" accept="image/jpeg,image/png,image/webp" multiple data-autosubmit>
        <span>+ Pilih flyer…</span>
    </label>
    <div class="gs-note">JPG / PNG / WEBP, maks 6 MB. Boleh banyak sekaligus; otomatis diperkecil dan langsung diunggah. Beberapa flyer tampil sebagai slide.</div>
    <?php
}

$pageTitle = 'Layanan Tamu';
include '../../includes/header.php';
?>

<style>
    body[data-theme] .main-content .gs-wrap { max-width: 1100px; margin: 0 auto; padding-bottom: 1rem; }
    body[data-theme] .main-content .gs-wrap * { box-sizing: border-box; }
    .gs-top { display: flex; align-items: center; justify-content: space-between; gap: .6rem; flex-wrap: wrap; margin-bottom: .6rem; }
    body[data-theme] .main-content .gs-wrap h1 { margin: 0; font-size: .92rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap h1 small { display: block; font-size: .64rem !important; font-weight: 500; color: var(--fd-muted) !important; }
    .gs-tabs { display: flex; gap: 2px; padding: 3px; border-radius: 12px; background: var(--fd-tile); border: 1px solid var(--fd-line); overflow-x: auto; scrollbar-width: none; margin-bottom: .6rem; }
    .gs-tabs::-webkit-scrollbar { display: none; }
    .gs-tab { display: inline-flex; align-items: center; gap: 6px; padding: 0 .75rem; height: 30px; border: 0; border-radius: 9px; background: transparent; cursor: pointer; white-space: nowrap; font-size: .7rem; font-weight: 600; color: var(--fd-muted) !important; -webkit-text-fill-color: var(--fd-muted) !important; }
    .gs-tab svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .gs-tab.on { background: var(--fd-card); box-shadow: 0 1px 4px rgba(0, 0, 0, .15); color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    .gs-tab i { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }
    .gs-pane { display: none; }
    .gs-pane.on { display: block; }
    .gs-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .6rem; align-items: start; }
    body[data-theme] .main-content .gs-wrap .gs-card { border-radius: 14px; background: var(--fd-card); border: 1px solid var(--fd-edge); box-shadow: var(--fd-shadow); padding: .75rem .9rem; }
    .gs-sec + .gs-sec { margin-top: .75rem; padding-top: .7rem; border-top: 1px solid var(--fd-line); }
    .gs-sec-h { margin-bottom: .45rem; font-size: .6rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--fd-muted); }
    .gs-f { margin-bottom: .55rem; }
    .gs-f label.l { display: block; margin-bottom: .2rem; font-size: .64rem; font-weight: 600; color: var(--fd-text-2, var(--fd-text)); }
    body[data-theme] .main-content .gs-wrap .gs-in, body[data-theme] .main-content .gs-wrap select.gs-in { width: 100%; height: 28px; padding: 0 .55rem; border-radius: 8px; border: 1px solid var(--fd-input-border); background: var(--fd-input-bg) !important; color: var(--fd-text) !important; font-size: .7rem !important; }
    body[data-theme] .main-content .gs-wrap textarea.gs-in { height: auto; min-height: 70px; padding: .4rem .55rem; resize: vertical; line-height: 1.5; }
    .gs-r2 { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
    .gs-hint { display: block; margin-top: .2rem; font-size: .6rem; color: var(--fd-muted); line-height: 1.45; }
    .gs-note { margin-top: .4rem; font-size: .6rem; color: var(--fd-muted); line-height: 1.45; }
    /* sakelar */
    .gs-sw { display: flex; align-items: center; justify-content: space-between; gap: .6rem; padding: .45rem .6rem; margin-bottom: .6rem; border-radius: 10px; background: var(--fd-tile); border: 1px solid var(--fd-line); cursor: pointer; }
    .gs-sw b { display: block; font-size: .7rem; color: var(--fd-text); }
    .gs-sw small { font-size: .6rem; color: var(--fd-muted); }
    .gs-sw input { position: absolute; opacity: 0; pointer-events: none; }
    .gs-sw .t { position: relative; flex: none; width: 32px; height: 18px; border-radius: 999px; background: #94a3b8; transition: background .18s; }
    .gs-sw .t::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform .18s; }
    .gs-sw input:checked + .t { background: #22c55e; }
    .gs-sw input:checked + .t::after { transform: translateX(14px); }
    /* flyer */
    .gs-fl { display: flex; align-items: center; gap: .45rem; padding: .35rem; margin-bottom: .35rem; border: 1px solid var(--fd-line); border-radius: 10px; background: var(--fd-tile); }
    .gs-fl img { width: 36px; height: 48px; object-fit: cover; border-radius: 5px; flex: none; }
    .gs-2t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .gs-fl .gs-in { min-width: 0; }
    .gs-ic { flex: none; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--fd-line); border-radius: 7px; background: var(--fd-card); cursor: pointer; font-size: .72rem; color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    .gs-ic.bad { color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    .gs-empty { padding: .6rem; text-align: center; font-size: .66rem; color: var(--fd-muted); border: 1px dashed var(--fd-line); border-radius: 10px; margin-bottom: .4rem; }
    .gs-up { display: flex; align-items: center; justify-content: center; height: 32px; border: 1px dashed var(--fd-accent); border-radius: 10px; cursor: pointer; font-size: .7rem; font-weight: 600; color: var(--fd-accent-text) !important; background: var(--fd-accent-soft); }
    .gs-up input { display: none; }
    .gs-ph { max-width: 150px; border-radius: 8px; display: block; margin-bottom: .35rem; }
    /* chip & tombol */
    .gs-chips { display: flex; flex-wrap: wrap; gap: 5px; }
    .gs-chip { padding: 4px 9px; border-radius: 999px; border: 1px solid var(--fd-line); background: var(--fd-tile); font-size: .62rem; color: var(--fd-muted); }
    .gs-chip b { color: var(--fd-text); font-size: .72rem; margin-right: 3px; }
    body[data-theme] .main-content .gs-wrap .gs-btn { display: inline-flex; align-items: center; gap: .3rem; height: 28px; padding: 0 .7rem; border-radius: 8px; border: 1px solid var(--fd-line); cursor: pointer; font-size: .66rem !important; font-weight: 600; text-decoration: none; background: var(--fd-tile); color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-btn.pri { background: var(--fd-accent); border-color: transparent; color: #fff !important; -webkit-text-fill-color: #fff !important; height: 30px; padding: 0 1rem; }
    .gs-save { position: sticky; bottom: 0; z-index: 5; display: flex; justify-content: space-between; align-items: center; gap: .6rem; margin-top: .75rem; padding: .5rem .75rem; border-radius: 12px; background: var(--fd-card); border: 1px solid var(--fd-edge); box-shadow: 0 -4px 16px rgba(0, 0, 0, .12); }
    .gs-save span { font-size: .62rem; color: var(--fd-muted); }
    @media (max-width: 860px) { .gs-cols { grid-template-columns: 1fr; } }
</style>

<div class="gs-wrap">
    <?php $sxActiveKey = 'guest_services'; include __DIR__ . '/settings-nav.php'; ?>

    <div class="gs-top">
        <h1>Layanan Tamu <small>Halaman yang dibuka tamu dari link di pesan welcome check-in. Semua tombol membuka WhatsApp ke Front Office.</small></h1>
        <div style="display:flex;gap:.35rem">
            <a class="gs-btn" href="<?php echo BASE_URL; ?>/guest-services.php?k=preview&l=id" target="_blank" rel="noopener">Lihat contoh · ID</a>
            <a class="gs-btn" href="<?php echo BASE_URL; ?>/guest-services.php?k=preview&l=en" target="_blank" rel="noopener">EN</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" id="gsForm">
        <input type="hidden" name="tab" id="gsTab" value="<?php echo $activeTab; ?>">
        <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">Simpan</button>

        <nav class="gs-tabs" role="tablist">
            <?php foreach ($tabs as $k => [$label, $on]): ?>
                <button type="button" class="gs-tab<?php echo $k === $activeTab ? ' on' : ''; ?>" data-tab="<?php echo $k; ?>" role="tab">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $ic[$k]; ?></svg><?php echo $label; ?><?php echo $on ? '<i title="Aktif"></i>' : ''; ?>
                </button>
            <?php endforeach; ?>
            <?php foreach ($customs as $c): ?>
                <button type="button" class="gs-tab<?php echo $activeTab === 'x_' . $c['id'] ? ' on' : ''; ?>" data-tab="x_<?php echo $c['id']; ?>" role="tab">
                    <span style="font-size:.8rem"><?php echo $c['icon']; ?></span><?php echo htmlspecialchars($c['name'] !== '' ? $c['name'] : 'Layanan'); ?><?php echo $c['enabled'] === '1' ? '<i title="Aktif"></i>' : ''; ?>
                </button>
            <?php endforeach; ?>
            <button type="button" class="gs-tab<?php echo $activeTab === 'add' ? ' on' : ''; ?>" data-tab="add" role="tab"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Layanan</button>
        </nav>

        <!-- UMUM -->
        <div class="gs-pane<?php echo $activeTab === 'general' ? ' on' : ''; ?>" data-pane="general">
            <div class="gs-cols">
                <div class="gs-card">
                    <div class="gs-sec">
                        <div class="gs-sec-h">Link di pesan welcome</div>
                        <label class="gs-sw"><span><b>Sertakan link layanan tamu</b><small>Bila mati, pesan welcome dikirim tanpa link. Isi pesan diatur di <a href="whatsapp.php">Pengaturan WhatsApp</a>.</small></span>
                            <input type="checkbox" name="gs_enabled" value="1" <?php echo $s['gs_enabled'] === '1' ? 'checked' : ''; ?>><span class="t"></span></label>
                    </div>
                    <div class="gs-sec">
                        <div class="gs-sec-h">Front Office</div>
                        <div class="gs-r2">
                            <div class="gs-f"><label class="l" for="gsFo">WhatsApp Front Office</label>
                                <input class="gs-in" id="gsFo" name="gs_fo_wa" value="<?php echo htmlspecialchars($s['gs_fo_wa']); ?>" placeholder="0812xxxxxxx"></div>
                            <div class="gs-f"><label class="l" for="gsHours">Jam balas</label>
                                <input class="gs-in" id="gsHours" name="gs_fo_hours" value="<?php echo htmlspecialchars($s['gs_fo_hours']); ?>" placeholder="07.00–22.00"></div>
                        </div>
                        <span class="gs-hint">Tujuan semua tombol WhatsApp. Kosong = nomor telepon hotel<?php echo $foNumber !== '' ? ' (sekarang: ' . htmlspecialchars($foNumber) . ')' : ' — belum ada, tombol WhatsApp tidak tampil'; ?>.</span>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-sec-h">Statistik 30 hari terakhir</div>
                    <div class="gs-chips">
                        <span class="gs-chip"><b><?php echo $stat['links']; ?></b>Link dikirim</span>
                        <span class="gs-chip"><b><?php echo $stat['open']; ?></b>Dibuka</span>
                        <span class="gs-chip"><b><?php echo $stat['wa_trip']; ?></b>WA Trip</span>
                        <span class="gs-chip"><b><?php echo $stat['wa_motor']; ?></b>WA Motor</span>
                        <span class="gs-chip"><b><?php echo $stat['wa_taxi']; ?></b>WA Taxi</span>
                        <span class="gs-chip"><b><?php echo $stat['wa_svc']; ?></b>WA Layanan lain</span>
                        <span class="gs-chip"><b><?php echo $stat['wa_fo']; ?></b>WA Front Office</span>
                        <span class="gs-chip"><b><?php echo $stat['save_flyer']; ?></b>Simpan flyer</span>
                    </div>
                    <div class="gs-note">Seberapa sering tamu membuka halaman dan menekan tombol.</div>
                </div>
            </div>
        </div>

        <!-- WIFI -->
        <div class="gs-pane<?php echo $activeTab === 'wifi' ? ' on' : ''; ?>" data-pane="wifi">
            <div class="gs-card" style="max-width:520px">
                <div class="gs-sec-h">WiFi tamu</div>
                <div class="gs-r2">
                    <div class="gs-f"><label class="l" for="gsSsid">Nama WiFi (SSID)</label>
                        <input class="gs-in" id="gsSsid" name="gs_wifi_ssid" value="<?php echo htmlspecialchars($s['gs_wifi_ssid']); ?>" maxlength="60" autocomplete="off"></div>
                    <div class="gs-f"><label class="l" for="gsWpass">Password</label>
                        <input class="gs-in" id="gsWpass" name="gs_wifi_pass" value="<?php echo htmlspecialchars($s['gs_wifi_pass']); ?>" maxlength="63" autocomplete="off"></div>
                </div>
                <div class="gs-f"><label class="l" for="gsWnote">Catatan (opsional)</label>
                    <input class="gs-in" id="gsWnote" name="gs_wifi_note" value="<?php echo htmlspecialchars($s['gs_wifi_note']); ?>" maxlength="100" placeholder="Kuat di lobi &amp; area kamar"></div>
                <div class="gs-note">Kartu WiFi tampil di atas halaman tamu (salin password, buka pengaturan WiFi, QR). Kosongkan nama WiFi bila tidak ingin ditampilkan. Password terlihat oleh pemegang link tamu (link unik per tamu); kosong = WiFi terbuka tanpa password.</div>
            </div>
        </div>

        <!-- TRIP -->
        <div class="gs-pane<?php echo $activeTab === 'trip' ? ' on' : ''; ?>" data-pane="trip">
            <div class="gs-cols">
                <div class="gs-card">
                    <div class="gs-sec-h">Teks</div>
                    <div class="gs-f"><label class="l" for="gsTitle">Judul</label>
                        <input class="gs-in" id="gsTitle" name="gs_trip_title" value="<?php echo htmlspecialchars($s['gs_trip_title']); ?>" maxlength="80" placeholder="Paket Trip Karimunjawa"></div>
                    <div class="gs-f"><label class="l" for="gsTitleEn">Judul (English)</label>
                        <input class="gs-in" id="gsTitleEn" name="gs_trip_title_en" value="<?php echo htmlspecialchars($s['gs_trip_title_en']); ?>" maxlength="80" placeholder="Karimunjawa Trip Packages"></div>
                    <div class="gs-f"><label class="l" for="gsChips">Label singkat</label>
                        <input class="gs-in" id="gsChips" name="gs_trip_chips" value="<?php echo htmlspecialchars($s['gs_trip_chips']); ?>" placeholder="Island Hopping, Snorkeling, Sunset">
                        <span class="gs-hint">Pisahkan dengan koma. Tab Trip tampil otomatis setelah ada flyer.</span></div>
                    <div class="gs-f"><label class="l" for="gsChipsEn">Label singkat (English)</label>
                        <input class="gs-in" id="gsChipsEn" name="gs_trip_chips_en" value="<?php echo htmlspecialchars($s['gs_trip_chips_en']); ?>" placeholder="Island Hopping, Snorkeling, Sunset">
                        <span class="gs-hint">Teks English tampil untuk tamu berbahasa Inggris; bila kosong dipakai teks Indonesia di atas.</span></div>
                    <div class="gs-f"><label class="l" for="gsTripPrice">Harga mulai dari (website) · Rp</label>
                        <input class="gs-in" id="gsTripPrice" name="gs_trip_price" inputmode="numeric" value="<?php echo htmlspecialchars($s['gs_trip_price'] ?? ''); ?>" placeholder="275000">
                        <span class="gs-hint">Tampil di halaman Services website ("From IDR …"). Kosong = harga terendah katalog Hotel Service bertipe trip.</span></div>
                </div>
                <div class="gs-card"><?php gsFlyerBox($flyers, 'trip'); ?></div>
            </div>
        </div>

        <!-- MOTOR / MOBIL / TAXI -->
        <?php foreach ($vehicle as $vk => $v):
            $p = "gs_{$vk}"; ?>
            <div class="gs-pane<?php echo $activeTab === $vk ? ' on' : ''; ?>" data-pane="<?php echo $vk; ?>">
                <div class="gs-cols">
                    <div class="gs-card">
                        <label class="gs-sw"><span><b>Tampil di halaman tamu</b><small><?php echo $v['off']; ?></small></span>
                            <input type="checkbox" name="<?php echo $p; ?>_enabled" value="1" <?php echo $s["{$p}_enabled"] === '1' ? 'checked' : ''; ?>><span class="t"></span></label>
                        <div class="gs-r2">
                            <div class="gs-f"><label class="l" for="<?php echo $p; ?>P">Harga mulai dari (Rp)</label>
                                <input class="gs-in" id="<?php echo $p; ?>P" name="<?php echo $p; ?>_price" inputmode="numeric" value="<?php echo htmlspecialchars($s["{$p}_price"]); ?>" placeholder="otomatis"></div>
                            <div class="gs-f"><label class="l" for="<?php echo $p; ?>U">Satuan</label>
                                <input class="gs-in" id="<?php echo $p; ?>U" name="<?php echo $p; ?>_unit" value="<?php echo htmlspecialchars($s["{$p}_unit"]); ?>" maxlength="20" placeholder="<?php echo $v['unit_ph']; ?>"></div>
                        </div>
                        <span class="gs-hint" style="margin:-.25rem 0 .55rem">Harga kosong = otomatis dari harga terendah katalog Hotel Service bertipe <?php echo $v['word']; ?><?php echo $prices[$vk]['price'] > 0 && $s["{$p}_price"] === '' ? ' (sekarang: Rp ' . number_format($prices[$vk]['price'], 0, ',', '.') . ')' : ''; ?>; bila tidak ada, harga tidak ditampilkan.</span>
                        <div class="gs-f"><label class="l" for="<?php echo $p; ?>N">Syarat &amp; info (satu per baris)</label>
                            <textarea class="gs-in" id="<?php echo $p; ?>N" name="<?php echo $p; ?>_notes" placeholder="<?php echo htmlspecialchars($v['notes_ph']); ?>"><?php echo htmlspecialchars($s["{$p}_notes"]); ?></textarea></div>
                        <div class="gs-f"><label class="l" for="<?php echo $p; ?>NE">Syarat &amp; info — English (opsional)</label>
                            <textarea class="gs-in" id="<?php echo $p; ?>NE" name="<?php echo $p; ?>_notes_en" placeholder="Helmet provided&#10;Bring ID / passport as deposit"><?php echo htmlspecialchars($s["{$p}_notes_en"]); ?></textarea>
                            <span class="gs-hint">Tampil untuk tamu berbahasa Inggris; kosong = pakai teks Indonesia.</span></div>
                        <?php if ($vk === 'motor'): ?>
                            <div class="gs-f"><label class="l">Foto (dipakai bila belum ada flyer motor)</label>
                                <?php if ($s['gs_motor_photo'] !== ''): ?>
                                    <img class="gs-ph" src="<?php echo htmlspecialchars(GuestServices::fileUrl($s['gs_motor_photo'])); ?>" alt="">
                                    <label class="gs-hint"><input type="checkbox" name="remove_motor_photo" value="1"> Hapus foto ini</label>
                                <?php endif; ?>
                                <input type="file" name="motor_photo" accept="image/jpeg,image/png,image/webp" class="gs-in" style="height:auto;padding:.25rem .4rem">
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="gs-card"><?php gsFlyerBox($flyers, $vk); ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- LAYANAN TAMBAHAN -->
        <?php foreach ($customs as $c): $cid = $c['id']; $cn = "custom[$cid]"; ?>
            <div class="gs-pane<?php echo $activeTab === 'x_' . $cid ? ' on' : ''; ?>" data-pane="x_<?php echo $cid; ?>">
                <div class="gs-cols">
                    <div class="gs-card">
                        <label class="gs-sw"><span><b>Tampil di halaman tamu</b><small>Matikan sementara tanpa menghapus layanan.</small></span>
                            <input type="checkbox" name="<?php echo $cn; ?>[enabled]" value="1" <?php echo $c['enabled'] === '1' ? 'checked' : ''; ?>><span class="t"></span></label>
                        <div class="gs-r2">
                            <div class="gs-f"><label class="l">Nama layanan</label>
                                <input class="gs-in" name="<?php echo $cn; ?>[name]" value="<?php echo htmlspecialchars($c['name']); ?>" maxlength="40" placeholder="Laundry"></div>
                            <div class="gs-f"><label class="l">Nama (English)</label>
                                <input class="gs-in" name="<?php echo $cn; ?>[name_en]" value="<?php echo htmlspecialchars($c['name_en']); ?>" maxlength="40" placeholder="Laundry Service"></div>
                        </div>
                        <div class="gs-f"><label class="l">Ikon</label>
                            <select class="gs-in" name="<?php echo $cn; ?>[icon]" style="max-width:120px">
                                <?php foreach (GuestServices::CUSTOM_ICONS as $ico): ?><option value="<?php echo $ico; ?>" <?php echo $ico === $c['icon'] ? 'selected' : ''; ?>><?php echo $ico; ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="gs-r2">
                            <div class="gs-f"><label class="l">Harga mulai dari (Rp, opsional)</label>
                                <input class="gs-in" name="<?php echo $cn; ?>[price]" inputmode="numeric" value="<?php echo htmlspecialchars($c['price']); ?>" placeholder="mis. 10000"></div>
                            <div class="gs-f"><label class="l">Satuan</label>
                                <input class="gs-in" name="<?php echo $cn; ?>[unit]" value="<?php echo htmlspecialchars($c['unit']); ?>" maxlength="20" placeholder="kg"></div>
                        </div>
                        <div class="gs-f"><label class="l">Syarat &amp; info (satu per baris)</label>
                            <textarea class="gs-in" name="<?php echo $cn; ?>[notes]" placeholder="Selesai dalam 1 hari&#10;Titip di Front Office"><?php echo htmlspecialchars($c['notes']); ?></textarea></div>
                        <div class="gs-f"><label class="l">Syarat &amp; info — English (opsional)</label>
                            <textarea class="gs-in" name="<?php echo $cn; ?>[notes_en]" placeholder="Ready within 1 day&#10;Leave at the Front Office"><?php echo htmlspecialchars($c['notes_en']); ?></textarea>
                            <span class="gs-hint">Tampil untuk tamu berbahasa Inggris; kosong = pakai teks Indonesia.</span></div>
                        <button type="submit" name="custom_op" value="del:<?php echo $cid; ?>" class="gs-btn" formnovalidate onclick="return confirm('Hapus layanan ini beserta flyernya?')" style="color:#b91c1c !important;-webkit-text-fill-color:#b91c1c !important">Hapus layanan ini</button>
                    </div>
                    <div class="gs-card"><?php gsFlyerBox($flyers, 'x_' . $cid); ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- TAMBAH LAYANAN -->
        <div class="gs-pane<?php echo $activeTab === 'add' ? ' on' : ''; ?>" data-pane="add">
            <div class="gs-card" style="max-width:520px">
                <div class="gs-sec-h">Tambah layanan baru</div>
                <div class="gs-note" style="margin:-.2rem 0 .6rem">Mis. Laundry, Restoran, Spa, Sewa Perahu. Layanan baru muncul sebagai tab di halaman tamu, lengkap dengan flyer, harga, dan tombol WhatsApp.</div>
                <div class="gs-r2">
                    <div class="gs-f"><label class="l" for="gsNewName">Nama layanan</label>
                        <input class="gs-in" id="gsNewName" name="new_custom_name" maxlength="40" placeholder="Laundry"></div>
                    <div class="gs-f"><label class="l" for="gsNewNameEn">Nama (English)</label>
                        <input class="gs-in" id="gsNewNameEn" name="new_custom_name_en" maxlength="40" placeholder="Laundry Service"></div>
                </div>
                <div class="gs-f"><label class="l" for="gsNewIcon">Ikon</label>
                    <select class="gs-in" id="gsNewIcon" name="new_custom_icon" style="max-width:120px">
                        <?php foreach (GuestServices::CUSTOM_ICONS as $ico): ?><option value="<?php echo $ico; ?>"><?php echo $ico; ?></option><?php endforeach; ?>
                    </select></div>
                <button type="submit" class="gs-btn pri">Tambah layanan</button>
                <div class="gs-note">Setelah ditambah, atur harga, syarat, dan flyer-nya di tab layanan tersebut.</div>
            </div>
        </div>

        <div class="gs-save">
            <span>Satu tombol menyimpan semua tab.</span>
            <button type="submit" class="gs-btn pri">Simpan Pengaturan</button>
        </div>
    </form>
</div>

<script>
    (function() {
        const tabs = document.querySelectorAll('.gs-tab'), panes = document.querySelectorAll('.gs-pane'), hid = document.getElementById('gsTab');
        tabs.forEach(t => t.addEventListener('click', () => {
            tabs.forEach(x => x.classList.toggle('on', x === t));
            panes.forEach(p => p.classList.toggle('on', p.dataset.pane === t.dataset.tab));
            hid.value = t.dataset.tab;
            history.replaceState(null, '', '?tab=' + t.dataset.tab);
        }));
        // Pilih flyer → langsung unggah (sekaligus menyimpan isian lain)
        document.querySelectorAll('[data-autosubmit]').forEach(i => i.addEventListener('change', () => {
            if (!i.files.length) return;
            i.parentElement.querySelector('span').textContent = 'Mengunggah…';
            document.getElementById('gsForm').submit();
        }));
    })();
</script>

<?php include '../../includes/footer.php'; ?>
