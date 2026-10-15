<?php
/**
 * Halaman Layanan Tamu (publik, mobile) — dibuka dari link di pesan welcome check-in.
 * Hanya menawarkan: semua tombol membuka WhatsApp ke Front Office. ?l=id|en mengganti bahasa.
 * k=preview menampilkan contoh (tanpa mencatat statistik).
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/WhatsAppHelper.php';
require_once __DIR__ . '/includes/GuestServices.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['k'] ?? $_POST['k'] ?? ''));
if ($code === '') {
    http_response_code(400);
    exit('Link tidak valid');
}

$db = Database::getInstance();
GuestServices::ensureTables($db);
$s = GuestServices::settings($db);
$preview = $code === 'preview';
$link = $preview ? ['id' => 0, 'guest_name' => 'Budi', 'room_number' => '105', 'booking_code' => 'BK-PREVIEW', 'lang' => 'id']
    : $db->fetchOne("SELECT * FROM guest_service_links WHERE short_code = ? LIMIT 1", [$code]);
if (!$link) {
    http_response_code(404);
    exit('Link tidak ditemukan');
}

// Catat klik tombol (sendBeacon dari halaman)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ev = (string)($_POST['e'] ?? '');
    if (!$preview && in_array($ev, ['wa_trip', 'wa_motor', 'wa_car', 'wa_fo', 'save_flyer'], true)) {
        $db->query("INSERT INTO guest_service_events (link_id, event) VALUES (?, ?)", [(int)$link['id'], $ev]);
    }
    http_response_code(204);
    exit;
}

// Bahasa: pilihan tamu (?l=) > bahasa HP tamu (Accept-Language: id → Indonesia, lainnya → English) > bahasa tersimpan di link
$lang = $link['lang'] === 'en' ? 'en' : 'id';
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
if (preg_match('/^\s*([a-z]{2,3})/', $accept, $am)) $lang = in_array($am[1], ['id', 'in'], true) ? 'id' : 'en';
if (in_array($_GET['l'] ?? '', ['id', 'en'], true)) $lang = $_GET['l'];
if (!$preview) {
    $db->query("UPDATE guest_service_links SET opens = opens + 1, last_open = NOW() WHERE id = ?", [(int)$link['id']]);
    $db->query("INSERT INTO guest_service_events (link_id, event) VALUES (?, 'open')", [(int)$link['id']]);
}

$T = [
    'id' => [
        'hello' => 'Halo', 'room' => 'Kamar', 'intro' => 'Semoga nyaman menginap. Ini pilihan seru selama di Karimunjawa.',
        'tab_trip' => 'Trip', 'tab_motor' => 'Sewa Motor', 'tab_car' => 'Sewa Mobil', 'car' => 'Sewa Mobil', 'swipe' => 'Geser untuk melihat flyer lainnya',
        'wa_car' => 'Cek ketersediaan mobil via WhatsApp', 'm_car' => 'Halo Front Office, saya %s (kamar %s, booking %s). Saya ingin menyewa mobil, apakah tersedia?', 'trip' => 'Paket Trip Karimunjawa', 'zoom' => 'Perbesar', 'save' => 'Simpan flyer',
        'wa_trip' => 'Tanya & Pesan Trip via WhatsApp', 'motor' => 'Sewa Motor', 'from' => 'mulai dari', 'per' => 'hari',
        'wa_motor' => 'Cek ketersediaan via WhatsApp', 'bar_fo' => 'Front Office', 'reply' => 'Balas cepat', 'chat' => 'Chat Front Office',
        'note' => 'Harga langsung dari hotel · pemesanan dikonfirmasi oleh Front Office.', 'close' => 'Tutup', 'empty' => 'Informasi layanan segera hadir.',
        'm_trip' => 'Halo Front Office, saya %s (kamar %s, booking %s). Saya tertarik dengan paket trip, boleh minta info & jadwalnya?',
        'm_motor' => 'Halo Front Office, saya %s (kamar %s, booking %s). Saya ingin menyewa motor, apakah tersedia?',
        'm_fo' => 'Halo Front Office, saya %s (kamar %s, booking %s).',
        'wifi' => 'WiFi Hotel', 'w_name' => 'Nama WiFi', 'w_pass' => 'Password', 'w_open' => 'Tanpa password (terbuka)', 'copy' => 'Salin', 'copied' => 'Tersalin ✓',
        'w_settings' => 'Buka pengaturan WiFi', 'w_qr' => 'Tampilkan QR WiFi', 'w_qr_hint' => 'Pindai dengan kamera HP lain untuk tersambung otomatis.', 'w_tip' => 'Salin password, lalu pilih jaringan ini di pengaturan WiFi HP Anda.',
    ],
    'en' => [
        'hello' => 'Hello', 'room' => 'Room', 'intro' => 'We hope you are enjoying your stay. Here are some great things to do in Karimunjawa.',
        'tab_trip' => 'Trips', 'tab_motor' => 'Motorbike', 'tab_car' => 'Car Rental', 'car' => 'Car Rental', 'swipe' => 'Swipe to see more flyers',
        'wa_car' => 'Check car availability on WhatsApp', 'm_car' => 'Hello Front Office, this is %s (room %s, booking %s). I would like to rent a car. Is one available?', 'trip' => 'Karimunjawa Trip Packages', 'zoom' => 'Enlarge', 'save' => 'Save flyer',
        'wa_trip' => 'Ask & Book a Trip on WhatsApp', 'motor' => 'Motorbike Rental', 'from' => 'from', 'per' => 'day',
        'wa_motor' => 'Check availability on WhatsApp', 'bar_fo' => 'Front Office', 'reply' => 'Quick replies', 'chat' => 'Chat Front Office',
        'note' => 'Prices direct from the hotel · bookings are confirmed by the Front Office.', 'close' => 'Close', 'empty' => 'Service information is coming soon.',
        'm_trip' => 'Hello Front Office, this is %s (room %s, booking %s). I am interested in a trip package. Could you share the details and schedule?',
        'm_motor' => 'Hello Front Office, this is %s (room %s, booking %s). I would like to rent a motorbike. Is one available?',
        'm_fo' => 'Hello Front Office, this is %s (room %s, booking %s).',
        'wifi' => 'Hotel WiFi', 'w_name' => 'Network', 'w_pass' => 'Password', 'w_open' => 'No password (open network)', 'copy' => 'Copy', 'copied' => 'Copied ✓',
        'w_settings' => 'Open WiFi settings', 'w_qr' => 'Show WiFi QR', 'w_qr_hint' => 'Scan with another phone camera to connect automatically.', 'w_tip' => "Copy the password, then pick this network in your phone's WiFi settings.",
    ],
][$lang];

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$hotel = '';
try {
    $hotel = (string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? '');
} catch (\Throwable $e) {
}
if ($hotel === '' && defined('BUSINESS_NAME')) $hotel = BUSINESS_NAME;

$logoUrl = '';
try {
    if (!function_exists('getBusinessLogo') && is_file(__DIR__ . '/includes/business_helper.php')) require_once __DIR__ . '/includes/business_helper.php';
    if (function_exists('getBusinessLogo')) $logoUrl = (string)getBusinessLogo();
} catch (\Throwable $e) {
}
$name = trim((string)$link['guest_name']);
$first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
$fo = GuestServices::foNumber($db, $s);
$wa = fn(string $tpl) => $fo === '' ? '#' : 'https://wa.me/' . $fo . '?text=' . rawurlencode(sprintf($T[$tpl], $name !== '' ? $name : 'guest', $link['room_number'] ?: '-', $link['booking_code'] ?: '-'));

$flyers = GuestServices::flyers($s);
$tripTitle = trim($s['gs_trip_title']) !== '' ? $s['gs_trip_title'] : $T['trip'];
$chips = array_filter(array_map('trim', explode(',', $s['gs_trip_chips'])));
$showMotor = $s['gs_motor_enabled'] === '1';
$mp = GuestServices::motorPrice($db, $s);
$notes = array_filter(array_map('trim', preg_split('/\R/', $s['gs_motor_notes'])));
$flyersBy = ['trip' => [], 'motor' => [], 'car' => []];
foreach ($flyers as $fl) $flyersBy[$fl['cat']][] = $fl;
$showCar = $s['gs_car_enabled'] === '1';
$cp = GuestServices::carPrice($db, $s);
$carNotes = array_filter(array_map('trim', preg_split('/\R/', $s['gs_car_notes'])));
// Tab yang tampil: Trip (bila ada flyer), Sewa Motor, Sewa Mobil
$tabs = [];
if ($flyersBy['trip']) $tabs['trip'] = '🏝️ ' . $T['tab_trip'];
if ($showMotor) $tabs['motor'] = '🏍️ ' . $T['tab_motor'];
if ($showCar) $tabs['car'] = '🚗 ' . $T['tab_car'];
$firstTab = (string)array_key_first($tabs);$ogImg = $flyers ? GuestServices::fileUrl($flyers[0]['path']) : '';
$wifiSsid = trim($s['gs_wifi_ssid']);
$wifiPass = trim($s['gs_wifi_pass']);
$wifiEsc = fn(string $v) => preg_replace('/([\\;,":])/', '\\\\$1', $v); // escape format QR "WIFI:"
$wifiQr = $wifiSsid === '' ? '' : 'WIFI:T:' . ($wifiPass === '' ? 'nopass' : 'WPA') . ';S:' . $wifiEsc($wifiSsid) . ';' . ($wifiPass === '' ? '' : 'P:' . $wifiEsc($wifiPass) . ';') . ';';
$self = GuestServices::baseUrl() . '/guest-services.php?k=' . urlencode($code);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($hotel) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $h($hotel) ?>">
<meta property="og:description" content="<?= $h($T['intro']) ?>">
<?php if ($ogImg !== ''): ?><meta property="og:image" content="<?= $h($ogImg) ?>"><?php endif; ?>
<style>
  :root { --navy:#0f2744; --gold:#b8893b; --sand:#faf6ef; --ink:#1f2937; --mut:#64748b; --wa:#25d366; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:system-ui,'Segoe UI',Roboto,sans-serif; background:var(--sand); color:var(--ink); padding-bottom:84px; -webkit-text-size-adjust:100%; }
  .wrap { max-width:440px; margin:0 auto; }
  header { background:linear-gradient(160deg,var(--navy),#1d4e89); color:#fff; padding:18px 18px 54px; border-radius:0 0 26px 26px; }
  .top { display:flex; justify-content:space-between; align-items:center; font-size:13px; font-weight:600; }
  .brand { display:flex; align-items:center; gap:10px; }
  .logo { width:42px; height:42px; border-radius:50%; background:#fff; color:var(--navy); display:inline-grid; place-items:center; font-weight:800; font-size:16px; object-fit:contain; padding:2px; flex:none; }
  .lang a { color:#fff; text-decoration:none; font-size:11px; padding:4px 9px; border:1px solid rgba(255,255,255,.4); border-radius:999px; margin-left:4px; opacity:.75; }
  .lang a.on { background:#fff; color:var(--navy); opacity:1; font-weight:700; }
  h1 { font-size:21px; margin-top:16px; font-weight:800; }
  .sub { font-size:13px; opacity:.92; margin-top:4px; line-height:1.45; }
  .tabs { margin:-26px 14px 0; background:#fff; border-radius:16px; padding:5px; display:flex; gap:4px; box-shadow:0 8px 24px rgba(15,39,68,.15); position:relative; }
  .tab { flex:1; text-align:center; padding:10px 4px; border:0; background:none; border-radius:12px; font:inherit; font-size:13px; font-weight:700; color:var(--mut); cursor:pointer; }
  .tab.on { background:var(--navy); color:#fff; }
  section { padding:18px 14px 4px; display:none; }
  section.on { display:block; }
  h2 { font-size:15px; font-weight:800; margin-bottom:10px; }
  .card { background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 4px 18px rgba(15,39,68,.08); margin-bottom:14px; }
  .fly { position:relative; display:block; width:100%; border:0; padding:0; background:#e2e8f0; cursor:zoom-in; }
  .fly img { display:block; width:100%; height:auto; }
  .track { display:flex; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; background:#e2e8f0; }
  .track::-webkit-scrollbar { display:none; }
  .track .fly { flex:0 0 100%; scroll-snap-align:center; align-self:center; }
  .cwrap { position:relative; }
  .cnt { position:absolute; left:10px; top:10px; background:rgba(0,0,0,.55); color:#fff; font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; pointer-events:none; }
  .dots { display:flex; justify-content:center; gap:6px; padding:10px 0 0; }
  .dot { width:7px; height:7px; border-radius:50%; background:#cbd5e1; border:0; padding:0; transition:all .2s; }
  .dot.on { background:var(--navy); width:18px; border-radius:4px; }
  .swipe { text-align:center; font-size:11px; color:var(--mut); padding-top:6px; }
  .fly .zoom { position:absolute; right:10px; bottom:10px; background:rgba(0,0,0,.55); color:#fff; font-size:11px; padding:5px 10px; border-radius:999px; }
  .body { padding:14px 16px 16px; }
  .ftitle { font-weight:700; font-size:14px; margin-bottom:8px; }
  .chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
  .chip { font-size:12px; font-weight:600; padding:6px 11px; border-radius:999px; background:#eef2f7; color:var(--navy); }
  .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:13px; border-radius:14px; font-weight:800; font-size:14px; text-decoration:none; }
  .wa { background:var(--wa); color:#fff; }
  .ghost { background:#fff; border:1.5px solid #cbd5e1; color:var(--navy); margin-top:8px; font-weight:700; }
  .price { display:flex; align-items:baseline; gap:6px; margin:2px 0 10px; }
  .price b { font-size:22px; color:var(--navy); }
  .price span { font-size:12px; color:var(--mut); }
  .moto img { display:block; width:100%; height:auto; max-height:240px; object-fit:cover; }
  ul.req { list-style:none; font-size:13px; color:var(--mut); line-height:1.7; margin:0 0 12px; }
  ul.req li::before { content:"✓ "; color:#16a34a; font-weight:800; }
  .note { font-size:12px; color:var(--mut); text-align:center; padding:6px 20px 14px; }
  .empty { text-align:center; color:var(--mut); padding:40px 20px; font-size:14px; }
  .bar { position:fixed; left:0; right:0; bottom:0; padding:10px 14px calc(10px + env(safe-area-inset-bottom)); background:rgba(255,255,255,.96); backdrop-filter:blur(8px); border-top:1px solid #e2e8f0; }
  .bar .in { max-width:440px; margin:0 auto; display:flex; align-items:center; gap:10px; }
  .bar small { font-size:11px; color:var(--mut); line-height:1.3; flex:1; }
  .bar .btn { width:auto; padding:12px 18px; white-space:nowrap; }
  .wifi { margin:16px 14px 0; background:#fff; border-radius:14px; padding:9px 12px; box-shadow:0 4px 14px rgba(15,39,68,.08); }
  .wifi h2 { margin-bottom:2px; font-size:13px; }
  .wrow { display:flex; align-items:center; gap:8px; padding:5px 0; border-top:1px solid #eef2f7; }
  .wrow small { display:block; font-size:10px; color:var(--mut); line-height:1.2; }
  .wrow b { font-size:13.5px; word-break:break-all; line-height:1.25; }
  .wrow > div { flex:1; min-width:0; }
  .cp { border:0; background:#eef2f7; color:var(--navy); font:inherit; font-size:11px; font-weight:700; padding:6px 10px; border-radius:8px; cursor:pointer; }
  .wacts { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
  .wacts .ghost { margin-top:0; flex:1; padding:7px; font-size:12px; border-radius:10px; border-width:1px; width:auto; cursor:pointer; font-family:inherit; }
  .wtip { font-size:11px; color:var(--mut); margin-top:5px; line-height:1.35; }
  #qrBox { display:none; text-align:center; padding-top:12px; }
  #qrCanvas { display:flex; justify-content:center; }
  .lb { position:fixed; inset:0; background:rgba(0,0,0,.92); display:none; z-index:50; overflow:auto; -webkit-overflow-scrolling:touch; }
  .lb.on { display:block; }
  .lb img { display:block; width:100%; height:auto; touch-action:pinch-zoom; }
  .lb button { position:fixed; top:12px; right:12px; z-index:51; border:0; background:#fff; color:#111; border-radius:999px; padding:8px 14px; font-weight:700; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="top">
      <span class="brand"><?php if ($logoUrl !== ''): ?><img class="logo" src="<?= $h($logoUrl) ?>" alt=""><?php else: ?><span class="logo"><?= $h(mb_strtoupper(mb_substr($hotel, 0, 1))) ?></span><?php endif; ?><?= $h($hotel) ?></span>
      <span class="lang"><a href="?k=<?= $h($code) ?>&l=id" class="<?= $lang === 'id' ? 'on' : '' ?>">ID</a><a href="?k=<?= $h($code) ?>&l=en" class="<?= $lang === 'en' ? 'on' : '' ?>">EN</a></span>
    </div>
    <h1><?= $h($T['hello']) ?><?= $first !== '' ? ', ' . $h($first) : '' ?> 👋</h1>
    <div class="sub"><?= $link['room_number'] ? $h($T['room']) . ' ' . $h($link['room_number']) . ' · ' : '' ?><?= $h($T['intro']) ?></div>
  </header>

  <?php if ($wifiSsid !== ''): ?>
  <div class="wifi" style="margin-top:-26px;position:relative">
    <h2>📶 <?= $h($T['wifi']) ?></h2>
    <div class="wrow"><div><small><?= $h($T['w_name']) ?></small><b><?= $h($wifiSsid) ?></b></div><button type="button" class="cp" data-copy="<?= $h($wifiSsid) ?>"><?= $h($T['copy']) ?></button></div>
    <div class="wrow"><div><small><?= $h($T['w_pass']) ?></small><b><?= $wifiPass !== '' ? $h($wifiPass) : $h($T['w_open']) ?></b></div><?php if ($wifiPass !== ''): ?><button type="button" class="cp" data-copy="<?= $h($wifiPass) ?>"><?= $h($T['copy']) ?></button><?php endif; ?></div>
    <?php if (trim($s['gs_wifi_note']) !== ''): ?><div class="wtip"><?= $h($s['gs_wifi_note']) ?></div><?php endif; ?>
    <div class="wtip"><?= $h($T['w_tip']) ?></div>
    <div class="wacts">
      <a class="btn ghost" id="wsetBtn" style="display:none" href="intent:#Intent;action=android.settings.WIFI_SETTINGS;end">⚙️ <?= $h($T['w_settings']) ?></a>
      <button type="button" class="btn ghost" id="qrBtn">▦ <?= $h($T['w_qr']) ?></button>
    </div>
    <div id="qrBox"><div id="qrCanvas"></div><div class="wtip"><?= $h($T['w_qr_hint']) ?></div></div>
  </div>
  <?php endif; ?>

  <?php
  // Slide flyer (geser kiri/kanan); satu kartu per kategori
  $carousel = function (array $list, string $ev, string $waKey, string $waLabel, string $extra = '') use ($h, $T, $wa, $tripTitle) {
      $n = count($list); $u0 = GuestServices::fileUrl($list[0]['path']);
      ob_start(); ?>
      <div class="card" data-car data-n="<?= $n ?>">
        <div class="cwrap">
          <div class="track">
            <?php foreach ($list as $i => $f): $u = GuestServices::fileUrl($f['path']); ?>
              <button type="button" class="fly" data-zoom="<?= $h($u) ?>" data-title="<?= $h($f['title']) ?>" aria-label="<?= $h($T['zoom']) ?>">
                <img src="<?= $h($u) ?>" alt="<?= $h($f['title'] ?: $tripTitle) ?>"<?= $i > 0 ? ' loading="lazy"' : '' ?>>
                <span class="zoom">🔍 <?= $h($T['zoom']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <?php if ($n > 1): ?><span class="cnt" data-cnt>1 / <?= $n ?></span><?php endif; ?>
        </div>
        <?php if ($n > 1): ?>
          <div class="dots"><?php for ($i = 0; $i < $n; $i++): ?><button type="button" class="dot<?= $i === 0 ? ' on' : '' ?>" aria-label="<?= $i + 1 ?>"></button><?php endfor; ?></div>
          <div class="swipe">↔ <?= $h($T['swipe']) ?></div>
        <?php endif; ?>
        <div class="body">
          <div class="ftitle" data-ftitle><?= $h($list[0]['title']) ?></div>
          <?= $extra ?>
          <a class="btn wa" data-ev="<?= $ev ?>" href="<?= $h($wa($waKey)) ?>">💬 <?= $h($waLabel) ?></a>
          <a class="btn ghost" data-ev="save_flyer" data-save href="<?= $h($u0) ?>" download>⬇️ <?= $h($T['save']) ?></a>
        </div>
      </div>
      <?php return ob_get_clean();
  };
  $priceHtml = function (array $p) use ($h, $T) {
      if ($p['price'] <= 0) return '';
      return '<div class="price"><b>Rp ' . number_format($p['price'], 0, ',', '.') . '</b><span>/ ' . $h($p['unit'] !== '' && strtolower($p['unit']) !== 'unit' ? $p['unit'] : $T['per']) . ' · ' . $h($T['from']) . '</span></div>';
  };
  $notesHtml = fn(array $l) => $l ? '<ul class="req">' . implode('', array_map(fn($n) => '<li>' . $h($n) . '</li>', $l)) . '</ul>' : '';
  ?>
  <?php if (count($tabs) > 1): ?>
  <div class="tabs" <?= $wifiSsid !== '' ? 'style="margin-top:14px"' : '' ?>>
    <?php foreach ($tabs as $tk => $tl): ?><button type="button" class="tab<?= $tk === $firstTab ? ' on' : '' ?>" data-tab="<?= $tk ?>"><?= $h($tl) ?></button><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (isset($tabs['trip'])): ?>
  <section id="s-trip" class="<?= $firstTab === 'trip' ? 'on' : '' ?>">
    <h2>🏝️ <?= $h($tripTitle) ?></h2>
    <?= $carousel($flyersBy['trip'], 'wa_trip', 'm_trip', $T['wa_trip'], $chips ? '<div class="chips">' . implode('', array_map(fn($c) => '<span class="chip">' . $h($c) . '</span>', $chips)) . '</div>' : '') ?>
  </section>
  <?php endif; ?>

  <?php if ($showMotor): ?>
  <section id="s-motor" class="<?= $firstTab === 'motor' ? 'on' : '' ?>">
    <h2>🏍️ <?= $h($T['motor']) ?></h2>
    <?php $motorExtra = $priceHtml($mp) . $notesHtml($notes); ?>
    <?php if ($flyersBy['motor']): ?>
      <?= $carousel($flyersBy['motor'], 'wa_motor', 'm_motor', $T['wa_motor'], $motorExtra) ?>
    <?php else: ?>
    <div class="card">
      <?php if ($s['gs_motor_photo'] !== ''): ?><div class="moto"><img src="<?= $h(GuestServices::fileUrl($s['gs_motor_photo'])) ?>" alt="<?= $h($T['motor']) ?>"></div><?php endif; ?>
      <div class="body">
        <?= $motorExtra ?>
        <a class="btn wa" data-ev="wa_motor" href="<?= $h($wa('m_motor')) ?>">💬 <?= $h($T['wa_motor']) ?></a>
      </div>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($showCar): ?>
  <section id="s-car" class="<?= $firstTab === 'car' ? 'on' : '' ?>">
    <h2>🚗 <?= $h($T['car']) ?></h2>
    <?php $carExtra = $priceHtml($cp) . $notesHtml($carNotes); ?>
    <?php if ($flyersBy['car']): ?>
      <?= $carousel($flyersBy['car'], 'wa_car', 'm_car', $T['wa_car'], $carExtra) ?>
    <?php else: ?>
    <div class="card"><div class="body">
      <?= $carExtra ?>
      <a class="btn wa" data-ev="wa_car" href="<?= $h($wa('m_car')) ?>">💬 <?= $h($T['wa_car']) ?></a>
    </div></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (!$tabs): ?><div class="empty"><?= $h($T['empty']) ?></div><?php endif; ?>
  <p class="note"><?= $h($T['note']) ?></p>
</div>

<?php if ($fo !== ''): ?>
<div class="bar"><div class="in">
  <small><b><?= $h($T['bar_fo']) ?></b><br><?= $h($T['reply']) ?><?= trim($s['gs_fo_hours']) !== '' ? ' ' . $h($s['gs_fo_hours']) : '' ?></small>
  <a class="btn wa" data-ev="wa_fo" href="<?= $h($wa('m_fo')) ?>">💬 <?= $h($T['chat']) ?></a>
</div></div>
<?php endif; ?>

<div class="lb" id="lb"><button type="button" id="lbClose"><?= $h($T['close']) ?> ✕</button><img id="lbImg" alt=""></div>
<script>
(function () {
  var code = <?= json_encode($code) ?>;
  // Tab
  document.querySelectorAll('.tab').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('on', x === t); });
      document.querySelectorAll('section').forEach(function (s) { s.classList.toggle('on', s.id === 's-' + t.dataset.tab); });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });
  // Perbesar flyer
  var lb = document.getElementById('lb'), lbImg = document.getElementById('lbImg');
  document.querySelectorAll('[data-zoom]').forEach(function (b) {
    b.addEventListener('click', function () { lbImg.src = b.dataset.zoom; lb.classList.add('on'); });
  });
  function closeLb() { lb.classList.remove('on'); lbImg.removeAttribute('src'); }
  document.getElementById('lbClose').addEventListener('click', closeLb);
  // WiFi: salin, pengaturan (Android), QR
  var copied = <?= json_encode($T['copied']) ?>;
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.copy, old = b.textContent;
      function done() { b.textContent = copied; setTimeout(function () { b.textContent = old; }, 1600); }
      function fallback() { var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (e) {} t.remove(); done(); }
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(v).then(done, fallback); } else { fallback(); }
    });
  });
  var wset = document.getElementById('wsetBtn');
  if (wset && /Android/i.test(navigator.userAgent)) wset.style.display = '';
  var qrBtn = document.getElementById('qrBtn'), qrLoaded = false;
  if (qrBtn) qrBtn.addEventListener('click', function () {
    var box = document.getElementById('qrBox');
    box.style.display = box.style.display === 'block' ? 'none' : 'block';
    if (qrLoaded || box.style.display !== 'block') return;
    qrLoaded = true;
    var sc = document.createElement('script');
    sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
    sc.onload = function () { new QRCode(document.getElementById('qrCanvas'), { text: <?= json_encode($wifiQr) ?>, width: 200, height: 200 }); };
    document.head.appendChild(sc);
  });
  // Slide flyer: titik, penghitung, judul & tombol simpan mengikuti slide yang tampil
  document.querySelectorAll('[data-car]').forEach(function (c) {
    var track = c.querySelector('.track'), slides = c.querySelectorAll('.fly'), dots = c.querySelectorAll('.dot');
    var cnt = c.querySelector('[data-cnt]'), ttl = c.querySelector('[data-ftitle]'), sv = c.querySelector('[data-save]'), cur = -1;
    function sync() {
      var i = Math.max(0, Math.min(slides.length - 1, Math.round(track.scrollLeft / track.clientWidth)));
      if (i === cur) return; cur = i;
      dots.forEach(function (d, k) { d.classList.toggle('on', k === i); });
      if (cnt) cnt.textContent = (i + 1) + ' / ' + slides.length;
      ttl.textContent = slides[i].dataset.title || '';
      sv.href = slides[i].dataset.zoom;
    }
    track.addEventListener('scroll', function () { window.requestAnimationFrame(sync); }, { passive: true });
    dots.forEach(function (d, k) { d.addEventListener('click', function () { track.scrollTo({ left: k * track.clientWidth, behavior: 'smooth' }); }); });
  });
  // Hitung klik tombol (tidak menghalangi navigasi)
  document.querySelectorAll('[data-ev]').forEach(function (a) {
    a.addEventListener('click', function () {
      try { navigator.sendBeacon('guest-services.php', new URLSearchParams({ k: code, e: a.dataset.ev })); } catch (e) {}
    });
  });
})();
</script>
</body>
</html>
