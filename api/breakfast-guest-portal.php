<?php

/**
 * Breakfast Guest Self-Pick Portal API
 * - create_link: authenticated frontdesk action
 * - get_link: public read by token
 * - submit_link: public submit by token with quota enforcement
 */
define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/BreakfastHelper.php';

/** Normalisasi nomor WA (sama dengan WhatsAppHelper::normalizeTarget). */
function WhatsAppHelperNormalize(string $t): string
{
    require_once __DIR__ . '/../includes/WhatsAppHelper.php';
    return WhatsAppHelper::normalizeTarget($t);
}

header('Content-Type: application/json');

$db = Database::getInstance();
$pdo = $db->getConnection();

function hotel_date()
{
    return (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

if (!function_exists('bf_menu_label')) {
    /** Nama menu untuk order & rekap kitchen: "Cappuccino" + serve_temp ice -> "Cappuccino (Ice)". */
    function bf_menu_label(array $m): string
    {
        $name = (string)($m['menu_name'] ?? '');
        $temp = (string)($m['serve_temp'] ?? '');
        if (($temp === 'hot' || $temp === 'ice') && !preg_match('/\b(hot|ice|iced)\b/i', $name)) {
            $name .= $temp === 'ice' ? ' (Ice)' : ' (Hot)';
        }
        return $name;
    }
}

function ensure_breakfast_orders_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NULL,
        guest_name VARCHAR(500) NOT NULL,
        room_number TEXT,
        total_pax INT DEFAULT 1,
        breakfast_time TIME,
        breakfast_date DATE,
        location VARCHAR(20) DEFAULT 'restaurant',
        breakfast_location VARCHAR(120) NULL,
        on_the_spot TINYINT(1) NOT NULL DEFAULT 0,
        menu_items TEXT,
        special_requests TEXT,
        total_price DECIMAL(10,2) DEFAULT 0.00,
        order_status VARCHAR(20) DEFAULT 'pending',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_booking_date (booking_id, breakfast_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_portal_links_table($pdo, $runAlter = true)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_guest_links (
        id INT PRIMARY KEY AUTO_INCREMENT,
        token VARCHAR(80) NOT NULL,
        short_code VARCHAR(16) NULL,
        booking_id INT NULL,
        guest_id INT NULL,
        guest_name VARCHAR(500) NOT NULL,
        guest_phone VARCHAR(60) NULL,
        room_number TEXT,
        breakfast_date DATE NOT NULL,
        max_main INT NOT NULL DEFAULT 2,
        max_drink INT NOT NULL DEFAULT 2,
        max_child INT NOT NULL DEFAULT 2,
        child_menu_ids TEXT,
        link_status VARCHAR(20) NOT NULL DEFAULT 'open',
        selected_menu_ids TEXT,
        selected_menu_notes TEXT,
        selected_menu_qty TEXT,
        selected_drink_ids TEXT,
        selected_drink_notes TEXT,
        selected_drink_qty TEXT,
        selected_child_ids TEXT,
        selected_child_notes TEXT,
        selected_child_qty TEXT,
        breakfast_time TIME NULL,
        breakfast_service VARCHAR(20) NULL,
        breakfast_location VARCHAR(120) NULL,
        on_the_spot TINYINT(1) NOT NULL DEFAULT 0,
        special_requests TEXT,
        expires_at DATETIME NULL,
        submitted_at DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_token (token),
        UNIQUE KEY uk_short_code (short_code),
        INDEX idx_guest_date (guest_name(191), breakfast_date),
        INDEX idx_status (link_status),
        INDEX idx_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$runAlter) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN short_code VARCHAR(16) NULL AFTER token");
    } catch (Exception $e) {
    }
    // Booking grup: link induk + satu link per kamar (parent_token = token link induk)
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN parent_token VARCHAR(80) NULL");
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD INDEX idx_parent (parent_token)");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD UNIQUE INDEX uk_short_code (short_code)");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN max_drink INT NOT NULL DEFAULT 2 AFTER max_main");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_ids TEXT AFTER selected_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_menu_notes TEXT AFTER selected_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_notes TEXT AFTER selected_drink_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_menu_qty TEXT AFTER selected_menu_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_drink_qty TEXT AFTER selected_drink_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_child_notes TEXT AFTER selected_child_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_child_qty TEXT AFTER selected_child_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN guest_composition TEXT AFTER child_menu_ids");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_time TIME NULL AFTER selected_child_notes");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_service VARCHAR(20) NULL AFTER breakfast_time");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN breakfast_location VARCHAR(120) NULL AFTER breakfast_service");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN on_the_spot TINYINT(1) NOT NULL DEFAULT 0 AFTER breakfast_location");
    } catch (Exception $e) {
    }
}

function ensure_breakfast_quota_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_guest_quota (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NOT NULL,
        guest_id INT NULL,
        guest_name VARCHAR(255) NULL,
        breakfast_date DATE NULL,
        adult_count INT NOT NULL DEFAULT 1,
        child_young_count INT NOT NULL DEFAULT 0,
        child_old_count INT NOT NULL DEFAULT 0,
        total_pax INT NOT NULL DEFAULT 1,
        max_main INT NOT NULL DEFAULT 2,
        max_drink INT NOT NULL DEFAULT 2,
        max_child INT NOT NULL DEFAULT 2,
        child_menu_ids TEXT,
        extra_main_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        extra_drink_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        extra_child_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_booking_id (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_booking_extras_table($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_extras (
        id INT PRIMARY KEY AUTO_INCREMENT,
        booking_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes TEXT,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function parse_json_body()
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_setting($db, $key)
{
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row['setting_value'] ?? '';
}

function default_portal_info_text()
{
    return implode("\n\n", [
        'Hello, here is your breakfast menu selection portal.',
        'Each room has a fixed breakfast allowance.',
        'Please confirm your selection through this link only. If you need to make changes, please contact Front Office.'
    ]);
}

function looks_like_old_indonesian_portal_text($text)
{
    $text = strtolower(trim((string)$text));
    if ($text === '') return false;
    return strpos($text, 'hai kak') !== false
        || strpos($text, 'pilihan menu breakfast') !== false
        || strpos($text, 'mohon konfirmasi menu') !== false
        || strpos($text, 'front office') !== false;
}

function to_float($value, $default = 0)
{
    if ($value === null || $value === '') return (float)$default;
    return (float)$value;
}

function create_short_code()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = 8;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// auto_submit_on_the_spot_after_midnight() dipindah ke includes/BreakfastAutoSpot.php (juga dipakai cron)
require_once __DIR__ . '/../includes/BreakfastAutoSpot.php';

function detect_guest_preferred_language($db, $link)
{
    $nationality = '';

    try {
        if (!empty($link['booking_id'])) {
            $row = $db->fetchOne(
                "SELECT g.nationality
                 FROM bookings b
                 LEFT JOIN guests g ON b.guest_id = g.id
                 WHERE b.id = ?
                 LIMIT 1",
                [(int)$link['booking_id']]
            );
            $nationality = trim((string)($row['nationality'] ?? ''));
        }

        if ($nationality === '' && !empty($link['guest_name'])) {
            $rowByName = $db->fetchOne(
                "SELECT nationality
                 FROM guests
                 WHERE LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
                 ORDER BY id DESC
                 LIMIT 1",
                [$link['guest_name']]
            );
            $nationality = trim((string)($rowByName['nationality'] ?? ''));
        }
    } catch (Exception $e) {
        $nationality = '';
    }

    $n = strtolower($nationality);
    $isLocal = false;
    foreach (['indonesia', 'indonesian', 'wni', 'id'] as $kw) {
        if ($n !== '' && strpos($n, $kw) !== false) {
            $isLocal = true;
            break;
        }
    }

    return [
        'preferred_lang' => $isLocal ? 'id' : 'en',
        'nationality' => $nationality
    ];
}

const BF_DEMO_TOKEN = 'demo-preview';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = parse_json_body();
if (!$action && !empty($body['action'])) {
    $action = $body['action'];
}

try {
    if ($action === 'create_link') {
        ensure_breakfast_orders_table($pdo);
        ensure_portal_links_table($pdo, true);
        ensure_breakfast_quota_table($pdo);
        ensure_booking_extras_table($pdo);

        // Ensure unique key on breakfast_orders
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD UNIQUE KEY uk_booking_date (booking_id, breakfast_date)");
        } catch (Exception $e) {
        }

        // Add new columns if not exist
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN adult_count INT NOT NULL DEFAULT 1 AFTER breakfast_date");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_young_count INT NOT NULL DEFAULT 0 AFTER adult_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_old_count INT NOT NULL DEFAULT 0 AFTER child_young_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN total_pax INT NOT NULL DEFAULT 1 AFTER child_old_count");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN max_drink INT NOT NULL DEFAULT 2 AFTER max_main");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN extra_drink_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER extra_main_price");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("UPDATE breakfast_guest_quota SET extra_drink_price = 20000 WHERE extra_drink_price = 75000 OR extra_drink_price = 0");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN breakfast_location VARCHAR(120) NULL AFTER location");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN on_the_spot TINYINT(1) NOT NULL DEFAULT 0 AFTER breakfast_location");
        } catch (Exception $e) {
        }
    } elseif ($action === 'submit_link') {
        // Submit may write new portal columns (notes/qty), ensure they exist.
        ensure_portal_links_table($pdo, true);
        ensure_breakfast_orders_table($pdo);
        ensure_booking_extras_table($pdo);
    } elseif ($action === 'get_link') {
        // Public portal read endpoint should stay lightweight.
        ensure_portal_links_table($pdo, false);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal inisialisasi tabel: ' . $e->getMessage()]);
    exit;
}

// ═══ Front desk: simpan jatah pax (Setup) & kirim link lewat WhatsApp gateway ═══
if ($action === 'save_setup' || $action === 'send_wa' || $action === 'save_phone') {
    require_once '../includes/auth.php';
    $auth = new Auth();
    $auth->requireLogin();
    if (!$auth->hasPermission('frontdesk')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    if ($action === 'save_setup') {
        // Jatah total disimpan di booking pertama; booking lain dalam baris/grup = 0 agar tidak dihitung dobel.
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['booking_ids'] ?? [])))));
        $pax = max(1, min(60, (int)($body['pax'] ?? 1)));
        $kids = max(0, min(30, (int)($body['kids'] ?? 0)));
        $kidMenuIds = bf_kid_menu_ids($db);
        if (!$ids) {
            echo json_encode(['success' => false, 'message' => 'Booking tidak ditemukan']);
            exit;
        }
        ensure_breakfast_quota_table($pdo);
        $price = bf_extra_package_price($db);
        $stmt = $pdo->prepare("INSERT INTO breakfast_guest_quota
            (booking_id, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, created_by)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?)
            ON DUPLICATE KEY UPDATE adult_count = VALUES(adult_count), child_young_count = VALUES(child_young_count), child_old_count = 0,
                total_pax = VALUES(total_pax), max_main = VALUES(max_main), max_drink = VALUES(max_drink), max_child = VALUES(max_child), child_menu_ids = VALUES(child_menu_ids),
                extra_main_price = VALUES(extra_main_price), extra_drink_price = 0, extra_child_price = 0, updated_at = NOW()");
        foreach ($ids as $i => $bid) {
            $p = $i === 0 ? $pax : 0;
            $k = $i === 0 ? $kids : 0;
            $stmt->execute([$bid, $p, $k, $p + $k, $p, $p * 2, $k, json_encode($kidMenuIds), $price, $_SESSION['user_id'] ?? null]);
        }
        echo json_encode(['success' => true, 'pax' => $pax, 'kids' => $kids]);
        exit;
    }

    if ($action === 'save_phone') {
        // Ubah nomor WhatsApp tamu dari daftar Breakfast.
        $guestId = (int)($body['guest_id'] ?? 0);
        $phone = trim((string)($body['phone'] ?? ''));
        if ($guestId <= 0 || $phone === '' || WhatsAppHelperNormalize($phone) === '') {
            echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid']);
            exit;
        }
        $phone = mb_substr($phone, 0, 30);
        // Satu baris Breakfast bisa berisi beberapa booking/tamu (grup). Semua data tamu di baris itu
        // ikut diperbarui, supaya nomor yang sama terpakai di Reservasi, Invoice, Kalender, dll.
        $guestIds = [$guestId];
        $bookingIds = array_values(array_unique(array_filter(array_map('intval', (array)($body['booking_ids'] ?? [])))));
        if ($bookingIds) {
            $ph = implode(',', array_fill(0, count($bookingIds), '?'));
            foreach ($db->fetchAll("SELECT DISTINCT guest_id FROM bookings WHERE id IN ($ph) AND guest_id IS NOT NULL", $bookingIds) ?: [] as $gr) {
                $guestIds[] = (int)$gr['guest_id'];
            }
        }
        $guestIds = array_values(array_unique(array_filter($guestIds)));
        $gph = implode(',', array_fill(0, count($guestIds), '?'));
        $db->query("UPDATE guests SET phone = ? WHERE id IN ($gph)", array_merge([$phone], $guestIds));
        // Link sarapan yang masih aktif untuk tamu/booking ini memakai nomor baru juga
        try {
            $sql = "UPDATE breakfast_guest_links SET guest_phone = ? WHERE guest_id IN ($gph)";
            $params = array_merge([$phone], $guestIds);
            if ($bookingIds) {
                $sql .= " OR booking_id IN (" . implode(',', array_fill(0, count($bookingIds), '?')) . ")";
                $params = array_merge($params, $bookingIds);
            }
            $db->query($sql, $params);
        } catch (\Throwable $e) {
        }
        echo json_encode(['success' => true, 'phone' => $phone, 'guests_updated' => count($guestIds)]);
        exit;
    }

    // send_wa: kirim pesan berisi link lewat Fonnte; simpan nomor ke data tamu bila sebelumnya kosong.
    require_once '../includes/WhatsAppHelper.php';
    $wa = new WhatsAppHelper($db);
    $target = WhatsAppHelper::normalizeTarget((string)($body['target'] ?? ''));
    $message = trim((string)($body['message'] ?? ''));
    if ($target === '' || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid']);
        exit;
    }
    $guestId = (int)($body['guest_id'] ?? 0);
    if ($guestId > 0 && !empty($body['save_phone'])) {
        try {
            $db->query("UPDATE guests SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')", [(string)$body['target'], $guestId]);
        } catch (\Throwable $e) {
        }
    }
    if (!$wa->isConfigured()) {
        echo json_encode(['success' => false, 'gateway' => false, 'target' => $target, 'message' => 'WhatsApp gateway belum diatur']);
        exit;
    }
    $res = $wa->send($target, $message, null, null, 'breakfast', (string)($body['ref'] ?? 'breakfast link'));
    echo json_encode(['success' => $res['ok'], 'gateway' => true, 'target' => $target, 'message' => $res['detail']]);
    exit;
}

if ($action === 'create_link') {
    require_once '../includes/auth.php';
    $auth = new Auth();
    $auth->requireLogin();
    if (!$auth->hasPermission('frontdesk')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $guestName = trim((string)($body['guest_name'] ?? ''));
    $guestPhone = trim((string)($body['guest_phone'] ?? ''));
    $guestId = !empty($body['guest_id']) ? (int)$body['guest_id'] : null;
    $bookingId = !empty($body['booking_id']) ? (int)$body['booking_id'] : null;
    $rooms = $body['room_number'] ?? [];
    if (!is_array($rooms)) $rooms = [$rooms];
    $rooms = array_values(array_unique(array_filter(array_map('trim', $rooms))));

    $breakfastDate = !empty($body['breakfast_date']) ? $body['breakfast_date'] : hotel_date();

    // New quota structure based on guest composition
    $adultCount = max(0, (int)($body['adult_count'] ?? 1));
    $childYoung = max(0, (int)($body['child_young_count'] ?? 0)); // < 7 years old
    $childOld = 0; // kids >=7 are not configured separately in this flow

    $maxMain = max(0, (int)($body['max_main'] ?? 2));
    $maxDrink = max(0, (int)($body['max_drink'] ?? 2));
    $maxChild = max(0, (int)($body['max_child'] ?? 2)); // for young children
    $expireHours = max(1, min(72, (int)($body['expire_hours'] ?? 24)));

    // Apply exact setup quotas per guest (do not multiply by pax)
    $totalPax = max(1, (int)($body['total_pax'] ?? ($adultCount + $childYoung + $childOld)));
    $totalMainQuota = $maxMain;
    $totalDrinkQuota = $maxDrink;
    $totalChildQuota = $maxChild;

    // Extra dihitung per paket (lihat BreakfastHelper); harga disimpan di extra_main_price.
    $extraMainPrice = bf_extra_package_price($db);
    $extraDrinkPrice = 0.0;
    $extraChildPrice = 0.0;
    $otherBookingIds = array_values(array_diff(array_unique(array_filter(array_map('intval', (array)($body['booking_ids'] ?? [])))), [(int)$bookingId]));

    $childMenuIds = $body['child_menu_ids'] ?? [];
    if (!is_array($childMenuIds)) $childMenuIds = [];
    $childMenuIds = array_values(array_unique(array_map('intval', $childMenuIds)));
    $childMenuIds = array_values(array_filter($childMenuIds, function ($v) {
        return $v > 0;
    }));

    if ($maxChild > 0) {
        $childMenuIds = bf_kid_menu_ids($db);
    }

    if ($guestName === '') {
        echo json_encode(['success' => false, 'message' => 'Nama tamu wajib diisi']);
        exit;
    }
    if (($maxMain + $maxDrink + $maxChild) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jatah menu minimal 1']);
        exit;
    }

    $token = bin2hex(random_bytes(24));
    $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $roomJson = json_encode($rooms);
    $childJson = json_encode($childMenuIds);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expireHours . ' hours'));

    // Store guest composition for quota calculation
    $totalPax = $adultCount + $childYoung + $childOld;
    $guestCompositionJson = json_encode([
        'adults' => $adultCount,
        'children_young' => $childYoung,  // < 7 years
        'children_old' => $childOld,      // >= 7 years
        'total_pax' => $totalPax
    ]);

    try {
        // Expire previous open links scoped by booking (room) when available,
        // so group bookings with identical guest names don't cancel each other's link.
        if ($bookingId) {
            $pdo->prepare("UPDATE breakfast_guest_links
                SET link_status = 'expired'
                WHERE breakfast_date = ? AND booking_id = ? AND link_status = 'open'")
                ->execute([$breakfastDate, $bookingId]);
        } else {
            $pdo->prepare("UPDATE breakfast_guest_links
                SET link_status = 'expired'
                WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?)) AND link_status = 'open'")
                ->execute([$breakfastDate, $guestName]);
        }

        if ($bookingId) {
            $pdo->prepare("INSERT INTO breakfast_guest_quota
                (booking_id, guest_id, guest_name, breakfast_date, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    guest_id = VALUES(guest_id),
                    guest_name = VALUES(guest_name),
                    breakfast_date = VALUES(breakfast_date),
                    adult_count = VALUES(adult_count),
                    child_young_count = VALUES(child_young_count),
                    child_old_count = VALUES(child_old_count),
                    total_pax = VALUES(total_pax),
                    max_main = VALUES(max_main),
                    max_drink = VALUES(max_drink),
                    max_child = VALUES(max_child),
                    child_menu_ids = VALUES(child_menu_ids),
                    extra_main_price = VALUES(extra_main_price),
                    extra_drink_price = VALUES(extra_drink_price),
                    extra_child_price = VALUES(extra_child_price),
                    updated_at = NOW()")
                ->execute([
                    $bookingId,
                    $guestId,
                    $guestName,
                    $breakfastDate,
                    $adultCount,
                    $childYoung,
                    $childOld,
                    $totalPax,
                    $maxMain,
                    $maxDrink,
                    $maxChild,
                    $childJson,
                    $extraMainPrice,
                    $extraDrinkPrice,
                    $extraChildPrice,
                    $userId
                ]);
        }

        foreach ($otherBookingIds as $obid) {
            $pdo->prepare("INSERT INTO breakfast_guest_quota (booking_id, guest_name, breakfast_date, adult_count, total_pax, max_main, max_drink, max_child, extra_main_price, created_by)
                VALUES (?, ?, ?, 0, 0, 0, 0, 0, ?, ?)
                ON DUPLICATE KEY UPDATE adult_count = 0, total_pax = 0, max_main = 0, max_drink = 0, max_child = 0, updated_at = NOW()")
                ->execute([$obid, $guestName, $breakfastDate, $extraMainPrice, $userId]);
        }

        $shortCode = null;
        $inserted = false;
        for ($i = 0; $i < 5; $i++) {
            $shortCode = create_short_code();
            try {
                $pdo->prepare("INSERT INTO breakfast_guest_links
                    (token, short_code, booking_id, guest_id, guest_name, guest_phone, room_number, breakfast_date,
                     max_main, max_drink, max_child, child_menu_ids, guest_composition, expires_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $token,
                        $shortCode,
                        $bookingId,
                        $guestId,
                        $guestName,
                        $guestPhone,
                        $roomJson,
                        $breakfastDate,
                        $maxMain,
                        $maxDrink,
                        $maxChild,
                        $childJson,
                        $guestCompositionJson,
                        $expiresAt,
                        $userId
                    ]);
                $inserted = true;
                break;
            } catch (Exception $innerEx) {
                if ($i === 4) {
                    throw $innerEx;
                }
            }
        }
        if (!$inserted) {
            throw new Exception('Tidak dapat membuat short link');
        }

        // Booking grup: satu link per kamar (anak dari link induk). Jatah dihitung per kamar dari jumlah tamu reservasinya;
        // bila jatah gabungan disetel manual (tidak sama dengan jumlah tamu reservasi), tetap satu pilihan gabungan.
        $roomLinks = [];
        if ($bookingId && $otherBookingIds) {
            $allIds = array_values(array_unique(array_merge([$bookingId], $otherBookingIds)));
            $phIds = implode(',', array_fill(0, count($allIds), '?'));
            $bks = $db->fetchAll("SELECT b.id, b.guest_id, b.adults, b.children, g.guest_name, r.room_number
                FROM bookings b JOIN rooms r ON r.id = b.room_id LEFT JOIN guests g ON g.id = b.guest_id
                WHERE b.id IN ($phIds) AND b.status IN ('checked_in', 'confirmed', 'pending')
                ORDER BY r.room_number + 0, r.room_number", $allIds) ?: [];
            $paxSum = array_sum(array_map(fn($b) => max(1, (int)$b['adults'] + (int)$b['children']), $bks));
            if (count($bks) > 1 && $paxSum === ($adultCount + $childYoung + $childOld)) {
                $kidMenuDefaults = bf_kid_menu_ids($db);
                foreach ($bks as $bk) {
                    $p = max(1, (int)$bk['adults'] + (int)$bk['children']);
                    $bid = (int)$bk['id'];
                    // jatah per kamar (sama aturan "Setup": 1 makanan + 2 minuman per tamu)
                    $pdo->prepare("INSERT INTO breakfast_guest_quota
                        (booking_id, guest_id, guest_name, breakfast_date, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, created_by)
                        VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, ?, ?, 0, 0, ?)
                        ON DUPLICATE KEY UPDATE guest_id = VALUES(guest_id), guest_name = VALUES(guest_name), breakfast_date = VALUES(breakfast_date),
                            adult_count = VALUES(adult_count), child_young_count = 0, child_old_count = 0, total_pax = VALUES(total_pax),
                            max_main = VALUES(max_main), max_drink = VALUES(max_drink), max_child = 0, child_menu_ids = VALUES(child_menu_ids),
                            extra_main_price = VALUES(extra_main_price), extra_drink_price = 0, extra_child_price = 0, updated_at = NOW()")
                        ->execute([$bid, $bk['guest_id'] ?: $guestId, $bk['guest_name'] ?: $guestName, $breakfastDate, $p, $p, $p, $p * 2, json_encode($kidMenuDefaults), $extraMainPrice, $userId]);
                    // link lama untuk kamar ini (selain induk baru) dinonaktifkan
                    $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE breakfast_date = ? AND booking_id = ? AND link_status = 'open' AND token <> ? AND parent_token IS NULL")->execute([$breakfastDate, $bid, $token]);
                    $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE breakfast_date = ? AND booking_id = ? AND link_status = 'open' AND parent_token IS NOT NULL AND parent_token <> ?")->execute([$breakfastDate, $bid, $token]);
                    $childToken = bin2hex(random_bytes(24));
                    $childCode = null;
                    for ($i = 0; $i < 5; $i++) {
                        $childCode = create_short_code();
                        try {
                            $pdo->prepare("INSERT INTO breakfast_guest_links
                                (token, short_code, booking_id, guest_id, guest_name, guest_phone, room_number, breakfast_date,
                                 max_main, max_drink, max_child, child_menu_ids, guest_composition, expires_at, created_by, parent_token)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)")
                                ->execute([
                                    $childToken, $childCode, $bid, $bk['guest_id'] ?: $guestId, $bk['guest_name'] ?: $guestName, $guestPhone,
                                    json_encode([(string)$bk['room_number']]), $breakfastDate, $p, $p * 2, json_encode([]),
                                    json_encode(['adults' => $p, 'children_young' => 0, 'children_old' => 0, 'total_pax' => $p]),
                                    $expiresAt, $userId, $token,
                                ]);
                            $roomLinks[] = ['room' => (string)$bk['room_number'], 'token' => $childToken, 'short_code' => $childCode];
                            break;
                        } catch (Exception $innerEx) {
                            if ($i === 4) throw $innerEx;
                        }
                    }
                }
            }
        }

        $linkUrl = rtrim(BASE_URL, '/') . '/modules/frontdesk/breakfast-guest.php?t=' . urlencode($token);
        $shortLink = rtrim(BASE_URL, '/') . '/go-breakfast.php?k=' . urlencode($shortCode);
        echo json_encode([
            'success' => true,
            'message' => 'Link berhasil dibuat',
            'data' => [
                'token' => $token,
                'short_code' => $shortCode,
                'link_url' => $linkUrl,
                'short_link' => $shortLink,
                'expires_at' => $expiresAt,
                'rooms' => $roomLinks
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal membuat link: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_link') {
    $token = trim((string)($_GET['token'] ?? $body['token'] ?? ''));
    $shortCode = trim((string)($_GET['k'] ?? $body['k'] ?? ''));
    if ($token === '' && $shortCode === '') {
        echo json_encode(['success' => false, 'message' => 'Token wajib']);
        exit;
    }

    if ($token === 'demo-group') {
        // Contoh link grup 3 kamar (tidak menyimpan apa pun)
        echo json_encode(['success' => true, 'data' => [
            'is_group' => true, 'token' => 'demo-group', 'guest_name' => 'Sample Group', 'breakfast_date' => date('Y-m-d', strtotime('+1 day')),
            'portal_logo_url' => '', 'preferred_lang' => 'en',
            'rooms' => [
                ['token' => 'demo-preview-101', 'room_number' => '101', 'status' => 'open', 'pax' => 2, 'on_the_spot' => 0],
                ['token' => 'demo-preview-102', 'room_number' => '102', 'status' => 'open', 'pax' => 2, 'on_the_spot' => 0],
                ['token' => 'demo-preview-103', 'room_number' => '103', 'status' => 'open', 'pax' => 1, 'on_the_spot' => 0],
            ],
        ]]);
        exit;
    }
    $demoRoom = '108';
    $demoPax = 2;
    if (strpos($token, 'demo-preview') === 0) {
        if (preg_match('/^demo-preview-(\d+)$/', $token, $dm)) {
            $demoRoom = $dm[1];
            $demoPax = $demoRoom === '103' ? 1 : 2;
        }
        $token = BF_DEMO_TOKEN;
    }
    if ($token === BF_DEMO_TOKEN) {
        // Tautan contoh untuk mencoba tampilan: data tamu rekaan, tidak ada yang tersimpan
        $link = [
            'id' => 0, 'token' => BF_DEMO_TOKEN, 'booking_id' => null, 'guest_name' => 'Sample Guest', 'room_number' => json_encode([$demoRoom]),
            'guest_composition' => json_encode(['adults' => $demoPax, 'children_young' => 0, 'children_old' => 0, 'total_pax' => $demoPax]),
            'breakfast_date' => date('Y-m-d', strtotime('+1 day')), 'max_main' => $demoPax, 'max_drink' => $demoPax * 2, 'max_child' => 0,
            'link_status' => 'open', 'submitted_at' => null, 'expires_at' => null, 'special_requests' => '',
            'breakfast_time' => null, 'breakfast_service' => null, 'breakfast_location' => null, 'on_the_spot' => 0, 'short_code' => null,
        ];
    } elseif ($token !== '') {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
    } else {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE short_code = ? LIMIT 1", [$shortCode]);
    }
    if (!$link) {
        echo json_encode(['success' => false, 'message' => 'Link tidak ditemukan']);
        exit;
    }

    try {
        $autoOnSpot = empty($link['id']) ? false : auto_submit_on_the_spot_after_midnight($db, $pdo, $link);
        if ($autoOnSpot) {
            $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE id = ? LIMIT 1", [(int)$link['id']]);
        }
    } catch (Exception $e) {
        // keep portal accessible even if auto-update fails
    }

    // Link induk booking grup: kembalikan daftar kamar (tiap kamar punya link sendiri)
    $groupKids = $token !== '' ? ($db->fetchAll("SELECT * FROM breakfast_guest_links WHERE parent_token = ? ORDER BY id", [(string)($link['token'] ?? '')]) ?: []) : [];
    if ($groupKids) {
        $roomsOut = [];
        foreach ($groupKids as $kid) {
            try {
                if (auto_submit_on_the_spot_after_midnight($db, $pdo, $kid)) {
                    $kid = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE id = ? LIMIT 1", [(int)$kid['id']]) ?: $kid;
                }
            } catch (Exception $e) {
            }
            $kr = json_decode((string)($kid['room_number'] ?? '[]'), true);
            $kidComp = json_decode((string)($kid['guest_composition'] ?? '{}'), true);
            $roomsOut[] = [
                'token' => $kid['token'],
                'room_number' => is_array($kr) && $kr ? (string)$kr[0] : (string)$kid['room_number'],
                'status' => (!empty($kid['submitted_at']) || in_array((string)($kid['link_status'] ?? ''), ['submitted', 'closed', 'locked'], true)) ? 'submitted' : 'open',
                'pax' => (int)($kid['max_main'] ?? 1),
                'on_the_spot' => (int)($kid['on_the_spot'] ?? 0),
            ];
        }
        usort($roomsOut, fn($x, $y) => strnatcmp($x['room_number'], $y['room_number']));
        $pLogoPath = get_setting($db, 'breakfast_portal_logo_path');
        $langG = detect_guest_preferred_language($db, $link);
        echo json_encode(['success' => true, 'data' => [
            'is_group' => true, 'token' => $link['token'], 'short_code' => $link['short_code'] ?? null, 'guest_name' => $link['guest_name'], 'breakfast_date' => $link['breakfast_date'],
            'portal_logo_url' => $pLogoPath ? ((strpos($pLogoPath, 'http') === 0) ? $pLogoPath : rtrim(BASE_URL, '/') . '/' . ltrim($pLogoPath, '/')) : '',
            'preferred_lang' => $langG['preferred_lang'], 'rooms' => $roomsOut,
        ]]);
        exit;
    }

    $linkStatus = (string)($link['link_status'] ?? 'open');
    $isLocked = in_array($linkStatus, ['submitted', 'closed', 'locked'], true) || !empty($link['submitted_at']);

    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
        echo json_encode(['success' => false, 'message' => 'Link sudah kedaluwarsa']);
        exit;
    }

    $menus = $db->fetchAll("SELECT * FROM breakfast_menus WHERE is_available = 1 ORDER BY category, menu_name") ?: [];
    $menuMap = [];
    foreach ($menus as &$m) {
        $m['menu_name'] = bf_menu_label($m);
    }
    unset($m);
    foreach ($menus as $m) {
        $menuMap[(int)$m['id']] = $m;
    }

    // Menu anak selalu mengikuti Setting Breakfast (kategori Kids) saat ini.
    $childIds = (int)($link['max_child'] ?? 0) > 0 ? bf_kid_menu_ids($db) : [];

    $childMenus = [];
    foreach ($childIds as $id) {
        if (isset($menuMap[$id])) $childMenus[] = $menuMap[$id];
    }

    $selectedMainIds = json_decode($link['selected_menu_ids'] ?? '[]', true);
    $selectedDrinkIds = json_decode($link['selected_drink_ids'] ?? '[]', true);
    $selectedChildIds = json_decode($link['selected_child_ids'] ?? '[]', true);
    $selectedMainNotes = json_decode($link['selected_menu_notes'] ?? '{}', true);
    $selectedDrinkNotes = json_decode($link['selected_drink_notes'] ?? '{}', true);
    $selectedChildNotes = json_decode($link['selected_child_notes'] ?? '{}', true);
    $selectedMainQty = json_decode($link['selected_menu_qty'] ?? '{}', true);
    $selectedDrinkQty = json_decode($link['selected_drink_qty'] ?? '{}', true);
    $selectedChildQty = json_decode($link['selected_child_qty'] ?? '{}', true);
    if (!is_array($selectedMainIds)) $selectedMainIds = [];
    if (!is_array($selectedDrinkIds)) $selectedDrinkIds = [];
    if (!is_array($selectedChildIds)) $selectedChildIds = [];
    if (!is_array($selectedMainNotes)) $selectedMainNotes = [];
    if (!is_array($selectedDrinkNotes)) $selectedDrinkNotes = [];
    if (!is_array($selectedChildNotes)) $selectedChildNotes = [];
    if (!is_array($selectedMainQty)) $selectedMainQty = [];
    if (!is_array($selectedDrinkQty)) $selectedDrinkQty = [];
    if (!is_array($selectedChildQty)) $selectedChildQty = [];
    $selectedMainIds = array_values(array_unique(array_map('intval', $selectedMainIds)));
    $selectedDrinkIds = array_values(array_unique(array_map('intval', $selectedDrinkIds)));
    $selectedChildIds = array_values(array_unique(array_map('intval', $selectedChildIds)));
    foreach ($selectedMainIds as $id) {
        $v = (int)($selectedMainQty[(string)$id] ?? 1);
        $selectedMainQty[(string)$id] = max(1, $v);
    }
    foreach ($selectedDrinkIds as $id) {
        $v = (int)($selectedDrinkQty[(string)$id] ?? 1);
        $selectedDrinkQty[(string)$id] = max(1, $v);
    }
    foreach ($selectedChildIds as $id) {
        $v = (int)($selectedChildQty[(string)$id] ?? 1);
        $selectedChildQty[(string)$id] = max(1, $v);
    }

    // Separate drinks from main courses
    $drinkCategories = ['drinks', 'beverages'];
    $alwaysMainNames = ['pancake', 'waffle'];
    $drinkMenus = [];
    $mainMenus = [];
    foreach ($menus as $m) {
        $menuId = (int)$m['id'];
        $menuNameLower = strtolower(trim((string)($m['menu_name'] ?? '')));
        $m['pre_selected'] = false;
        if ($isLocked) {
            $m['pre_selected'] = in_array($menuId, $selectedMainIds, true) || in_array($menuId, $selectedDrinkIds, true) || in_array($menuId, $selectedChildIds, true);
        }
        if (strtolower((string)($m['category'] ?? '')) === 'kids') continue; // hanya di bagian For Kids
        if (in_array($menuId, $childIds, true) && !in_array($menuNameLower, $alwaysMainNames, true)) continue;
        if (in_array(strtolower($m['category'] ?? ''), $drinkCategories, true)) {
            $m['drink_kind'] = bf_drink_kind((string)$m['menu_name']);
            $drinkMenus[] = $m;
        } else {
            $mainMenus[] = $m;
        }
    }

    $rooms = json_decode($link['room_number'] ?? '[]', true);
    if (!is_array($rooms)) {
        $rooms = !empty($link['room_number']) ? [$link['room_number']] : [];
    }

    $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
    if (!is_array($guestComposition)) $guestComposition = [];
    $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));

    $waInfo = get_setting($db, 'breakfast_wa_info_text');
    if ($waInfo === '' || looks_like_old_indonesian_portal_text($waInfo)) {
        $waInfo = default_portal_info_text();
    }
    $waMediaPath = get_setting($db, 'breakfast_wa_media_path');
    $portalLogoPath = get_setting($db, 'breakfast_portal_logo_path');
    $extraMainPrice = 75000.0;
    $extraDrinkPrice = 20000.0;
    $extraChildPrice = 75000.0;

    $quotaRow = null;
    if (!empty($link['booking_id'])) {
        $quotaRow = $db->fetchOne("SELECT extra_main_price, extra_drink_price, extra_child_price FROM breakfast_guest_quota WHERE booking_id = ? LIMIT 1", [(int)$link['booking_id']]);
    }
    if (!$quotaRow) {
        $quotaRow = $db->fetchOne(
            "SELECT extra_main_price, extra_drink_price, extra_child_price
             FROM breakfast_guest_quota
             WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [$link['breakfast_date'], $link['guest_name']]
        );
    }
    if ($quotaRow) {
        $extraMainPrice = max(0, to_float($quotaRow['extra_main_price'] ?? 75000, 75000));
        $extraDrinkPrice = max(0, to_float($quotaRow['extra_drink_price'] ?? 20000, 20000));
        if ((int)round($extraDrinkPrice) === 75000) $extraDrinkPrice = 20000.0;
        $extraChildPrice = max(0, to_float($quotaRow['extra_child_price'] ?? 75000, 75000));
    }
    // Kids / child portion (fruit) is ALWAYS free - never charged.
    $extraChildPrice = 0.0;
    $waMediaUrl = '';
    if ($waMediaPath) {
        $waMediaUrl = (strpos($waMediaPath, 'http') === 0)
            ? $waMediaPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($waMediaPath, '/');
    }

    $portalLogoUrl = '';
    if ($portalLogoPath) {
        $portalLogoUrl = (strpos($portalLogoPath, 'http') === 0)
            ? $portalLogoPath
            : rtrim(BASE_URL, '/') . '/' . ltrim($portalLogoPath, '/');
    }

    $specialRequestsLink = (string)($link['special_requests'] ?? '');
    $isAutoOnSpotMidnight = strpos($specialRequestsLink, '[AUTO ON THE SPOT MIDNIGHT]') !== false;
    $autoOnSpotMessageEn = "We are sorry, because your breakfast menu was not selected before 05:00, you can order directly at the restaurant this morning. Thank you for your understanding. If you need help, please contact Front Office.";
    $autoOnSpotMessageId = "Mohon maaf, karena menu sarapan belum dipilih sebelum pukul 05:00, pagi ini Anda bisa langsung memesan di restoran. Terima kasih atas pengertiannya. Bila perlu bantuan, silakan hubungi Front Office.";
    $langInfo = detect_guest_preferred_language($db, $link);

    echo json_encode([
        'success' => true,
        'data' => [
            'token' => $token,
            'short_code' => $link['short_code'] ?? null,
            'guest_name' => $link['guest_name'],
            'room_number' => $rooms,
            'breakfast_date' => $link['breakfast_date'],
            'total_pax' => $totalPax,
            'max_main' => (int)$link['max_main'],
            'max_drink' => (int)($link['max_drink'] ?? 2),
            'max_child' => (int)$link['max_child'],
            'extra_main_price' => (float)$extraMainPrice,
            'extra_drink_price' => (float)$extraDrinkPrice,
            'extra_child_price' => (float)$extraChildPrice,
            'extra_package_price' => bf_extra_package_price($db),
            'main_menus' => $mainMenus,
            'drink_menus' => $drinkMenus,
            'child_menus' => $childMenus,
            'wa_info_text' => $waInfo,
            'wa_media_url' => $waMediaUrl,
            'portal_logo_url' => $portalLogoUrl,
            'expires_at' => $link['expires_at'],
            'submitted_at' => $link['submitted_at'] ?? null,
            'is_locked' => $isLocked,
            'breakfast_time' => $link['breakfast_time'] ?? null,
            'breakfast_service' => $link['breakfast_service'] ?? null,
            'breakfast_location' => $link['breakfast_location'] ?? null,
            'on_the_spot' => (int)($link['on_the_spot'] ?? 0),
            'auto_on_the_spot_midnight' => $isAutoOnSpotMidnight,
            'auto_on_the_spot_message' => $isAutoOnSpotMidnight ? $autoOnSpotMessageEn : '',
            'auto_on_the_spot_message_en' => $isAutoOnSpotMidnight ? $autoOnSpotMessageEn : '',
            'auto_on_the_spot_message_id' => $isAutoOnSpotMidnight ? $autoOnSpotMessageId : '',
            'preferred_lang' => $langInfo['preferred_lang'],
            'guest_nationality' => $langInfo['nationality'],
            'selected_main_ids' => $selectedMainIds,
            'selected_drink_ids' => $selectedDrinkIds,
            'selected_child_ids' => $selectedChildIds,
            'selected_main_notes' => $selectedMainNotes,
            'selected_drink_notes' => $selectedDrinkNotes,
            'selected_child_notes' => $selectedChildNotes,
            'selected_main_qty' => $selectedMainQty,
            'selected_drink_qty' => $selectedDrinkQty,
            'selected_child_qty' => $selectedChildQty
        ]
    ]);
    exit;
}

if ($action === 'submit_link') {
    $token = trim((string)($body['token'] ?? ''));
    $reqLang = strtolower(trim((string)($body['lang'] ?? 'en')));
    if (!in_array($reqLang, ['en', 'id'], true)) $reqLang = 'en';
    $msg = function ($idText, $enText) use ($reqLang) {
        return $reqLang === 'id' ? $idText : $enText;
    };
    if ($token === '') {
        echo json_encode(['success' => false, 'message' => $msg('Token wajib', 'Token is required')]);
        exit;
    }
    if ($token === BF_DEMO_TOKEN || strpos($token, 'demo-preview') === 0) {
        // Tautan contoh: pura-pura berhasil, tidak menyimpan apa pun
        echo json_encode(['success' => true, 'demo' => true, 'data' => ['extra_total_price' => 0]]);
        exit;
    }

    $selectedMain = $body['selected_main'] ?? [];
    $selectedDrink = $body['selected_drink'] ?? [];
    $selectedChild = $body['selected_child'] ?? [];
    $selectedMainQtyRaw = $body['selected_main_qty'] ?? [];
    $selectedDrinkQtyRaw = $body['selected_drink_qty'] ?? [];
    $selectedChildQtyRaw = $body['selected_child_qty'] ?? [];
    if (!is_array($selectedMain)) $selectedMain = [];
    if (!is_array($selectedDrink)) $selectedDrink = [];
    if (!is_array($selectedChild)) $selectedChild = [];
    $selectedMain = array_values(array_unique(array_map('intval', $selectedMain)));
    $selectedDrink = array_values(array_unique(array_map('intval', $selectedDrink)));
    $selectedChild = array_values(array_unique(array_map('intval', $selectedChild)));
    $selectedMain = array_values(array_filter($selectedMain, function ($v) {
        return $v > 0;
    }));
    $selectedDrink = array_values(array_filter($selectedDrink, function ($v) {
        return $v > 0;
    }));
    $selectedChild = array_values(array_filter($selectedChild, function ($v) {
        return $v > 0;
    }));
    $onTheSpot = !empty($body['on_the_spot']) ? 1 : 0;

    $normalizeNotesMap = function ($raw, $allowedIds) {
        if (!is_array($raw)) return [];
        $allowed = [];
        foreach ($allowedIds as $id) {
            $allowed[(int)$id] = true;
        }
        $clean = [];
        foreach ($raw as $k => $v) {
            $id = (int)$k;
            if ($id <= 0 || empty($allowed[$id])) continue;
            $note = trim((string)$v);
            if ($note === '') continue;
            if (mb_strlen($note) > 160) {
                $note = mb_substr($note, 0, 160);
            }
            $clean[(string)$id] = $note;
        }
        return $clean;
    };

    $normalizeQtyMap = function ($raw, $allowedIds) {
        $out = [];
        if (!is_array($raw)) $raw = [];
        $allowed = [];
        foreach ($allowedIds as $id) {
            $allowed[(int)$id] = true;
        }
        foreach ($allowedIds as $id) {
            $sid = (string)(int)$id;
            $qty = (int)($raw[$sid] ?? $raw[(int)$id] ?? 1);
            if ($qty < 1) $qty = 1;
            if ($qty > 50) $qty = 50;
            if (!empty($allowed[(int)$id])) {
                $out[$sid] = $qty;
            }
        }
        return $out;
    };

    $selectedMainNotes = $normalizeNotesMap($body['selected_main_notes'] ?? [], $selectedMain);
    $selectedDrinkNotes = $normalizeNotesMap($body['selected_drink_notes'] ?? [], $selectedDrink);
    $selectedChildNotes = $normalizeNotesMap($body['selected_child_notes'] ?? [], $selectedChild);
    $selectedMainQty = $normalizeQtyMap($selectedMainQtyRaw, $selectedMain);
    $selectedDrinkQty = $normalizeQtyMap($selectedDrinkQtyRaw, $selectedDrink);
    $selectedChildQty = $normalizeQtyMap($selectedChildQtyRaw, $selectedChild);

    $specialRequests = trim((string)($body['special_requests'] ?? ''));
    $serviceType = trim((string)($body['service_type'] ?? $body['location'] ?? ''));
    if (!in_array($serviceType, ['restaurant', 'room_service', 'take_away'], true)) {
        echo json_encode(['success' => false, 'message' => $msg('Pilih layanan breakfast: Restaurant / Room Service / Take Away', 'Please choose a breakfast service: Restaurant / Room Service / Take Away')]);
        exit;
    }

    // Portal tamu: room service tidak tersedia, lokasi selalu restoran.
    if ($serviceType === 'room_service') $serviceType = 'restaurant';
    $body['breakfast_location'] = 'Main Restaurant';
    $breakfastLocation = trim((string)($body['breakfast_location'] ?? ''));
    if ($breakfastLocation === '') {
        echo json_encode(['success' => false, 'message' => $msg('Lokasi breakfast wajib diisi', 'Breakfast location is required')]);
        exit;
    }
    if (mb_strlen($breakfastLocation) > 120) {
        $breakfastLocation = mb_substr($breakfastLocation, 0, 120);
    }

    $breakfastTimeRaw = trim((string)($body['breakfast_time'] ?? ''));
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $breakfastTimeRaw, $mt)) {
        echo json_encode(['success' => false, 'message' => $msg('Waktu breakfast wajib diisi (format HH:MM)', 'Breakfast time is required (format HH:MM)')]);
        exit;
    }
    $breakfastTime = sprintf('%02d:%02d:00', (int)$mt[1], (int)$mt[2]);

    // Pastikan kolom cash_book.booking_id ada SEBELUM transaksi: ALTER TABLE di dalam transaksi
    // memicu implicit commit sehingga commit() di akhir gagal dan tamu menerima pesan error.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM cash_book LIKE 'booking_id'")->fetch()) {
            $pdo->exec("ALTER TABLE cash_book ADD COLUMN booking_id INT NULL AFTER payment_method");
        }
    } catch (Exception $e) {
        // Kolom mungkin sudah ada / tabel belum siap
    }

    $pdo->beginTransaction();
    try {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
        if (!$link) {
            throw new Exception('Link tidak ditemukan');
        }
        if (!empty($link['submitted_at']) || in_array((string)($link['link_status'] ?? 'open'), ['submitted', 'closed', 'locked'], true)) {
            throw new Exception('Menu sudah dikirim. Untuk perubahan, silakan hubungi Front Office.');
        }
        if ($db->fetchOne("SELECT id FROM breakfast_guest_links WHERE parent_token = ? LIMIT 1", [(string)$link['token']])) {
            throw new Exception($msg('Silakan pilih kamar terlebih dahulu', 'Please choose a room first'));
        }
        if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
            $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
            throw new Exception('Link sudah kedaluwarsa');
        }

        $maxMain = max(0, (int)$link['max_main']);
        $maxDrink = max(0, (int)($link['max_drink'] ?? 2));
        $maxChild = max(0, (int)$link['max_child']);

        $sumMainQty = array_sum(array_map('intval', $selectedMainQty));
        $sumDrinkQty = array_sum(array_map('intval', $selectedDrinkQty));
        $sumChildQty = array_sum(array_map('intval', $selectedChildQty));
        $extraMainCount = max(0, $sumMainQty - $maxMain);
        $extraDrinkCount = max(0, $sumDrinkQty - $maxDrink);
        $extraChildCount = max(0, $sumChildQty - $maxChild);

        $allowedChild = $maxChild > 0 ? bf_kid_menu_ids($db) : [];

        foreach ($selectedChild as $id) {
            if (!in_array($id, $allowedChild, true)) {
                throw new Exception($msg('Ada menu anak yang tidak diizinkan', 'Some kids menu items are not allowed'));
            }
        }

        $alwaysMainNames = ['pancake', 'waffle'];
        $allowedChildAlsoMain = [];
        if (count($allowedChild) > 0) {
            $childPlaceholders = implode(',', array_fill(0, count($allowedChild), '?'));
            $childMenus = $db->fetchAll("SELECT id, menu_name FROM breakfast_menus WHERE id IN ($childPlaceholders)", $allowedChild) ?: [];
            foreach ($childMenus as $cm) {
                $nm = strtolower(trim((string)($cm['menu_name'] ?? '')));
                if (in_array($nm, $alwaysMainNames, true)) {
                    $allowedChildAlsoMain[(int)$cm['id']] = true;
                }
            }
        }

        foreach ($selectedMain as $id) {
            if (in_array($id, $allowedChild, true) && empty($allowedChildAlsoMain[(int)$id])) {
                throw new Exception($msg('Menu anak tidak boleh dipilih di menu utama', 'Kids menu cannot be selected in Main Course'));
            }
        }

        $extraMainPrice = 75000.0;
        $extraDrinkPrice = 20000.0;
        $extraChildPrice = 75000.0;

        $quotaRow = null;
        if (!empty($link['booking_id'])) {
            $quotaRow = $db->fetchOne("SELECT extra_main_price, extra_drink_price, extra_child_price FROM breakfast_guest_quota WHERE booking_id = ? LIMIT 1", [(int)$link['booking_id']]);
        }
        if (!$quotaRow) {
            $quotaRow = $db->fetchOne(
                "SELECT extra_main_price, extra_drink_price, extra_child_price
                 FROM breakfast_guest_quota
                 WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?))
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                [$link['breakfast_date'], $link['guest_name']]
            );
        }
        if ($quotaRow) {
            $extraMainPrice = max(0, to_float($quotaRow['extra_main_price'] ?? 75000, 75000));
            $extraDrinkPrice = max(0, to_float($quotaRow['extra_drink_price'] ?? 20000, 20000));
            if ((int)round($extraDrinkPrice) === 75000) $extraDrinkPrice = 20000.0;
            $extraChildPrice = max(0, to_float($quotaRow['extra_child_price'] ?? 75000, 75000));
        }
        // Kids / child portion (fruit) is ALWAYS free - never charged.
        $extraChildPrice = 0.0;

        $menuItems = [];
        $totalPrice = 0;
        $extraChargeTotal = 0;
        $allSelected = array_values(array_unique(array_merge($selectedMain, $selectedDrink, $selectedChild)));
        if (!$onTheSpot && count($allSelected) === 0) {
            throw new Exception($msg('Pilih minimal 1 menu', 'Please select at least 1 menu item'));
        }

        if ($onTheSpot) {
            $menuItems[] = [
                'menu_id' => 0,
                'menu_name' => 'ON THE SPOT (Guest will choose at restaurant)',
                'quantity' => 1,
                'price' => 0,
                'is_free' => 1,
                'group' => 'on_the_spot',
                'is_on_the_spot' => 1
            ];
        } else {
            $placeholders = implode(',', array_fill(0, count($allSelected), '?'));
            $menus = $db->fetchAll("SELECT * FROM breakfast_menus WHERE is_available = 1 AND id IN ($placeholders)", $allSelected) ?: [];
            $menuMap = [];
            foreach ($menus as $m) {
                $m['menu_name'] = bf_menu_label($m);
                $menuMap[(int)$m['id']] = $m;
            }

            $usedMainQty = 0;
            foreach ($selectedMain as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedMainNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedMainQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedMainQty - $maxMain);
                $extraAfter = max(0, ($usedMainQty + $qty) - $maxMain);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'main'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraMainPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraMainPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedMainQty += $qty;
            }

            $usedDrinkQty = 0;
            foreach ($selectedDrink as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedDrinkNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedDrinkQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedDrinkQty - $maxDrink);
                $extraAfter = max(0, ($usedDrinkQty + $qty) - $maxDrink);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'drink'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraDrinkPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraDrinkPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedDrinkQty += $qty;
            }

            $usedChildQty = 0;
            foreach ($selectedChild as $id) {
                if (empty($menuMap[$id])) continue;
                $m = $menuMap[$id];
                $itemNote = trim((string)($selectedChildNotes[(string)$id] ?? ''));
                $qty = max(1, (int)($selectedChildQty[(string)$id] ?? 1));
                $extraBefore = max(0, $usedChildQty - $maxChild);
                $extraAfter = max(0, ($usedChildQty + $qty) - $maxChild);
                $extraQty = max(0, $extraAfter - $extraBefore);
                $item = [
                    'menu_id' => (int)$m['id'],
                    'menu_name' => $m['menu_name'],
                    'quantity' => $qty,
                    'price' => (float)$m['price'],
                    'is_free' => (int)$m['is_free'],
                    'group' => 'child'
                ];
                if ($itemNote !== '') {
                    $item['note'] = $itemNote;
                }
                if ($extraQty > 0) {
                    $item['is_extra'] = 1;
                    $item['extra_base_price'] = $extraChildPrice;
                }
                $menuItems[] = $item;
                if ($extraQty > 0) {
                    $charge = (float)$extraChildPrice * $extraQty;
                    $totalPrice += $charge;
                    $extraChargeTotal += $charge;
                } elseif (!(int)$m['is_free']) {
                    $totalPrice += (float)$m['price'] * $qty;
                }
                $usedChildQty += $qty;
            }

            if (count($menuItems) === 0) {
                throw new Exception('Menu tidak valid');
            }
        }

        // Extra Breakfast per paket: jatah per pax 1 makanan + 1 jus + 1 kopi/teh.
        $bfCnt = bf_count_extra($menuItems, $maxMain, $maxChild);
        if (!$onTheSpot && !$bfCnt['kids_ok']) {
            throw new Exception($msg(
                'Menu anak maksimal ' . $maxChild . ' porsi (1 pancake/waffle per anak di bawah 7 tahun).',
                'Kids menu is limited to ' . $maxChild . ' portion(s): 1 pancake or waffle per child under 7.'
            ));
        }
        if (!$onTheSpot && !$bfCnt['drink_ok']) {
            throw new Exception($msg(
                'Minuman melebihi jatah: maksimal ' . $bfCnt['drink_cap'] . ' jus dan ' . $bfCnt['drink_cap'] . ' kopi/teh. Tambah makanan extra untuk mendapat minuman tambahan.',
                'Too many drinks: you can choose up to ' . $bfCnt['drink_cap'] . ' juice and ' . $bfCnt['drink_cap'] . ' coffee/tea. Add an extra breakfast (main course) to get more drinks.'
            ));
        }
        $extraPackages = $onTheSpot ? 0 : $bfCnt['packages'];
        $extraMainCount = $bfCnt['extra']['main'];
        $extraDrinkCount = $bfCnt['extra']['juice'] + $bfCnt['extra']['coffee'];
        $extraChildCount = 0;
        $extraChargeTotal = $extraPackages * bf_extra_package_price($db);
        // Tanda "extra" per item dari perhitungan lama dibuang; total order = menu berbayar saja.
        $totalPrice = 0;
        foreach ($menuItems as &$mi) {
            unset($mi['is_extra'], $mi['extra_base_price']);
            if (empty($mi['is_free']) && empty($mi['is_on_the_spot'])) $totalPrice += (float)$mi['price'] * (int)$mi['quantity'];
        }
        unset($mi);

        $guestName = $link['guest_name'];
        $breakfastDate = $link['breakfast_date'];
        $bookingId = !empty($link['booking_id']) ? (int)$link['booking_id'] : null;
        $roomJson = $link['room_number'] ?: json_encode([]);
        $menuJson = json_encode($menuItems);
        $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
        if (!is_array($guestComposition)) $guestComposition = [];
        $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));
        $createdBy = isset($link['created_by']) ? (int)$link['created_by'] : 0;

        $portalNote = '[Guest Portal]';
        if ($onTheSpot) {
            $portalNote .= ' ON THE SPOT';
        }
        if ($extraMainCount > 0 || $extraDrinkCount > 0 || $extraChildCount > 0) {
            $portalNote .= ' Extra: main=' . $extraMainCount . ', drink=' . $extraDrinkCount . ', child=' . $extraChildCount;
        }
        if ($specialRequests !== '') {
            $portalNote .= ' ' . $specialRequests;
        }

        // Kamar dalam grup memakai nama tamu yang sama: cocokkan pesanan per booking (per kamar)
        if (!empty($link['parent_token']) && $bookingId) {
            $existing = $db->fetchOne("SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND booking_id = ? LIMIT 1", [$breakfastDate, $bookingId]);
        } else {
            $existing = $db->fetchOne(
                "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0 LIMIT 1",
                [$breakfastDate, $guestName]
            );
        }

        if ($existing) {
            $pdo->prepare("UPDATE breakfast_orders SET
                booking_id = ?, guest_name = ?, room_number = ?, total_pax = ?, breakfast_time = ?,
                breakfast_date = ?, location = ?, breakfast_location = ?, menu_items = ?, special_requests = ?, total_price = ?,
                on_the_spot = ?, order_status = 'submitted'
                WHERE id = ?")
                ->execute([
                    $bookingId,
                    $guestName,
                    $roomJson,
                    $totalPax,
                    $breakfastTime,
                    $breakfastDate,
                    $serviceType,
                    $breakfastLocation,
                    $menuJson,
                    $portalNote,
                    $totalPrice,
                    (int)$onTheSpot,
                    (int)$existing['id']
                ]);
            $orderId = (int)$existing['id'];
        } else {
            $pdo->prepare("INSERT INTO breakfast_orders
                (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date,
                 location, breakfast_location, on_the_spot, menu_items, special_requests, total_price, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?)")
                ->execute([
                    $bookingId,
                    $guestName,
                    $roomJson,
                    $totalPax,
                    $breakfastTime,
                    $breakfastDate,
                    $serviceType,
                    $breakfastLocation,
                    (int)$onTheSpot,
                    $menuJson,
                    $portalNote,
                    $totalPrice,
                    $createdBy
                ]);
            $orderId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE breakfast_guest_links
            SET link_status = 'submitted', selected_menu_ids = ?, selected_menu_notes = ?, selected_menu_qty = ?, selected_drink_ids = ?, selected_drink_notes = ?, selected_drink_qty = ?, selected_child_ids = ?, selected_child_notes = ?, selected_child_qty = ?,
                breakfast_time = ?, breakfast_service = ?, breakfast_location = ?, on_the_spot = ?, special_requests = ?, submitted_at = NOW()
            WHERE id = ?")
            ->execute([
                json_encode($selectedMain),
                json_encode($selectedMainNotes),
                json_encode($selectedMainQty),
                json_encode($selectedDrink),
                json_encode($selectedDrinkNotes),
                json_encode($selectedDrinkQty),
                json_encode($selectedChild),
                json_encode($selectedChildNotes),
                json_encode($selectedChildQty),
                $breakfastTime,
                $serviceType,
                $breakfastLocation,
                (int)$onTheSpot,
                $specialRequests,
                (int)$link['id']
            ]);

        $targetBookingId = !empty($bookingId) ? (int)$bookingId : 0;
        if ($targetBookingId <= 0) {
            // Fallback: try to resolve booking from guest + breakfast date so extras still land in invoice.
            $resolved = $db->fetchOne(
                "SELECT b.id
                 FROM bookings b
                 LEFT JOIN guests g ON b.guest_id = g.id
                 WHERE LOWER(TRIM(g.guest_name)) = LOWER(TRIM(?))
                   AND DATE(?) BETWEEN DATE(b.check_in_date) AND DATE(b.check_out_date)
                 ORDER BY b.id DESC
                 LIMIT 1",
                [$guestName, $breakfastDate]
            );
            if (!empty($resolved['id'])) {
                $targetBookingId = (int)$resolved['id'];
            }
        }

        // Tagihan Extra Breakfast -> invoice Hotel Service (belum lunas) atas nama tamu.
        bf_sync_extra_invoice($db, $pdo, [
            'booking_id' => $targetBookingId,
            'guest_name' => $guestName,
            'guest_phone' => (string)($link['guest_phone'] ?? ''),
            'rooms' => is_array(json_decode((string)$roomJson, true)) ? json_decode((string)$roomJson, true) : [],
            'date' => $breakfastDate,
            'packages' => $extraPackages,
            'ref' => 'link=' . ($link['short_code'] ?? substr($token, 0, 16)),
            'created_by' => $createdBy ?: null,
        ]);
        // ============================================================
        // CREATE INVOICE IN CASH_BOOK FOR PAID MENU ITEMS
        // Division: RESTO (id=2), Category: Moka
        // ============================================================
        $paidMenuTotal = 0;
        $paidMenuNames = [];
        foreach ($menuItems as $mi) {
            if (empty($mi['is_free']) && empty($mi['is_on_the_spot']) && empty($mi['is_extra'])) {
                $itemTotal = (float)$mi['price'] * (int)$mi['quantity'];
                $paidMenuTotal += $itemTotal;
                $paidMenuNames[] = $mi['menu_name'] . ' x' . $mi['quantity'];
            }
        }

        if ($paidMenuTotal > 0 && $targetBookingId > 0) {
            // Kolom cash_book.booking_id sudah dipastikan sebelum transaksi (lihat atas).

            // Get or create category "Moka" for division RESTO (id=2)
            $mokaCategory = $db->fetchOne("SELECT id FROM categories WHERE division_id = 2 AND LOWER(TRIM(category_name)) = 'moka' LIMIT 1");
            if (empty($mokaCategory['id'])) {
                // Create category Moka if not exists
                $pdo->prepare("INSERT INTO categories (division_id, category_name, category_type, description) VALUES (2, 'Moka', 'income', 'Pembayaran breakfast via guest portal')")->execute();
                $mokaCategoryId = (int)$pdo->lastInsertId();
            } else {
                $mokaCategoryId = (int)$mokaCategory['id'];
            }

            // Build description with menu details
            $roomNumbers = is_array($roomJson) ? implode(', ', $roomJson) : trim($roomJson, '[]"');
            $cashbookDesc = 'Breakfast: ' . implode(', ', $paidMenuNames) . ' - ' . $guestName . ' (Room: ' . $roomNumbers . ') - ' . $breakfastDate;

            // Check if already exists for this booking+date to avoid duplicates
            $existingCashbook = $db->fetchOne(
                "SELECT id FROM cash_book WHERE booking_id = ? AND description LIKE ? AND transaction_date = ? LIMIT 1",
                [$targetBookingId, '%' . $guestName . '%', $breakfastDate]
            );

            if (!empty($existingCashbook['id'])) {
                // Update existing entry
                $pdo->prepare("UPDATE cash_book SET amount = ?, description = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([(float)$paidMenuTotal, $cashbookDesc, (int)$existingCashbook['id']]);
            } else {
                // Insert new income entry
                $pdo->prepare("INSERT INTO cash_book 
                    (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, booking_id, created_by)
                    VALUES (?, TIME(NOW()), 2, ?, 'income', ?, ?, 'cash', ?, ?)")
                    ->execute([
                        $breakfastDate,
                        $mokaCategoryId,
                        (float)$paidMenuTotal,
                        $cashbookDesc,
                        $targetBookingId,
                        $createdBy
                    ]);
            }
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => $msg('Pilihan sarapan berhasil dikirim', 'Breakfast selection submitted successfully'),
            'service_note' => $msg(
                'Sarapan ini sudah termasuk buah & orange juice - silakan beri tahu waiters.',
                'This breakfast already includes fruit & orange juice - please inform the waiters.'
            ),
            'data' => [
                'order_id' => $orderId,
                'extra_main_count' => $extraMainCount,
                'extra_child_count' => $extraChildCount,
                'extra_total_price' => (float)$extraChargeTotal,
                'extra_packages' => $extraPackages ?? 0,
                'paid_menu_total' => (float)$paidMenuTotal
            ]
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
