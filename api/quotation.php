<?php

/**
 * API Penawaran kamar (tidak memblok kamar).
 * POST action:
 *   create        check_in, check_out, guest_name, guest_phone, guest_email, notes, valid_until, lang, items(JSON: [{room_id,disc_type,disc_value}])
 *   get           id            → penawaran + item + kamar yang sudah bentrok (untuk tombol Konfirmasi)
 *   list          status=open|confirmed|cancelled|all
 *   cancel        id
 *   confirm_mark  id, ref       → tandai terkonfirmasi setelah booking dibuat dari penawaran
 */
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
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
    if ($act === 'create') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $res = Quotations::create($db, [
            'check_in' => $_POST['check_in'] ?? '', 'check_out' => $_POST['check_out'] ?? '',
            'guest_name' => $_POST['guest_name'] ?? '', 'guest_phone' => $_POST['guest_phone'] ?? '', 'guest_email' => $_POST['guest_email'] ?? '',
            'notes' => $_POST['notes'] ?? '', 'valid_until' => $_POST['valid_until'] ?? '', 'lang' => $_POST['lang'] ?? 'id',
            'items' => is_array($items) ? $items : [],
        ], $uid);
        echo json_encode($res);
    } elseif ($act === 'get') {
        $q = Quotations::get($db, (int)($_POST['id'] ?? 0));
        if (!$q) {
            echo json_encode(['ok' => false, 'msg' => 'Penawaran tidak ditemukan']);
        } else {
            echo json_encode(['ok' => true, 'quote' => $q, 'conflicts' => $q['status'] === 'open' ? Quotations::conflicts($db, $q) : []]);
        }
    } elseif ($act === 'list') {
        $st = in_array($_POST['status'] ?? 'open', ['open', 'confirmed', 'cancelled', 'all'], true) ? $_POST['status'] : 'open';
        echo json_encode(['ok' => true, 'rows' => Quotations::listAll($db, $st)]);
    } elseif ($act === 'cancel') {
        echo json_encode(['ok' => Quotations::cancel($db, (int)($_POST['id'] ?? 0)), 'msg' => 'Penawaran dibatalkan']);
    } elseif ($act === 'confirm_mark') {
        echo json_encode(['ok' => Quotations::markConfirmed($db, (int)($_POST['id'] ?? 0), (string)($_POST['ref'] ?? ''))]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal']);
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Gagal: ' . $e->getMessage()]);
}
