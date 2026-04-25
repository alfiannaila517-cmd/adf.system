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
if (!$auth->hasPermission('frontdesk')) { header('Location: ' . BASE_URL . '/403.php'); exit; }

$db = Database::getInstance();
$pdo = $db->getConnection();
$today = date('Y-m-d');

// WhatsApp outreach settings (daily breakfast broadcast template)
$waInfoText = '';
$waMediaPath = '';
$waMediaUrl = '';

$upsertSetting = function ($key, $value) use ($db) {
    $exists = $db->fetchOne("SELECT setting_key FROM settings WHERE setting_key = ? LIMIT 1", [$key]);
    if ($exists) {
        $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
    } else {
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
    }
};

try {
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_wa_info_text'");
    $waInfoText = $row['setting_value'] ?? '';
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_wa_media_path'");
    $waMediaPath = $row['setting_value'] ?? '';
    if ($waMediaPath) {
        $waMediaUrl = (strpos($waMediaPath, 'http') === 0)
            ? $waMediaPath
            : BASE_URL . '/' . ltrim($waMediaPath, '/');
    }
} catch (Exception $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wa_action'] ?? '') === 'save_wa_info') {
    try {
        $newInfoText = trim($_POST['wa_info_text'] ?? '');
        $removeMedia = !empty($_POST['wa_remove_media']);
        $newMediaPath = $waMediaPath;

        if ($removeMedia) {
            if ($newMediaPath && strpos($newMediaPath, 'http') !== 0) {
                $oldAbsPath = BASE_PATH . '/' . ltrim($newMediaPath, '/');
                if (is_file($oldAbsPath)) {
                    @unlink($oldAbsPath);
                }
            }
            $newMediaPath = '';
        }

        if (!empty($_FILES['wa_media_file']) && ($_FILES['wa_media_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($_FILES['wa_media_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new Exception('Upload media gagal. Coba ulangi.');
            }

            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $origName = $_FILES['wa_media_file']['name'] ?? 'media';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                throw new Exception('Format media harus JPG, PNG, WEBP, atau PDF.');
            }

            $maxSize = 5 * 1024 * 1024;
            if (($_FILES['wa_media_file']['size'] ?? 0) > $maxSize) {
                throw new Exception('Ukuran media maksimal 5MB.');
            }

            $uploadDir = BASE_PATH . '/uploads/breakfast-wa';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $bizTag = defined('ACTIVE_BUSINESS_ID') ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)ACTIVE_BUSINESS_ID) : 'biz';
            $newName = 'wa_info_' . $bizTag . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetAbs = $uploadDir . '/' . $newName;
            if (!move_uploaded_file($_FILES['wa_media_file']['tmp_name'], $targetAbs)) {
                throw new Exception('Tidak bisa menyimpan file upload.');
            }

            if ($newMediaPath && strpos($newMediaPath, 'http') !== 0) {
                $oldAbsPath = BASE_PATH . '/' . ltrim($newMediaPath, '/');
                if (is_file($oldAbsPath)) {
                    @unlink($oldAbsPath);
                }
            }

            $newMediaPath = 'uploads/breakfast-wa/' . $newName;
        }

        $upsertSetting('breakfast_wa_info_text', $newInfoText);
        $upsertSetting('breakfast_wa_media_path', $newMediaPath);
        header('Location: breakfast.php?success=' . urlencode('Template WhatsApp berhasil disimpan'));
        exit;
    } catch (Exception $e) {
        header('Location: breakfast.php?success=' . urlencode('Gagal simpan template WA: ' . $e->getMessage()));
        exit;
    }
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
} catch (Exception $e) {}

// Widen guest_name column and drop old unique constraint (combined multi-guest names can be long)
try { $pdo->exec("ALTER TABLE breakfast_orders MODIFY guest_name VARCHAR(500) NOT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE breakfast_orders DROP INDEX uk_guest_date"); } catch (Exception $e) {}

// Get menus
$freeMenus = $paidMenus = [];
try {
    $freeMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=1 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
    $paidMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=0 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Default child menu IDs for guest portal quota (example: pancake + waffle)
$defaultChildMenuIds = [];
foreach (array_merge($freeMenus, $paidMenus) as $mx) {
    $nameLower = strtolower(trim($mx['menu_name'] ?? ''));
    if (in_array($nameLower, ['pancake', 'waffle'], true)) {
        $defaultChildMenuIds[] = (int)$mx['id'];
    }
}

// Get in-house guests WHO HAVE NOT ORDERED TODAY
// Group by guest_id: one guest may have multiple bookings/rooms
$inHouseGuests = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.id as guest_id, g.guest_name, COALESCE(g.phone,'') as guest_phone,
               GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ',') as rooms,
               GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as booking_ids
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND NOT EXISTS (
            SELECT 1 FROM breakfast_orders bo 
            WHERE bo.breakfast_date = ? 
            AND FIND_IN_SET(g.guest_name, REPLACE(bo.guest_name, ', ', ',')) > 0
        )
        GROUP BY g.id, g.guest_name
        ORDER BY MIN(r.room_number) ASC
    ");
    $stmt->execute([$today]);
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

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
    foreach ($todayOrders as &$o) { $o['menu_items'] = json_decode($o['menu_items'], true) ?: []; }
} catch (Exception $e) {}

// Edit mode
$editOrder = null; $editMenuIds = []; $editMenuQty = []; $editMenuNotes = []; $editCustomExtras = [];
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
            }
        }
    }
}

$pageTitle = 'Breakfast Order';
include '../../includes/header.php';
?>

<style>
.bf-wrap{max-width:1300px;margin:0 auto}
.bf-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem}
.bf-head h1{font-size:1.5rem;font-weight:800;background:linear-gradient(135deg,#f59e0b,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0;display:flex;align-items:center;gap:.5rem}
.bf-head-actions{display:flex;gap:.5rem}
.bf-head-btn{padding:.5rem .875rem;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);border-radius:8px;font-size:.75rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:.35rem;transition:all .2s}
.bf-head-btn:hover{border-color:var(--primary-color);background:rgba(99,102,241,.1)}
.bf-grid{display:grid;grid-template-columns:1fr 350px;gap:1.25rem}
.bf-card{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:12px;padding:1rem}
.bf-section{margin-bottom:1rem}
.bf-title{font-size:.85rem;font-weight:700;color:var(--text-primary);margin-bottom:.65rem;padding-bottom:.4rem;border-bottom:2px solid var(--bg-tertiary);display:flex;align-items:center;gap:.4rem}
.bf-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:.75rem}
.bf-group{display:flex;flex-direction:column}
.bf-label{font-size:.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-bottom:.3rem}
.bf-input,.bf-select{padding:.55rem .65rem;border-radius:6px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.85rem;width:100%}
.bf-input:focus,.bf-select:focus{outline:none;border-color:var(--primary-color)}
.bf-radio-group{display:flex;gap:.5rem}
.bf-radio-label{flex:1;display:flex;align-items:center;justify-content:center;gap:.35rem;padding:.5rem;background:var(--bg-primary);border:2px solid var(--bg-tertiary);border-radius:8px;cursor:pointer;font-size:.78rem;font-weight:600;transition:all .2s}
.bf-radio-label:hover{border-color:var(--primary-color)}
.bf-radio-label:has(input:checked){border-color:var(--primary-color);background:rgba(99,102,241,.15)}
.bf-radio-label input{display:none}
.bf-menu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem}
.bf-menu-item{background:var(--bg-primary);border:1px solid var(--bg-tertiary);border-radius:8px;padding:.65rem;transition:all .2s;cursor:pointer}
.bf-menu-item:hover{border-color:var(--primary-color)}
.bf-menu-item:has(input:checked){border-color:#10b981;background:rgba(16,185,129,.1)}
.bf-menu-cb{display:flex;align-items:flex-start;gap:.5rem}
.bf-menu-cb input[type="checkbox"]{margin-top:.15rem;width:16px;height:16px;cursor:pointer}
.bf-menu-name{font-size:.8rem;font-weight:700;color:var(--text-primary);margin-bottom:.2rem}
.bf-menu-price{font-size:.72rem;font-weight:700;color:#10b981}
.bf-menu-cat{display:inline-block;padding:.15rem .4rem;background:rgba(99,102,241,.15);border-radius:4px;font-size:.6rem;font-weight:600;text-transform:uppercase;color:var(--primary-color)}
.bf-menu-qty{display:none;align-items:center;gap:.35rem;margin-top:.5rem;padding-top:.5rem;border-top:1px dashed var(--bg-tertiary)}
.bf-menu-item:has(input:checked) .bf-menu-qty{display:flex}
.bf-qty-input{width:50px;padding:.3rem;border-radius:4px;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.8rem;text-align:center}
.bf-menu-note{display:none;margin-top:.35rem}
.bf-menu-item:has(input:checked) .bf-menu-note{display:block}
.bf-note-input{width:100%;padding:.3rem .5rem;border-radius:4px;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.72rem;font-family:inherit}
.bf-textarea{width:100%;padding:.55rem .65rem;border-radius:6px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.85rem;font-family:inherit;resize:vertical;min-height:50px}
.bf-actions{display:flex;gap:.5rem;margin-top:1rem}
.bf-btn-submit{flex:1;padding:.75rem 1rem;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .2s}
.bf-btn-submit:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,185,129,.3)}
.bf-btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.bf-btn-reset{padding:.75rem 1rem;background:var(--bg-primary);color:var(--text-muted);border:1px solid var(--bg-tertiary);border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;text-align:center}
/* Sidebar */
.bf-side{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:12px;overflow:hidden;height:fit-content;position:sticky;top:1rem}
.bf-side-title{padding:.85rem 1rem;background:linear-gradient(135deg,var(--primary-color),var(--secondary-color));color:#fff;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:.4rem}
.bf-side-count{background:rgba(255,255,255,.25);padding:.15rem .5rem;border-radius:10px;font-size:.7rem;margin-left:auto}
.bf-order{padding:.75rem 1rem;border-bottom:1px solid var(--bg-tertiary);transition:background .2s}
.bf-order:last-child{border-bottom:none}
.bf-order:hover{background:var(--bg-primary)}
.bf-order-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem}
.bf-order-time{font-size:.75rem;font-weight:700;color:var(--primary-color)}
.bf-order-pax{font-size:.65rem;padding:.2rem .4rem;background:var(--bg-tertiary);border-radius:4px;color:var(--text-muted)}
.bf-order-guest{font-size:.8rem;font-weight:600;color:var(--text-primary);margin-bottom:.25rem}
.bf-order-room{font-size:.7rem;color:var(--text-muted);margin-bottom:.35rem}
.bf-order-menus{display:flex;flex-wrap:wrap;gap:.25rem}
.bf-order-tag{font-size:.62rem;padding:.15rem .35rem;background:rgba(139,92,246,.15);color:#a78bfa;border-radius:3px}
.bf-order-foot{display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;padding-top:.35rem;border-top:1px dashed var(--bg-tertiary)}
.bf-order-price{font-size:.72rem;font-weight:700;color:#10b981}
.bf-order-status{font-size:.6rem;padding:.2rem .4rem;border-radius:4px;font-weight:700;text-transform:uppercase}
.bf-order-status.pending{background:rgba(245,158,11,.2);color:#f59e0b}
.bf-order-status.preparing{background:rgba(99,102,241,.2);color:#6366f1}
.bf-order-status.served{background:rgba(16,185,129,.2);color:#10b981}
.bf-order-status.completed{background:rgba(107,114,128,.2);color:#9ca3af}
.bf-order-btns{display:flex;gap:.35rem;margin-top:.4rem}
.bf-order-btn{padding:.25rem .5rem;border-radius:4px;font-size:.65rem;font-weight:600;cursor:pointer;border:none;transition:all .2s}
.bf-order-btn.edit{background:rgba(99,102,241,.15);color:#6366f1}
.bf-order-btn.del{background:rgba(239,68,68,.15);color:#ef4444}
.bf-empty{padding:2rem 1rem;text-align:center;color:var(--text-muted)}
.bf-empty-icon{font-size:2rem;margin-bottom:.5rem}
.bf-alert{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600}
.bf-alert.ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#10b981}
.bf-alert.err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444}
.bf-no-guest{padding:1rem;text-align:center;font-size:.8rem;color:var(--text-muted);background:rgba(245,158,11,.08);border-radius:8px}
/* Multi-guest selection */
.bf-guest-list{max-height:200px;overflow-y:auto;border:1px solid var(--bg-tertiary);border-radius:8px;padding:.5rem}
.bf-guest-item{display:flex;align-items:center;gap:.5rem;padding:.45rem .55rem;border-radius:6px;transition:background .15s;cursor:pointer}
.bf-guest-item:hover{background:var(--bg-primary)}
.bf-guest-item:has(input:checked){background:rgba(16,185,129,.1)}
.bf-guest-item input[type="checkbox"]{width:16px;height:16px;cursor:pointer}
.bf-guest-item .guest-info{flex:1}
.bf-guest-item .guest-name{font-size:.8rem;font-weight:600;color:var(--text-primary)}
.bf-guest-item .guest-room{font-size:.68rem;color:var(--text-muted)}
.bf-guest-count{font-size:.72rem;color:var(--primary-color);font-weight:600;margin-top:.4rem}
.bf-guest-tools{display:flex;align-items:center;gap:.4rem}
.bf-wa-guest-btn{padding:.25rem .5rem;border:none;border-radius:6px;font-size:.66rem;font-weight:700;cursor:pointer;background:rgba(16,185,129,.15);color:#10b981}
.bf-link-guest-btn{padding:.25rem .5rem;border:none;border-radius:6px;font-size:.66rem;font-weight:700;cursor:pointer;background:rgba(14,165,233,.14);color:#0284c7}
.bf-wa-phone{font-size:.62rem;color:#64748b;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bf-wa-panel{margin-top:.6rem;padding:.65rem;border:1px solid var(--bg-tertiary);border-radius:8px;background:var(--bg-primary)}
.bf-wa-panel-title{font-size:.72rem;font-weight:800;color:var(--text-primary);margin-bottom:.45rem}
.bf-wa-textarea{width:100%;min-height:66px;padding:.5rem .55rem;border-radius:6px;background:var(--bg-secondary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.72rem;resize:vertical;font-family:inherit}
.bf-wa-row{display:flex;gap:.45rem;align-items:center;flex-wrap:wrap;margin-top:.45rem}
.bf-wa-file{font-size:.67rem;color:var(--text-muted)}
.bf-wa-save{padding:.35rem .65rem;border:none;border-radius:6px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;font-size:.68rem;font-weight:700;cursor:pointer}
.bf-wa-send{padding:.35rem .65rem;border:none;border-radius:6px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-size:.68rem;font-weight:700;cursor:pointer}
.bf-link-send{padding:.35rem .65rem;border:none;border-radius:6px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;font-size:.68rem;font-weight:700;cursor:pointer}
.bf-wa-check{display:flex;align-items:center;gap:.3rem;font-size:.66rem;color:var(--text-muted)}
.bf-wa-media-link{display:inline-flex;align-items:center;gap:.25rem;font-size:.66rem;color:#0ea5e9;text-decoration:none}
.bf-link-grid{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:.45rem}
.bf-link-group{display:flex;flex-direction:column;gap:.25rem}
.bf-link-group label{font-size:.64rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.25px}
.bf-link-group input{padding:.35rem .45rem;border-radius:6px;border:1px solid var(--bg-tertiary);background:var(--bg-secondary);color:var(--text-primary);font-size:.72rem}
.bf-child-menu-list{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.4rem}
.bf-child-menu-item{font-size:.66rem;padding:.2rem .4rem;border-radius:999px;background:rgba(99,102,241,.12);color:#4f46e5;display:flex;align-items:center;gap:.25rem}
/* Notes in sidebar */
.bf-order-note{font-size:.62rem;color:#f59e0b;font-style:italic;margin-left:.2rem}
.bf-order-special{font-size:.68rem;color:var(--text-muted);background:rgba(245,158,11,.08);padding:.3rem .5rem;border-radius:4px;margin-top:.35rem;font-style:italic;border-left:2px solid #f59e0b}
.bf-order-btn.print{background:rgba(16,185,129,.15);color:#10b981}
.bf-custom-extra{background:var(--bg-primary);border:1px solid var(--bg-tertiary);border-radius:8px;padding:.65rem;margin-bottom:.5rem;transition:all .2s}
.bf-custom-extra:hover{border-color:#f59e0b}
@media(max-width:900px){.bf-grid{grid-template-columns:1fr}.bf-side{position:static}.bf-menu-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.bf-row{grid-template-columns:1fr}.bf-menu-grid{grid-template-columns:1fr}.bf-radio-group{flex-direction:column}}
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
                    <div class="bf-title">👤 Pilih Tamu In-House <span style="font-size:.68rem;font-weight:400;color:var(--text-muted);margin-left:.5rem">(bisa pilih beberapa)</span></div>
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
                        <label class="bf-label">Pilih Tamu (centang 1 atau lebih) *</label>
                        <div class="bf-guest-list" id="guestList">
                            <?php foreach ($inHouseGuests as $g): 
                                $roomList = $g['rooms'];
                                $bookingIdFirst = explode(',', $g['booking_ids'])[0];
                            ?>
                            <label class="bf-guest-item">
                                <input type="checkbox" name="guest_checks[]" value="<?php echo $g['guest_id']; ?>"
                                       data-name="<?php echo htmlspecialchars($g['guest_name']); ?>"
                                       data-rooms="<?php echo htmlspecialchars($roomList); ?>"
                                       data-booking="<?php echo $bookingIdFirst; ?>"
                                       data-phone="<?php echo htmlspecialchars($g['guest_phone'] ?? ''); ?>">
                                <div class="guest-info">
                                    <div class="guest-name"><?php echo htmlspecialchars($g['guest_name']); ?></div>
                                    <div class="guest-room">🛏️ Room <?php echo $roomList; ?></div>
                                </div>
                                <div class="bf-guest-tools">
                                    <?php if (!empty($g['guest_phone'])): ?>
                                        <span class="bf-wa-phone" title="<?php echo htmlspecialchars($g['guest_phone']); ?>"><?php echo htmlspecialchars($g['guest_phone']); ?></span>
                                    <?php else: ?>
                                        <span class="bf-wa-phone">No phone</span>
                                    <?php endif; ?>
                                    <button type="button" class="bf-link-guest-btn" onclick="sendGuestSelectionLink(event,this)">Link</button>
                                    <button type="button" class="bf-wa-guest-btn" onclick="sendSingleGuestWa(event,this)">WA</button>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="bf-guest-count" id="guestCount">0 tamu dipilih</div>

                        <div class="bf-wa-panel">
                            <div class="bf-wa-panel-title">💬 Template WhatsApp Harian</div>
                            <textarea name="wa_info_text" id="waInfoText" class="bf-wa-textarea" placeholder="Contoh: Promo breakfast hari ini, jam layanan, atau info khusus." form="waTemplateForm"><?php echo htmlspecialchars($waInfoText); ?></textarea>
                            <div class="bf-wa-row">
                                <input type="file" name="wa_media_file" class="bf-wa-file" accept=".jpg,.jpeg,.png,.webp,.pdf" form="waTemplateForm">
                                <button type="submit" class="bf-wa-save" form="waTemplateForm">💾 Simpan Template</button>
                                <?php if (!empty($waMediaUrl)): ?>
                                    <a href="<?php echo htmlspecialchars($waMediaUrl); ?>" target="_blank" class="bf-wa-media-link">🖼️ Lihat media</a>
                                    <label class="bf-wa-check"><input type="checkbox" name="wa_remove_media" value="1" form="waTemplateForm">Hapus media</label>
                                <?php endif; ?>
                            </div>
                            <div class="bf-wa-row" style="margin-top:.55rem">
                                <button type="button" class="bf-wa-send" onclick="sendSelectedGuestsWa()">📲 Kirim WA ke tamu terpilih</button>
                            </div>
                            <div style="margin-top:.7rem;padding-top:.55rem;border-top:1px dashed var(--bg-tertiary)">
                                <div class="bf-wa-panel-title" style="margin-bottom:.35rem">🔗 Link Pilih Menu Sendiri (Guest Portal)</div>
                                <div class="bf-link-grid">
                                    <div class="bf-link-group">
                                        <label>Dewasa (≥7 thn)</label>
                                        <input type="number" id="linkAdultCount" min="0" max="10" value="1" onchange="updateQuotaDisplay()">
                                    </div>
                                    <div class="bf-link-group">
                                        <label>Anak < 7 thn</label>
                                        <input type="number" id="linkChildYoung" min="0" max="10" value="0" onchange="updateQuotaDisplay()">
                                    </div>
                                    <div class="bf-link-group">
                                        <label>Anak ≥ 7 thn</label>
                                        <input type="number" id="linkChildOld" min="0" max="10" value="0" onchange="updateQuotaDisplay()">
                                    </div>
                                </div>
                                <div style="font-size:.72rem;margin-top:.5rem;padding:.5rem;background:rgba(14,165,233,.1);border-radius:6px;color:#0284c7">
                                    <strong>📊 Ringkasan Quota:</strong>
                                    <span id="quotaSummary">Main Course: 2 | Minum: 2 | Menu Anak: 0</span>
                                </div>
                                <div class="bf-link-grid" style="margin-top:.5rem">
                                    <div class="bf-link-group">
                                        <label>Kuota Main/Dewasa</label>
                                        <input type="number" id="linkQuotaMain" min="0" max="10" value="2" onchange="updateQuotaDisplay()">
                                    </div>
                                    <div class="bf-link-group">
                                        <label>Kuota Minum</label>
                                        <input type="number" id="linkQuotaDrink" min="0" max="10" value="2" onchange="updateQuotaDisplay()">
                                    </div>
                                    <div class="bf-link-group">
                                        <label>Kadaluarsa (jam)</label>
                                        <input type="number" id="linkExpireHours" min="1" max="72" value="24">
                                    </div>
                                </div>
                                <div style="font-size:.66rem;color:var(--text-muted);margin-top:.45rem">Menu anak (usia <7 thn) - hanya pancake & waffle:</div>
                                <div class="bf-child-menu-list" id="childMenuIdsWrap"></div>
                                <div class="bf-wa-row" style="margin-top:.55rem">
                                    <button type="button" class="bf-link-send" onclick="sendSelectedGuestsPortalLinks()">🔗+📲 Buat Link & Kirim WA (terpilih)</button>
                                </div>
                            </div>
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
                            <label class="bf-label">Jumlah Pax *</label>
                            <input type="number" name="total_pax" id="totalPax" class="bf-input" min="1" max="20" required value="<?php echo $editOrder ? (int)$editOrder['total_pax'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Jam *</label>
                            <input type="time" name="breakfast_time" id="bfTime" class="bf-input" required value="<?php echo $editOrder ? $editOrder['breakfast_time'] : ''; ?>">
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
                    <div style="margin-bottom:1rem">
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">✨ Free Breakfast</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($freeMenus as $m): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-cb">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                        <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($paidMenus) > 0): ?>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">💰 Extra (Berbayar)</div>
                        <div class="bf-menu-grid">
                            <?php foreach ($paidMenus as $m): ?>
                            <div class="bf-menu-item">
                                <label class="bf-menu-cb">
                                    <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                    <div>
                                        <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                        <div class="bf-menu-price">Rp <?php echo number_format($m['price'], 0, ',', '.'); ?></div>
                                        <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                    </div>
                                </label>
                                <div class="bf-menu-qty">
                                    <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                    <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                </div>
                                <div class="bf-menu-note">
                                    <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Custom Extra Breakfast (Manual) -->
                    <div style="margin-top:1rem">
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center">
                            <span>🛒 Extra Breakfast (Manual)</span>
                            <button type="button" onclick="addCustomExtra()" style="padding:.3rem .65rem;background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;border:none;border-radius:6px;font-size:.72rem;font-weight:700;cursor:pointer">+ Tambah</button>
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
                        <p style="font-size:.65rem;color:var(--text-muted);margin-top:.4rem">Tambahkan item extra breakfast yang tidak ada di daftar menu. Isi nama dan harga manual.</p>
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
                    ?>
                    <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($roomStr); ?></div>
                    <div class="bf-order-room"><?php echo ($order['location'] ?? 'restaurant') === 'restaurant' ? '🍽️ Restaurant' : (($order['location'] ?? '') === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></div>
                    <div class="bf-order-menus">
                        <?php foreach ($order['menu_items'] as $item): ?>
                        <span class="bf-order-tag">
                            <?php echo htmlspecialchars($item['menu_name'] ?? '?'); ?>
                            <?php if (($item['quantity'] ?? 1) > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
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

<form id="waTemplateForm" method="POST" enctype="multipart/form-data" autocomplete="off" style="display:none;">
    <input type="hidden" name="wa_action" value="save_wa_info">
</form>

<script>
// Guest checkbox counter
var guestChecks = document.querySelectorAll('input[name="guest_checks[]"]');
var guestCountEl = document.getElementById('guestCount');
if (guestChecks.length > 0) {
    guestChecks.forEach(function(cb) {
        cb.addEventListener('change', function() {
            var count = document.querySelectorAll('input[name="guest_checks[]"]:checked').length;
            guestCountEl.textContent = count + ' tamu dipilih';
        });
    });
}

// Collect common form data (menu, time, pax, etc)
function collectFormData() {
    var pax = document.getElementById('totalPax').value;
    var time = document.getElementById('bfTime').value;
    if (!pax || parseInt(pax) < 1) { alert('Isi jumlah pax!'); return null; }
    if (!time) { alert('Isi jam sarapan!'); return null; }
    var menus = document.querySelectorAll('input[name="menu_items[]"]:checked');
    
    // Collect custom extras
    var customExtras = [];
    document.querySelectorAll('.bf-custom-extra').forEach(function(el) {
        var name = el.querySelector('.custom-extra-name').value.trim();
        var price = parseFloat(el.querySelector('.custom-extra-price').value) || 0;
        var qty = parseInt(el.querySelector('.custom-extra-qty').value) || 1;
        var note = el.querySelector('.custom-extra-note').value.trim();
        if (name && price >= 0) {
            customExtras.push({ name: name, price: price, quantity: qty, note: note });
        }
    });
    
    if (menus.length === 0 && customExtras.length === 0) { alert('Pilih minimal 1 menu atau tambahkan extra manual!'); return null; }
    var menuItems = [], menuQty = {}, menuNote = {};
    menus.forEach(function(cb) {
        var id = cb.value;
        menuItems.push(id);
        var q = document.querySelector('input[name="menu_qty[' + id + ']"]');
        menuQty[id] = q ? parseInt(q.value) || 1 : 1;
        var n = document.querySelector('input[name="menu_note[' + id + ']"]');
        menuNote[id] = n ? n.value.trim() : '';
    });
    return {
        total_pax: parseInt(pax),
        breakfast_time: time,
        breakfast_date: document.querySelector('input[name="breakfast_date"]').value,
        location: (document.querySelector('input[name="location"]:checked') || {value:'restaurant'}).value,
        special_requests: document.querySelector('textarea[name="special_requests"]').value.trim(),
        menu_items: menuItems,
        menu_qty: menuQty,
        menu_note: menuNote,
        custom_extras: customExtras
    };
}

// Form submit via AJAX
var submitting = false;
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
        var roomArr = roomsStr ? roomsStr.split(',').map(function(r){ return r.trim(); }) : [];
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
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
            } else {
                alert('❌ ' + (res.message || 'Gagal'));
                submitting = false; btn.disabled = false; btn.textContent = '✓ Update Order';
            }
        }).catch(function(err) {
            alert('❌ Error: ' + err.message);
            submitting = false; btn.disabled = false; btn.textContent = '✓ Update Order';
        });
        return;
    }

    // CREATE MODE: multi-guest
    var checked = document.querySelectorAll('input[name="guest_checks[]"]:checked');
    if (checked.length === 0) { alert('Pilih minimal 1 tamu!'); return; }

    var guests = [];
    checked.forEach(function(cb) {
        var roomsStr = cb.dataset.rooms || '';
        guests.push({
            guest_id: parseInt(cb.value) || null,
            guest_name: cb.dataset.name || '',
            room_number: roomsStr ? roomsStr.split(',').map(function(r){ return r.trim(); }) : [],
            booking_id: parseInt(cb.dataset.booking) || null
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
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
        } else {
            alert('❌ ' + (res.message || 'Gagal menyimpan'));
            submitting = false; btn.disabled = false; btn.textContent = '✓ Simpan Order';
        }
    })
    .catch(function(err) {
        alert('❌ Error koneksi: ' + err.message);
        submitting = false; btn.disabled = false; btn.textContent = '✓ Simpan Order';
    });
});

// Delete order
function hapusOrder(id, name) {
    if (!confirm('Hapus order sarapan "' + name + '"?')) return;
    fetch('<?php echo BASE_URL; ?>/api/breakfast-order-action.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'delete', id: id})
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) location.reload();
        else alert('Gagal: ' + (d.message || '?'));
    })
    .catch(function() { alert('Error koneksi'); });
}

// PDF Print — A4 format
function cetakOrder(order) {
    var rooms = order.room_number;
    if (typeof rooms === 'string') { try { rooms = JSON.parse(rooms); } catch(e) { rooms = [rooms]; } }
    var roomStr = Array.isArray(rooms) ? rooms.join(', ') : (rooms || '-');
    var items = order.menu_items;
    if (typeof items === 'string') { try { items = JSON.parse(items); } catch(e) { items = []; } }
    var locMap = {restaurant:'Restaurant', room_service:'Room Service', take_away:'Take Away'};
    var locLabel = locMap[order.location] || order.location;
    var timeStr = order.breakfast_time ? order.breakfast_time.substring(0,5) : '-';
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
        if (it.is_free) html += ' <span style="color:#10b981;font-size:10px;font-weight:400">(Free)</span>';
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
        filename: 'breakfast-' + escHtml(order.guest_name).replace(/[\s,]+/g,'-') + '-' + dateStr + '.pdf',
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['avoid-all'] }
    }).from(container).save().then(function() {
        document.body.removeChild(container);
    });
}

function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function numberFmt(n) { return parseInt(n).toLocaleString('id-ID'); }

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

var waContext = {
    infoText: <?php echo json_encode($waInfoText, JSON_UNESCAPED_UNICODE); ?>,
    mediaUrl: <?php echo json_encode($waMediaUrl, JSON_UNESCAPED_UNICODE); ?>,
    freeMenus: <?php echo json_encode(array_map(function ($m) { return $m['menu_name']; }, $freeMenus), JSON_UNESCAPED_UNICODE); ?>,
    paidMenus: <?php echo json_encode(array_map(function ($m) {
        return ['name' => $m['menu_name'], 'price' => (float)$m['price']];
    }, $paidMenus), JSON_UNESCAPED_UNICODE); ?>,
    todayLabel: <?php echo json_encode(date('d/m/Y', strtotime($today)), JSON_UNESCAPED_UNICODE); ?>
};

var linkContext = {
    createApi: <?php echo json_encode(BASE_URL . '/api/breakfast-guest-portal.php', JSON_UNESCAPED_UNICODE); ?>,
    childMenuCandidates: <?php echo json_encode(array_map(function($m){
        return ['id' => (int)$m['id'], 'name' => $m['menu_name']];
    }, array_merge($freeMenus, $paidMenus)), JSON_UNESCAPED_UNICODE); ?>,
    childMenuDefaults: <?php echo json_encode($defaultChildMenuIds, JSON_UNESCAPED_UNICODE); ?>
};

function renderChildMenuOptions() {
    var wrap = document.getElementById('childMenuIdsWrap');
    if (!wrap) return;
    var html = '';
    (linkContext.childMenuCandidates || []).forEach(function(m) {
        var checked = (linkContext.childMenuDefaults || []).indexOf(parseInt(m.id, 10)) >= 0;
        html += '<label class="bf-child-menu-item">' +
            '<input type="checkbox" class="child-menu-id" value="' + m.id + '" ' + (checked ? 'checked' : '') + '> ' +
            escHtml(m.name) +
            '</label>';
    });
    wrap.innerHTML = html;
}

function getSelectedChildMenuIds() {
    return Array.from(document.querySelectorAll('.child-menu-id:checked')).map(function(el) {
        return parseInt(el.value, 10);
    }).filter(function(v) { return Number.isFinite(v) && v > 0; });
}

async function createGuestPortalLinkFromCheckbox(cb) {
    var adultCount = parseInt((document.getElementById('linkAdultCount') || {value:'1'}).value, 10);
    var childYoung = parseInt((document.getElementById('linkChildYoung') || {value:'0'}).value, 10);
    var childOld = parseInt((document.getElementById('linkChildOld') || {value:'0'}).value, 10);
    var quotaMain = parseInt((document.getElementById('linkQuotaMain') || {value:'2'}).value, 10);
    var quotaDrink = parseInt((document.getElementById('linkQuotaDrink') || {value:'2'}).value, 10);
    var expireHours = parseInt((document.getElementById('linkExpireHours') || {value:'24'}).value, 10);
    
    if (!Number.isFinite(adultCount) || adultCount < 0) adultCount = 0;
    if (!Number.isFinite(childYoung) || childYoung < 0) childYoung = 0;
    if (!Number.isFinite(childOld) || childOld < 0) childOld = 0;
    if (!Number.isFinite(quotaMain) || quotaMain < 0) quotaMain = 0;
    if (!Number.isFinite(quotaDrink) || quotaDrink < 0) quotaDrink = 0;
    if (!Number.isFinite(expireHours) || expireHours < 1) expireHours = 24;

    var body = {
        action: 'create_link',
        guest_id: parseInt(cb.value, 10) || null,
        guest_name: cb.dataset.name || '',
        guest_phone: cb.dataset.phone || '',
        booking_id: parseInt(cb.dataset.booking, 10) || null,
        room_number: (cb.dataset.rooms || '').split(',').map(function(r){ return r.trim(); }).filter(Boolean),
        breakfast_date: <?php echo json_encode($today); ?>,
        // New quota structure
        adult_count: adultCount,
        child_young_count: childYoung,  // < 7 years old
        child_old_count: childOld,      // >= 7 years old
        max_main: quotaMain,
        max_drink: quotaDrink,
        max_child: childYoung, // young children get child menu quota
        child_menu_ids: getSelectedChildMenuIds(),
        expire_hours: expireHours
    };

    var res = await fetch(linkContext.createApi, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    });
    var data = await res.json();
    if (!data.success) {
        throw new Error(data.message || 'Gagal membuat link tamu');
    }
    return data.data || {};
}

function updateQuotaDisplay() {
    var adultCount = parseInt((document.getElementById('linkAdultCount') || {value:'1'}).value, 10) || 0;
    var childYoung = parseInt((document.getElementById('linkChildYoung') || {value:'0'}).value, 10) || 0;
    var childOld = parseInt((document.getElementById('linkChildOld') || {value:'0'}).value, 10) || 0;
    var quotaMain = parseInt((document.getElementById('linkQuotaMain') || {value:'2'}).value, 10) || 0;
    var quotaDrink = parseInt((document.getElementById('linkQuotaDrink') || {value:'2'}).value, 10) || 0;
    
    var totalMain = (adultCount * quotaMain) + (childOld * quotaMain);
    var totalDrink = (adultCount * quotaDrink) + (childOld * quotaDrink);
    var totalChild = childYoung; // young children only get child menu
    
    var summary = 'Main Course: ' + totalMain + ' | Minum: ' + totalDrink + ' | Menu Anak: ' + totalChild;
    var el = document.getElementById('quotaSummary');
    if (el) el.textContent = summary;
}

function buildPortalLinkWaMessage(guestName, roomLabel, portalLink) {
    var lines = [];
    lines.push('Selamat pagi Bapak/Ibu ' + (guestName || 'Tamu') + ' 🙏');
    lines.push('Silakan pilih menu sarapan melalui link berikut:');
    lines.push(portalLink);
    if (roomLabel) lines.push('Kamar: ' + roomLabel);
    lines.push('Sistem akan membatasi pilihan sesuai jatah menu Anda.');
    lines.push('Terima kasih.');
    return lines.join('\n');
}

async function sendGuestSelectionLink(evt, btn) {
    evt.preventDefault();
    evt.stopPropagation();
    var row = btn.closest('.bf-guest-item');
    var cb = row ? row.querySelector('input[name="guest_checks[]"]') : null;
    if (!cb) return;

    try {
        var linkData = await createGuestPortalLinkFromCheckbox(cb);
        var portalLink = linkData.short_link || linkData.link_url || '';
        var phone = normalizeWaPhone(cb.dataset.phone || '');
        if (!phone) {
            prompt('Link portal berhasil dibuat. Salin link berikut untuk dikirim manual:', portalLink);
            return;
        }
        var msg = buildPortalLinkWaMessage(cb.dataset.name || 'Tamu', cb.dataset.rooms || '-', portalLink);
        window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
    } catch (err) {
        alert('❌ ' + err.message);
    }
}

async function sendSelectedGuestsPortalLinks() {
    var selected = Array.from(document.querySelectorAll('input[name="guest_checks[]"]:checked'));
    if (!selected.length) {
        alert('Pilih minimal 1 tamu.');
        return;
    }

    var success = 0;
    var manualLinks = [];
    for (var i = 0; i < selected.length; i++) {
        var cb = selected[i];
        try {
            var linkData = await createGuestPortalLinkFromCheckbox(cb);
            var portalLink = linkData.short_link || linkData.link_url || '';
            var phone = normalizeWaPhone(cb.dataset.phone || '');
            if (phone) {
                var msg = buildPortalLinkWaMessage(cb.dataset.name || 'Tamu', cb.dataset.rooms || '-', portalLink);
                window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
                success++;
            } else {
                manualLinks.push((cb.dataset.name || 'Tamu') + ': ' + portalLink);
            }
        } catch (err) {
            manualLinks.push((cb.dataset.name || 'Tamu') + ': ERROR - ' + err.message);
        }
    }

    if (manualLinks.length) {
        prompt('Sebagian link perlu kirim manual (copy text di bawah):', manualLinks.join('\n'));
    }
    alert('Selesai. Link WA otomatis dibuka untuk ' + success + ' tamu.');
}

function normalizeWaPhone(rawPhone) {
    var p = String(rawPhone || '').replace(/[^0-9]/g, '');
    if (!p) return '';
    if (p.indexOf('00') === 0) p = p.substring(2);
    if (p.indexOf('62') === 0) return p;
    if (p.charAt(0) === '0') return '62' + p.substring(1);
    if (p.charAt(0) === '8') return '62' + p;
    return p;
}

function buildBreakfastWaMessage(guestName, roomLabel) {
    var lines = [];
    lines.push('Selamat pagi Bapak/Ibu ' + (guestName || 'Tamu') + ' 🙏');
    lines.push('Kami dari Front Office ingin konfirmasi pilihan sarapan untuk hari ini (' + waContext.todayLabel + ').');
    if (roomLabel) lines.push('Kamar: ' + roomLabel);
    lines.push('');
    if (waContext.freeMenus && waContext.freeMenus.length) {
        lines.push('*Menu Free Breakfast:*');
        waContext.freeMenus.forEach(function(name) { lines.push('- ' + name); });
        lines.push('');
    }
    if (waContext.paidMenus && waContext.paidMenus.length) {
        lines.push('*Menu Extra (Berbayar):*');
        waContext.paidMenus.forEach(function(item) {
            lines.push('- ' + item.name + ' (Rp ' + numberFmt(item.price || 0) + ')');
        });
        lines.push('');
    }
    var customInfo = (document.getElementById('waInfoText') || {value: waContext.infoText || ''}).value.trim();
    if (customInfo) {
        lines.push('*Info Tambahan:*');
        lines.push(customInfo);
        lines.push('');
    }
    if (waContext.mediaUrl) {
        lines.push('Media/Info: ' + waContext.mediaUrl);
        lines.push('');
    }
    lines.push('Silakan balas pesan ini dengan menu pilihan Anda. Terima kasih.');
    return lines.join('\n');
}

function openWaLinkByCheckbox(cb) {
    if (!cb) return false;
    var phone = normalizeWaPhone(cb.dataset.phone || '');
    if (!phone) return false;
    var guestName = cb.dataset.name || 'Tamu';
    var roomLabel = cb.dataset.rooms || '-';
    var msg = buildBreakfastWaMessage(guestName, roomLabel);
    var waUrl = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg);
    window.open(waUrl, '_blank');
    return true;
}

function sendSingleGuestWa(evt, btn) {
    evt.preventDefault();
    evt.stopPropagation();
    var row = btn.closest('.bf-guest-item');
    var cb = row ? row.querySelector('input[name="guest_checks[]"]') : null;
    if (!cb) return;
    if (!openWaLinkByCheckbox(cb)) {
        alert('Nomor WhatsApp tamu belum tersedia. Isi nomor di data guest terlebih dulu.');
    }
}

function sendSelectedGuestsWa() {
    var selected = Array.from(document.querySelectorAll('input[name="guest_checks[]"]:checked'));
    if (!selected.length) {
        alert('Pilih minimal 1 tamu untuk kirim WhatsApp.');
        return;
    }

    var valid = selected.filter(function(cb) { return normalizeWaPhone(cb.dataset.phone || ''); });
    if (!valid.length) {
        alert('Tamu terpilih tidak memiliki nomor WhatsApp yang valid.');
        return;
    }

    valid.forEach(function(cb, idx) {
        setTimeout(function() { openWaLinkByCheckbox(cb); }, idx * 350);
    });

    var invalidCount = selected.length - valid.length;
    if (invalidCount > 0) {
        alert('WA dibuka untuk ' + valid.length + ' tamu. ' + invalidCount + ' tamu dilewati karena nomor belum valid.');
    }
}

renderChildMenuOptions();
</script>

<?php include '../../includes/footer.php'; ?>
