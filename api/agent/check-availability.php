<?php
/**
 * Agent Tool: check_availability
 * Cek kamar tersedia untuk tanggal tertentu. Bisa filter berdasarkan tipe kamar.
 * Method: GET
 * Params: check_in (YYYY-MM-DD), check_out (YYYY-MM-DD), room_type? (opsional, misal: King, Twin, Standard)
 * Header: X-Agent-Key: <key>
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

/**
 * Parsing tanggal yang fleksibel — terima berbagai format dari AI.
 * Support: YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY, MM/DD/YYYY, natural language, dll.
 */
function agent_parse_date($input) {
    $input = trim($input);
    if (!$input) return null;

    // Bulan Indonesia → angka
    $bulanMap = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'ags' => 8,
        'sep' => 9, 'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'des' => 12,
    ];

    // 1. Standard: YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $input, $m)) {
        return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]));
    }

    // 2. DD-MM-YYYY atau DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $input, $m)) {
        return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
    }

    // 3. "23 Maret 2026" atau "23 March 2026"
    if (preg_match('/^(\d{1,2})\s+(\w+)\s+(\d{4})$/i', $input, $m)) {
        $day = (int)$m[1];
        $monthStr = strtolower($m[2]);
        $year = (int)$m[3];
        $month = $bulanMap[$monthStr] ?? null;
        if (!$month) {
            // coba PHP strtotime untuk bulan Inggris
            $ts = strtotime($input);
            return $ts ? (new DateTime())->setTimestamp($ts) : null;
        }
        return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    // 4. "March 23, 2026"
    if (preg_match('/^(\w+)\s+(\d{1,2}),?\s+(\d{4})$/i', $input, $m)) {
        $ts = strtotime($input);
        return $ts ? (new DateTime())->setTimestamp($ts) : null;
    }

    // 5. YYYY/MM/DD
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $input, $m)) {
        return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]));
    }

    // 6. Fallback: coba strtotime PHP
    $ts = strtotime($input);
    if ($ts && $ts > 0) {
        return (new DateTime())->setTimestamp($ts);
    }

    return null;
}

try {
    $checkIn   = trim($_GET['check_in']  ?? '');
    $checkOut  = trim($_GET['check_out'] ?? '');
    $roomType  = trim($_GET['room_type'] ?? '');

    if (!$checkIn || !$checkOut) {
        echo json_encode(['success' => false, 'error' => 'Parameter check_in dan check_out wajib diisi (format YYYY-MM-DD)']);
        exit;
    }

    // Smart date parsing — terima berbagai format
    $ciDate = agent_parse_date($checkIn);
    $coDate = agent_parse_date($checkOut);

    if (!$ciDate || !$coDate) {
        echo json_encode(['success' => false, 'error' => "Format tanggal tidak dikenali. check_in='$checkIn', check_out='$checkOut'. Gunakan format YYYY-MM-DD."]);
        exit;
    }
    if ($coDate <= $ciDate) {
        echo json_encode(['success' => false, 'error' => "check_out ({$coDate->format('Y-m-d')}) harus setelah check_in ({$ciDate->format('Y-m-d')})"]);
        exit;
    }

    // Normalize ke YYYY-MM-DD
    $checkIn  = $ciDate->format('Y-m-d');
    $checkOut = $coDate->format('Y-m-d');
    $totalNights = $ciDate->diff($coDate)->days;

    // Kamar yang sudah dipesan di rentang ini
    $stmt = $pdo->prepare("SELECT DISTINCT room_id FROM bookings
         WHERE check_in_date < ? AND check_out_date > ?
         AND status IN ('pending','confirmed','checked_in')");
    $stmt->execute([$checkOut, $checkIn]);
    $bookedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'room_id');

    // Semua kamar aktif beserta tipe — hanya gunakan kolom yang pasti ada
    $sql = "SELECT r.id, r.room_number,
                rt.type_name, rt.base_price,
                COALESCE(rt.max_occupancy, 2) as max_occupancy,
                COALESCE(rt.description, '') as description
         FROM rooms r
         LEFT JOIN room_types rt ON r.room_type_id = rt.id
         WHERE r.status != 'maintenance'
         ORDER BY rt.base_price ASC, r.room_number ASC";
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute();
    $allRooms = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $available = [];
    foreach ($allRooms as $room) {
        if (in_array($room['id'], $bookedIds)) continue;

        // Filter berdasarkan tipe kamar jika diminta
        if ($roomType && stripos($room['type_name'], $roomType) === false) continue;

        $available[] = [
            'room_id'         => (int)$room['id'],
            'room_number'     => $room['room_number'],
            'type'            => $room['type_name'],
            'max_occupancy'   => (int)$room['max_occupancy'],
            'price_per_night' => (int)$room['base_price'],
            'total_price'     => (int)$room['base_price'] * $totalNights,
        ];
    }

    // Ringkasan per tipe kamar
    $summary = [];
    foreach ($available as $r) {
        $type = $r['type'];
        if (!isset($summary[$type])) {
            $summary[$type] = [
                'type'            => $type,
                'available_count' => 0,
                'price_per_night' => $r['price_per_night'],
                'total_price'     => $r['total_price'],
                'max_occupancy'   => $r['max_occupancy'],
            ];
        }
        $summary[$type]['available_count']++;
    }

    // Buat ringkasan teks untuk AI
    $textParts = [];
    $textParts[] = "Ketersediaan kamar tanggal {$checkIn} s/d {$checkOut} ({$totalNights} malam):";
    if (count($available) === 0) {
        $filterNote = $roomType ? " tipe \"{$roomType}\"" : "";
        $textParts[] = "Tidak ada kamar{$filterNote} yang tersedia untuk tanggal tersebut.";
    } else {
        foreach (array_values($summary) as $s) {
            $textParts[] = "- {$s['type']}: {$s['available_count']} kamar tersedia, Rp " . number_format($s['price_per_night'], 0, ',', '.') . "/malam (total {$totalNights} malam = Rp " . number_format($s['total_price'], 0, ',', '.') . ")";
        }
    }

    echo json_encode([
        'success'          => true,
        'text_summary'     => implode("\n", $textParts),
        'check_in'         => $checkIn,
        'check_out'        => $checkOut,
        'total_nights'     => $totalNights,
        'room_type_filter' => $roomType ?: null,
        'available_count'  => count($available),
        'summary_by_type'  => array_values($summary),
        'rooms'            => $available,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
