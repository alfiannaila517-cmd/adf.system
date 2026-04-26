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

header('Content-Type: application/json');

$db = Database::getInstance();
$pdo = $db->getConnection();

function hotel_date()
{
    return (int)date('H') < 12 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
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

function ensure_portal_links_table($pdo)
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
        selected_drink_ids TEXT,
        selected_drink_notes TEXT,
        selected_child_ids TEXT,
        selected_child_notes TEXT,
        breakfast_time TIME NULL,
        breakfast_service VARCHAR(20) NULL,
        breakfast_location VARCHAR(120) NULL,
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

    try {
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN short_code VARCHAR(16) NULL AFTER token");
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
        $pdo->exec("ALTER TABLE breakfast_guest_links ADD COLUMN selected_child_notes TEXT AFTER selected_child_ids");
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = parse_json_body();
if (!$action && !empty($body['action'])) {
    $action = $body['action'];
}

try {
    ensure_breakfast_orders_table($pdo);
    ensure_portal_links_table($pdo);
    ensure_breakfast_quota_table($pdo);
    ensure_booking_extras_table($pdo);
    
    // Ensure unique key on breakfast_orders
    try { $pdo->exec("ALTER TABLE breakfast_orders ADD UNIQUE KEY uk_booking_date (booking_id, breakfast_date)"); } catch (Exception $e) {}
    
    // Add new columns if not exist
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN adult_count INT NOT NULL DEFAULT 1 AFTER breakfast_date"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_young_count INT NOT NULL DEFAULT 0 AFTER adult_count"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN child_old_count INT NOT NULL DEFAULT 0 AFTER child_young_count"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN total_pax INT NOT NULL DEFAULT 1 AFTER child_old_count"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN max_drink INT NOT NULL DEFAULT 2 AFTER max_main"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_guest_quota ADD COLUMN extra_drink_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER extra_main_price"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE breakfast_orders ADD COLUMN breakfast_location VARCHAR(120) NULL AFTER location"); } catch (Exception $e) {}
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal inisialisasi tabel: ' . $e->getMessage()]);
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

    $extraMainPrice = 75000.0;
    $extraDrinkPrice = 75000.0;
    $extraChildPrice = 75000.0;

    $childMenuIds = $body['child_menu_ids'] ?? [];
    if (!is_array($childMenuIds)) $childMenuIds = [];
    $childMenuIds = array_values(array_unique(array_map('intval', $childMenuIds)));
    $childMenuIds = array_values(array_filter($childMenuIds, function ($v) {
        return $v > 0;
    }));

    if ($maxChild > 0 && count($childMenuIds) === 0) {
        $fallbackKids = $db->fetchAll("SELECT id FROM breakfast_menus WHERE is_available = 1 AND LOWER(TRIM(menu_name)) IN ('pancake','waffle') ORDER BY menu_name") ?: [];
        foreach ($fallbackKids as $fk) {
            $id = (int)($fk['id'] ?? 0);
            if ($id > 0) $childMenuIds[] = $id;
        }
        $childMenuIds = array_values(array_unique($childMenuIds));
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
        $pdo->prepare("UPDATE breakfast_guest_links
            SET link_status = 'expired'
            WHERE breakfast_date = ? AND LOWER(TRIM(guest_name)) = LOWER(TRIM(?)) AND link_status = 'open'")
            ->execute([$breakfastDate, $guestName]);

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
                'expires_at' => $expiresAt
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

    if ($token !== '') {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
    } else {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE short_code = ? LIMIT 1", [$shortCode]);
    }
    if (!$link) {
        echo json_encode(['success' => false, 'message' => 'Link tidak ditemukan']);
        exit;
    }

    $linkStatus = (string)($link['link_status'] ?? 'open');
    $isLocked = in_array($linkStatus, ['submitted', 'closed', 'locked'], true) || !empty($link['submitted_at']);

    if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
        $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
        echo json_encode(['success' => false, 'message' => 'Link sudah kedaluwarsa']);
        exit;
    }

    $menus = $db->fetchAll("SELECT id, menu_name, category, is_free, price, image_url, description FROM breakfast_menus WHERE is_available = 1 ORDER BY category, menu_name") ?: [];
    $menuMap = [];
    foreach ($menus as $m) {
        $menuMap[(int)$m['id']] = $m;
    }

    $childIds = json_decode($link['child_menu_ids'] ?? '[]', true);
    if (!is_array($childIds)) $childIds = [];
    $childIds = array_values(array_unique(array_map('intval', $childIds)));

    if (count($childIds) === 0 && (int)($link['max_child'] ?? 0) > 0) {
        $fallbackKids = $db->fetchAll("SELECT id FROM breakfast_menus WHERE is_available = 1 AND LOWER(TRIM(menu_name)) IN ('pancake','waffle') ORDER BY menu_name") ?: [];
        foreach ($fallbackKids as $fk) {
            $id = (int)($fk['id'] ?? 0);
            if ($id > 0) $childIds[] = $id;
        }
        $childIds = array_values(array_unique($childIds));
    }

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
    if (!is_array($selectedMainIds)) $selectedMainIds = [];
    if (!is_array($selectedDrinkIds)) $selectedDrinkIds = [];
    if (!is_array($selectedChildIds)) $selectedChildIds = [];
    if (!is_array($selectedMainNotes)) $selectedMainNotes = [];
    if (!is_array($selectedDrinkNotes)) $selectedDrinkNotes = [];
    if (!is_array($selectedChildNotes)) $selectedChildNotes = [];
    $selectedMainIds = array_values(array_unique(array_map('intval', $selectedMainIds)));
    $selectedDrinkIds = array_values(array_unique(array_map('intval', $selectedDrinkIds)));
    $selectedChildIds = array_values(array_unique(array_map('intval', $selectedChildIds)));

    // Separate drinks from main courses
    $drinkCategories = ['drinks', 'beverages'];
    $drinkMenus = [];
    $mainMenus = [];
    foreach ($menus as $m) {
        $menuId = (int)$m['id'];
        $m['pre_selected'] = false;
        if ($isLocked) {
            $m['pre_selected'] = in_array($menuId, $selectedMainIds, true) || in_array($menuId, $selectedDrinkIds, true) || in_array($menuId, $selectedChildIds, true);
        }
        if (in_array($menuId, $childIds, true)) continue;
        if (in_array(strtolower($m['category'] ?? ''), $drinkCategories, true)) {
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
    $extraDrinkPrice = 75000.0;
    $extraChildPrice = 75000.0;
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
            'selected_main_ids' => $selectedMainIds,
            'selected_drink_ids' => $selectedDrinkIds,
            'selected_child_ids' => $selectedChildIds,
            'selected_main_notes' => $selectedMainNotes,
            'selected_drink_notes' => $selectedDrinkNotes,
            'selected_child_notes' => $selectedChildNotes
        ]
    ]);
    exit;
}

if ($action === 'submit_link') {
    $token = trim((string)($body['token'] ?? ''));
    if ($token === '') {
        echo json_encode(['success' => false, 'message' => 'Token wajib']);
        exit;
    }

    $selectedMain = $body['selected_main'] ?? [];
    $selectedDrink = $body['selected_drink'] ?? [];
    $selectedChild = $body['selected_child'] ?? [];
    if (!is_array($selectedMain)) $selectedMain = [];
    if (!is_array($selectedDrink)) $selectedDrink = [];
    if (!is_array($selectedChild)) $selectedChild = [];
    $selectedMain = array_values(array_unique(array_map('intval', $selectedMain)));
    $selectedDrink = array_values(array_unique(array_map('intval', $selectedDrink)));
    $selectedChild = array_values(array_unique(array_map('intval', $selectedChild)));
    $selectedMain = array_values(array_filter($selectedMain, function ($v) { return $v > 0; }));
    $selectedDrink = array_values(array_filter($selectedDrink, function ($v) { return $v > 0; }));
    $selectedChild = array_values(array_filter($selectedChild, function ($v) { return $v > 0; }));

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

    $selectedMainNotes = $normalizeNotesMap($body['selected_main_notes'] ?? [], $selectedMain);
    $selectedDrinkNotes = $normalizeNotesMap($body['selected_drink_notes'] ?? [], $selectedDrink);
    $selectedChildNotes = $normalizeNotesMap($body['selected_child_notes'] ?? [], $selectedChild);

    $specialRequests = trim((string)($body['special_requests'] ?? ''));
    $serviceType = trim((string)($body['service_type'] ?? $body['location'] ?? ''));
    if (!in_array($serviceType, ['restaurant', 'room_service', 'take_away'], true)) {
        echo json_encode(['success' => false, 'message' => 'Pilih layanan breakfast: Restaurant / Room Service / Take Away']);
        exit;
    }

    $breakfastLocation = trim((string)($body['breakfast_location'] ?? ''));
    if ($breakfastLocation === '') {
        echo json_encode(['success' => false, 'message' => 'Lokasi breakfast wajib diisi']);
        exit;
    }
    if (mb_strlen($breakfastLocation) > 120) {
        $breakfastLocation = mb_substr($breakfastLocation, 0, 120);
    }

    $breakfastTimeRaw = trim((string)($body['breakfast_time'] ?? ''));
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $breakfastTimeRaw, $mt)) {
        echo json_encode(['success' => false, 'message' => 'Waktu breakfast wajib diisi (format HH:MM)']);
        exit;
    }
    $breakfastTime = sprintf('%02d:%02d:00', (int)$mt[1], (int)$mt[2]);

    $pdo->beginTransaction();
    try {
        $link = $db->fetchOne("SELECT * FROM breakfast_guest_links WHERE token = ? LIMIT 1", [$token]);
        if (!$link) {
            throw new Exception('Link tidak ditemukan');
        }
        if (!empty($link['submitted_at']) || in_array((string)($link['link_status'] ?? 'open'), ['submitted', 'closed', 'locked'], true)) {
            throw new Exception('Menu sudah dikirim. Untuk perubahan, silakan hubungi Front Office.');
        }
        if (!empty($link['expires_at']) && strtotime($link['expires_at']) < time()) {
            $pdo->prepare("UPDATE breakfast_guest_links SET link_status = 'expired' WHERE id = ?")->execute([(int)$link['id']]);
            throw new Exception('Link sudah kedaluwarsa');
        }

        $maxMain = max(0, (int)$link['max_main']);
        $maxDrink = max(0, (int)($link['max_drink'] ?? 2));
        $maxChild = max(0, (int)$link['max_child']);

        $extraMainCount = max(0, count($selectedMain) - $maxMain);
        $extraDrinkCount = max(0, count($selectedDrink) - $maxDrink);
        $extraChildCount = max(0, count($selectedChild) - $maxChild);

        $allowedChild = json_decode($link['child_menu_ids'] ?? '[]', true);
        if (!is_array($allowedChild)) $allowedChild = [];
        $allowedChild = array_values(array_unique(array_map('intval', $allowedChild)));

        foreach ($selectedChild as $id) {
            if (!in_array($id, $allowedChild, true)) {
                throw new Exception('Ada menu anak yang tidak diizinkan');
            }
        }

        foreach ($selectedMain as $id) {
            if (in_array($id, $allowedChild, true)) {
                throw new Exception('Menu anak tidak boleh dipilih di menu utama');
            }
        }

        $extraMainPrice = 75000.0;
        $extraDrinkPrice = 75000.0;
        $extraChildPrice = 75000.0;

        $allSelected = array_values(array_unique(array_merge($selectedMain, $selectedDrink, $selectedChild)));
        if (count($allSelected) === 0) {
            throw new Exception('Pilih minimal 1 menu');
        }

        $placeholders = implode(',', array_fill(0, count($allSelected), '?'));
        $menus = $db->fetchAll("SELECT id, menu_name, price, is_free FROM breakfast_menus WHERE is_available = 1 AND id IN ($placeholders)", $allSelected) ?: [];
        $menuMap = [];
        foreach ($menus as $m) {
            $menuMap[(int)$m['id']] = $m;
        }

        $menuItems = [];
        $totalPrice = 0;
        $extraChargeTotal = 0;
        foreach ($selectedMain as $id) {
            if (empty($menuMap[$id])) continue;
            $m = $menuMap[$id];
            $itemNote = trim((string)($selectedMainNotes[(string)$id] ?? ''));
            $isExtra = $maxMain >= 0 && count($menuItems) >= $maxMain;
            $item = [
                'menu_id' => (int)$m['id'],
                'menu_name' => $m['menu_name'],
                'quantity' => 1,
                'price' => (float)$m['price'],
                'is_free' => (int)$m['is_free'],
                'group' => 'main'
            ];
            if ($itemNote !== '') {
                $item['note'] = $itemNote;
            }
            if ($isExtra) {
                $item['is_extra'] = 1;
                $item['extra_base_price'] = $extraMainPrice;
            }
            $menuItems[] = $item;
            if ($isExtra) {
                $charge = (float)$extraMainPrice;
                $totalPrice += $charge;
                $extraChargeTotal += $charge;
            } elseif (!(int)$m['is_free']) {
                $totalPrice += (float)$m['price'];
            }
        }
        
        // Process drinks
        foreach ($selectedDrink as $id) {
            if (empty($menuMap[$id])) continue;
            $m = $menuMap[$id];
            $itemNote = trim((string)($selectedDrinkNotes[(string)$id] ?? ''));
            $existingDrinkCount = 0;
            foreach ($menuItems as $mi) {
                if (($mi['group'] ?? '') === 'drink') $existingDrinkCount++;
            }
            $isExtra = $maxDrink >= 0 && $existingDrinkCount >= $maxDrink;
            $item = [
                'menu_id' => (int)$m['id'],
                'menu_name' => $m['menu_name'],
                'quantity' => 1,
                'price' => (float)$m['price'],
                'is_free' => (int)$m['is_free'],
                'group' => 'drink'
            ];
            if ($itemNote !== '') {
                $item['note'] = $itemNote;
            }
            if ($isExtra) {
                $item['is_extra'] = 1;
                $item['extra_base_price'] = $extraDrinkPrice;
            }
            $menuItems[] = $item;
            if ($isExtra) {
                $charge = (float)$extraDrinkPrice;
                $totalPrice += $charge;
                $extraChargeTotal += $charge;
            } elseif (!(int)$m['is_free']) {
                $totalPrice += (float)$m['price'];
            }
        }
        
        foreach ($selectedChild as $id) {
            if (empty($menuMap[$id])) continue;
            $m = $menuMap[$id];
            $itemNote = trim((string)($selectedChildNotes[(string)$id] ?? ''));
            $existingChildCount = 0;
            foreach ($menuItems as $mi) {
                if (($mi['group'] ?? '') === 'child') $existingChildCount++;
            }
            $isExtra = $maxChild >= 0 && $existingChildCount >= $maxChild;
            $item = [
                'menu_id' => (int)$m['id'],
                'menu_name' => $m['menu_name'],
                'quantity' => 1,
                'price' => (float)$m['price'],
                'is_free' => (int)$m['is_free'],
                'group' => 'child'
            ];
            if ($itemNote !== '') {
                $item['note'] = $itemNote;
            }
            if ($isExtra) {
                $item['is_extra'] = 1;
                $item['extra_base_price'] = $extraChildPrice;
            }
            $menuItems[] = $item;
            if ($isExtra) {
                $charge = (float)$extraChildPrice;
                $totalPrice += $charge;
                $extraChargeTotal += $charge;
            } elseif (!(int)$m['is_free']) {
                $totalPrice += (float)$m['price'];
            }
        }

        if (count($menuItems) === 0) {
            throw new Exception('Menu tidak valid');
        }

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
        if ($extraMainCount > 0 || $extraChildCount > 0) {
            $portalNote .= ' Extra: main=' . $extraMainCount . ', child=' . $extraChildCount;
        }
        if ($specialRequests !== '') {
            $portalNote .= ' ' . $specialRequests;
        }

        $existing = $db->fetchOne(
            "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0 LIMIT 1",
            [$breakfastDate, $guestName]
        );

        if ($existing) {
            $pdo->prepare("UPDATE breakfast_orders SET
                booking_id = ?, guest_name = ?, room_number = ?, total_pax = ?, breakfast_time = ?,
                breakfast_date = ?, location = ?, breakfast_location = ?, menu_items = ?, special_requests = ?, total_price = ?,
                order_status = 'submitted'
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
                    (int)$existing['id']
                ]);
            $orderId = (int)$existing['id'];
        } else {
            $pdo->prepare("INSERT INTO breakfast_orders
                (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date,
                 location, breakfast_location, menu_items, special_requests, total_price, order_status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?)")
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
                    $createdBy
                ]);
            $orderId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE breakfast_guest_links
            SET link_status = 'submitted', selected_menu_ids = ?, selected_menu_notes = ?, selected_drink_ids = ?, selected_drink_notes = ?, selected_child_ids = ?, selected_child_notes = ?,
                breakfast_time = ?, breakfast_service = ?, breakfast_location = ?, special_requests = ?, submitted_at = NOW()
            WHERE id = ?")
            ->execute([
                json_encode($selectedMain),
                json_encode($selectedMainNotes),
                json_encode($selectedDrink),
                json_encode($selectedDrinkNotes),
                json_encode($selectedChild),
                json_encode($selectedChildNotes),
                $breakfastTime,
                $serviceType,
                $breakfastLocation,
                $specialRequests,
                (int)$link['id']
            ]);

        if (($extraMainCount > 0 || $extraDrinkCount > 0 || $extraChildCount > 0) && !empty($bookingId) && $extraChargeTotal > 0) {
            $extraLabel = [];
            if ($extraMainCount > 0) $extraLabel[] = 'main x' . $extraMainCount;
            if ($extraDrinkCount > 0) $extraLabel[] = 'drink x' . $extraDrinkCount;
            if ($extraChildCount > 0) $extraLabel[] = 'child x' . $extraChildCount;
            $pdo->prepare("INSERT INTO booking_extras (booking_id, item_name, quantity, unit_price, total_price, notes, created_by)
                VALUES (?, 'Breakfast Extra (Guest Portal)', 1, ?, ?, ?, NULL)")
                ->execute([
                    (int)$bookingId,
                    (float)$extraChargeTotal,
                    (float)$extraChargeTotal,
                    'Auto extra from guest portal [' . implode(', ', $extraLabel) . '] token=' . ($link['short_code'] ?? $token)
                ]);
        }

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Pilihan sarapan berhasil dikirim',
            'data' => [
                'order_id' => $orderId,
                'extra_main_count' => $extraMainCount,
                'extra_child_count' => $extraChildCount,
                'extra_total_price' => (float)$extraChargeTotal
            ]
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
