<?php
/**
 * Sunsea - Pemesanan Baru (Simplified dengan dropdown database & auto-calculate)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

// Load data dari database
$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$packages = $pdo->query("SELECT id, name, base_price FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();
$tickets = $pdo->query("SELECT id, ticket_name, ticket_type, price_cost, price_sell FROM tickets WHERE is_active=1 ORDER BY ticket_name")->fetchAll();
$rooms = $pdo->query("SELECT r.id, r.room_type, r.price_cost, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type")->fetchAll();
$caterings = $pdo->query("SELECT id, menu_name, vendor_name, price_cost, price_sell, portion_unit FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name")->fetchAll();
$guides = $pdo->query("SELECT id, name, guide_type, daily_rate_cost, daily_rate_sell FROM guides WHERE is_active=1 ORDER BY guide_type, name")->fetchAll();
$facilities = $pdo->query("SELECT id, name, unit, price_cost, price_sell FROM facilities WHERE is_active=1 ORDER BY name")->fetchAll();
$coordinators = $pdo->query("SELECT id, name FROM coordinators WHERE is_active=1 ORDER BY name")->fetchAll();

// Handle AJAX request untuk fetch price
if ($_GET['action'] ?? '' === 'get_price') {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? '';
    $id = (int)($_GET['id'] ?? 0);
    
    $price = ['cost' => 0, 'sell' => 0];
    
    if ($type === 'ticket' && $id > 0) {
        $r = $pdo->prepare("SELECT price_cost, price_sell FROM tickets WHERE id=?")->execute([$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'room' && $id > 0) {
        $r = $pdo->prepare("SELECT price_cost, price_sell FROM accommodation_rooms WHERE id=?")->execute([$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'catering' && $id > 0) {
        $r = $pdo->prepare("SELECT price_cost, price_sell FROM caterings WHERE id=?")->execute([$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'guide' && $id > 0) {
        $r = $pdo->prepare("SELECT daily_rate_cost, daily_rate_sell FROM guides WHERE id=?")->execute([$id]);
        if ($r) $price = ['cost' => (float)$r['daily_rate_cost'], 'sell' => (float)$r['daily_rate_sell']];
    } elseif ($type === 'facility' && $id > 0) {
        $r = $pdo->prepare("SELECT price_cost, price_sell FROM facilities WHERE id=?")->execute([$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    }
    
    echo json_encode($price);
    exit;
}

// Handle form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_booking') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $bookingMode = $_POST['booking_mode'] ?? 'paket';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $pax = max(1, (int)($_POST['pax_count'] ?? 1));

    if ($customerId <= 0 || $startDate === '' || $endDate === '') {
        $_SESSION['flash_message'] = 'Customer, tanggal mulai, dan tanggal selesai wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }

    $components = [];
    $costTotal = 0.0;
    $sellTotal = 0.0;

    // Helper function untuk tambah komponen
    $addComponent = function($code, $name, $qty, $unit, $costPrice, $sellPrice) use (&$components, &$costTotal, &$sellTotal) {
        $totalCost = $qty * $costPrice;
        $totalSell = $qty * $sellPrice;
        $costTotal += $totalCost;
        $sellTotal += $totalSell;
        $components[] = [
            'component_code' => $code,
            'component_name' => $name,
            'qty' => $qty,
            'unit' => $unit,
            'price_cost' => $costPrice,
            'price_sell' => $sellPrice,
            'total_cost' => $totalCost,
            'total_sell' => $totalSell,
        ];
    };

    // 1. Tiket
    if (!empty($_POST['ticket_id'])) {
        $ticketId = (int)$_POST['ticket_id'];
        $ticketQty = max(1, (int)($_POST['ticket_qty'] ?? $pax));
        $tkt = $pdo->prepare("SELECT ticket_name, price_cost, price_sell FROM tickets WHERE id=?")->fetch(PDO::FETCH_ASSOC);
        $tkt->execute([$ticketId]);
        if ($tkt) {
            $addComponent('ticket', 'Tiket: ' . $tkt['ticket_name'], $ticketQty, 'pax', (float)$tkt['price_cost'], (float)$tkt['price_sell']);
        }
    }

    // 2. Penginapan
    if (!empty($_POST['room_id'])) {
        $roomId = (int)$_POST['room_id'];
        $nights = max(1, (int)($_POST['stay_nights'] ?? 1));
        $roomQty = max(1, (int)($_POST['stay_room_qty'] ?? 1));
        $rm = $pdo->prepare("SELECT r.room_type, r.price_cost, r.price_sell, p.name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.id=?")->fetch(PDO::FETCH_ASSOC);
        $rm->execute([$roomId]);
        if ($rm) {
            $unitQty = $nights * $roomQty;
            $addComponent('penginapan', 'Penginapan: ' . $rm['name'] . ' - ' . $rm['room_type'], $unitQty, 'room-night', (float)$rm['price_cost'], (float)$rm['price_sell']);
        }
    }

    // 3. Catering
    if (!empty($_POST['catering_id'])) {
        $cateringId = (int)$_POST['catering_id'];
        $cateringQty = max(1, (int)($_POST['catering_qty'] ?? $pax));
        $cat = $pdo->prepare("SELECT menu_name, vendor_name, portion_unit, price_cost, price_sell FROM caterings WHERE id=?")->fetch(PDO::FETCH_ASSOC);
        $cat->execute([$cateringId]);
        if ($cat) {
            $addComponent('catering', 'Catering: ' . $cat['vendor_name'] . ' - ' . $cat['menu_name'], $cateringQty, $cat['portion_unit'], (float)$cat['price_cost'], (float)$cat['price_sell']);
        }
    }

    // 4. Guide Darat
    if (!empty($_POST['guide_darat_id'])) {
        $guideId = (int)$_POST['guide_darat_id'];
        $days = max(1, (int)($_POST['guide_darat_days'] ?? 1));
        $gd = $pdo->prepare("SELECT name, daily_rate_cost, daily_rate_sell FROM guides WHERE id=?")->fetch(PDO::FETCH_ASSOC);
        $gd->execute([$guideId]);
        if ($gd) {
            $addComponent('guide_darat', 'Guide Darat: ' . $gd['name'], $days, 'hari', (float)$gd['daily_rate_cost'], (float)$gd['daily_rate_sell']);
        }
    }

    // 5. Guide Laut
    if (!empty($_POST['guide_laut_id'])) {
        $guideId = (int)$_POST['guide_laut_id'];
        $days = max(1, (int)($_POST['guide_laut_days'] ?? 1));
        $gl = $pdo->prepare("SELECT name, daily_rate_cost, daily_rate_sell FROM guides WHERE id=?")->fetch(PDO::FETCH_ASSOC);
        $gl->execute([$guideId]);
        if ($gl) {
            $addComponent('guide_laut', 'Guide Laut: ' . $gl['name'], $days, 'hari', (float)$gl['daily_rate_cost'], (float)$gl['daily_rate_sell']);
        }
    }

    // 6. Fasilitas
    if (!empty($_POST['facility_ids']) && is_array($_POST['facility_ids'])) {
        $facStmt = $pdo->prepare("SELECT id, name, unit, price_cost, price_sell FROM facilities WHERE id=?");
        foreach ($_POST['facility_ids'] as $fid) {
            $fid = (int)$fid;
            if ($fid <= 0) continue;
            $facStmt->execute([$fid]);
            if ($fac = $facStmt->fetch()) {
                $qty = max(1, (float)($_POST['facility_qty_' . $fid] ?? 1));
                $addComponent('fasilitas', 'Fasilitas: ' . $fac['name'], $qty, $fac['unit'], (float)$fac['price_cost'], (float)$fac['price_sell']);
            }
        }
    }

    if (empty($components)) {
        $_SESSION['flash_message'] = 'Minimal pilih satu komponen (tiket, penginapan, catering, dll).';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }

    $margin = $sellTotal - $costTotal;
    $createdBy = $auth->getCurrentUser()['username'] ?? 'system';
    $bookingNo = sunseaNextNumber($pdo, 'booking');
    $coordId = (int)($_POST['coordinator_id'] ?? 0) ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO booking_orders
            (booking_no, customer_id, booking_mode, start_date, end_date, pax_count,
             coordinator_id, status, cost_total, sell_total, margin_amount, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $bookingNo, $customerId, $bookingMode, $startDate, $endDate, $pax,
                $coordId, 'draft', $costTotal, $sellTotal, $margin,
                trim($_POST['notes'] ?? ''), $createdBy
            ]);
        $bookingId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO booking_order_items
            (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?)");

        foreach ($components as $idx => $c) {
            $ins->execute([
                $bookingId,
                $c['component_code'],
                $c['component_name'],
                $c['qty'],
                $c['unit'],
                $c['price_cost'],
                $c['price_sell'],
                $c['total_cost'],
                $c['total_sell'],
                $idx,
            ]);
        }

        // Generate schedule
        $cur = strtotime($startDate);
        $end = strtotime($endDate);
        $sched = $pdo->prepare("INSERT INTO booking_schedule (booking_id, activity_date, activity_type, title) VALUES (?,?,?,?)");
        while ($cur <= $end) {
            $date = date('Y-m-d', $cur);
            $sched->execute([$bookingId, $date, 'other', 'Operasional ' . $bookingNo]);
            $cur = strtotime('+1 day', $cur);
        }

        $pdo->commit();
        $_SESSION['flash_message'] = 'Pemesanan berhasil disimpan: ' . $bookingNo;
        $_SESSION['flash_type'] = 'success';
        header('Location: bookings.php?view=' . $bookingId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = 'Gagal simpan: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }
}

$pageTitle = 'Input Pemesanan Baru';
$activePage = 'bookings';
include 'layout-header.php';
?>

<div style="max-width:900px;">
    <div style="margin-bottom:16px;"><a class="ss-btn ss-btn-outline ss-btn-sm" href="bookings.php"><i data-feather="arrow-left"></i> Kembali ke Daftar</a></div>

    <form method="POST" id="bookingForm">
        <input type="hidden" name="action" value="save_booking">

        <div class="ss-card" style="margin-bottom:16px;">
            <div class="ss-card-title" style="margin-bottom:12px;">1. Data Dasar Pemesanan</div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group" style="grid-column:1/-1;">
                    <label class="ss-label">Customer *</label>
                    <select name="customer_id" class="ss-select" required>
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ' (' . $c['phone'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Mode Booking *</label>
                    <select name="booking_mode" class="ss-select">
                        <option value="paket">Paket</option>
                        <option value="ecer">Ecer</option>
                    </select>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Jumlah Pax *</label>
                    <input type="number" name="pax_count" class="ss-input" value="1" min="1" required>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Tanggal Mulai *</label>
                    <input type="date" name="start_date" class="ss-input" required>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Tanggal Selesai *</label>
                    <input type="date" name="end_date" class="ss-input" required>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Koordinator</label>
                    <select name="coordinator_id" class="ss-select">
                        <option value="">-- Pilih Koordinator --</option>
                        <?php foreach ($coordinators as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ss-form-group" style="grid-column:1/-1;">
                    <label class="ss-label">Catatan</label>
                    <textarea name="notes" class="ss-textarea" placeholder="Catatan khusus pesanan"></textarea>
                </div>
            </div>
        </div>

        <div class="ss-card" style="margin-bottom:16px;">
            <div class="ss-card-title" style="margin-bottom:12px;">2. Pilih Komponen dari Database</div>

            <!-- Tiket -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Tiket</strong></label>
                <div class="ss-form-grid cols-3">
                    <select name="ticket_id" class="ss-select" onchange="loadPrice('ticket', this.value, 'ticketPrice')">
                        <option value="">-- Tidak pilih --</option>
                        <?php foreach ($tickets as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['ticket_name'] . ' (' . $t['ticket_type'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="ticket_qty" class="ss-input" placeholder="Qty" value="1" min="1" onchange="calculateTotal()">
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <div>
                            <small style="color:var(--ss-muted)">Jual</small><br>
                            <strong id="ticketPrice" style="color:var(--ss-ocean)">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Penginapan -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Penginapan</strong></label>
                <div class="ss-form-grid cols-3">
                    <select name="room_id" class="ss-select" onchange="loadPrice('room', this.value, 'roomPrice')">
                        <option value="">-- Tidak pilih --</option>
                        <?php foreach ($rooms as $r): ?>
                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['partner_name'] . ' - ' . $r['room_type']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="ss-form-grid cols-2" style="gap:6px;">
                        <input type="number" name="stay_room_qty" class="ss-input" placeholder="Jml Kamar" value="1" min="1" onchange="calculateTotal()">
                        <input type="number" name="stay_nights" class="ss-input" placeholder="Malam" value="1" min="1" onchange="calculateTotal()">
                    </div>
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <div>
                            <small style="color:var(--ss-muted)">Jual</small><br>
                            <strong id="roomPrice" style="color:var(--ss-ocean)">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Catering -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Catering</strong></label>
                <div class="ss-form-grid cols-3">
                    <select name="catering_id" class="ss-select" onchange="loadPrice('catering', this.value, 'cateringPrice')">
                        <option value="">-- Tidak pilih --</option>
                        <?php foreach ($caterings as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['vendor_name'] . ' - ' . $c['menu_name'] . ' (' . $c['portion_unit'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="catering_qty" class="ss-input" placeholder="Qty" value="1" min="1" onchange="calculateTotal()">
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <div>
                            <small style="color:var(--ss-muted)">Jual</small><br>
                            <strong id="cateringPrice" style="color:var(--ss-ocean)">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guide Darat -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Guide Darat</strong></label>
                <div class="ss-form-grid cols-3">
                    <select name="guide_darat_id" class="ss-select" onchange="loadPrice('guide', this.value, 'guideDaratPrice')">
                        <option value="">-- Tidak pilih --</option>
                        <?php foreach ($guides as $g): if ($g['guide_type'] === 'darat'): ?>
                        <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                    <input type="number" name="guide_darat_days" class="ss-input" placeholder="Hari" value="1" min="1" onchange="calculateTotal()">
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <div>
                            <small style="color:var(--ss-muted)">Jual</small><br>
                            <strong id="guideDaratPrice" style="color:var(--ss-ocean)">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guide Laut -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Guide Laut</strong></label>
                <div class="ss-form-grid cols-3">
                    <select name="guide_laut_id" class="ss-select" onchange="loadPrice('guide', this.value, 'guideLautPrice')">
                        <option value="">-- Tidak pilih --</option>
                        <?php foreach ($guides as $g): if ($g['guide_type'] === 'laut'): ?>
                        <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                    <input type="number" name="guide_laut_days" class="ss-input" placeholder="Hari" value="1" min="1" onchange="calculateTotal()">
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <div>
                            <small style="color:var(--ss-muted)">Jual</small><br>
                            <strong id="guideLautPrice" style="color:var(--ss-ocean)">-</strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fasilitas -->
            <div style="margin-bottom:12px;padding:12px;background:var(--ss-gray-1);border-radius:8px;">
                <label class="ss-label" style="margin-bottom:6px;"><strong>Fasilitas Tambahan</strong></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px;">
                    <?php foreach ($facilities as $f): ?>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="facility_ids[]" value="<?php echo $f['id']; ?>" onchange="calculateTotal()">
                        <span><?php echo htmlspecialchars($f['name'] . ' (' . $f['unit'] . ')'); ?></span>
                        <small style="color:var(--ss-ocean)"><?php echo sunseaRupiah((float)$f['price_sell']); ?></small>
                        <input type="number" name="facility_qty_<?php echo $f['id']; ?>" class="ss-input" placeholder="Qty" value="1" min="0" step="0.01" style="width:70px;" onchange="calculateTotal()">
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:12px;">3. Estimasi Harga</div>
            <div style="display:grid;grid-template-columns:1fr 280px;gap:18px;">
                <div></div>
                <div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;"><span style="color:var(--ss-muted)">Total Modal</span><strong id="totalCost">Rp 0</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;"><span style="color:var(--ss-muted)">Total Jual</span><strong id="totalSell">Rp 0</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:2px solid var(--ss-ocean);font-size:16px;">
                        <span>Margin</span>
                        <strong style="color:var(--ss-success)" id="totalMargin">Rp 0</strong>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                <a href="bookings.php" class="ss-btn ss-btn-outline">Batal</a>
                <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="save"></i> Simpan Pemesanan</button>
            </div>
        </div>
    </form>
</div>

<script>
function rupiah(n) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(n));
}

function loadPrice(type, id, displayId) {
    if (!id) {
        document.getElementById(displayId).textContent = '-';
        calculateTotal();
        return;
    }
    fetch('bookings-new.php?action=get_price&type=' + type + '&id=' + id)
        .then(r => r.json())
        .then(data => {
            document.getElementById(displayId).textContent = rupiah(data.sell);
            calculateTotal();
        })
        .catch(e => console.error(e));
}

function calculateTotal() {
    let costTotal = 0, sellTotal = 0;

    // Tiket
    if (document.querySelector('select[name="ticket_id"]').value) {
        const priceText = document.getElementById('ticketPrice').textContent;
        const price = parseFloat(priceText.replace(/[^0-9.-]/g, '')) || 0;
        const qty = parseFloat(document.querySelector('input[name="ticket_qty"]').value) || 0;
        sellTotal += price * qty;
    }

    // Penginapan
    if (document.querySelector('select[name="room_id"]').value) {
        const priceText = document.getElementById('roomPrice').textContent;
        const price = parseFloat(priceText.replace(/[^0-9.-]/g, '')) || 0;
        const nights = parseFloat(document.querySelector('input[name="stay_nights"]').value) || 1;
        const qty = parseFloat(document.querySelector('input[name="stay_room_qty"]').value) || 1;
        sellTotal += price * nights * qty;
    }

    // Catering
    if (document.querySelector('select[name="catering_id"]').value) {
        const priceText = document.getElementById('cateringPrice').textContent;
        const price = parseFloat(priceText.replace(/[^0-9.-]/g, '')) || 0;
        const qty = parseFloat(document.querySelector('input[name="catering_qty"]').value) || 0;
        sellTotal += price * qty;
    }

    // Guide Darat
    if (document.querySelector('select[name="guide_darat_id"]').value) {
        const priceText = document.getElementById('guideDaratPrice').textContent;
        const price = parseFloat(priceText.replace(/[^0-9.-]/g, '')) || 0;
        const days = parseFloat(document.querySelector('input[name="guide_darat_days"]').value) || 1;
        sellTotal += price * days;
    }

    // Guide Laut
    if (document.querySelector('select[name="guide_laut_id"]').value) {
        const priceText = document.getElementById('guideLautPrice').textContent;
        const price = parseFloat(priceText.replace(/[^0-9.-]/g, '')) || 0;
        const days = parseFloat(document.querySelector('input[name="guide_laut_days"]').value) || 1;
        sellTotal += price * days;
    }

    // Fasilitas
    document.querySelectorAll('input[name="facility_ids[]"]:checked').forEach(checkbox => {
        const facId = checkbox.value;
        const facPriceText = checkbox.parentElement.querySelector('small').textContent;
        const facPrice = parseFloat(facPriceText.replace(/[^0-9.-]/g, '')) || 0;
        const facQty = parseFloat(document.querySelector('input[name="facility_qty_' + facId + '"]').value) || 1;
        sellTotal += facPrice * facQty;
    });

    const margin = sellTotal - costTotal;
    document.getElementById('totalCost').textContent = rupiah(costTotal);
    document.getElementById('totalSell').textContent = rupiah(sellTotal);
    document.getElementById('totalMargin').textContent = rupiah(margin);
}
</script>

<?php include 'layout-footer.php';
