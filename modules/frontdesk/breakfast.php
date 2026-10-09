<?php

/**
 * BREAKFAST ORDER - Rewritten clean version
 * Flow: Pick guest (not yet ordered today) → Pick menu → Submit → Pick next guest
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
// Breakfast hotel date rolls over at 10:00, so last night's picks stay visible until 10 AM.
$today = ((int)date('H') < 10) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

$guestLinkMessageTemplate = '';
try {
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_guest_link_template' LIMIT 1");
    $guestLinkMessageTemplate = trim((string)($row['setting_value'] ?? ''));
} catch (Exception $e) {
}

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
        id INT PRIMARY KEY AUTO_INCREMENT, menu_name VARCHAR(100) NOT NULL,
        description TEXT, category ENUM('western','indonesian','asian','drinks','beverages','extras') DEFAULT 'western',
        price DECIMAL(10,2) DEFAULT 0.00, is_free BOOLEAN DEFAULT TRUE, is_available BOOLEAN DEFAULT TRUE,
        image_url VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
        id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT NULL, guest_name VARCHAR(500) NOT NULL,
        room_number TEXT, total_pax INT DEFAULT 1, breakfast_time TIME, breakfast_date DATE,
        location VARCHAR(20) DEFAULT 'restaurant', menu_items TEXT, special_requests TEXT,
        total_price DECIMAL(10,2) DEFAULT 0.00, order_status VARCHAR(20) DEFAULT 'pending',
        created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

// Widen guest_name column and drop old unique constraint (combined multi-guest names can be long)
try {
    $pdo->exec("ALTER TABLE breakfast_orders MODIFY guest_name VARCHAR(500) NOT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE breakfast_orders DROP INDEX uk_guest_date");
} catch (Exception $e) {
}

// Get menus
$freeMenus = $paidMenus = [];
try {
    $freeMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=1 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
    $paidMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=0 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Default child menu IDs for guest portal quota (example: pancake + waffle)
$defaultChildMenuIds = [];
foreach (array_merge($freeMenus, $paidMenus) as $mx) {
    $nameLower = strtolower(trim($mx['menu_name'] ?? ''));
    if (strpos($nameLower, 'pancake') !== false || strpos($nameLower, 'waff') !== false || strpos($nameLower, 'wafel') !== false) {
        $defaultChildMenuIds[] = (int)$mx['id'];
    }
}
// Bila ada menu berkategori Kids, itulah menu anak.
$kidCatIds = array_map(fn($m) => (int)$m['id'], array_filter(array_merge($freeMenus, $paidMenus), fn($m) => strtolower((string)($m['category'] ?? '')) === 'kids'));
if ($kidCatIds) $defaultChildMenuIds = array_values($kidCatIds);

// Get in-house guests WHO HAVE NOT ORDERED TODAY
// Group by guest_id: one guest may have multiple bookings/rooms
$inHouseGuests = [];
$guestQuotaMap = [];
try {
    // Tabel kuota bisa belum ada (dibuat saat link/setup pertama): jangan sampai daftar tamu kosong.
    try {
        $quotaRows = $pdo->query("SELECT booking_id, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, breakfast_date FROM breakfast_guest_quota")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($quotaRows as $qr) {
            $guestQuotaMap[(int)$qr['booking_id']] = $qr;
        }
    } catch (Exception $e) {
    }

    // Satu baris per grup booking (group_id) atau per tamu; pax = dewasa + anak dari reservasi.
    $stmt = $pdo->prepare("
        SELECT MIN(g.id) AS guest_id,
               GROUP_CONCAT(DISTINCT g.guest_name ORDER BY b.id SEPARATOR '||') AS guest_names,
               COALESCE(MAX(NULLIF(g.phone, '')), '') AS guest_phone,
               GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ',') AS rooms,
               GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') AS booking_ids,
               COUNT(DISTINCT b.id) AS room_count,
               SUM(GREATEST(1, COALESCE(b.adults, 1) + COALESCE(b.children, 0))) AS res_pax
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND NOT EXISTS (
            SELECT 1 FROM breakfast_orders bo
            WHERE bo.breakfast_date = ?
            AND bo.room_number LIKE CONCAT('%\"', r.room_number, '\"%')
        )
        GROUP BY COALESCE(NULLIF(b.group_id, ''), CONCAT('G', g.id))
        ORDER BY MIN(r.room_number) ASC
    ");
    $stmt->execute([$today]);
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inHouseGuests as &$ig) {
        $ig['guest_name'] = implode(', ', array_unique(array_filter(explode('||', (string)$ig['guest_names']))));
        // Pax yang sudah disetel (Setup) disimpan di breakfast_guest_quota per booking.
        $setPax = null;
        $kids = 0;
        foreach (array_map('intval', explode(',', (string)$ig['booking_ids'])) as $bid) {
            if (isset($guestQuotaMap[$bid])) {
                $setPax = ($setPax ?? 0) + (int)$guestQuotaMap[$bid]['max_main'];
                $kids += (int)($guestQuotaMap[$bid]['max_child'] ?? 0);
            }
        }
        $ig['pax_set'] = $setPax !== null && $setPax > 0;
        $ig['pax'] = $ig['pax_set'] ? $setPax : max(1, (int)$ig['res_pax']);
        $ig['kids'] = $ig['pax_set'] ? $kids : 0;
    }
    unset($ig);
} catch (Exception $e) {
}

// Link sarapan yang SUDAH terkirim via WhatsApp untuk hari sarapan ini (log gateway, ref "breakfast 201,204"):
// ditandai di daftar tamu supaya tidak terkirim dua kali.
$waSentRooms = [];
try {
    $waRows = $pdo->prepare("SELECT ref, target, created_at FROM wa_message_log
        WHERE type = 'breakfast' AND status = 'sent' AND created_at >= ? ORDER BY id ASC");
    $waRows->execute([$today . ' 10:00:00']);
    foreach ($waRows->fetchAll(PDO::FETCH_ASSOC) as $wr) {
        $refRooms = preg_replace('/^breakfast\s*/i', '', (string)$wr['ref']);
        foreach (array_filter(array_map('trim', explode(',', $refRooms))) as $rn) {
            $waSentRooms[$rn] = ['time' => date('H:i', strtotime($wr['created_at'])), 'target' => $wr['target']];
        }
    }
} catch (Exception $e) {
    // tabel log WA belum ada
}
foreach ($inHouseGuests as &$ig) {
    $ig['wa_sent'] = null;
    foreach (array_filter(array_map('trim', explode(',', (string)$ig['rooms']))) as $rn) {
        if (isset($waSentRooms[$rn])) {
            $ig['wa_sent'] = $waSentRooms[$rn];
        }
    }
}
unset($ig);

// Map Extra Breakfast (over-quota) per room number for today — used to flag orders
$extraByRoom = [];
try {
    // Tagihan Extra Breakfast = invoice Hotel Service dengan penanda [BF-EXTRA …] dan rooms=… di catatan.
    $exStmt = $pdo->prepare("SELECT total, notes FROM hotel_invoices WHERE notes LIKE ? AND status <> 'cancelled'");
    $exStmt->execute(['%[BF-EXTRA%date=' . $today . '%']);
    foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
        if (!preg_match('/rooms=([^ ]*)/', (string)$ex['notes'], $mm)) continue;
        $rs = array_values(array_filter(explode(',', $mm[1])));
        if (!$rs) continue;
        // Dibagi rata agar total per order tetap benar walau order punya beberapa kamar.
        foreach ($rs as $rn) $extraByRoom[$rn] = ($extraByRoom[$rn] ?? 0) + (float)$ex['total'] / count($rs);
    }
} catch (Exception $e) {
}


// Today's orders for sidebar
$todayOrders = [];
try {
    $stmt = $pdo->prepare("SELECT bo.* FROM breakfast_orders bo
        WHERE bo.breakfast_date = ?
        AND bo.id = (
            SELECT MAX(bo2.id) FROM breakfast_orders bo2
            WHERE bo2.guest_name = bo.guest_name
              AND bo2.breakfast_date = bo.breakfast_date
              AND bo2.room_number = bo.room_number
        )
        ORDER BY bo.breakfast_time ASC, bo.id ASC");
    $stmt->execute([$today]);
    $todayOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todayOrders as &$o) {
        $o['menu_items'] = json_decode($o['menu_items'], true) ?: [];
    }
    unset($o);
} catch (Exception $e) {
}

// Rekap total pesanan per menu (untuk kitchen prep) — jumlahkan quantity semua
// order hari ini per nama menu, diurutkan dari yang paling banyak dipesan.
$menuRecap = [];
foreach ($todayOrders as $order) {
    foreach ($order['menu_items'] as $item) {
        $menuName = trim($item['menu_name'] ?? '');
        if ($menuName === '') continue;
        $qty = (int)($item['quantity'] ?? 1);
        if (!isset($menuRecap[$menuName])) $menuRecap[$menuName] = 0;
        $menuRecap[$menuName] += $qty;
    }
}
arsort($menuRecap);


// Edit mode
$editOrder = null;
$editMenuIds = [];
$editMenuQty = [];
$editMenuNotes = [];
$editMenuExtra = [];
$editCustomExtras = [];
if (!empty($_GET['edit'])) {
    $editOrder = $db->fetchOne("SELECT * FROM breakfast_orders WHERE id = ?", [(int)$_GET['edit']]);
    if ($editOrder) {
        foreach (json_decode($editOrder['menu_items'], true) ?: [] as $item) {
            if (!empty($item['is_custom'])) {
                $editCustomExtras[] = $item;
            } else {
                $editMenuIds[] = $item['menu_id'];
                $editMenuQty[$item['menu_id']] = $item['quantity'];
                if (!empty($item['note'])) $editMenuNotes[$item['menu_id']] = $item['note'];
                if (!empty($item['is_extra'])) $editMenuExtra[$item['menu_id']] = true;
            }
        }
    }
}

$pageTitle = 'Breakfast Order';
// Halaman ini memakai html2pdf (cetak/ekspor) -> footer memuat library-nya.
$needsPdfLibs = true;
include '../../includes/header.php';
?>

<style>
    .bf-wrap {
        max-width: 1300px;
        margin: 0 auto
    }

    .bf-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
        gap: .75rem
    }

    .bf-head h1 {
        font-size: 1.5rem;
        font-weight: 800;
        background: linear-gradient(135deg, #f59e0b, #f97316);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
        display: flex;
        align-items: center;
        gap: .5rem
    }

    .bf-head-actions {
        display: flex;
        gap: .5rem
    }

    .bf-head-btn {
        padding: .5rem .875rem;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        border-radius: 8px;
        font-size: .75rem;
        font-weight: 600;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: .35rem;
        transition: all .2s
    }

    .bf-head-btn:hover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, .1)
    }

    .bf-grid {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 1.25rem
    }

    .bf-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        padding: 1rem
    }

    .bf-section {
        margin-bottom: 1rem
    }

    .bf-title {
        font-size: .85rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: .65rem;
        padding-bottom: .4rem;
        border-bottom: 2px solid var(--bg-tertiary);
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: .75rem;
        margin-bottom: .75rem
    }

    .bf-group {
        display: flex;
        flex-direction: column
    }

    .bf-label {
        font-size: .68rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: .3rem
    }

    .bf-input,
    .bf-select {
        padding: .55rem .65rem;
        border-radius: 6px;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .85rem;
        width: 100%
    }

    .bf-input:focus,
    .bf-select:focus {
        outline: none;
        border-color: var(--primary-color)
    }

    .bf-radio-group {
        display: flex;
        gap: .5rem
    }

    .bf-radio-label {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .35rem;
        padding: .5rem;
        background: var(--bg-primary);
        border: 2px solid var(--bg-tertiary);
        border-radius: 8px;
        cursor: pointer;
        font-size: .78rem;
        font-weight: 600;
        transition: all .2s
    }

    .bf-radio-label:hover {
        border-color: var(--primary-color)
    }

    .bf-radio-label:has(input:checked) {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, .15)
    }

    .bf-radio-label input {
        display: none
    }

    .bf-menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: .5rem
    }

    .bf-menu-item {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .65rem;
        transition: all .2s;
        cursor: pointer
    }

    .bf-menu-item:hover {
        border-color: var(--primary-color)
    }

    .bf-menu-item:has(input[type="checkbox"]:checked) {
        border-color: #10b981;
        background: rgba(16, 185, 129, .1)
    }

    .bf-menu-cb {
        display: flex;
        align-items: flex-start;
        gap: .5rem
    }

    .bf-menu-cb input[type="checkbox"] {
        margin-top: .15rem;
        width: 16px;
        height: 16px;
        cursor: pointer
    }

    .bf-menu-name {
        font-size: .8rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: .2rem
    }

    .bf-menu-price {
        font-size: .72rem;
        font-weight: 700;
        color: #10b981
    }

    .bf-menu-cat {
        display: inline-block;
        padding: .15rem .4rem;
        background: rgba(99, 102, 241, .15);
        border-radius: 4px;
        font-size: .6rem;
        font-weight: 600;
        text-transform: uppercase;
        color: var(--primary-color)
    }

    .bf-menu-qty {
        display: none;
        align-items: center;
        gap: .35rem;
        margin-top: .5rem;
        padding-top: .5rem;
        border-top: 1px dashed var(--bg-tertiary)
    }

    .bf-menu-item:has(input[type="checkbox"]:checked) .bf-menu-qty {
        display: flex
    }

    .bf-qty-input {
        width: 50px;
        padding: .3rem;
        border-radius: 4px;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .8rem;
        text-align: center
    }

    .bf-menu-note {
        display: none;
        margin-top: .35rem
    }

    .bf-menu-item:has(input[type="checkbox"]:checked) .bf-menu-note {
        display: block
    }

    .bf-note-input {
        width: 100%;
        padding: .3rem .5rem;
        border-radius: 4px;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .72rem;
        font-family: inherit
    }

    .bf-textarea {
        width: 100%;
        padding: .55rem .65rem;
        border-radius: 6px;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .85rem;
        font-family: inherit;
        resize: vertical;
        min-height: 50px
    }

    .bf-actions {
        display: flex;
        gap: .5rem;
        margin-top: 1rem
    }

    .bf-btn-submit {
        flex: 1;
        padding: .75rem 1rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: .85rem;
        font-weight: 700;
        cursor: pointer;
        transition: all .2s
    }

    .bf-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, .3)
    }

    .bf-btn-submit:disabled {
        opacity: .5;
        cursor: not-allowed;
        transform: none
    }

    .bf-btn-reset {
        padding: .75rem 1rem;
        background: var(--bg-primary);
        color: var(--text-muted);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        font-size: .85rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        text-align: center
    }

    /* Sidebar */
    .bf-side {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        overflow: hidden;
        height: fit-content;
        position: sticky;
        top: 1rem
    }

    .bf-side-title {
        padding: .85rem 1rem;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: #fff;
        font-size: .9rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-side-count {
        background: rgba(255, 255, 255, .25);
        padding: .15rem .5rem;
        border-radius: 10px;
        font-size: .7rem;
        margin-left: auto
    }

    .bf-recap-card {
        margin: .75rem;
        border: 1px solid #fde68a;
        background: #fffbeb;
        border-radius: 10px;
        overflow: hidden
    }

    [data-theme="dark"] .bf-recap-card {
        background: #292015;
        border-color: #78350f
    }

    .bf-recap-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        padding: .55rem .75rem;
        font-size: .78rem;
        font-weight: 700;
        color: #92400e;
        border-bottom: 1px solid #fde68a
    }

    [data-theme="dark"] .bf-recap-head {
        color: #fbbf24;
        border-bottom-color: #78350f
    }

    .bf-recap-print {
        border: none;
        background: #f59e0b;
        color: #fff;
        font-size: .7rem;
        font-weight: 700;
        padding: .25rem .55rem;
        border-radius: 6px;
        cursor: pointer;
        white-space: nowrap
    }

    .bf-recap-print:hover {
        background: #d97706
    }

    .bf-recap-table {
        width: 100%;
        border-collapse: collapse
    }

    .bf-recap-table tr:not(:last-child) td {
        border-bottom: 1px dashed #fde68a
    }

    [data-theme="dark"] .bf-recap-table tr:not(:last-child) td {
        border-bottom-color: #78350f
    }

    .bf-recap-name {
        padding: .4rem .75rem;
        font-size: .8rem;
        color: var(--text-primary)
    }

    .bf-recap-qty {
        padding: .4rem .75rem;
        font-size: .85rem;
        font-weight: 800;
        color: #d97706;
        text-align: right
    }

    .bf-order {
        padding: .75rem 1rem;
        border-bottom: 1px solid var(--bg-tertiary);
        transition: background .2s
    }

    .bf-order:last-child {
        border-bottom: none
    }

    .bf-order:hover {
        background: var(--bg-primary)
    }

    .bf-order-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: .35rem
    }

    .bf-order-time {
        font-size: .75rem;
        font-weight: 700;
        color: var(--primary-color)
    }

    .bf-order-pax {
        font-size: .65rem;
        padding: .2rem .4rem;
        background: var(--bg-tertiary);
        border-radius: 4px;
        color: var(--text-muted)
    }

    .bf-order-guest {
        font-size: .8rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: .25rem
    }

    .bf-order-room {
        font-size: .7rem;
        color: var(--text-muted);
        margin-bottom: .35rem
    }

    .bf-order-extra-badge {
        font-size: .68rem;
        font-weight: 700;
        color: #b45309;
        background: rgba(245, 158, 11, .14);
        border: 1px solid rgba(245, 158, 11, .35);
        border-radius: 6px;
        padding: .25rem .45rem;
        margin-bottom: .4rem;
        line-height: 1.3
    }

    .bf-order-extra-badge span {
        font-weight: 500;
        opacity: .8
    }


    display: flex;
    flex-wrap: wrap;
    gap: .25rem
    }

    .bf-order-tag {
        font-size: .62rem;
        padding: .15rem .35rem;
        background: rgba(139, 92, 246, .15);
        color: #a78bfa;
        border-radius: 3px
    }

    .bf-order-foot {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: .4rem;
        padding-top: .35rem;
        border-top: 1px dashed var(--bg-tertiary)
    }

    .bf-order-price {
        font-size: .72rem;
        font-weight: 700;
        color: #10b981
    }

    .bf-order-status {
        font-size: .6rem;
        padding: .2rem .4rem;
        border-radius: 4px;
        font-weight: 700;
        text-transform: uppercase
    }

    .bf-order-status.pending {
        background: rgba(245, 158, 11, .2);
        color: #f59e0b
    }

    .bf-order-status.preparing {
        background: rgba(99, 102, 241, .2);
        color: #6366f1
    }

    .bf-order-status.served {
        background: rgba(16, 185, 129, .2);
        color: #10b981
    }

    .bf-order-status.completed {
        background: rgba(107, 114, 128, .2);
        color: #9ca3af
    }

    .bf-order-btns {
        display: flex;
        gap: .35rem;
        margin-top: .4rem
    }

    .bf-order-btn {
        padding: .25rem .5rem;
        border-radius: 4px;
        font-size: .65rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .2s
    }

    .bf-order-btn.edit {
        background: rgba(99, 102, 241, .15);
        color: #6366f1
    }

    .bf-order-btn.del {
        background: rgba(239, 68, 68, .15);
        color: #ef4444
    }

    .bf-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: var(--text-muted)
    }

    .bf-empty-icon {
        font-size: 2rem;
        margin-bottom: .5rem
    }

    .bf-alert {
        padding: .75rem 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        font-size: .85rem;
        font-weight: 600
    }

    .bf-alert.ok {
        background: rgba(16, 185, 129, .15);
        border: 1px solid rgba(16, 185, 129, .3);
        color: #10b981
    }

    .bf-alert.err {
        background: rgba(239, 68, 68, .15);
        border: 1px solid rgba(239, 68, 68, .3);
        color: #ef4444
    }

    .bf-no-guest {
        padding: 1rem;
        text-align: center;
        font-size: .8rem;
        color: var(--text-muted);
        background: rgba(245, 158, 11, .08);
        border-radius: 8px
    }

    /* Multi-guest selection */
    .bf-guest-list {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .5rem
    }

    .bf-guest-item {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .45rem .55rem;
        border-radius: 6px;
        transition: background .15s;
        cursor: pointer
    }

    .bf-guest-item:hover {
        background: var(--bg-primary)
    }

    .bf-guest-item:has(input:checked) {
        background: rgba(16, 185, 129, .1)
    }

    .bf-guest-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        cursor: pointer
    }

    .bf-guest-item .guest-info {
        flex: 1
    }

    .bf-guest-item .guest-name {
        font-size: .8rem;
        font-weight: 600;
        color: var(--text-primary)
    }

    .bf-guest-item .guest-room {
        font-size: .68rem;
        color: var(--text-muted)
    }

    .bf-guest-count {
        font-size: .72rem;
        color: var(--primary-color);
        font-weight: 600;
        margin-top: .4rem
    }

    .bf-guest-tools {
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-link-guest-btn {
        padding: .25rem .5rem;
        border: none;
        border-radius: 6px;
        font-size: .66rem;
        font-weight: 700;
        cursor: pointer;
        background: rgba(14, 165, 233, .14);
        color: #0284c7
    }

    .bf-setup-guest-btn {
        padding: .25rem .5rem;
        border: none;
        border-radius: 6px;
        font-size: .66rem;
        font-weight: 700;
        cursor: pointer;
        background: rgba(99, 102, 241, .14);
        color: #4f46e5
    }

    .bf-wa-phone {
        font-size: .62rem;
        color: #64748b;
        max-width: 120px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .bf-wa-panel {
        margin-top: .6rem;
        padding: .65rem;
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        background: var(--bg-primary)
    }

    .bf-wa-panel-title {
        font-size: .72rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-bottom: .45rem
    }

    .bf-wa-row {
        display: flex;
        gap: .45rem;
        align-items: center;
        flex-wrap: wrap;
        margin-top: .45rem
    }

    .bf-link-send {
        padding: .35rem .65rem;
        border: none;
        border-radius: 6px;
        background: linear-gradient(135deg, #0ea5e9, #0284c7);
        color: #fff;
        font-size: .68rem;
        font-weight: 700;
        cursor: pointer
    }

    .bf-link-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(120px, 1fr));
        gap: .45rem
    }

    .bf-link-group {
        display: flex;
        flex-direction: column;
        gap: .25rem
    }

    .bf-link-group label {
        font-size: .64rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .25px
    }

    .bf-link-group input {
        padding: .35rem .45rem;
        border-radius: 6px;
        border: 1px solid var(--bg-tertiary);
        background: var(--bg-secondary);
        color: var(--text-primary);
        font-size: .72rem
    }

    .bf-child-menu-list {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .4rem
    }

    .bf-child-menu-item {
        font-size: .66rem;
        padding: .2rem .4rem;
        border-radius: 999px;
        background: rgba(99, 102, 241, .12);
        color: #4f46e5;
        display: flex;
        align-items: center;
        gap: .25rem
    }

    .bf-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, .45);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px
    }

    .bf-modal {
        width: min(700px, 100%);
        max-height: 85vh;
        overflow: auto;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        padding: 12px
    }

    .bf-modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: .6rem
    }

    .bf-modal-title {
        font-size: .9rem;
        font-weight: 800;
        color: var(--text-primary)
    }

    .bf-modal-close {
        border: none;
        background: rgba(239, 68, 68, .12);
        color: #ef4444;
        border-radius: 6px;
        padding: .3rem .55rem;
        font-weight: 700;
        cursor: pointer
    }

    .bf-modal-backdrop.show {
        display: flex
    }

    /* Notes in sidebar */
    .bf-order-note {
        font-size: .62rem;
        color: #f59e0b;
        font-style: italic;
        margin-left: .2rem
    }

    .bf-order-special {
        font-size: .68rem;
        color: var(--text-muted);
        background: rgba(245, 158, 11, .08);
        padding: .3rem .5rem;
        border-radius: 4px;
        margin-top: .35rem;
        font-style: italic;
        border-left: 2px solid #f59e0b
    }

    .bf-order-btn.print {
        background: rgba(16, 185, 129, .15);
        color: #10b981
    }

    .bf-custom-extra {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .65rem;
        margin-bottom: .5rem;
        transition: all .2s
    }

    .bf-custom-extra:hover {
        border-color: #f59e0b
    }

    @media(max-width:900px) {
        .bf-grid {
            grid-template-columns: 1fr
        }

        .bf-side {
            position: static
        }

        .bf-menu-grid {
            grid-template-columns: 1fr 1fr
        }
    }

    @media(max-width:600px) {
        .bf-row {
            grid-template-columns: 1fr
        }

        .bf-menu-grid {
            grid-template-columns: 1fr
        }

        .bf-radio-group {
            flex-direction: column
        }
    }
    /* ===== Daftar tamu & link sarapan ===== */
    body[data-theme] .main-content .bf-wrap .bf-guest-list {
        max-height: 320px;
        padding: 6px !important;
        border-radius: 12px !important;
        border: 1px solid var(--fd-line) !important;
        background: var(--fd-tile) !important;
        display: grid;
        gap: 6px;
    }

    body[data-theme] .main-content .bf-wrap label.bf-guest-item {
        display: flex !important;
        align-items: center;
        gap: 10px;
        margin: 0 !important;
        padding: 9px 10px !important;
        background: var(--fd-input-bg) !important;
    }

    body[data-theme] .main-content .bf-wrap .bf-guest-item .guest-info {
        flex: 1;
        min-width: 0;
    }

    body[data-theme] .main-content .bf-wrap .bf-guest-item .guest-name {
        display: flex;
        align-items: center;
        gap: 6px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    body[data-theme] .main-content .bf-wrap .bfg-tag {
        flex-shrink: 0;
        padding: 1px 7px;
        border-radius: 999px;
        background: var(--fd-accent-soft);
        color: var(--fd-accent-text) !important;
        font-size: 0.58rem;
        font-weight: 700;
    }

    body[data-theme] .main-content .bf-wrap .guest-room b.bfg-pax {
        color: var(--fd-text) !important;
        font-weight: 700;
    }

    body[data-theme] .main-content .bf-wrap .bfg-src {
        margin-left: 2px;
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.16);
        font-size: 0.56rem;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .bf-wrap .bfg-sent {
        display: inline-flex;
        align-items: center;
        margin-left: 4px;
        padding: 1px 8px;
        border-radius: 999px;
        background: #dcfce7;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
        font-size: 0.6rem !important;
        font-weight: 800;
        white-space: nowrap;
    }

    body[data-theme] .main-content .bf-wrap .bfg-sent[hidden] {
        display: none;
    }

    body[data-theme] .main-content .bf-wrap .bf-guest-item .guest-info { flex: 1 1 0; }
    body[data-theme] .main-content .bf-wrap .bfg-sent-wrap { flex: 0 1 auto; display: flex; justify-content: center; padding: 0 6px; }
    body[data-theme] .main-content .bf-wrap .bfg-sent-wrap .bfg-sent { gap: 6px; margin: 0; padding: 4px 11px 4px 5px; font-size: 0.66rem !important; border: 1px solid #a7f3d0; box-shadow: 0 2px 8px -4px rgba(4, 120, 87, 0.35); }
    body[data-theme] .main-content .bf-wrap .bfg-sent i { font-style: normal; width: 17px; height: 17px; border-radius: 50%; background: #047857; color: #fff !important; -webkit-text-fill-color: #fff !important; display: inline-flex; align-items: center; justify-content: center; font-size: 0.6rem; }
    body[data-theme] .main-content .bf-wrap .bfg-sent b { font-weight: 800; padding-left: 6px; border-left: 1px solid #a7f3d0; }
    body[data-theme] .main-content .bf-wrap .bfg-sent b:empty { display: none; }
    @media (max-width: 640px) { body[data-theme] .main-content .bf-wrap .bfg-sent-wrap .bfg-sent { font-size: 0 !important; padding: 3px; } body[data-theme] .main-content .bf-wrap .bfg-sent b { display: none; } }
    .bfg-resend-ic { width: 52px; height: 52px; margin: 2px auto 10px; border-radius: 50%; background: #dcfce7; color: #047857; display: flex; align-items: center; justify-content: center; }
    .bfg-resend-ic svg { width: 26px; height: 26px; fill: #25d366; }
    .bfg-resend-t { text-align: center; font-size: 0.95rem; font-weight: 800; margin: 0 0 4px; }
    .bfg-resend-s { text-align: center; font-size: 0.74rem; line-height: 1.5; color: var(--text-muted, #64748b) !important; margin: 0; }
    .bfg-resend-s b { color: inherit; }
    #waResendModal .bfg-modal-actions { justify-content: center; }
    #waResendModal .bfg-btn { min-width: 110px; height: 38px; }

    /* WA sudah terkirim: tombol jadi outline + centang agar tidak terkirim dua kali */
    body[data-theme] .main-content .bf-wrap .bf-wa-send.is-sent {
        position: relative;
        background: #fff !important;
        border: 1.5px solid #25d366 !important;
        box-shadow: none;
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-send.is-sent svg {
        fill: #25d366 !important;
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-send.is-sent::after {
        content: '✓';
        position: absolute;
        right: -4px;
        bottom: -4px;
        width: 15px;
        height: 15px;
        border-radius: 50%;
        background: #047857;
        color: #fff;
        font-size: 9px;
        font-weight: 900;
        line-height: 15px;
        text-align: center;
        border: 1.5px solid #fff;
    }

    body[data-theme] .main-content .bf-wrap .bf-guest-tools {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-phone {
        max-width: 110px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 0.62rem !important;
        color: var(--fd-muted) !important;
    }

    body[data-theme] .main-content .bf-wrap .bf-setup-guest-btn {
        height: 28px;
        padding: 0 10px !important;
        border-radius: 8px !important;
        border: 1px solid var(--fd-input-border) !important;
        background: var(--fd-input-bg) !important;
        color: var(--fd-text-2) !important;
        -webkit-text-fill-color: var(--fd-text-2) !important;
        font-size: 0.68rem !important;
        font-weight: 600;
    }

    body[data-theme] .main-content .bf-wrap .bf-link-btn {
        width: 28px; height: 28px; display: grid; place-items: center; padding: 0; border-radius: 8px;
        border: 1px solid var(--fd-input-border); background: var(--fd-input-bg); cursor: pointer;
        color: var(--fd-text-2) !important; transition: border-color .12s, color .12s;
    }
    body[data-theme] .main-content .bf-wrap .bf-link-btn svg { width: 14px; height: 14px; }
    body[data-theme] .main-content .bf-wrap .bf-link-btn:hover { border-color: #2563eb; color: #1d4ed8 !important; }
    body[data-theme] .main-content .bf-wrap .bf-link-btn:disabled { opacity: .5; cursor: wait; }

    body[data-theme] .main-content .bf-wrap .bf-wa-send {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: #25d366;
        color: #fff !important;
        cursor: pointer;
        box-shadow: 0 4px 10px -4px rgba(37, 211, 102, 0.7);
        transition: transform 0.12s;
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-send:hover {
        transform: scale(1.08);
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-send:disabled,
    body[data-theme] .main-content .bf-wrap .bfg-bulk-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
    }

    body[data-theme] .main-content .bf-wrap .bf-wa-send svg,
    body[data-theme] .main-content .bf-wrap .bfg-bulk-btn svg {
        width: 16px;
        height: 16px;
        fill: #fff !important;
    }

    body[data-theme] .main-content .bf-wrap .bfg-bulk {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 8px;
    }

    body[data-theme] .main-content .bf-wrap .bfg-bulk-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        height: 32px;
        padding: 0 14px;
        border: 0;
        border-radius: 9px;
        background: #25d366;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.72rem !important;
        font-weight: 700;
        cursor: pointer;
    }

    /* Modal setup & nomor */
    .bfg-modal {
        max-width: 360px !important;
    }

    .bfg-field-label {
        display: block;
        margin: 4px 0 6px;
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-muted, #64748b) !important;
    }

    .bfg-stepper {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .bfg-stepper button {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, 0.45);
        background: rgba(37, 99, 235, 0.08);
        color: #1d4ed8 !important;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
    }

    .bfg-stepper input,
    .bfg-input {
        flex: 1;
        width: 100%;
        height: 38px;
        padding: 0 10px;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, 0.45);
        background: var(--bg-primary, #fff) !important;
        color: inherit !important;
        font-size: 0.9rem;
        font-weight: 700;
        text-align: center;
    }

    .bfg-input {
        font-weight: 500;
        text-align: left;
    }

    .bfg-hint {
        margin: 10px 0 0;
        font-size: 0.7rem;
        line-height: 1.5;
        color: var(--text-muted, #64748b) !important;
    }

    .bfg-check {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        font-size: 0.72rem;
    }

    .bfg-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 14px;
    }

    .bfg-btn {
        height: 34px;
        padding: 0 16px;
        border-radius: 9px;
        border: 0;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.76rem;
        font-weight: 700;
        cursor: pointer;
    }

    .bfg-btn.green {
        background: #25d366;
    }

    .bfg-btn.ghost {
        background: transparent;
        border: 1px solid rgba(148, 163, 184, 0.5);
        color: var(--text-muted, #64748b) !important;
        -webkit-text-fill-color: var(--text-muted, #64748b) !important;
    }

    /* Spesifisitas tinggi: gaya global input (tema terang) menimpa latar checkbox & ukuran angka */
    body[data-theme] #guestList input[type="checkbox"]:checked {
        background-color: #2563eb !important;
        border-color: #2563eb !important;
    }

    body[data-theme] #guestSetupModal #setupPax,
    body[data-theme] #guestSetupModal #setupKids,
    body[data-theme] #phoneAskModal #phoneAskInput {
        font-size: 1rem !important;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
        background: #ffffff !important;
    }

    body[data-theme="dark"] #guestSetupModal #setupPax,
    body[data-theme="dark"] #guestSetupModal #setupKids,
    body[data-theme="dark"] #phoneAskModal #phoneAskInput {
        color: #e2e8f0 !important;
        -webkit-text-fill-color: #e2e8f0 !important;
        background: rgba(255, 255, 255, 0.06) !important;
    }

    .bfg-toast {
        position: fixed;
        left: 50%;
        bottom: 24px;
        z-index: 10070;
        padding: 10px 16px;
        border-radius: 12px;
        background: linear-gradient(135deg, #064e3b, #047857);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.78rem;
        box-shadow: 0 16px 40px -12px rgba(4, 120, 87, 0.55);
        transform: translate(-50%, 20px);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s, transform 0.2s;
    }

    .bfg-toast.err {
        background: linear-gradient(135deg, #7f1d1d, #b91c1c);
    }

    .bfg-toast.show {
        opacity: 1;
        transform: translate(-50%, 0);
    }

    /* Popup sukses di tengah: centang hijau, hilang otomatis */
    .bfg-ok {
        position: fixed;
        inset: 0;
        z-index: 10080;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        pointer-events: none;
        background: rgba(15, 23, 42, 0.18);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.22s, visibility 0.22s;
    }

    .bfg-ok.show {
        opacity: 1;
        visibility: visible;
    }

    .bfg-ok-card {
        min-width: 250px;
        max-width: 340px;
        padding: 22px 26px 20px;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45);
        text-align: center;
        transform: scale(0.85);
        transition: transform 0.28s cubic-bezier(.2, 1.3, .5, 1);
    }

    .bfg-ok.show .bfg-ok-card {
        transform: none;
    }

    body[data-theme="dark"] .bfg-ok-card {
        background: #111a2e;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .bfg-ok-ic {
        display: block;
        width: 60px;
        height: 60px;
        margin: 0 auto 10px;
    }

    .bfg-ok-ic circle {
        fill: #059669;
    }

    .bfg-ok-ic path {
        fill: none;
        stroke: #fff;
        stroke-width: 4.5;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 40;
        stroke-dashoffset: 40;
    }

    .bfg-ok.show .bfg-ok-ic path {
        animation: bfgOkDraw 0.35s 0.15s ease-out forwards;
    }

    @keyframes bfgOkDraw {
        to { stroke-dashoffset: 0; }
    }

    body[data-theme] .bfg-ok-card b {
        display: block;
        font-size: 0.95rem;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }

    body[data-theme] .bfg-ok-card small {
        display: block;
        margin-top: 4px;
        font-size: 0.75rem;
        line-height: 1.45;
        color: #64748b !important;
        -webkit-text-fill-color: #64748b !important;
    }

    body[data-theme="dark"] .bfg-ok-card b {
        color: #e2e8f0 !important;
        -webkit-text-fill-color: #e2e8f0 !important;
    }

    body[data-theme="dark"] .bfg-ok-card small {
        color: #94a3b8 !important;
        -webkit-text-fill-color: #94a3b8 !important;
    }
    /* Nomor WA di sebelah nama (klik untuk ubah) */
    body[data-theme] .main-content .bf-wrap .bfg-phone {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        flex-shrink: 0;
        margin-left: 4px;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid rgba(5, 150, 105, 0.28);
        background: rgba(5, 150, 105, 0.07);
        font-size: 0.74rem !important;
        font-weight: 600;
        letter-spacing: 0.01em;
        color: #047857 !important;
        -webkit-text-fill-color: #047857 !important;
        cursor: pointer;
    }

    body[data-theme] .main-content .bf-wrap .bfg-phone svg {
        width: 12px;
        height: 12px;
    }

    body[data-theme] .main-content .bf-wrap .bfg-phone.empty {
        border-style: dashed;
        border-color: rgba(220, 38, 38, 0.35);
        background: rgba(220, 38, 38, 0.05);
        color: #b91c1c !important;
        -webkit-text-fill-color: #b91c1c !important;
    }

    body[data-theme="dark"] .main-content .bf-wrap .bfg-phone {
        color: #6ee7b7 !important;
        -webkit-text-fill-color: #6ee7b7 !important;
    }

    body[data-theme="dark"] .main-content .bf-wrap .bfg-phone.empty {
        color: #fca5a5 !important;
        -webkit-text-fill-color: #fca5a5 !important;
    }

    body[data-theme] .main-content .bf-wrap .bf-guest-item .guest-name > .bfg-name {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Popup gagal kirim */
    .bfg-fail-reason {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 10px;
        background: rgba(220, 38, 38, 0.07);
        border: 1px solid rgba(220, 38, 38, 0.25);
        font-size: 0.76rem;
        line-height: 1.45;
    }

    body[data-theme] .bfg-fail-reason,
    body[data-theme] .bfg-fail-reason b {
        color: #991b1b !important;
        -webkit-text-fill-color: #991b1b !important;
    }

    body[data-theme="dark"] .bfg-fail-reason,
    body[data-theme="dark"] .bfg-fail-reason b {
        color: #fca5a5 !important;
        -webkit-text-fill-color: #fca5a5 !important;
    }
</style>

<div class="bf-wrap">
    <div class="bf-head">
        <h1>🍳 Breakfast Order</h1>
        <div class="bf-head-actions">
            <a href="breakfast.php" class="bf-head-btn">📋 Orders</a>
            <a href="in-house.php" class="bf-head-btn">👥 In House</a>
            <a href="dashboard.php" class="bf-head-btn">🏠 Dashboard</a>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="bf-alert ok">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>

    <div class="bf-grid">
        <!-- FORM -->
        <div class="bf-card">
            <form id="bfForm" autocomplete="off">
                <?php if ($editOrder): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editOrder['id']; ?>">
                <?php endif; ?>

                <!-- Guest Selection -->
                <div class="bf-section">
                    <div class="bf-title">👤 Tamu In-House <span class="bf-title-note">centang beberapa untuk 1 link gabungan</span></div>
                    <?php if (count($inHouseGuests) > 0 || $editOrder): ?>
                        <div class="bf-group">
                            <?php if ($editOrder): ?>
                                <?php
                                $editRooms = json_decode($editOrder['room_number'], true);
                                $editRoomStr = is_array($editRooms) ? implode(', ', $editRooms) : $editOrder['room_number'];
                                ?>
                                <label class="bf-label">Editing: <?php echo htmlspecialchars($editOrder['guest_name']); ?></label>
                                <input type="hidden" id="editGuestData"
                                    data-id="edit_<?php echo $editOrder['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($editOrder['guest_name']); ?>"
                                    data-rooms="<?php echo htmlspecialchars($editRoomStr); ?>"
                                    data-booking="<?php echo $editOrder['booking_id']; ?>">
                            <?php else: ?>
                                <div class="bf-guest-list" id="guestList">
                                    <?php foreach ($inHouseGuests as $g):
                                        $bIds = array_map('intval', explode(',', (string)$g['booking_ids']));
                                        $pax = (int)$g['pax'];
                                    ?>
                                        <label class="bf-guest-item">
                                            <input type="checkbox" name="guest_checks[]" value="<?php echo (int)$g['guest_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($g['guest_name']); ?>"
                                                data-rooms="<?php echo htmlspecialchars($g['rooms']); ?>"
                                                data-booking="<?php echo $bIds[0]; ?>"
                                                data-booking-ids="<?php echo htmlspecialchars(json_encode($bIds)); ?>"
                                                data-phone="<?php echo htmlspecialchars($g['guest_phone'] ?? ''); ?>"
                                                data-wa-sent="<?php echo $g['wa_sent'] ? htmlspecialchars($g['wa_sent']['time']) : ''; ?>"
                                                data-wa-target="<?php echo $g['wa_sent'] ? htmlspecialchars($g['wa_sent']['target']) : ''; ?>"
                                                data-pax="<?php echo $pax; ?>"
                                                data-kids="<?php echo (int)$g['kids']; ?>"
                                                data-adults="<?php echo $pax; ?>"
                                                data-child-young="0"
                                                data-child-old="0"
                                                data-total-pax="<?php echo $pax; ?>"
                                                data-max-main="<?php echo $pax; ?>"
                                                data-max-drink="<?php echo $pax; ?>"
                                                data-max-child="0"
                                                data-child-menu-ids="[]">
                                            <div class="guest-info">
                                                <div class="guest-name">
                                                    <span class="bfg-name"><?php echo htmlspecialchars($g['guest_name']); ?></span>
                                                    <span class="bfg-phone<?php echo empty($g['guest_phone']) ? ' empty' : ''; ?>" title="Klik untuk ubah nomor WhatsApp" onclick="editGuestPhone(event,this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg><span><?php echo htmlspecialchars($g['guest_phone'] ?: 'Tambah nomor'); ?></span></span>
                                                    <?php if ((int)$g['room_count'] > 1): ?><span class="bfg-tag">Grup · <?php echo (int)$g['room_count']; ?> kamar</span><?php endif; ?>
                                                </div>
                                                <div class="guest-room">
                                                    Room <?php echo htmlspecialchars(str_replace(',', ', ', $g['rooms'])); ?>
                                                    · <b class="bfg-pax"><?php echo $pax; ?> pax<?php echo $g['kids'] ? ' + ' . (int)$g['kids'] . ' kids' : ''; ?></b>
                                                    <span class="bfg-src"><?php echo $g['pax_set'] ? 'disetel' : 'dari reservasi'; ?></span>
                                                </div>
                                            </div>
                                            <div class="bfg-sent-wrap">
                                                <span class="bfg-sent"<?php echo $g['wa_sent'] ? '' : ' hidden'; ?>><i>✓</i>Link terkirim<b><?php echo $g['wa_sent'] ? htmlspecialchars($g['wa_sent']['time']) : ''; ?></b></span>
                                            </div>
                                            <div class="bf-guest-tools">
                                                <button type="button" class="bf-setup-guest-btn" onclick="openGuestSetup(event,this)">Setup</button>
                                                <button type="button" class="bf-link-btn" title="Salin link sarapan tamu" aria-label="Salin link" onclick="bfgLinkAction(event,this,false)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
                                                <button type="button" class="bf-link-btn" title="Buka link sarapan tamu" aria-label="Buka link" onclick="bfgLinkAction(event,this,true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg></button>
                                                <button type="button" class="bf-wa-send<?php echo $g['wa_sent'] ? ' is-sent' : ''; ?>" aria-label="Kirim link via WhatsApp" onclick="sendGuestSelectionLink(event,this)">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42z" /></svg>
                                                </button>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="bfg-bulk">
                                    <span class="bf-guest-count" id="guestCount">0 tamu dipilih</span>
                                    <button type="button" class="bfg-bulk-btn" id="bulkLinkBtn" onclick="sendSelectedGuestsPortalLinks()" disabled>
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89A11.82 11.82 0 0 0 12.05 0z" /></svg>
                                        <span>Kirim 1 link gabungan</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bf-no-guest">🎉 Semua tamu in-house sudah order sarapan hari ini!</div>
                    <?php endif; ?>
                </div>

                <!-- Time & Details -->
                <div class="bf-section">
                    <div class="bf-title">⏰ Waktu & Detail</div>
                    <div class="bf-row">
                        <div class="bf-group">
                            <label class="bf-label">Jumlah Pax * <span class="bf-label-note">otomatis dari menu</span></label>
                            <input type="number" name="total_pax" id="totalPax" class="bf-input bf-input-auto" min="1" readonly tabindex="-1" placeholder="Pilih menu" value="<?php echo $editOrder ? (int)$editOrder['total_pax'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Jam *</label>
                            <input type="time" name="breakfast_time" id="bfTime" class="bf-input" required value="<?php echo $editOrder ? $editOrder['breakfast_time'] : '07:00'; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Tanggal</label>
                            <input type="date" name="breakfast_date" class="bf-input" value="<?php echo $editOrder ? $editOrder['breakfast_date'] : $today; ?>" readonly>
                        </div>
                    </div>
                    <div class="bf-row">
                        <div class="bf-group" style="grid-column:span 2">
                            <label class="bf-label">Lokasi *</label>
                            <div class="bf-radio-group">
                                <label class="bf-radio-label"><input type="radio" name="location" value="restaurant" <?php echo (!$editOrder || ($editOrder['location'] ?? '') === 'restaurant') ? 'checked' : ''; ?>> 🍽️ Restaurant</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="room_service" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'room_service') ? 'checked' : ''; ?>> 🛏️ Room Service</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="take_away" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'take_away') ? 'checked' : ''; ?>> 🥡 Take Away</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Menu -->
                <div class="bf-section">
                    <div class="bf-title">🍽️ Pilih Menu</div>

                    <?php if (count($freeMenus) > 0): ?>
                        <div class="bf-menu-block">
                            <div class="bf-subhead">✨ Free Breakfast</div>
                            <div class="bf-menu-grid">
                                <?php foreach ($freeMenus as $m): ?>
                                    <div class="bf-menu-item">
                                        <label class="bf-menu-cb">
                                            <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" data-cat="<?php echo htmlspecialchars(strtolower((string)$m['category'])); ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                            <div>
                                                <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                                <span class="bf-menu-cat" data-cat="<?php echo htmlspecialchars(strtolower((string)$m['category'])); ?>"><?php echo htmlspecialchars((string)$m['category']); ?></span>
                                                <?php if (in_array($m['serve_temp'] ?? '', ['hot', 'ice'], true)): ?><span class="bf-temp <?php echo $m['serve_temp']; ?>"><?php echo strtoupper($m['serve_temp']); ?></span><?php endif; ?>
                                            </div>
                                        </label>
                                        <div class="bf-menu-qty">
                                            <span class="bf-qty-label">Qty</span>
                                            <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                        </div>
                                        <?php if (!empty($editMenuExtra[$m['id']])): ?><input type="hidden" name="menu_extra[<?php echo $m['id']; ?>]" value="1"><?php endif; ?>
                                        <div class="bf-menu-note">
                                            <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (count($paidMenus) > 0): ?>
                        <div class="bf-menu-block">
                            <div class="bf-subhead">💰 Extra (Berbayar)</div>
                            <div class="bf-menu-grid">
                                <?php foreach ($paidMenus as $m): ?>
                                    <div class="bf-menu-item">
                                        <label class="bf-menu-cb">
                                            <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" data-cat="<?php echo htmlspecialchars(strtolower((string)$m['category'])); ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                            <div>
                                                <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                                <div class="bf-menu-price">Rp <?php echo number_format($m['price'], 0, ',', '.'); ?></div>
                                                <span class="bf-menu-cat" data-cat="<?php echo htmlspecialchars(strtolower((string)$m['category'])); ?>"><?php echo htmlspecialchars((string)$m['category']); ?></span>
                                                <?php if (in_array($m['serve_temp'] ?? '', ['hot', 'ice'], true)): ?><span class="bf-temp <?php echo $m['serve_temp']; ?>"><?php echo strtoupper($m['serve_temp']); ?></span><?php endif; ?>
                                            </div>
                                        </label>
                                        <div class="bf-menu-qty">
                                            <span class="bf-qty-label">Qty</span>
                                            <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                        </div>
                                        <?php if (!empty($editMenuExtra[$m['id']])): ?><input type="hidden" name="menu_extra[<?php echo $m['id']; ?>]" value="1"><?php endif; ?>
                                        <div class="bf-menu-note">
                                            <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Custom Extra Breakfast (Manual) -->
                    <div class="bf-menu-block">
                        <div class="bf-subhead">
                            <span>🛒 Extra Breakfast (Manual)</span>
                            <button type="button" onclick="addCustomExtra()" class="bf-add-btn">+ Tambah</button>
                        </div>
                        <div id="customExtrasContainer">
                            <?php if (!empty($editCustomExtras)): ?>
                                <?php foreach ($editCustomExtras as $idx => $ce): ?>
                                    <div class="bf-custom-extra" data-index="<?php echo $idx; ?>">
                                        <div style="display:flex;gap:.5rem;align-items:center">
                                            <input type="text" class="bf-input custom-extra-name" placeholder="Nama item, cth: Extra Nasi" value="<?php echo htmlspecialchars($ce['menu_name']); ?>" style="flex:1;font-size:.8rem;padding:.45rem .55rem" required>
                                            <input type="number" class="bf-input custom-extra-price" placeholder="Harga" value="<?php echo (int)$ce['price']; ?>" min="0" step="1000" style="width:110px;font-size:.8rem;padding:.45rem .55rem" required>
                                            <input type="number" class="bf-input custom-extra-qty" placeholder="Qty" value="<?php echo $ce['quantity'] ?? 1; ?>" min="1" max="20" style="width:55px;font-size:.8rem;padding:.45rem .55rem;text-align:center">
                                            <button type="button" onclick="this.closest('.bf-custom-extra').remove()" style="padding:.4rem .55rem;background:rgba(239,68,68,.15);color:#ef4444;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700">✕</button>
                                        </div>
                                        <input type="text" class="bf-note-input custom-extra-note" placeholder="Catatan (opsional)" value="<?php echo htmlspecialchars($ce['note'] ?? ''); ?>" style="margin-top:.35rem;width:100%">
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="bf-hint">Tambahkan item extra breakfast yang tidak ada di daftar menu. Isi nama dan harga manual.</p>
                    </div>
                </div>

                <!-- Notes -->
                <div class="bf-section">
                    <div class="bf-title">📝 Catatan</div>
                    <textarea name="special_requests" class="bf-textarea" placeholder="Alergi, permintaan khusus, dll"><?php echo $editOrder ? htmlspecialchars($editOrder['special_requests'] ?? '') : ''; ?></textarea>
                </div>

                <div class="bf-actions">
                    <button type="submit" class="bf-btn-submit" id="btnSubmit"><?php echo $editOrder ? '✓ Update Order' : '✓ Simpan Order'; ?></button>
                    <?php if ($editOrder): ?>
                        <a href="breakfast.php" class="bf-btn-reset">✕ Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- SIDEBAR: Today's Orders -->
        <div class="bf-side">
            <?php if (!empty($menuRecap)): ?>
                <div class="bf-recap-card">
                    <div class="bf-recap-head">
                        <span>📋 Rekap Total per Menu <span style="font-weight:400;color:var(--text-muted);font-size:.7rem">(untuk kitchen)</span></span>
                        <button type="button" class="bf-recap-print" onclick="cetakRekap()" title="Cetak Rekap PDF">🖨️ PDF</button>
                    </div>
                    <table class="bf-recap-table">
                        <?php foreach ($menuRecap as $menuName => $qty): ?>
                            <tr>
                                <td class="bf-recap-name"><?php echo htmlspecialchars($menuName); ?></td>
                                <td class="bf-recap-qty">×<?php echo $qty; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <div class="bf-side-title">
                📊 Today's Orders
                <span class="bf-side-count"><?php echo count($todayOrders); ?></span>
            </div>

            <?php if (count($todayOrders) > 0): ?>
                <?php foreach ($todayOrders as $order): ?>
                    <div class="bf-order">
                        <div class="bf-order-head">
                            <span class="bf-order-time">🕐 <?php echo $order['breakfast_time'] ? date('H:i', strtotime($order['breakfast_time'])) : '-'; ?></span>
                            <span class="bf-order-pax"><?php echo $order['total_pax']; ?> pax</span>
                        </div>
                        <div class="bf-order-guest"><?php echo htmlspecialchars($order['guest_name']); ?></div>
                        <?php
                        $rooms = json_decode($order['room_number'], true);
                        $roomStr = is_array($rooms) ? implode(', ', $rooms) : ($order['room_number'] ?: '-');
                        // Detect Extra Breakfast (over-quota) for this order's room(s)
                        $orderRooms = is_array($rooms) ? $rooms : array_filter(array_map('trim', explode(',', (string)$order['room_number'])));
                        $extraAmt = 0;
                        foreach ($orderRooms as $rm) {
                            if (isset($extraByRoom[(string)$rm])) $extraAmt += (float)$extraByRoom[(string)$rm];
                        }
                        ?>
                        <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($roomStr); ?></div>
                        <?php if ($extraAmt > 0): ?>
                            <div class="bf-order-extra-badge">⚠️ Extra Breakfast: Rp <?php echo number_format($extraAmt, 0, ',', '.'); ?> <span>(invoice Hotel Service)</span></div>
                        <?php endif; ?>
                        <div class="bf-order-room"><?php echo ($order['location'] ?? 'restaurant') === 'restaurant' ? '🍽️ Restaurant' : (($order['location'] ?? '') === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></div>
                        <div class="bf-order-menus">
                            <?php foreach ($order['menu_items'] as $item): ?>
                                <span class="bf-order-tag">
                                    <?php echo htmlspecialchars($item['menu_name'] ?? '?'); ?>
                                    <?php if (($item['quantity'] ?? 1) > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
                                    <?php if (!empty($item['is_extra'])): ?><span class="bf-order-xbf">Extra BF</span><?php endif; ?>
                                    <?php if (!empty($item['note'])): ?><span class="bf-order-note">(<?php echo htmlspecialchars($item['note']); ?>)</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($order['special_requests'])): ?>
                            <div class="bf-order-special">📝 <?php echo htmlspecialchars($order['special_requests']); ?></div>
                        <?php endif; ?>
                        <div class="bf-order-foot">
                            <span class="bf-order-price"><?php echo $order['total_price'] > 0 ? 'Rp ' . number_format($order['total_price'], 0, ',', '.') : 'Free'; ?></span>
                            <span class="bf-order-status <?php echo $order['order_status']; ?>"><?php echo ucfirst($order['order_status']); ?></span>
                        </div>
                        <div class="bf-order-btns">
                            <a href="?edit=<?php echo $order['id']; ?>" class="bf-order-btn edit">✏️ Edit</a>
                            <button class="bf-order-btn print" onclick='cetakOrder(<?php echo json_encode($order, JSON_HEX_APOS | JSON_HEX_TAG); ?>)'>🖨️ PDF</button>
                            <button class="bf-order-btn del" onclick="hapusOrder(<?php echo $order['id']; ?>,'<?php echo htmlspecialchars(addslashes($order['guest_name'])); ?>')">🗑️ Hapus</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bf-empty">
                    <div class="bf-empty-icon">📭</div>
                    <p style="font-size:.8rem">Belum ada order hari ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="bf-modal-backdrop" id="guestSetupModal">
    <div class="bf-modal bfg-modal">
        <div class="bf-modal-head">
            <div class="bf-modal-title" id="guestSetupTitle">Setup</div>
            <button type="button" class="bf-modal-close" onclick="closeGuestSetup()">✕</button>
        </div>
        <label class="bfg-field-label" for="setupPax">Jatah sarapan (pax)</label>
        <div class="bfg-stepper">
            <button type="button" onclick="stepSetupPax(-1)" aria-label="Kurangi">−</button>
            <input type="number" id="setupPax" min="1" max="60">
            <button type="button" onclick="stepSetupPax(1)" aria-label="Tambah">+</button>
        </div>
        <label class="bfg-field-label" for="setupKids" style="margin-top:12px">Kids di bawah 7 tahun · gratis</label>
        <div class="bfg-stepper">
            <button type="button" onclick="stepSetupKids(-1)" aria-label="Kurangi">−</button>
            <input type="number" id="setupKids" min="0" max="30">
            <button type="button" onclick="stepSetupKids(1)" aria-label="Tambah">+</button>
        </div>
        <p class="bfg-hint" id="setupHint"></p>
        <div class="bfg-modal-actions">
            <button type="button" class="bfg-btn ghost" onclick="closeGuestSetup()">Batal</button>
            <button type="button" class="bfg-btn" id="setupSaveBtn" onclick="saveGuestSetup()">Simpan</button>
        </div>
    </div>
</div>

<div class="bf-modal-backdrop" id="phoneAskModal">
    <div class="bf-modal bfg-modal">
        <div class="bf-modal-head">
            <div class="bf-modal-title">Nomor WhatsApp tamu</div>
            <button type="button" class="bf-modal-close" onclick="phoneAskDone(null)">✕</button>
        </div>
        <p class="bfg-hint" id="phoneAskText" style="margin-top:0"></p>
        <input type="tel" class="bfg-input" id="phoneAskInput" placeholder="08xx atau +62…">
        <label class="bfg-check"><input type="checkbox" id="phoneAskSave" checked> Simpan nomor ke data tamu</label>
        <div class="bfg-modal-actions">
            <button type="button" class="bfg-btn ghost" onclick="phoneAskDone('')">Salin link saja</button>
            <button type="button" class="bfg-btn green" onclick="phoneAskDone(document.getElementById('phoneAskInput').value)">Kirim</button>
        </div>
    </div>
</div>

<div class="bf-modal-backdrop" id="waFailModal">
    <div class="bf-modal bfg-modal">
        <div class="bf-modal-head">
            <div class="bf-modal-title">WhatsApp belum terkirim</div>
            <button type="button" class="bf-modal-close" onclick="waFailClose()">✕</button>
        </div>
        <div class="bfg-fail-reason" id="waFailReason"></div>
        <p class="bfg-hint" style="margin-top:0">Link sarapan sudah dibuat. Kirim manual lewat WhatsApp, atau salin link-nya. Cek juga nomor tamu dan status perangkat di Pengaturan WhatsApp.</p>
        <div class="bfg-modal-actions">
            <button type="button" class="bfg-btn ghost" onclick="waFailCopy()">Salin link</button>
            <button type="button" class="bfg-btn green" onclick="waFailOpen()">Buka WhatsApp</button>
        </div>
    </div>
</div>

<div class="bf-modal-backdrop" id="waResendModal" onclick="if(event.target===this)waResendDone(false)">
    <div class="bf-modal bfg-modal" style="text-align:center">
        <div class="bfg-resend-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89A11.82 11.82 0 0 0 12.05 0z"/></svg></div>
        <p class="bfg-resend-t">Link sarapan sudah dikirim</p>
        <p class="bfg-resend-s" id="waResendText"></p>
        <div class="bfg-modal-actions">
            <button type="button" class="bfg-btn ghost" onclick="waResendDone(false)">Batal</button>
            <button type="button" class="bfg-btn green" onclick="waResendDone(true)">Kirim lagi</button>
        </div>
    </div>
</div>

<div class="bfg-toast" id="bfgToast"></div>
<div class="bfg-ok" id="bfgOk" role="status" aria-live="polite">
    <div class="bfg-ok-card">
        <svg class="bfg-ok-ic" viewBox="0 0 52 52" aria-hidden="true">
            <circle cx="26" cy="26" r="24" />
            <path d="M15 27.5l7.5 7.5L37.5 19" />
        </svg>
        <b id="bfgOkTitle"></b>
        <small id="bfgOkSub"></small>
    </div>
</div>

<script>
    // Guest checkbox counter
    var guestChecks = document.querySelectorAll('input[name="guest_checks[]"]');
    var guestCountEl = document.getElementById('guestCount');
    var activeSetupCheckbox = null;
    if (guestChecks.length > 0) {
        guestChecks.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var checked = document.querySelectorAll('input[name="guest_checks[]"]:checked');
                var count = checked.length;
                guestCountEl.textContent = count + ' tamu dipilih';
                if (count === 1) {
                    applyGuestSettingsFromCheckbox(checked[0]);
                }
            });
        });
    }

    function applyGuestSettingsFromCheckbox(cb) {
        if (!cb) return;
        var adults = parseInt(cb.dataset.adults || '1', 10) || 1;
        var childYoung = parseInt(cb.dataset.childYoung || '0', 10) || 0;
        var childOld = parseInt(cb.dataset.childOld || '0', 10) || 0;
        var maxMain = parseInt(cb.dataset.maxMain || '2', 10) || 2;
        var maxDrink = parseInt(cb.dataset.maxDrink || '2', 10) || 2;
        var maxChild = parseInt(cb.dataset.maxChild || '0', 10) || 0;
        var extraMainPrice = parseFloat(cb.dataset.extraMainPrice || '75000') || 75000;
        var extraDrinkPrice = parseFloat(cb.dataset.extraDrinkPrice || '20000') || 20000;
        if (Math.round(extraDrinkPrice) === 75000) extraDrinkPrice = 20000;
        var extraChildPrice = parseFloat(cb.dataset.extraChildPrice || '75000') || 75000;
        var childMenuIds = [];
        try {
            childMenuIds = JSON.parse(cb.dataset.childMenuIds || '[]');
        } catch (e) {
            childMenuIds = [];
        }

        cb.dataset.adults = String(adults);
        cb.dataset.childYoung = String(childYoung);
        cb.dataset.childOld = String(childOld);
        cb.dataset.maxMain = String(maxMain);
        cb.dataset.maxDrink = String(maxDrink);
        cb.dataset.maxChild = String(maxChild);
        cb.dataset.extraMainPrice = String(extraMainPrice);
        cb.dataset.extraDrinkPrice = String(extraDrinkPrice);
        cb.dataset.extraChildPrice = String(extraChildPrice);
        cb.dataset.childMenuIds = JSON.stringify(childMenuIds);
    }

    // Jumlah pax otomatis dari menu: total qty makanan (1 tamu = 1 makanan); bila hanya minuman,
    // pakai total qty minuman. Tetap bisa diubah manual setelahnya.
    function bfAutoPax() {
        var food = 0,
            drink = 0;
        document.querySelectorAll('input[name="menu_items[]"]:checked').forEach(function(cb) {
            var q = document.querySelector('input[name="menu_qty[' + cb.value + ']"]');
            var qty = q ? (parseInt(q.value) || 1) : 1;
            var cat = cb.dataset.cat || '';
            if (cat === 'drinks' || cat === 'drink' || cat === 'beverages' || cat === 'beverage') drink += qty;
            else if (cat !== 'extras') food += qty;
        });
        var pax = food > 0 ? food : drink;
        var el = document.getElementById('totalPax');
        if (el) el.value = pax > 0 ? pax : '';
    }
    document.querySelectorAll('input[name="menu_items[]"]').forEach(function(cb) {
        cb.addEventListener('change', bfAutoPax);
        var q = document.querySelector('input[name="menu_qty[' + cb.value + ']"]');
        if (q) q.addEventListener('input', bfAutoPax);
    });

    // Collect common form data (menu, time, pax, etc)
    function collectFormData() {
        var pax = document.getElementById('totalPax').value;
        var time = document.getElementById('bfTime').value;
        if (!pax || parseInt(pax) < 1) {
            alert('Pilih minimal 1 menu makanan atau minuman!');
            return null;
        }
        if (!time) {
            alert('Isi jam sarapan!');
            return null;
        }
        var menus = document.querySelectorAll('input[name="menu_items[]"]:checked');

        // Collect custom extras
        var customExtras = [];
        document.querySelectorAll('.bf-custom-extra').forEach(function(el) {
            var name = el.querySelector('.custom-extra-name').value.trim();
            var price = parseFloat(el.querySelector('.custom-extra-price').value) || 0;
            var qty = parseInt(el.querySelector('.custom-extra-qty').value) || 1;
            var note = el.querySelector('.custom-extra-note').value.trim();
            if (name && price >= 0) {
                customExtras.push({
                    name: name,
                    price: price,
                    quantity: qty,
                    note: note
                });
            }
        });

        if (menus.length === 0 && customExtras.length === 0) {
            alert('Pilih minimal 1 menu atau tambahkan extra manual!');
            return null;
        }
        var menuItems = [],
            menuQty = {},
            menuNote = {},
            menuExtra = {};
        menus.forEach(function(cb) {
            var id = cb.value;
            menuItems.push(id);
            var q = document.querySelector('input[name="menu_qty[' + id + ']"]');
            menuQty[id] = q ? parseInt(q.value) || 1 : 1;
            var n = document.querySelector('input[name="menu_note[' + id + ']"]');
            menuNote[id] = n ? n.value.trim() : '';
            var x = document.querySelector('input[name="menu_extra[' + id + ']"]');
            if (x && x.value === '1') menuExtra[id] = 1;
        });
        return {
            total_pax: parseInt(pax),
            breakfast_time: time,
            breakfast_date: document.querySelector('input[name="breakfast_date"]').value,
            location: (document.querySelector('input[name="location"]:checked') || {
                value: 'restaurant'
            }).value,
            special_requests: document.querySelector('textarea[name="special_requests"]').value.trim(),
            menu_items: menuItems,
            menu_qty: menuQty,
            menu_note: menuNote,
            menu_extra: menuExtra,
            custom_extras: customExtras
        };
    }

    // Form submit via AJAX
    var submitting = false;

    // Build an over-quota notification message from the save API response.
    function bfExtraMessage(res) {
        var charge = parseFloat(res && res.extra_charge) || 0;
        if (charge <= 0) return '';
        var parts = [];
        var em = parseInt(res.extra_main) || 0;
        var ed = parseInt(res.extra_drink) || 0;
        if (em > 0) parts.push(em + ' menu utama');
        if (ed > 0) parts.push(ed + ' minuman');
        var rp = 'Rp ' + charge.toLocaleString('id-ID');
        return '⚠️ Melebihi jatah sarapan: ' + parts.join(' + ') +
            '.\nTagihan Extra Breakfast ' + rp +
            ' otomatis ditambahkan ke Hotel Service Invoice.';
    }

    document.getElementById('bfForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (submitting) return;

        var common = collectFormData();
        if (!common) return;

        var editData = document.getElementById('editGuestData');
        var btn = document.getElementById('btnSubmit');

        if (editData) {
            // EDIT MODE: single guest update
            var roomsStr = editData.dataset.rooms || '';
            var roomArr = roomsStr ? roomsStr.split(',').map(function(r) {
                return r.trim();
            }) : [];
            var data = Object.assign({}, common, {
                action: 'update_order',
                edit_id: parseInt(document.querySelector('input[name="edit_id"]').value),
                booking_id: parseInt(editData.dataset.booking) || null,
                guest_name: editData.dataset.name || '',
                room_number: roomArr
            });
            submitting = true;
            btn.disabled = true;
            btn.textContent = '⏳ Menyimpan...';
            fetch('../../api/breakfast-save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(res) {
                    if (res.success) {
                        var em = bfExtraMessage(res);
                        if (em) alert(em);
                        window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
                    } else {
                        alert('❌ ' + (res.message || 'Gagal'));
                        submitting = false;
                        btn.disabled = false;
                        btn.textContent = '✓ Update Order';
                    }
                }).catch(function(err) {
                    alert('❌ Error: ' + err.message);
                    submitting = false;
                    btn.disabled = false;
                    btn.textContent = '✓ Update Order';
                });
            return;
        }

        // CREATE MODE: multi-guest
        var checked = document.querySelectorAll('input[name="guest_checks[]"]:checked');
        if (checked.length === 0) {
            alert('Pilih minimal 1 tamu!');
            return;
        }

        var guests = [];
        checked.forEach(function(cb) {
            var roomsStr = cb.dataset.rooms || '';
            guests.push({
                guest_id: parseInt(cb.value) || null,
                guest_name: cb.dataset.name || '',
                room_number: roomsStr ? roomsStr.split(',').map(function(r) {
                    return r.trim();
                }) : [],
                booking_id: parseInt(cb.dataset.booking) || null,
                booking_ids: JSON.parse(cb.dataset.bookingIds || '[]')
            });
        });

        submitting = true;
        btn.disabled = true;
        btn.textContent = '⏳ Menyimpan order...';

        var payload = Object.assign({}, common, {
            action: 'create_bulk',
            guests: guests
        });

        fetch('../../api/breakfast-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(res) {
                if (res.success) {
                    var em = bfExtraMessage(res);
                    if (em) alert(em);
                    window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
                } else {
                    alert('❌ ' + (res.message || 'Gagal menyimpan'));
                    submitting = false;
                    btn.disabled = false;
                    btn.textContent = '✓ Simpan Order';
                }
            })
            .catch(function(err) {
                alert('❌ Error koneksi: ' + err.message);
                submitting = false;
                btn.disabled = false;
                btn.textContent = '✓ Simpan Order';
            });
    });

    // Delete order
    function hapusOrder(id, name) {
        if (!confirm('Hapus order sarapan "' + name + '"?')) return;
        fetch('<?php echo BASE_URL; ?>/api/breakfast-order-action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: id
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(d) {
                if (d.success) location.reload();
                else alert('Gagal: ' + (d.message || '?'));
            })
            .catch(function() {
                alert('Error koneksi');
            });
    }

    // PDF Print — A4 format
    function cetakOrder(order) {
        var rooms = order.room_number;
        if (typeof rooms === 'string') {
            try {
                rooms = JSON.parse(rooms);
            } catch (e) {
                rooms = [rooms];
            }
        }
        var roomStr = Array.isArray(rooms) ? rooms.join(', ') : (rooms || '-');
        var items = order.menu_items;
        if (typeof items === 'string') {
            try {
                items = JSON.parse(items);
            } catch (e) {
                items = [];
            }
        }
        var locMap = {
            restaurant: 'Restaurant',
            room_service: 'Room Service',
            take_away: 'Take Away'
        };
        var locLabel = locMap[order.location] || order.location;
        var timeStr = order.breakfast_time ? order.breakfast_time.substring(0, 5) : '-';
        var dateStr = order.breakfast_date || '<?php echo $today; ?>';

        var html = '<div style="font-family:Arial,sans-serif;width:100%;max-width:700px;margin:0 auto;padding:30px 40px;color:#1a1a2e">';

        // Header
        html += '<div style="text-align:center;border-bottom:3px solid #f59e0b;padding-bottom:15px;margin-bottom:25px">';
        html += '<div style="font-size:28px;font-weight:800;color:#f59e0b;letter-spacing:1px">BREAKFAST ORDER</div>';
        html += '<div style="font-size:14px;color:#374151;margin-top:6px;font-weight:600"><?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Karimunjawa"); ?></div>';
        html += '<div style="font-size:11px;color:#9ca3af;margin-top:4px">Order #' + (order.id || '-') + ' | ' + dateStr + '</div>';
        html += '</div>';

        // Guest info
        html += '<table style="width:100%;font-size:13px;margin-bottom:25px;border-collapse:collapse">';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;width:130px;border-bottom:1px solid #f3f4f6;vertical-align:top">Tamu</td><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #f3f4f6">' + escHtml(order.guest_name) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Room</td><td style="padding:8px 12px;font-weight:600;color:#6366f1;border-bottom:1px solid #f3f4f6">' + escHtml(roomStr) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Tanggal</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + dateStr + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Jam</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + timeStr + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Jumlah Pax</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + (order.total_pax || 1) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Lokasi</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + locLabel + '</td></tr>';
        html += '</table>';

        // Menu header
        html += '<div style="font-size:15px;font-weight:700;margin-bottom:12px;padding:10px 12px;background:#fef3c7;border-radius:6px">Menu Items</div>';

        // Menu table
        html += '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:20px">';
        html += '<thead><tr style="background:#f9fafb">';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280;text-transform:uppercase">Menu</th>';
        html += '<th style="padding:10px 12px;text-align:center;width:50px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Qty</th>';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Catatan</th>';
        html += '<th style="padding:10px 12px;text-align:right;width:110px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Harga</th>';
        html += '</tr></thead><tbody>';

        var totalPrice = 0;
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var price = parseFloat(it.price) || 0;
            var qty = parseInt(it.quantity) || 1;
            var lineTotal = it.is_free ? 0 : price * qty;
            totalPrice += lineTotal;
            html += '<tr style="border-bottom:1px solid #f3f4f6">';
            html += '<td style="padding:10px 12px;font-weight:600">' + escHtml(it.menu_name || '?');
            if (it.is_extra) html += ' <span style="color:#b45309;font-size:10px;font-weight:700">(Extra BF)</span>';
            else if (it.is_free) html += ' <span style="color:#10b981;font-size:10px;font-weight:400">(Free)</span>';
            html += '</td>';
            html += '<td style="padding:10px 12px;text-align:center">' + qty + '</td>';
            html += '<td style="padding:10px 12px;color:#92400e;font-style:italic">' + escHtml(it.note || '-') + '</td>';
            html += '<td style="padding:10px 12px;text-align:right">' + (lineTotal > 0 ? 'Rp ' + numberFmt(lineTotal) : '-') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';

        // Special requests
        if (order.special_requests) {
            html += '<div style="margin-bottom:20px;padding:12px 14px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;font-size:12px">';
            html += '<strong>Catatan Khusus:</strong> ' + escHtml(order.special_requests);
            html += '</div>';
        }

        // Total
        html += '<div style="text-align:right;padding:14px 12px;border-top:2px solid #e5e7eb;margin-bottom:30px">';
        if (totalPrice > 0) {
            html += '<span style="font-size:18px;font-weight:800;color:#10b981">Total: Rp ' + numberFmt(totalPrice) + '</span>';
        } else {
            html += '<span style="font-size:15px;font-weight:700;color:#6b7280">Free Breakfast</span>';
        }
        html += '</div>';

        // Footer — no absolute positioning, just at the end with spacing
        html += '<div style="text-align:center;font-size:9px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:40px">';
        html += 'Printed from ADF System — <?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Hotel"); ?> &copy; <?php echo date("Y"); ?>';
        html += '<br>Printed: ' + new Date().toLocaleString('id-ID');
        html += '</div>';

        html += '</div>';

        var container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        html2pdf().set({
            margin: [10, 15, 15, 15],
            filename: 'breakfast-' + escHtml(order.guest_name).replace(/[\s,]+/g, '-') + '-' + dateStr + '.pdf',
            html2canvas: {
                scale: 2,
                useCORS: true
            },
            jsPDF: {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait'
            },
            pagebreak: {
                mode: ['avoid-all']
            }
        }).from(container).save().then(function() {
            document.body.removeChild(container);
        });
    }

    // Rekap Total Pesanan per Menu — PDF untuk kitchen prep
    var rekapMenuData = <?php echo json_encode($menuRecap, JSON_HEX_APOS | JSON_HEX_TAG); ?>;
    var rekapTotalPax = <?php echo (int) array_sum(array_column($todayOrders, 'total_pax')); ?>;

    function cetakRekap() {
        var entries = Object.keys(rekapMenuData).map(function(name) {
            return {
                name: name,
                qty: rekapMenuData[name]
            };
        });

        var html = '<div style="font-family:Arial,sans-serif;width:100%;max-width:700px;margin:0 auto;padding:30px 40px;color:#1a1a2e">';

        html += '<div style="text-align:center;border-bottom:3px solid #f59e0b;padding-bottom:15px;margin-bottom:25px">';
        html += '<div style="font-size:26px;font-weight:800;color:#f59e0b;letter-spacing:1px">REKAP PESANAN SARAPAN</div>';
        html += '<div style="font-size:14px;color:#374151;margin-top:6px;font-weight:600"><?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Karimunjawa"); ?></div>';
        html += '<div style="font-size:11px;color:#9ca3af;margin-top:4px"><?php echo $today; ?> | Total ' + <?php echo (int) count($todayOrders); ?> + ' order, ' + rekapTotalPax + ' pax</div>';
        html += '</div>';

        html += '<table style="width:100%;font-size:13px;border-collapse:collapse">';
        html += '<thead><tr style="background:#fef3c7">';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #fde68a;font-size:11px;color:#92400e;text-transform:uppercase">Menu</th>';
        html += '<th style="padding:10px 12px;text-align:right;width:100px;border-bottom:2px solid #fde68a;font-size:11px;color:#92400e;text-transform:uppercase">Total Qty</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < entries.length; i++) {
            html += '<tr style="border-bottom:1px solid #f3f4f6">';
            html += '<td style="padding:10px 12px;font-weight:600">' + escHtml(entries[i].name) + '</td>';
            html += '<td style="padding:10px 12px;text-align:right;font-weight:800;color:#d97706;font-size:15px">×' + entries[i].qty + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';

        html += '<div style="text-align:center;font-size:9px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:30px">';
        html += 'Printed from ADF System — <?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Hotel"); ?> &copy; <?php echo date("Y"); ?>';
        html += '<br>Printed: ' + new Date().toLocaleString('id-ID');
        html += '</div>';

        html += '</div>';

        var container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        html2pdf().set({
            margin: [10, 15, 15, 15],
            filename: 'rekap-sarapan-<?php echo $today; ?>.pdf',
            html2canvas: {
                scale: 2,
                useCORS: true
            },
            jsPDF: {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait'
            },
            pagebreak: {
                mode: ['avoid-all']
            }
        }).from(container).save().then(function() {
            document.body.removeChild(container);
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function numberFmt(n) {
        return parseInt(n).toLocaleString('id-ID');
    }

    function addCustomExtra() {
        var container = document.getElementById('customExtrasContainer');
        var idx = container.querySelectorAll('.bf-custom-extra').length;
        var div = document.createElement('div');
        div.className = 'bf-custom-extra';
        div.dataset.index = idx;
        div.innerHTML = '<div style="display:flex;gap:.5rem;align-items:center">' +
            '<input type="text" class="bf-input custom-extra-name" placeholder="Nama item, cth: Extra Nasi" style="flex:1;font-size:.8rem;padding:.45rem .55rem" required>' +
            '<input type="number" class="bf-input custom-extra-price" placeholder="Harga" min="0" step="1000" style="width:110px;font-size:.8rem;padding:.45rem .55rem" required>' +
            '<input type="number" class="bf-input custom-extra-qty" placeholder="Qty" value="1" min="1" max="20" style="width:55px;font-size:.8rem;padding:.45rem .55rem;text-align:center">' +
            '<button type="button" onclick="this.closest(\'.bf-custom-extra\').remove()" style="padding:.4rem .55rem;background:rgba(239,68,68,.15);color:#ef4444;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700">✕</button>' +
            '</div>' +
            '<input type="text" class="bf-note-input custom-extra-note" placeholder="Catatan (opsional)" style="margin-top:.35rem;width:100%">';
        container.appendChild(div);
        div.querySelector('.custom-extra-name').focus();
    }

    var linkContext = {
        createApi: <?php echo json_encode(BASE_URL . '/api/breakfast-guest-portal.php', JSON_UNESCAPED_UNICODE); ?>,
        childMenuDefaults: <?php echo json_encode($defaultChildMenuIds, JSON_UNESCAPED_UNICODE); ?>,
        portalLinkTemplate: <?php echo json_encode($guestLinkMessageTemplate, JSON_UNESCAPED_UNICODE); ?>
    };

    function renderChildMenuOptions() {
        return;
    }

    function getSelectedChildMenuIdsFromGuest(cb) {
        if (!cb) return [];
        var ids = [];
        try {
            ids = JSON.parse(cb.dataset.childMenuIds || '[]');
        } catch (e) {
            ids = [];
        }
        if (!Array.isArray(ids) || ids.length === 0) {
            ids = Array.isArray(linkContext.childMenuDefaults) ? linkContext.childMenuDefaults : [];
        }
        return ids.map(function(v) {
            return parseInt(v, 10);
        }).filter(function(v) {
            return Number.isFinite(v) && v > 0;
        });
    }

    function buildPortalLinkWaMessage(guestName, roomLabel, portalLink) {
        var template = (linkContext.portalLinkTemplate || '').trim();
        if (!template) {
            template = [
                'Hello {guest_name},',
                'Please select your breakfast menu using the link below:',
                '{portal_link}',
                '{room_line}',
                'The system will limit the selection based on your breakfast allowance.',
                'Thank you.'
            ].join('\n');
        }

        var message = template
            .replace(/\{guest_name\}/g, guestName || 'Guest')
            .replace(/\{room_label\}/g, roomLabel || '')
            .replace(/\{room_line\}/g, roomLabel ? 'Room: ' + roomLabel : '')
            .replace(/\{portal_link\}/g, portalLink || '');

        var cleaned = message
            .split('\n')
            .map(function(line) {
                return line.trim();
            })
            .filter(function(line, index, arr) {
                return line !== '' || (index > 0 && arr[index - 1] !== '');
            });

        var deduped = [];
        var lastLine = null;
        var seenPortalLink = false;
        for (var i = 0; i < cleaned.length; i++) {
            var line = cleaned[i];
            if (!line) {
                if (deduped.length && deduped[deduped.length - 1] !== '') deduped.push('');
                continue;
            }
            if (line === lastLine) continue;
            if (portalLink && line.indexOf(portalLink) !== -1) {
                if (seenPortalLink) continue;
                seenPortalLink = true;
            }
            deduped.push(line);
            lastLine = line;
        }

        return deduped.join('\n').trim();
    }

    // ═══ Link sarapan: jatah pax dari reservasi (bisa disetel), grup = 1 link, kirim via WhatsApp ═══
    var bfgSetupCb = null;

    // Sukses: popup centang hijau di tengah, hilang otomatis.
    function bfgOk(title, sub) {
        var p = document.getElementById('bfgOk');
        document.getElementById('bfgOkTitle').textContent = title;
        var s = document.getElementById('bfgOkSub');
        s.textContent = sub || '';
        s.style.display = sub ? '' : 'none';
        p.classList.remove('show');
        void p.offsetWidth; // ulang animasi centang
        p.classList.add('show');
        clearTimeout(p._h);
        p._h = setTimeout(function() { p.classList.remove('show'); }, 1800);
    }

    function bfgToast(msg, kind) {
        var t = document.getElementById('bfgToast');
        t.textContent = msg;
        t.className = 'bfg-toast show ' + (kind || 'ok');
        clearTimeout(t._h);
        t._h = setTimeout(function() { t.className = 'bfg-toast'; }, 4500);
    }

    function bfgRowOf(cb) {
        return cb.closest('.bf-guest-item');
    }

    function bfgSetPax(cb, pax, source, kids) {
        cb.dataset.pax = cb.dataset.adults = cb.dataset.totalPax = cb.dataset.maxMain = cb.dataset.maxDrink = String(pax);
        cb.dataset.kids = String(kids || 0);
        var row = bfgRowOf(cb);
        row.querySelector('.bfg-pax').textContent = pax + ' pax' + (kids ? ' + ' + kids + ' kids' : '');
        if (source) row.querySelector('.bfg-src').textContent = source;
    }

    function openGuestSetup(evt, btn) {
        evt.preventDefault();
        evt.stopPropagation();
        var cb = bfgRowOf(btn).querySelector('input[name="guest_checks[]"]');
        if (!cb) return;
        bfgSetupCb = cb;
        document.getElementById('guestSetupTitle').textContent = 'Setup: ' + (cb.dataset.name || 'Guest');
        document.getElementById('setupPax').value = parseInt(cb.dataset.pax || '1', 10) || 1;
        document.getElementById('setupKids').value = parseInt(cb.dataset.kids || '0', 10) || 0;
        document.getElementById('setupHint').textContent = 'Room ' + (cb.dataset.rooms || '-').replace(/,/g, ', ') +
            '. 1 pax = 1 makanan + 1 jus + 1 kopi/teh; makanan extra ditagih Rp 82.500 lewat invoice Hotel Service. ' +
            'Kids (< 7 th) gratis: 1 pancake/waffle + 1 minuman per anak — jangan dihitung juga di pax.';
        document.getElementById('guestSetupModal').classList.add('show');
        setTimeout(function() { document.getElementById('setupPax').select(); }, 50);
    }

    function closeGuestSetup() {
        document.getElementById('guestSetupModal').classList.remove('show');
        bfgSetupCb = null;
    }

    function stepSetupKids(d) {
        var el = document.getElementById('setupKids');
        el.value = Math.max(0, Math.min(30, (parseInt(el.value, 10) || 0) + d));
    }

    function stepSetupPax(d) {
        var el = document.getElementById('setupPax');
        el.value = Math.max(1, Math.min(60, (parseInt(el.value, 10) || 1) + d));
    }

    async function saveGuestSetup() {
        if (!bfgSetupCb) return;
        var pax = Math.max(1, Math.min(60, parseInt(document.getElementById('setupPax').value, 10) || 1));
        var kids = Math.max(0, Math.min(30, parseInt(document.getElementById('setupKids').value, 10) || 0));
        var btn = document.getElementById('setupSaveBtn');
        btn.disabled = true;
        try {
            var res = await fetch(linkContext.createApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_setup', booking_ids: JSON.parse(bfgSetupCb.dataset.bookingIds || '[]'), pax: pax, kids: kids })
            });
            var data = await res.json();
            if (!data.success) throw new Error(data.message || 'Gagal menyimpan');
            bfgSetPax(bfgSetupCb, pax, 'disetel', kids);
            closeGuestSetup();
            bfgOk('Jatah tersimpan', pax + ' pax' + (kids ? ' + ' + kids + ' kids' : ''));
        } catch (e) {
            bfgToast(e.message, 'err');
        }
        btn.disabled = false;
    }

    // Gabungkan beberapa baris (kamar/grup) menjadi 1 link: kamar & pax dijumlahkan.
    function bfgCombine(cbs) {
        var names = [], rooms = [], ids = [], pax = 0, kids = 0, phone = '', guestId = null;
        cbs.forEach(function(cb) {
            var n = (cb.dataset.name || '').trim();
            if (n && names.indexOf(n) === -1) names.push(n);
            (cb.dataset.rooms || '').split(',').map(function(r) { return r.trim(); }).filter(Boolean).forEach(function(r) {
                if (rooms.indexOf(r) === -1) rooms.push(r);
            });
            JSON.parse(cb.dataset.bookingIds || '[]').forEach(function(id) { if (ids.indexOf(id) === -1) ids.push(id); });
            pax += parseInt(cb.dataset.pax || '1', 10) || 1;
            kids += parseInt(cb.dataset.kids || '0', 10) || 0;
            if (!phone && cb.dataset.phone) { phone = cb.dataset.phone; guestId = parseInt(cb.value, 10) || null; }
        });
        if (!guestId) guestId = parseInt(cbs[0].value, 10) || null;
        return { names: names, rooms: rooms, ids: ids, pax: pax, kids: kids, phone: phone, guestId: guestId };
    }

    async function bfgCreateLink(c) {
        var res = await fetch(linkContext.createApi, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_link',
                guest_id: c.guestId,
                guest_name: c.names.join(', '),
                guest_phone: c.phone,
                booking_id: c.ids[0] || null,
                booking_ids: c.ids,
                room_number: c.rooms,
                breakfast_date: <?php echo json_encode($today); ?>,
                adult_count: c.pax,
                child_young_count: c.kids,
                child_menu_ids: linkContext.childMenuDefaults || [],
                total_pax: c.pax,
                max_main: c.pax,
                max_drink: c.pax * 2,
                max_child: c.kids,
                expire_hours: 24
            })
        });
        var data = await res.json();
        if (!data.success) throw new Error(data.message || 'Gagal membuat link');
        return data.data || {};
    }

    var phoneAskResolve = null;

    function askPhone(label, current, text) {
        document.getElementById('phoneAskText').textContent = text || (label + ' belum punya nomor WhatsApp. Masukkan nomor untuk mengirim link.');
        document.getElementById('phoneAskInput').value = current || '';
        document.getElementById('phoneAskModal').classList.add('show');
        setTimeout(function() { document.getElementById('phoneAskInput').focus(); }, 50);
        return new Promise(function(resolve) { phoneAskResolve = resolve; });
    }

    function phoneAskDone(value) {
        document.getElementById('phoneAskModal').classList.remove('show');
        if (phoneAskResolve) {
            phoneAskResolve(value === null ? null : String(value).trim());
            phoneAskResolve = null;
        }
    }

    // Salin / buka link sarapan tamu: memakai link yang sudah dikirim (tidak membuat link baru agar link tamu tidak hangus);
    // bila belum ada, baru dibuat.
    async function bfgLinkAction(evt, btn, openIt) {
        evt.preventDefault();
        evt.stopPropagation();
        var cb = bfgRowOf(btn).querySelector('input[name="guest_checks[]"]');
        var w = openIt ? window.open('', '_blank') : null; // dibuka sekarang agar tidak diblokir popup-blocker
        btn.disabled = true;
        try {
            var url = '';
            var res = await fetch(linkContext.createApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'current_link', booking_ids: JSON.parse(cb.dataset.bookingIds || '[]'), breakfast_date: <?php echo json_encode($today); ?> })
            });
            var d = await res.json();
            if (d.success && d.data) url = d.data.short_link || d.data.link_url || '';
            if (!url) { var made = await bfgCreateLink(bfgCombine([cb])); url = made.short_link || made.link_url || ''; }
            if (!url) throw new Error('Link tidak tersedia');
            if (openIt) {
                if (w) w.location.href = url; else window.open(url, '_blank');
            } else {
                try { await navigator.clipboard.writeText(url); bfgOk('Link disalin', url); }
                catch (e) { prompt('Salin link berikut:', url); }
            }
        } catch (e) {
            if (w) w.close();
            bfgToast(e.message, 'err');
        } finally {
            btn.disabled = false;
        }
    }

    async function bfgSend(cbs, btn) {
        var c = bfgCombine(cbs);
        // Sudah pernah terkirim hari ini? Minta konfirmasi agar tamu tidak menerima link dua kali.
        var sentCbs = cbs.filter(function(cb) { return cb.dataset.waSent; });
        if (sentCbs.length) {
            var info = sentCbs.map(function(cb) {
                return 'Room <b>' + bfgEsc((cb.dataset.rooms || '').replace(/,/g, ', ')) + '</b> jam <b>' + bfgEsc(cb.dataset.waSent) + '</b>' +
                    (cb.dataset.waTarget ? ' ke <b>' + bfgEsc(cb.dataset.waTarget) + '</b>' : '');
            });
            var again = await waResendAsk(info.join('<br>') + '<br>Kirim lagi ke tamu?');
            if (!again) return;
        }
        if (btn) btn.disabled = true;
        try {
            var link = await bfgCreateLink(c);
            var portalLink = link.short_link || link.link_url || '';
            var label = c.names.join(', ');
            var phone = c.phone, typed = false;
            if (!phone) {
                phone = await askPhone(label);
                if (phone === null) return;
                if (phone === '') {
                    try { await navigator.clipboard.writeText(portalLink); bfgOk('Link disalin', 'Tempel ke chat tamu'); }
                    catch (e) { prompt('Salin link berikut:', portalLink); }
                    return;
                }
                typed = true;
            }
            var msg = buildPortalLinkWaMessage(label, c.rooms.join(', '), portalLink);
            var res = await fetch(linkContext.createApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'send_wa', target: phone, message: msg, guest_id: c.guestId, save_phone: typed && document.getElementById('phoneAskSave').checked ? 1 : 0, ref: 'breakfast ' + c.rooms.join(',') })
            });
            var r = await res.json();
            if (r.success) {
                bfgOk('Link sarapan terkirim', 'WhatsApp ' + (r.target || phone) + ' · ' + c.pax + ' pax' + (c.kids ? ' + ' + c.kids + ' kids' : ''));
                var now = new Date();
                var hhmm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
                cbs.forEach(function(cb) {
                    var row = bfgRowOf(cb);
                    var s = row.querySelector('.bfg-sent');
                    if (s) { s.hidden = false; var sb = s.querySelector('b'); if (sb) sb.textContent = hhmm; }
                    var wb = row.querySelector('.bf-wa-send');
                    if (wb) wb.classList.add('is-sent');
                    cb.dataset.waSent = hhmm;
                    cb.dataset.waTarget = r.target || phone;
                    if (typed) cb.dataset.phone = phone;
                });
            } else {
                // Gateway belum diatur / gagal: tampilkan alasannya; tombol "Buka WhatsApp" diklik langsung
                // oleh petugas (window.open setelah proses async diblokir popup-blocker browser).
                waFail = { phone: phone, msg: msg, link: portalLink };
                document.getElementById('waFailReason').innerHTML = '<b>' + (r.gateway ? 'Gateway menolak' : 'Gateway belum aktif') + ':</b> ' +
                    String(r.message || 'Tidak diketahui').replace(/[&<>]/g, function(ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]; }) +
                    '<br>Nomor tujuan: ' + (r.target || phone);
                document.getElementById('waFailModal').classList.add('show');
            }
        } catch (e) {
            bfgToast(e.message, 'err');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    var waFail = null;
    var waResendResolve = null;

    function bfgEsc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; });
    }

    // Popup konfirmasi kirim ulang (pengganti confirm() bawaan browser)
    function waResendAsk(html) {
        document.getElementById('waResendText').innerHTML = html;
        document.getElementById('waResendModal').classList.add('show');
        return new Promise(function(resolve) { waResendResolve = resolve; });
    }

    function waResendDone(ok) {
        document.getElementById('waResendModal').classList.remove('show');
        if (waResendResolve) { waResendResolve(!!ok); waResendResolve = null; }
    }

    function waFailClose() {
        document.getElementById('waFailModal').classList.remove('show');
    }

    function waFailOpen() {
        if (!waFail) return;
        window.open('https://wa.me/' + normalizeWaPhone(waFail.phone) + '?text=' + encodeURIComponent(waFail.msg), '_blank');
        waFailClose();
    }

    async function waFailCopy() {
        if (!waFail) return;
        try { await navigator.clipboard.writeText(waFail.link); bfgOk('Link disalin', 'Tempel ke chat tamu'); }
        catch (e) { prompt('Salin link berikut:', waFail.link); }
        waFailClose();
    }

    // Ubah nomor WhatsApp tamu langsung dari daftar.
    async function editGuestPhone(evt, chip) {
        evt.preventDefault();
        evt.stopPropagation();
        var cb = bfgRowOf(chip).querySelector('input[name="guest_checks[]"]');
        if (!cb) return;
        var phone = await askPhone(cb.dataset.name, cb.dataset.phone, 'Nomor WhatsApp ' + cb.dataset.name + ':');
        if (!phone) return;
        try {
            var res = await fetch(linkContext.createApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_phone', guest_id: parseInt(cb.value, 10), booking_ids: JSON.parse(cb.dataset.bookingIds || '[]'), phone: phone })
            });
            var r = await res.json();
            if (!r.success) throw new Error(r.message || 'Gagal menyimpan nomor');
            cb.dataset.phone = phone;
            chip.querySelector('span').textContent = phone;
            chip.classList.remove('empty');
            bfgOk('Nomor tersimpan', phone);
        } catch (e) {
            bfgToast(e.message, 'err');
        }
    }

    function sendGuestSelectionLink(evt, btn) {
        evt.preventDefault();
        evt.stopPropagation();
        var cb = bfgRowOf(btn).querySelector('input[name="guest_checks[]"]');
        if (cb) bfgSend([cb], btn);
    }

    function sendSelectedGuestsPortalLinks() {
        var selected = Array.from(document.querySelectorAll('input[name="guest_checks[]"]:checked'));
        if (!selected.length) return bfgToast('Pilih minimal 1 tamu', 'err');
        bfgSend(selected, document.getElementById('bulkLinkBtn'));
    }

    // Tombol gabungan: aktif & menampilkan total pax tamu yang dicentang.
    document.querySelectorAll('input[name="guest_checks[]"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var sel = Array.from(document.querySelectorAll('input[name="guest_checks[]"]:checked'));
            var btn = document.getElementById('bulkLinkBtn');
            if (!btn) return;
            btn.disabled = sel.length === 0;
            var pax = sel.reduce(function(s, x) { return s + (parseInt(x.dataset.pax || '1', 10) || 1); }, 0);
            btn.querySelector('span').textContent = sel.length > 1 ? 'Kirim 1 link gabungan · ' + sel.length + ' tamu · ' + pax + ' pax' :
                (sel.length === 1 ? 'Kirim link · ' + pax + ' pax' : 'Kirim 1 link gabungan');
        });
    });

    function normalizeWaPhone(rawPhone) {
        var p = String(rawPhone || '').replace(/[^0-9]/g, '');
        if (!p) return '';
        if (p.indexOf('00') === 0) p = p.substring(2);
        if (p.indexOf('62') === 0) return p;
        if (p.charAt(0) === '0') return '62' + p.substring(1);
        if (p.charAt(0) === '8') return '62' + p;
        return p;
    }

    renderChildMenuOptions();
</script>

<?php include '../../includes/footer.php'; ?>