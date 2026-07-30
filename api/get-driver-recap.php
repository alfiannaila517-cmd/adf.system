<?php

/**
 * API: GET DRIVER / PARTNER RECAP
 * GET /api/get-driver-recap.php?month=YYYY-MM
 *
 * Monthly recap per driver/partner (car rental + airport/harbor drop trips),
 * used by the "Tagihan Driver" tab in modules/bills/index.php.
 * Mirrors the owner-recap logic from modules/frontdesk/rental-mobil-dashboard.php.
 */

if (ob_get_level()) ob_end_clean();

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';
    require_once '../includes/DriverPaymentHelper.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $businessId = $_SESSION['business_id'] ?? 1;

    ensureDriverTripPaymentColumns($pdo);

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    try {
        $pdo->query("SELECT 1 FROM rental_car_bookings LIMIT 1")->fetch();
    } catch (Exception $tableError) {
        throw new Exception('rental_car_bookings table does not exist yet.');
    }

    // ── Owner recap (car rental + airport/harbor drop trips with a linked driver car) ──
    $ownerStmt = $pdo->prepare("SELECT
        rc.partner_owner, rc.owner_phone,
        COUNT(*) as total_trips,
        COALESCE(SUM(cb.total_price),0) as total_revenue,
        COALESCE(SUM(cb.owner_amount),0) as owner_total,
        COALESCE(SUM(cb.hotel_commission),0) as hotel_total,
        AVG(rc.owner_commission_pct) as avg_comm_pct,
        GROUP_CONCAT(DISTINCT rc.car_name ORDER BY rc.car_name SEPARATOR ', ') as cars,
        SUM(cb.service_type = 'car_rental') as rental_trips,
        SUM(cb.service_type = 'airport_drop') as airport_trips,
        SUM(cb.service_type = 'harbor_drop') as harbor_trips
        FROM rental_car_bookings cb
        JOIN rental_cars rc ON cb.car_id = rc.id
        WHERE cb.business_id=? AND cb.status='returned'
          AND DATE(COALESCE(cb.actual_return, cb.end_datetime, cb.created_at)) BETWEEN ? AND ?
          AND rc.partner_owner IS NOT NULL AND rc.partner_owner != ''
        GROUP BY rc.partner_owner, rc.owner_phone
        ORDER BY total_revenue DESC");
    $ownerStmt->execute([$businessId, $monthStart, $monthEnd]);
    $recap = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);

    $ownerKey = static function (?string $name): string {
        $name = trim((string)$name);
        return $name !== '' ? strtolower($name) : '__tanpa_pemilik__';
    };

    foreach ($recap as &$or) {
        $or['total_trips'] = (int)$or['total_trips'];
        $or['total_revenue'] = (float)$or['total_revenue'];
        $or['owner_total'] = (float)$or['owner_total'];
        $or['hotel_total'] = (float)$or['hotel_total'];
        $or['avg_comm_pct'] = (float)$or['avg_comm_pct'];
        $or['rental_trips'] = (int)$or['rental_trips'];
        $or['airport_trips'] = (int)$or['airport_trips'];
        $or['harbor_trips'] = (int)$or['harbor_trips'];
        $or['airport_total'] = 0.0;
        $or['harbor_total'] = 0.0;
        $or['paid_total'] = 0.0;
        $or['unpaid_total'] = 0.0;
        $or['paid_trips'] = 0;
        $or['unpaid_trips'] = 0;
        $or['detail_rows'] = [];
    }
    unset($or);

    $indexMap = [];
    foreach ($recap as $idx => $or) {
        $indexMap[$ownerKey($or['partner_owner'] ?? '')] = $idx;
    }

    // ── Detail rows: car rental + airport/harbor drop trips (linked driver car) ──
    $detailMap = [];
    $detailStmt = $pdo->prepare("SELECT
        rc.partner_owner, cb.id as trip_id, cb.service_type,
        COALESCE(cb.actual_return, cb.end_datetime, cb.created_at) as trx_date,
        cb.guest_name, cb.room_number, cb.trip_destination,
        cb.total_price, cb.owner_amount, cb.driver_paid, rc.car_name, rc.plate_number
        FROM rental_car_bookings cb
        JOIN rental_cars rc ON cb.car_id = rc.id
        WHERE cb.business_id=? AND cb.status='returned'
          AND DATE(COALESCE(cb.actual_return, cb.end_datetime, cb.created_at)) BETWEEN ? AND ?
          AND rc.partner_owner IS NOT NULL AND rc.partner_owner != ''
        ORDER BY trx_date DESC, cb.id DESC");
    $detailStmt->execute([$businessId, $monthStart, $monthEnd]);
    foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
        $key = $ownerKey($detail['partner_owner'] ?? '');
        $carLabel = trim(($detail['car_name'] ?? '') . ' (' . ($detail['plate_number'] ?? '') . ')');
        $label = $detail['service_type'] === 'car_rental' ? $carLabel : ($detail['trip_destination'] ?: $carLabel);
        $detailMap[$key][] = [
            'trip_id' => (int)$detail['trip_id'],
            'trx_date' => $detail['trx_date'],
            'guest_name' => $detail['guest_name'],
            'room_number' => $detail['room_number'],
            'label' => $label,
            'service_type' => $detail['service_type'],
            'source' => 'trip',
            'total_price' => (float)$detail['total_price'],
            'owner_amount' => (float)$detail['owner_amount'],
            'paid' => (bool)$detail['driver_paid'],
        ];
    }

    // ── Airport/Harbor Drop trips (driver: Moyong) ──────────────────────
    $dropOwnerName = 'Moyong';
    $dropKey = $ownerKey($dropOwnerName);

    try {
        $pdo->query("SELECT 1 FROM hotel_invoice_items LIMIT 1")->fetch();

        $dropStmt = $pdo->prepare("SELECT
            hi.guest_name, hi.room_number, hi.created_at as trx_date,
            hii.id as trip_id, hii.service_type, hii.description, hii.total_price, hii.driver_paid
            FROM hotel_invoice_items hii
            JOIN hotel_invoices hi ON hii.invoice_id = hi.id
            WHERE hi.business_id=? AND hii.service_type IN ('airport_drop','harbor_drop')
              AND hi.status NOT IN ('cancelled')
              AND DATE(hi.created_at) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM rental_car_bookings cb2
                  WHERE cb2.invoice_id = hii.invoice_id AND cb2.service_type = hii.service_type
              )
            ORDER BY hi.created_at DESC, hii.id DESC");
        $dropStmt->execute([$businessId, $monthStart, $monthEnd]);
        $dropDetails = $dropStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!isset($indexMap[$dropKey]) && !empty($dropDetails)) {
            $recap[] = [
                'partner_owner' => $dropOwnerName,
                'owner_phone' => null,
                'total_trips' => 0,
                'total_revenue' => 0.0,
                'owner_total' => 0.0,
                'hotel_total' => 0.0,
                'avg_comm_pct' => 100,
                'cars' => 'Airport Drop, Harbor Drop',
                'rental_trips' => 0,
                'airport_trips' => 0,
                'harbor_trips' => 0,
                'airport_total' => 0.0,
                'harbor_total' => 0.0,
                'detail_rows' => [],
            ];
            $indexMap[$dropKey] = count($recap) - 1;
        }

        foreach ($dropDetails as $detail) {
            $idx = $indexMap[$dropKey] ?? null;
            if ($idx === null) continue;
            $amount = (float)$detail['total_price'];
            $recap[$idx]['total_trips'] += 1;
            $recap[$idx]['total_revenue'] += $amount;
            $recap[$idx]['owner_total'] += $amount;
            $recap[$idx]['avg_comm_pct'] = 100;
            if ($detail['service_type'] === 'airport_drop') {
                $recap[$idx]['airport_trips'] += 1;
                $recap[$idx]['airport_total'] += $amount;
            }
            if ($detail['service_type'] === 'harbor_drop') {
                $recap[$idx]['harbor_trips'] += 1;
                $recap[$idx]['harbor_total'] += $amount;
            }
            $detailMap[$dropKey][] = [
                'trip_id' => (int)$detail['trip_id'],
                'trx_date' => $detail['trx_date'],
                'guest_name' => $detail['guest_name'],
                'room_number' => $detail['room_number'],
                'label' => $detail['description'] ?: $detail['service_type'],
                'service_type' => $detail['service_type'],
                'source' => 'legacy',
                'total_price' => $amount,
                'owner_amount' => $amount,
                'paid' => (bool)$detail['driver_paid'],
            ];
        }
    } catch (Exception $dropError) {
        // hotel_invoice_items table not available - skip drop trips silently
    }

    $totals = ['trips' => 0, 'revenue' => 0.0, 'owner_total' => 0.0, 'hotel_total' => 0.0, 'paid_total' => 0.0, 'unpaid_total' => 0.0];
    foreach ($recap as &$or) {
        $key = $ownerKey($or['partner_owner'] ?? '');
        $rows = $detailMap[$key] ?? [];
        $or['detail_rows'] = $rows;
        foreach ($rows as $row) {
            if ($row['paid']) {
                $or['paid_total'] += $row['owner_amount'];
                $or['paid_trips']++;
            } else {
                $or['unpaid_total'] += $row['owner_amount'];
                $or['unpaid_trips']++;
            }
        }
        $totals['trips'] += $or['total_trips'];
        $totals['revenue'] += $or['total_revenue'];
        $totals['owner_total'] += $or['owner_total'];
        $totals['hotel_total'] += $or['hotel_total'];
        $totals['paid_total'] += $or['paid_total'];
        $totals['unpaid_total'] += $or['unpaid_total'];
    }
    unset($or);

    usort($recap, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

    echo json_encode([
        'success' => true,
        'month' => $month,
        'recap' => $recap,
        'totals' => $totals,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => get_class($e),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit;
}
