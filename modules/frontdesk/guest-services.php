<?php

/**
 * FRONT DESK - PENGATURAN LAYANAN TAMU
 * Flyer trip, info sewa motor, nomor Front Office untuk halaman yang dibuka dari link di pesan welcome check-in.
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

// ---- Simpan (PRG) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? 'save');
    try {
        if ($act === 'save') {
            GuestServices::save($db, 'gs_enabled', !empty($_POST['gs_enabled']) ? '1' : '0');
            GuestServices::save($db, 'gs_fo_wa', trim((string)($_POST['gs_fo_wa'] ?? '')));
            GuestServices::save($db, 'gs_fo_hours', trim((string)($_POST['gs_fo_hours'] ?? '')));
            GuestServices::save($db, 'gs_trip_title', trim((string)($_POST['gs_trip_title'] ?? '')));
            GuestServices::save($db, 'gs_trip_chips', trim((string)($_POST['gs_trip_chips'] ?? '')));
            GuestServices::save($db, 'gs_wifi_ssid', trim((string)($_POST['gs_wifi_ssid'] ?? '')));
            GuestServices::save($db, 'gs_wifi_pass', trim((string)($_POST['gs_wifi_pass'] ?? '')));
            GuestServices::save($db, 'gs_wifi_note', trim((string)($_POST['gs_wifi_note'] ?? '')));
            GuestServices::save($db, 'gs_car_enabled', !empty($_POST['gs_car_enabled']) ? '1' : '0');
            GuestServices::save($db, 'gs_car_price', preg_replace('/[^\d]/', '', (string)($_POST['gs_car_price'] ?? '')));
            GuestServices::save($db, 'gs_car_unit', trim((string)($_POST['gs_car_unit'] ?? '')));
            GuestServices::save($db, 'gs_car_notes', trim((string)($_POST['gs_car_notes'] ?? '')));
            GuestServices::save($db, 'gs_taxi_enabled', !empty($_POST['gs_taxi_enabled']) ? '1' : '0');
            GuestServices::save($db, 'gs_taxi_price', preg_replace('/[^\d]/', '', (string)($_POST['gs_taxi_price'] ?? '')));
            GuestServices::save($db, 'gs_taxi_unit', trim((string)($_POST['gs_taxi_unit'] ?? '')));
            GuestServices::save($db, 'gs_taxi_notes', trim((string)($_POST['gs_taxi_notes'] ?? '')));
            GuestServices::save($db, 'gs_motor_enabled', !empty($_POST['gs_motor_enabled']) ? '1' : '0');
            GuestServices::save($db, 'gs_motor_price', preg_replace('/[^\d]/', '', (string)($_POST['gs_motor_price'] ?? '')));
            GuestServices::save($db, 'gs_motor_unit', trim((string)($_POST['gs_motor_unit'] ?? '')));
            GuestServices::save($db, 'gs_motor_notes', trim((string)($_POST['gs_motor_notes'] ?? '')));
            // Judul flyer yang ada
            foreach ($flyers as $i => &$f) {
                if (isset($_POST['flyer_title'][$i])) $f['title'] = trim((string)$_POST['flyer_title'][$i]);
            }
            unset($f);
            GuestServices::save($db, 'gs_flyers', json_encode($flyers, JSON_UNESCAPED_UNICODE));
            // Foto motor (opsional) di form yang sama
            if (!empty($_FILES['motor_photo']['name'])) {
                $new = GuestServices::saveImage($_FILES['motor_photo'], 1000);
                GuestServices::deleteImage($s['gs_motor_photo']);
                GuestServices::save($db, 'gs_motor_photo', $new);
            } elseif (!empty($_POST['remove_motor_photo'])) {
                GuestServices::deleteImage($s['gs_motor_photo']);
                GuestServices::save($db, 'gs_motor_photo', '');
            }
            setFlash('success', 'Pengaturan Layanan Tamu tersimpan.');
        } elseif ($act === 'add_flyer') {
            $path = GuestServices::saveImage($_FILES['flyer'] ?? []);
            $cat = isset(GuestServices::FLYER_CATS[$_POST['new_cat'] ?? '']) ? $_POST['new_cat'] : 'trip';
            $flyers[] = ['path' => $path, 'title' => trim((string)($_POST['new_title'] ?? '')), 'cat' => $cat];
            GuestServices::save($db, 'gs_flyers', json_encode($flyers, JSON_UNESCAPED_UNICODE));
            setFlash('success', 'Flyer ditambahkan.');
        } elseif ($act === 'del_flyer') {
            $i = (int)($_POST['idx'] ?? -1);
            if (isset($flyers[$i])) {
                GuestServices::deleteImage($flyers[$i]['path']);
                array_splice($flyers, $i, 1);
                GuestServices::save($db, 'gs_flyers', json_encode($flyers, JSON_UNESCAPED_UNICODE));
                setFlash('success', 'Flyer dihapus.');
            }
        } elseif ($act === 'move_flyer') {
            $i = (int)($_POST['idx'] ?? -1);
            if ($i > 0 && isset($flyers[$i])) {
                [$flyers[$i - 1], $flyers[$i]] = [$flyers[$i], $flyers[$i - 1]];
                GuestServices::save($db, 'gs_flyers', json_encode($flyers, JSON_UNESCAPED_UNICODE));
            }
        }
    } catch (\RuntimeException $e) {
        setFlash('error', $e->getMessage());
    }
    header('Location: guest-services.php');
    exit;
}

$mp = GuestServices::motorPrice($db, $s);
$cp = GuestServices::carPrice($db, $s);
$xp = GuestServices::taxiPrice($db, $s);
$foNumber = GuestServices::foNumber($db, $s);
// Statistik 30 hari terakhir
$stat = ['links' => 0, 'open' => 0, 'wa_trip' => 0, 'wa_motor' => 0, 'wa_car' => 0, 'wa_taxi' => 0, 'wa_fo' => 0, 'save_flyer' => 0];
try {
    $stat['links'] = (int)($db->fetchOne("SELECT COUNT(*) c FROM guest_service_links WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['c'] ?? 0);
    foreach ($db->fetchAll("SELECT event, COUNT(*) c FROM guest_service_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY event") ?: [] as $r) {
        if (isset($stat[$r['event']])) $stat[$r['event']] = (int)$r['c'];
    }
} catch (\Throwable $e) {
}
$pageTitle = 'Layanan Tamu';
include '../../includes/header.php';
?>

<style>
    body[data-theme] .main-content .gs-wrap { max-width: 1200px; margin: 0 auto; padding-bottom: 1.5rem; }
    body[data-theme] .main-content .gs-wrap .gs-head h1 { margin: 0 0 .75rem; font-size: .95rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-head h1 small { display: block; font-size: .68rem !important; font-weight: 500; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .gs-wrap .gs-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .75rem; align-items: start; margin-bottom: .75rem; }
    body[data-theme] .main-content .gs-wrap .gs-card { border-radius: 14px; background: var(--fd-card); border: 1px solid var(--fd-edge); box-shadow: var(--fd-shadow); padding: .85rem 1rem; margin-bottom: .75rem; }
    body[data-theme] .main-content .gs-wrap .gs-grid .gs-card { margin-bottom: 0; }
    body[data-theme] .main-content .gs-wrap .gs-card h3 { margin: 0 0 .15rem; font-size: .82rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-card > p.gs-sub { margin: 0 0 .7rem; padding-bottom: .55rem; border-bottom: 1px solid var(--fd-line); font-size: .68rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .gs-wrap label.gs-label { display: block; margin: 0 0 .3rem; font-size: .6rem !important; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .gs-wrap .gs-input, body[data-theme] .main-content .gs-wrap textarea.gs-input { width: 100%; padding: .45rem .65rem; border-radius: 9px; border: 1px solid var(--fd-input-border); background: var(--fd-input-bg) !important; color: var(--fd-text) !important; font-size: .74rem !important; }
    body[data-theme] .main-content .gs-wrap textarea.gs-input { min-height: 80px; resize: vertical; line-height: 1.5; }
    body[data-theme] .main-content .gs-wrap .gs-field { margin-bottom: .7rem; }
    body[data-theme] .main-content .gs-wrap .gs-hint { display: block; margin-top: .3rem; font-size: .64rem !important; color: var(--fd-muted) !important; line-height: 1.5; }
    body[data-theme] .main-content .gs-wrap .gs-row { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }
    body[data-theme] .main-content .gs-wrap .gs-btn { display: inline-flex; align-items: center; gap: .35rem; height: 30px; padding: 0 .8rem; border-radius: 8px; border: 1px solid transparent; cursor: pointer; font-size: .7rem !important; font-weight: 600; text-decoration: none; background: var(--fd-accent); color: #fff !important; -webkit-text-fill-color: #fff !important; }
    body[data-theme] .main-content .gs-wrap .gs-btn.ghost { background: var(--fd-tile); border-color: var(--fd-line); color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-btn.danger { background: rgba(220, 38, 38, .1); color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; }
    body[data-theme] .main-content .gs-wrap label.gs-switch { display: flex; gap: .55rem; align-items: flex-start; padding: .55rem .65rem; border-radius: 10px; background: var(--fd-tile); border: 1px solid var(--fd-line); cursor: pointer; margin-bottom: .7rem; }
    body[data-theme] .main-content .gs-wrap .gs-switch input { width: 16px; height: 16px; margin-top: 1px; accent-color: #0ea5e9; }
    body[data-theme] .main-content .gs-wrap .gs-switch b { display: block; font-size: .74rem; color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-switch small { font-size: .64rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .gs-wrap .gs-flyer { display: flex; gap: .6rem; align-items: center; padding: .5rem; border: 1px solid var(--fd-line); border-radius: 10px; background: var(--fd-tile); margin-bottom: .5rem; }
    body[data-theme] .main-content .gs-wrap .gs-flyer img { width: 56px; height: 72px; object-fit: cover; border-radius: 6px; flex: none; }
    body[data-theme] .main-content .gs-wrap .gs-flyer .gs-input { flex: 1; min-width: 0; }
    body[data-theme] .main-content .gs-wrap .gs-stats { display: flex; gap: 6px; flex-wrap: wrap; }
    body[data-theme] .main-content .gs-wrap .gs-stats > div { min-width: 82px; padding: 6px 10px; border-radius: 10px; border: 1px solid var(--fd-line); background: var(--fd-tile); text-align: center; }
    body[data-theme] .main-content .gs-wrap .gs-stats b { display: block; font-size: 1rem !important; font-weight: 800; color: var(--fd-text) !important; }
    body[data-theme] .main-content .gs-wrap .gs-stats span { font-size: .58rem !important; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--fd-muted) !important; }
    @media (max-width: 960px) { body[data-theme] .main-content .gs-wrap .gs-grid { grid-template-columns: 1fr; } }
</style>

<div class="gs-wrap">
    <div class="gs-head">
        <h1>Layanan Tamu <small>Halaman yang dibuka tamu dari link di pesan welcome check-in: flyer trip &amp; sewa motor. Semua tombol membuka WhatsApp ke Front Office.</small></h1>
    </div>
    <?php $sxActiveKey = 'guest_services'; include __DIR__ . '/settings-nav.php'; ?>

    <div class="gs-card">
        <h3>Statistik 30 hari terakhir</h3>
        <p class="gs-sub">Seberapa sering tamu membuka halaman dan menekan tombol.</p>
        <div class="gs-stats">
            <div><b><?php echo $stat['links']; ?></b><span>Link dikirim</span></div>
            <div><b><?php echo $stat['open']; ?></b><span>Dibuka</span></div>
            <div><b><?php echo $stat['wa_trip']; ?></b><span>WA Trip</span></div>
            <div><b><?php echo $stat['wa_motor']; ?></b><span>WA Motor</span></div>
            <div><b><?php echo $stat['wa_car']; ?></b><span>WA Mobil</span></div>
            <div><b><?php echo $stat['wa_taxi']; ?></b><span>WA Taxi</span></div>
            <div><b><?php echo $stat['wa_fo']; ?></b><span>WA Front Office</span></div>
            <div><b><?php echo $stat['save_flyer']; ?></b><span>Simpan flyer</span></div>
        </div>
    </div>

    <!-- Tambah flyer (form sendiri agar unggah tidak bercampur dengan pengaturan lain) -->
    <div class="gs-card">
        <h3>Flyer Trip</h3>
        <p class="gs-sub">JPG / PNG / WEBP, maks 6 MB. Otomatis diperkecil agar ringan di sinyal lemah. Pilih kategori (Trip / Sewa Motor / Sewa Mobil / Sewa Taxi); beberapa flyer dalam satu kategori tampil sebagai slide yang bisa digeser. Urutan di sini = urutan slide.</p>
        <?php foreach ($flyers as $i => $f): ?>
            <div class="gs-flyer">
                <img src="<?php echo htmlspecialchars(GuestServices::fileUrl($f['path'])); ?>" alt="">
                <span style="flex:1;min-width:0;font-size:.7rem;color:var(--fd-text)"><?php echo htmlspecialchars($f['title'] !== '' ? $f['title'] : basename($f['path'])); ?> <em style="font-style:normal;font-size:.6rem;font-weight:700;padding:.05rem .45rem;border-radius:999px;background:var(--fd-accent-soft);color:var(--fd-accent-text)"><?php echo htmlspecialchars(GuestServices::FLYER_CATS[$f['cat']]); ?></em></span>
                <?php if ($i > 0): ?>
                    <form method="post"><input type="hidden" name="act" value="move_flyer"><input type="hidden" name="idx" value="<?php echo $i; ?>"><button class="gs-btn ghost" title="Naikkan">↑</button></form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Hapus flyer ini?')"><input type="hidden" name="act" value="del_flyer"><input type="hidden" name="idx" value="<?php echo $i; ?>"><button class="gs-btn danger">Hapus</button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$flyers): ?><span class="gs-hint" style="margin-bottom:.5rem">Belum ada flyer. Halaman tamu akan menampilkan tab Trip setelah flyer pertama diunggah.</span><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="gs-row" style="margin-top:.5rem">
            <input type="hidden" name="act" value="add_flyer">
            <select name="new_cat" class="gs-input" style="max-width:150px">
                <?php foreach (GuestServices::FLYER_CATS as $ck => $cl): ?><option value="<?php echo $ck; ?>"><?php echo $cl; ?></option><?php endforeach; ?>
            </select>
            <input type="file" name="flyer" accept="image/jpeg,image/png,image/webp" required class="gs-input" style="max-width:260px">
            <input type="text" name="new_title" class="gs-input" style="max-width:240px" maxlength="80" placeholder="Judul flyer (opsional)">
            <button class="gs-btn">Unggah flyer</button>
        </form>
    </div>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="act" value="save">
        <div class="gs-grid">
            <div>
                <div class="gs-card">
                    <h3>Link di Pesan Welcome</h3>
                    <p class="gs-sub">Link otomatis dibuat per tamu saat check-in. Isi pesannya diatur di <a href="whatsapp.php">Pengaturan WhatsApp</a> (placeholder <code>{services_link}</code>).</p>
                    <label class="gs-switch">
                        <input type="checkbox" name="gs_enabled" value="1" <?php echo $s['gs_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span><b>Sertakan link layanan tamu di pesan welcome</b><small>Bila mati, pesan welcome dikirim tanpa link.</small></span>
                    </label>
                    <div class="gs-field">
                        <label class="gs-label" for="gsFo">WhatsApp Front Office</label>
                        <input class="gs-input" id="gsFo" name="gs_fo_wa" value="<?php echo htmlspecialchars($s['gs_fo_wa']); ?>" placeholder="0812xxxxxxx">
                        <span class="gs-hint">Tujuan semua tombol WhatsApp. Kosong = nomor telepon hotel<?php echo $foNumber !== '' ? ' (sekarang: ' . htmlspecialchars($foNumber) . ')' : ' — belum ada, tombol WhatsApp tidak tampil'; ?>.</span>
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gsHours">Jam balas</label>
                        <input class="gs-input" id="gsHours" name="gs_fo_hours" value="<?php echo htmlspecialchars($s['gs_fo_hours']); ?>" placeholder="07.00–22.00">
                    </div>
                    <div class="gs-row">
                        <a class="gs-btn ghost" href="<?php echo BASE_URL; ?>/guest-services.php?k=preview" target="_blank" rel="noopener">Lihat contoh halaman tamu</a>
                        <a class="gs-btn ghost" href="<?php echo BASE_URL; ?>/guest-services.php?k=preview&l=en" target="_blank" rel="noopener">English</a>
                    </div>
                </div>
                <div class="gs-card" style="margin-top:.75rem">
                    <h3>WiFi Tamu</h3>
                    <p class="gs-sub">Kartu WiFi di bagian atas halaman tamu (salin password, buka pengaturan WiFi, QR). Kosongkan nama WiFi bila tidak ingin ditampilkan.</p>
                    <div class="gs-field">
                        <label class="gs-label" for="gsSsid">Nama WiFi (SSID)</label>
                        <input class="gs-input" id="gsSsid" name="gs_wifi_ssid" value="<?php echo htmlspecialchars($s['gs_wifi_ssid']); ?>" maxlength="60" autocomplete="off">
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gsWpass">Password</label>
                        <input class="gs-input" id="gsWpass" name="gs_wifi_pass" value="<?php echo htmlspecialchars($s['gs_wifi_pass']); ?>" maxlength="63" autocomplete="off">
                        <span class="gs-hint">Password terlihat oleh pemegang link tamu (link unik per tamu). Kosong = WiFi terbuka tanpa password.</span>
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gsWnote">Catatan (opsional)</label>
                        <input class="gs-input" id="gsWnote" name="gs_wifi_note" value="<?php echo htmlspecialchars($s['gs_wifi_note']); ?>" maxlength="100" placeholder="Kuat di lobi &amp; area kamar">
                    </div>
                </div>
                <div class="gs-card" style="margin-top:.75rem">
                    <h3>Tab Trip</h3>
                    <p class="gs-sub">Teks di sekitar flyer.</p>
                    <div class="gs-field">
                        <label class="gs-label" for="gsTitle">Judul</label>
                        <input class="gs-input" id="gsTitle" name="gs_trip_title" value="<?php echo htmlspecialchars($s['gs_trip_title']); ?>" maxlength="80" placeholder="Paket Trip Karimunjawa">
                    </div>
                    <div class="gs-field">
                        <label class="gs-label" for="gsChips">Label singkat</label>
                        <input class="gs-input" id="gsChips" name="gs_trip_chips" value="<?php echo htmlspecialchars($s['gs_trip_chips']); ?>" placeholder="Island Hopping, Snorkeling, Sunset, Private Trip">
                        <span class="gs-hint">Pisahkan dengan koma.</span>
                    </div>
                    <?php foreach ($flyers as $i => $f): ?>
                        <div class="gs-field">
                            <label class="gs-label">Judul flyer <?php echo $i + 1; ?></label>
                            <input class="gs-input" name="flyer_title[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($f['title']); ?>" maxlength="80">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="gs-card">
                <h3>Sewa Motor</h3>
                <p class="gs-sub">Tab kedua di halaman tamu.</p>
                <label class="gs-switch">
                    <input type="checkbox" name="gs_motor_enabled" value="1" <?php echo $s['gs_motor_enabled'] === '1' ? 'checked' : ''; ?>>
                    <span><b>Tampilkan sewa motor</b><small>Matikan bila tidak menawarkan sewa motor.</small></span>
                </label>
                <div class="gs-field">
                    <label class="gs-label" for="gsPrice">Harga mulai dari (Rp)</label>
                    <input class="gs-input" id="gsPrice" name="gs_motor_price" inputmode="numeric" value="<?php echo htmlspecialchars($s['gs_motor_price']); ?>" placeholder="otomatis">
                    <span class="gs-hint">Kosong = otomatis dari harga terendah katalog Hotel Service bertipe motor<?php echo $mp['price'] > 0 && $s['gs_motor_price'] === '' ? ' (sekarang: Rp ' . number_format($mp['price'], 0, ',', '.') . ')' : ''; ?>.</span>
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsUnit">Satuan</label>
                    <input class="gs-input" id="gsUnit" name="gs_motor_unit" value="<?php echo htmlspecialchars($s['gs_motor_unit']); ?>" maxlength="20" placeholder="hari">
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsNotes">Syarat &amp; info (satu per baris)</label>
                    <textarea class="gs-input" id="gsNotes" name="gs_motor_notes" placeholder="Helm disediakan&#10;Bawa KTP / paspor untuk jaminan&#10;Bisa diantar ke hotel"><?php echo htmlspecialchars($s['gs_motor_notes']); ?></textarea>
                </div>
                <div class="gs-field">
                    <label class="gs-label">Foto motor</label>
                    <?php if ($s['gs_motor_photo'] !== ''): ?>
                        <img src="<?php echo htmlspecialchars(GuestServices::fileUrl($s['gs_motor_photo'])); ?>" alt="" style="max-width:200px;border-radius:8px;display:block;margin-bottom:.4rem">
                        <label class="gs-hint"><input type="checkbox" name="remove_motor_photo" value="1"> Hapus foto ini</label>
                    <?php endif; ?>
                    <input type="file" name="motor_photo" accept="image/jpeg,image/png,image/webp" class="gs-input">
                </div>
            </div>
            <div class="gs-card">
                <h3>Sewa Mobil</h3>
                <p class="gs-sub">Tab ketiga di halaman tamu. Unggah flyer mobil di bagian Flyer dengan kategori Sewa Mobil.</p>
                <label class="gs-switch">
                    <input type="checkbox" name="gs_car_enabled" value="1" <?php echo $s['gs_car_enabled'] === '1' ? 'checked' : ''; ?>>
                    <span><b>Tampilkan sewa mobil</b><small>Matikan bila tidak menawarkan sewa mobil.</small></span>
                </label>
                <div class="gs-field">
                    <label class="gs-label" for="gsCarPrice">Harga mulai dari (Rp)</label>
                    <input class="gs-input" id="gsCarPrice" name="gs_car_price" inputmode="numeric" value="<?php echo htmlspecialchars($s['gs_car_price']); ?>" placeholder="otomatis / kosongkan">
                    <span class="gs-hint">Kosong = otomatis dari harga terendah katalog Hotel Service bertipe mobil<?php echo $cp['price'] > 0 && $s['gs_car_price'] === '' ? ' (sekarang: Rp ' . number_format($cp['price'], 0, ',', '.') . ')' : ''; ?>; bila tidak ada, harga tidak ditampilkan.</span>
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsCarUnit">Satuan</label>
                    <input class="gs-input" id="gsCarUnit" name="gs_car_unit" value="<?php echo htmlspecialchars($s['gs_car_unit']); ?>" maxlength="20" placeholder="hari">
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsCarNotes">Syarat &amp; info (satu per baris)</label>
                    <textarea class="gs-input" id="gsCarNotes" name="gs_car_notes" placeholder="Dengan atau tanpa sopir&#10;Bisa antar-jemput pelabuhan"><?php echo htmlspecialchars($s['gs_car_notes']); ?></textarea>
                </div>
            </div>
            <div class="gs-card">
                <h3>Sewa Taxi</h3>
                <p class="gs-sub">Tab keempat di halaman tamu. Unggah flyer taxi di bagian Flyer dengan kategori Sewa Taxi.</p>
                <label class="gs-switch">
                    <input type="checkbox" name="gs_taxi_enabled" value="1" <?php echo $s['gs_taxi_enabled'] === '1' ? 'checked' : ''; ?>>
                    <span><b>Tampilkan sewa taxi</b><small>Matikan bila tidak menawarkan taxi / antar-jemput.</small></span>
                </label>
                <div class="gs-field">
                    <label class="gs-label" for="gsTaxiPrice">Harga mulai dari (Rp)</label>
                    <input class="gs-input" id="gsTaxiPrice" name="gs_taxi_price" inputmode="numeric" value="<?php echo htmlspecialchars($s['gs_taxi_price']); ?>" placeholder="otomatis / kosongkan">
                    <span class="gs-hint">Kosong = otomatis dari harga terendah katalog Hotel Service bertipe taxi<?php echo $xp['price'] > 0 && $s['gs_taxi_price'] === '' ? ' (sekarang: Rp ' . number_format($xp['price'], 0, ',', '.') . ')' : ''; ?>; bila tidak ada, harga tidak ditampilkan.</span>
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsTaxiUnit">Satuan</label>
                    <input class="gs-input" id="gsTaxiUnit" name="gs_taxi_unit" value="<?php echo htmlspecialchars($s['gs_taxi_unit']); ?>" maxlength="20" placeholder="trip">
                </div>
                <div class="gs-field">
                    <label class="gs-label" for="gsTaxiNotes">Syarat &amp; info (satu per baris)</label>
                    <textarea class="gs-input" id="gsTaxiNotes" name="gs_taxi_notes" placeholder="Antar-jemput pelabuhan / bandara&#10;Pesan sehari sebelumnya"><?php echo htmlspecialchars($s['gs_taxi_notes']); ?></textarea>
                </div>
            </div>
        </div>
        <div class="gs-row" style="justify-content:flex-end">
            <button type="submit" class="gs-btn">Simpan Pengaturan</button>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
