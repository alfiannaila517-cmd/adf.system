<?php

/**
 * API Penawaran kamar (tidak memblok kamar).
 * POST action:
 *   create        check_in, check_out, guest_name, guest_phone, guest_email, notes, valid_until, lang, items(JSON: [{room_id,disc_type,disc_value}]), extras(JSON: [{catalog_id|name,price,qty,unit,type}])
 *   update        id + field yang sama dengan create (kecuali lang) → ubah penawaran terbuka
 *   rooms         → daftar kamar + harga dasar
 *   catalog       → katalog Hotel Service aktif (untuk fasilitas tambahan)
 *   get           id            → penawaran + item + kamar yang sudah bentrok (untuk tombol Konfirmasi)
 *   list          status=open|confirmed|cancelled|all
 *   cancel        id
 *   confirm_mark  id, ref       → tandai terkonfirmasi setelah booking dibuat dari penawaran (+ email ke tamu)
 *   web_pending / web_seen ids / web_followup id / record_dp id, amount, method → reservasi dari website
 *   extend_hold   id            → perpanjang hold (blok kamar) 24 jam dari sekarang
 *   hold_dismiss  id            → peringatan hold hampir habis dijawab "Biarkan" (lepas otomatis)
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/business_helper.php';
require_once '../includes/Quotations.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Invalid method']);
    exit;
}

try {
    $db = Database::getInstance();
    $uid = (int)($auth->getCurrentUser()['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    Quotations::releaseExpired($db); // hold > 24 jam tanpa konfirmasi → kamar dilepas
    if ($act === 'create') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $extras = json_decode((string)($_POST['extras'] ?? '[]'), true);
        $res = Quotations::create($db, [
            'check_in' => $_POST['check_in'] ?? '', 'check_out' => $_POST['check_out'] ?? '',
            'guest_name' => $_POST['guest_name'] ?? '', 'guest_phone' => $_POST['guest_phone'] ?? '', 'guest_email' => $_POST['guest_email'] ?? '',
            'notes' => $_POST['notes'] ?? '', 'valid_until' => $_POST['valid_until'] ?? '', 'lang' => $_POST['lang'] ?? 'id',
            'items' => is_array($items) ? $items : [],
            'extras' => is_array($extras) ? $extras : [],
        ], $uid);
        echo json_encode($res);
    } elseif ($act === 'update') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $extras = json_decode((string)($_POST['extras'] ?? '[]'), true);
        echo json_encode(Quotations::update($db, (int)($_POST['id'] ?? 0), [
            'check_in' => $_POST['check_in'] ?? '', 'check_out' => $_POST['check_out'] ?? '',
            'guest_name' => $_POST['guest_name'] ?? '', 'guest_phone' => $_POST['guest_phone'] ?? '', 'guest_email' => $_POST['guest_email'] ?? '',
            'notes' => $_POST['notes'] ?? '', 'valid_until' => $_POST['valid_until'] ?? '',
            'items' => is_array($items) ? $items : [],
            'extras' => is_array($extras) ? $extras : [],
        ]));
    } elseif ($act === 'rooms') {
        echo json_encode(['ok' => true, 'rows' => Quotations::roomList($db)]);
    } elseif ($act === 'catalog') {
        $bizId = (int)($_SESSION['business_id'] ?? 0) ?: ((function_exists('getNumericBusinessId') ? (int)getNumericBusinessId(ACTIVE_BUSINESS_ID) : 0) ?: 1);
        echo json_encode(['ok' => true, 'rows' => Quotations::catalog($db, $bizId)]);
    } elseif ($act === 'get') {
        $q = Quotations::get($db, (int)($_POST['id'] ?? 0));
        if (!$q) {
            echo json_encode(['ok' => false, 'msg' => 'Penawaran tidak ditemukan']);
        } else {
            $extra = [];
            if (($q['source'] ?? '') === 'website') {
                // Data untuk pesan follow-up WhatsApp: DP 50% + rekening transfer
                require_once '../includes/WebReservations.php';
                $set = static function (string $k, string $def) use ($db): string {
                    $r = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", [$k]);
                    return trim((string)($r['setting_value'] ?? '')) !== '' ? (string)$r['setting_value'] : $def;
                };
                $biz = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '';
                $extra = ['dp_suggest' => WebReservations::dpAmount((float)$q['total']), 'bank' => [
                    'no' => $set('invoice_bank_account_number_' . $biz, '1926663992'),
                    'name' => $set('invoice_bank_account_name_' . $biz, 'BNI Jepara'),
                ]];
            }
            echo json_encode(['ok' => true, 'quote' => $q, 'conflicts' => $q['status'] === 'open' ? Quotations::conflicts($db, $q) : []] + $extra);
        }
    } elseif ($act === 'list') {
        $st = in_array($_POST['status'] ?? 'open', ['open', 'confirmed', 'cancelled', 'expired', 'all'], true) ? $_POST['status'] : 'open';
        echo json_encode(['ok' => true, 'rows' => Quotations::listAll($db, $st)]);
    } elseif ($act === 'cancel') {
        echo json_encode(['ok' => Quotations::cancel($db, (int)($_POST['id'] ?? 0)), 'msg' => 'Penawaran dibatalkan']);
    } elseif ($act === 'extend_hold') {
        echo json_encode(Quotations::extendHold($db, (int)($_POST['id'] ?? 0), $uid));
    } elseif ($act === 'confirm_mark') {
        $qid = (int)($_POST['id'] ?? 0);
        $ref = (string)($_POST['ref'] ?? '');
        $ok = Quotations::markConfirmed($db, $qid, $ref);
        $mail = null;
        if ($ok) {
            // Tamu otomatis menerima email "reservasi berhasil" + rincian pembayaran (bila ada email)
            require_once '../includes/WebReservations.php';
            $mail = WebReservations::sendConfirmed($db, $qid, $ref);
        }
        echo json_encode(['ok' => $ok, 'email' => $mail === null ? 'sent' : $mail]);
    } elseif ($act === 'web_pending') {
        // Reservasi website yang belum dilihat admin → popup di Front Desk
        $rows = $db->fetchAll("SELECT id, quote_code, guest_name, guest_phone, check_in, check_out, nights, total, adults, children, created_at
            FROM quotations WHERE source = 'website' AND status = 'open' AND seen_at IS NULL ORDER BY id DESC LIMIT 10") ?: [];
        // + hold yang segera habis (≤ 60 menit) → popup "perpanjang atau biarkan"
        echo json_encode(['ok' => true, 'rows' => $rows, 'expiring' => Quotations::expiringSoon($db)]);
    } elseif ($act === 'hold_dismiss') {
        echo json_encode(['ok' => Quotations::dismissHoldWarning($db, (int)($_POST['id'] ?? 0))]);
    } elseif ($act === 'web_seen') {
        $ids = array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
        if ($ids) {
            $db->query('UPDATE quotations SET seen_at = NOW() WHERE seen_at IS NULL AND id IN (' . implode(',', $ids) . ')');
        }
        echo json_encode(['ok' => true]);
    } elseif ($act === 'web_followup') {
        $db->query("UPDATE quotations SET followup_at = NOW(), seen_at = COALESCE(seen_at, NOW()) WHERE id = ?", [(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok' => true]);
    } elseif ($act === 'record_dp') {
        // Catat DP yang sudah diterima (transfer / tunai). 0 = hapus catatan DP.
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        $method = in_array($_POST['method'] ?? '', ['transfer', 'cash', 'qris', 'card'], true) ? $_POST['method'] : 'transfer';
        $r = $db->query("UPDATE quotations SET dp_amount = ?, dp_method = ?, dp_paid_at = " . ($amount > 0 ? 'NOW()' : 'NULL') . ", seen_at = COALESCE(seen_at, NOW()) WHERE id = ? AND status = 'open'", [$amount, $amount > 0 ? $method : null, (int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok' => $r !== false && $r->rowCount() === 1]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal']);
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Gagal: ' . $e->getMessage()]);
}

// ═══ CLOUDBEDS: blok hold yang dibuat / dilepas dikirim segera (setelah respons) agar OTA ikut tertutup / terbuka ═══
if (!empty(Quotations::$pushBlocks) && isset($db)) {
    require_once dirname(__DIR__) . '/includes/cloudbeds_push_hook.php';
    cloudbedsPushAfterResponse($db, [], array_unique(Quotations::$pushBlocks));
}
