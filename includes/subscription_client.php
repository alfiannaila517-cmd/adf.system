<?php

/**
 * Tagihan langganan bulanan ADF System untuk bisnis di sistem ini (Narayana Hotel, Eat Meet, Bens Cafe, ...).
 * Pola sama dengan Karimunjawa Explore: tarif, jatuh tempo, akun Pakasir, dan status KUNCI semuanya dikontrol
 * dari adfsystem.store (menu Klien Langganan). Bisnis ini hanya membaca (sinkron tiap 5 menit) lalu membuat
 * tagihan bulanan flat (biaya dasar), membayar via Pakasir, dan menampilkan banner / kunci layar.
 *
 * Semua data disimpan di database bisnis yang sedang aktif:
 * - tabel adf_subscription_invoices (satu baris per periode YYYY-MM, atau MANUAL-<id> untuk tagihan manual)
 * - tabel settings, key berawalan subscription_*
 */

if (!defined('ADFSUB_DEFAULT_SYNC_URL')) {
    define('ADFSUB_DEFAULT_SYNC_URL', 'https://adfsystem.store/api/subscription-config.php');
}

function adfsub_pdo(): PDO
{
    return Database::getInstance()->getConnection();
}

function adfsub_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? $default : (string) $val;
    } catch (Exception $e) {
        return $default;
    }
}

function adfsub_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

function adfsub_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `adf_subscription_invoices` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `period`              VARCHAR(40) NOT NULL COMMENT 'YYYY-MM untuk bulanan, MANUAL-<id> untuk tagihan manual',
        `type`                ENUM('recurring','manual') DEFAULT 'recurring',
        `description`         VARCHAR(255) NULL,
        `total_amount`        DECIMAL(15,2) DEFAULT 0.00,
        `status`              ENUM('unpaid','paid','cancelled') DEFAULT 'unpaid',
        `due_date`            DATE NULL,
        `order_id`            VARCHAR(60) NULL,
        `txn_id`              VARCHAR(100) NULL,
        `payment_link`        VARCHAR(255) NULL,
        `paid_at`             DATETIME NULL,
        `overdue_notified_at` DATE NULL,
        `invoice_emailed_at`  DATETIME NULL COMMENT 'Email tagihan berhasil dikirim ADF',
        `paid_emailed_at`     DATETIME NULL COMMENT 'Email invoice lunas berhasil dikirim ADF',
        `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_period` (`period`),
        INDEX `idx_adfsub_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['invoice_emailed_at', 'paid_emailed_at'] as $col) {
        $has = $pdo->query("SHOW COLUMNS FROM adf_subscription_invoices LIKE '{$col}'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE adf_subscription_invoices ADD COLUMN `{$col}` DATETIME NULL");
        }
    }
    $done = true;
}

/** Minta ADF kirim email "Tagihan baru"; tandai terkirim hanya kalau ADF benar-benar mengirim email. */
function adfsub_email_invoice(PDO $pdo, array $inv): bool
{
    $resp = adfsub_post_adf(adfsub_config($pdo), 'subscription-invoice-notify.php', [
        'period' => $inv['period'],
        'total_amount' => (float) $inv['total_amount'],
        'due_date' => (string) ($inv['due_date'] ?? ''),
    ], 6);
    if (!empty($resp['emailed'])) {
        $pdo->prepare("UPDATE adf_subscription_invoices SET invoice_emailed_at = NOW() WHERE id = ?")->execute([$inv['id']]);
        return true;
    }
    return false;
}

/** Minta ADF catat transaksi + kirim email "Pembayaran diterima" (PDF invoice); tandai terkirim kalau berhasil. */
function adfsub_email_paid(PDO $pdo, array $inv): bool
{
    $resp = adfsub_post_adf(adfsub_config($pdo), 'subscription-payment-notify.php', [
        'period' => $inv['period'],
        'total_amount' => (float) $inv['total_amount'],
        'paid_at' => !empty($inv['paid_at']) ? date('c', strtotime($inv['paid_at'])) : date('c'),
        'order_id' => (string) ($inv['order_id'] ?? ''),
        'description' => (string) ($inv['description'] ?: ('Langganan ' . $inv['period'])),
    ], 15);
    if (!empty($resp['emailed'])) {
        $pdo->prepare("UPDATE adf_subscription_invoices SET paid_emailed_at = NOW() WHERE id = ?")->execute([$inv['id']]);
        return true;
    }
    return false;
}

/**
 * Kirim ulang email yang belum terkirim (mis. email notifikasi baru diisi di ADF setelah tagihan dibuat,
 * atau SMTP sempat gagal): email tagihan untuk tagihan bulanan yang belum dibayar, dan email lunas untuk
 * pembayaran 14 hari terakhir. Dipanggil bersama sinkron (maks. tiap 5 menit).
 */
function adfsub_send_pending_emails(PDO $pdo): void
{
    adfsub_ensure_schema($pdo);
    $pending = $pdo->query("SELECT * FROM adf_subscription_invoices WHERE status = 'unpaid' AND type = 'recurring' AND invoice_emailed_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pending as $inv) {
        adfsub_email_invoice($pdo, $inv);
    }
    $paid = $pdo->query("SELECT * FROM adf_subscription_invoices WHERE status = 'paid' AND paid_emailed_at IS NULL AND paid_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($paid as $inv) {
        adfsub_email_paid($pdo, $inv);
    }
}

function adfsub_config(PDO $pdo): array
{
    return [
        'client_key' => adfsub_setting($pdo, 'subscription_client_key'),
        'client_token' => adfsub_setting($pdo, 'subscription_client_token'),
        'sync_url' => adfsub_setting($pdo, 'subscription_sync_url', ADFSUB_DEFAULT_SYNC_URL) ?: ADFSUB_DEFAULT_SYNC_URL,
        'client_name' => adfsub_setting($pdo, 'subscription_client_name'),
        'base_fee' => (float) adfsub_setting($pdo, 'subscription_base_fee', '0'),
        'start_date' => adfsub_setting($pdo, 'subscription_start_date'),
        'due_date_override' => adfsub_setting($pdo, 'subscription_due_date_override'),
        'locked' => adfsub_setting($pdo, 'subscription_locked', '0') === '1',
        'pakasir_slug' => adfsub_setting($pdo, 'subscription_pakasir_slug'),
        'pakasir_api_key' => adfsub_setting($pdo, 'subscription_pakasir_api_key'),
        'provider_name' => adfsub_setting($pdo, 'subscription_provider_name', 'ADF System'),
        'provider_email' => adfsub_setting($pdo, 'subscription_provider_email'),
        'last_sync_at' => adfsub_setting($pdo, 'subscription_last_sync_at'),
        'last_sync_error' => adfsub_setting($pdo, 'subscription_last_sync_error'),
    ];
}

function adfsub_is_connected(array $cfg): bool
{
    return $cfg['client_key'] !== '' && $cfg['client_token'] !== '';
}

/** POST JSON ke endpoint ADF (URL diturunkan dari sync URL). Return array JSON atau null. */
function adfsub_post_adf(array $cfg, string $endpoint, array $payload, int $timeout = 8): ?array
{
    $url = str_replace('subscription-config.php', $endpoint, $cfg['sync_url']);
    $payload = array_merge(['client_key' => $cfg['client_key'], 'client_token' => $cfg['client_token']], $payload);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'ADFSystem-SubscriptionClient/1.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("adfsub_post_adf {$endpoint} failed: http={$httpCode} resp=" . substr((string) $response, 0, 200));
            return null;
        }
        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        error_log('adfsub_post_adf error: ' . $e->getMessage());
        return null;
    }
}

/** Ambil tarif, jatuh tempo, akun Pakasir, dan status kunci dari adfsystem.store. Dibatasi tiap 5 menit kecuali $force. */
function adfsub_sync(PDO $pdo, bool $force = false): bool
{
    $cfg = adfsub_config($pdo);
    if (!adfsub_is_connected($cfg)) {
        return false;
    }
    // Sinkron tiap 1 menit supaya Kunci / Buka Kunci dari ADF cepat terasa (panggilan ringan, timeout 8 detik).
    $interval = 60;
    if (!$force && $cfg['last_sync_at'] !== '' && strtotime($cfg['last_sync_at']) > time() - $interval) {
        return true;
    }
    adfsub_set_setting($pdo, 'subscription_last_sync_at', date('Y-m-d H:i:s'));

    $data = adfsub_post_adf($cfg, 'subscription-config.php', []);
    if (!$data || !array_key_exists('base_fee', $data)) {
        adfsub_set_setting($pdo, 'subscription_last_sync_error', 'Gagal sinkron dengan ADF System (' . date('d/m H:i') . ')');
        return false;
    }

    $map = [
        'subscription_client_name' => (string) ($data['client_name'] ?? ''),
        'subscription_base_fee' => (string) (float) ($data['base_fee'] ?? 0),
        'subscription_start_date' => (string) ($data['subscription_start_date'] ?? ''),
        'subscription_due_date_override' => (string) ($data['due_date_override'] ?? ''),
        'subscription_locked' => !empty($data['locked']) ? '1' : '0',
        'subscription_pakasir_slug' => (string) ($data['pakasir_slug'] ?? ''),
        'subscription_pakasir_api_key' => (string) ($data['pakasir_api_key'] ?? ''),
        'subscription_provider_name' => (string) ($data['provider_name'] ?? 'ADF System'),
        'subscription_provider_email' => (string) ($data['provider_email'] ?? ''),
        'subscription_last_sync_error' => '',
    ];
    foreach ($map as $key => $value) {
        adfsub_set_setting($pdo, $key, $value);
    }

    adfsub_sync_manual_invoices($pdo);
    adfsub_send_pending_emails($pdo);
    return true;
}

/** Tarik tagihan manual (di luar bulanan) yang dibuat admin ADF untuk klien ini. */
function adfsub_sync_manual_invoices(PDO $pdo): void
{
    $cfg = adfsub_config($pdo);
    $data = adfsub_post_adf($cfg, 'subscription-manual-invoices.php', []);
    if (!$data || !isset($data['manual_invoices']) || !is_array($data['manual_invoices'])) {
        return;
    }
    adfsub_ensure_schema($pdo);
    $remoteIds = [];
    foreach ($data['manual_invoices'] as $mi) {
        $id = (string) ($mi['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $remoteIds[] = 'MANUAL-' . $id;
        $exists = $pdo->prepare("SELECT id FROM adf_subscription_invoices WHERE period = ?");
        $exists->execute(['MANUAL-' . $id]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $pdo->prepare("INSERT INTO adf_subscription_invoices (period, type, description, total_amount, status, due_date) VALUES (?, 'manual', ?, ?, 'unpaid', ?)")
            ->execute(['MANUAL-' . $id, (string) ($mi['description'] ?? 'Tagihan Manual'), (float) ($mi['amount'] ?? 0), ($mi['due_date'] ?? null) ?: null]);
        adfsub_push($pdo, '🧾 Tagihan Baru dari ADF System', ($mi['description'] ?? 'Tagihan manual') . ' sebesar Rp ' . number_format((float) ($mi['amount'] ?? 0), 0, ',', '.'));
    }
    // Tagihan manual yang dihapus admin ADF (dan belum dibayar) ikut dihapus di sini.
    $local = $pdo->query("SELECT period FROM adf_subscription_invoices WHERE type = 'manual' AND status = 'unpaid'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($local as $period) {
        if (!in_array($period, $remoteIds, true)) {
            $pdo->prepare("DELETE FROM adf_subscription_invoices WHERE period = ? AND status = 'unpaid'")->execute([$period]);
        }
    }
}

/** Tanggal yang sama di bulan lain, disesuaikan ke akhir bulan kalau bulannya lebih pendek (31 -> 30/28). */
function adfsub_clamp_day(string $period, int $day): string
{
    $daysInMonth = (int) date('t', strtotime($period . '-01'));
    return sprintf('%s-%02d', $period, min(max($day, 1), $daysInMonth));
}

/**
 * Jatuh tempo pertama (patokan): tanggal "Jatuh Tempo" dari ADF kalau diisi; kalau kosong, Mulai Langganan + 1 bulan.
 * Tagihan bulan-bulan berikutnya jatuh tempo di tanggal yang sama setiap bulan.
 */
function adfsub_anchor_due_date(array $cfg): string
{
    if ($cfg['due_date_override'] !== '' && strtotime($cfg['due_date_override'])) {
        return date('Y-m-d', strtotime($cfg['due_date_override']));
    }
    if ($cfg['start_date'] !== '' && strtotime($cfg['start_date'])) {
        $start = strtotime($cfg['start_date']);
        $nextPeriod = date('Y-m', strtotime(date('Y-m-01', $start) . ' +1 month'));
        return adfsub_clamp_day($nextPeriod, (int) date('j', $start));
    }
    return '';
}

/** Periode (YYYY-MM) tagihan pertama; tidak ada tagihan sebelum ini. */
function adfsub_first_period(array $cfg): string
{
    $anchor = adfsub_anchor_due_date($cfg);
    return $anchor !== '' ? substr($anchor, 0, 7) : '';
}

/** Jatuh tempo periode YYYY-MM: tanggal patokan diulang tiap bulan (akhir bulan kalau belum ada patokan). */
function adfsub_due_date(array $cfg, string $period): string
{
    // Pakai tanggal asli (bukan hasil penyesuaian), supaya mulai tgl 31 tetap 31 di bulan yang panjang.
    if ($cfg['due_date_override'] !== '' && strtotime($cfg['due_date_override'])) {
        $day = (int) date('j', strtotime($cfg['due_date_override']));
    } elseif ($cfg['start_date'] !== '' && strtotime($cfg['start_date'])) {
        $day = (int) date('j', strtotime($cfg['start_date']));
    } else {
        $day = 31;
    }
    return adfsub_clamp_day($period, $day);
}

/** Buat (atau segarkan selama belum dibayar) tagihan bulanan flat untuk periode YYYY-MM. */
function adfsub_get_or_refresh_invoice(PDO $pdo, string $period): ?array
{
    $cfg = adfsub_config($pdo);
    // Belum ada tagihan sebelum periode jatuh tempo pertama (mis. mulai 3 Okt -> tagihan pertama 3 Nov).
    $firstPeriod = adfsub_first_period($cfg);
    if (!adfsub_is_connected($cfg) || $cfg['base_fee'] <= 0 || ($firstPeriod !== '' && $period < $firstPeriod)) {
        return null;
    }
    adfsub_ensure_schema($pdo);
    $dueDate = adfsub_due_date($cfg, $period);

    $stmt = $pdo->prepare("SELECT * FROM adf_subscription_invoices WHERE period = ?");
    $stmt->execute([$period]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->prepare("INSERT INTO adf_subscription_invoices (period, type, description, total_amount, status, due_date) VALUES (?, 'recurring', ?, ?, 'unpaid', ?)")
            ->execute([$period, 'Langganan ' . $period, $cfg['base_fee'], $dueDate]);
        $stmt->execute([$period]);
        $newRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($newRow) {
            adfsub_email_invoice($pdo, $newRow);
        }
        adfsub_push($pdo, '🧾 Tagihan Baru dari ADF System', 'Tagihan langganan ' . $period . ' sebesar Rp ' . number_format($cfg['base_fee'], 0, ',', '.') . ', jatuh tempo ' . date('d M Y', strtotime($dueDate)) . '.');
    } elseif ($row['status'] === 'unpaid' && ((float) $row['total_amount'] !== $cfg['base_fee'] || $row['due_date'] !== $dueDate)) {
        // Tarif / jatuh tempo diubah dari ADF: ikuti selama tagihan belum dibayar.
        $pdo->prepare("UPDATE adf_subscription_invoices SET total_amount = ?, due_date = ? WHERE period = ? AND status = 'unpaid'")
            ->execute([$cfg['base_fee'], $dueDate, $period]);
    }

    $stmt->execute([$period]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function adfsub_pakasir_create_link(array $cfg, string $orderId, int $amount): ?array
{
    if ($cfg['pakasir_slug'] === '' || $cfg['pakasir_api_key'] === '' || $amount <= 0) {
        return null;
    }
    $url = 'https://app.pakasir.com/api/v2/create-transaction/' . rawurlencode($cfg['pakasir_slug']) . '/' . rawurlencode($orderId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['method' => 'payment_link', 'amount' => $amount]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $cfg['pakasir_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $response, true);
    if ($httpCode < 200 || $httpCode >= 300 || empty($data['payment_link']) || empty($data['txn_id'])) {
        error_log('adfsub_pakasir_create_link failed: http=' . $httpCode . ' resp=' . substr((string) $response, 0, 200));
        return null;
    }
    return ['txn_id' => (string) $data['txn_id'], 'payment_link' => (string) $data['payment_link']];
}

function adfsub_pakasir_status(array $cfg, string $txnId): ?array
{
    if ($cfg['pakasir_slug'] === '' || $cfg['pakasir_api_key'] === '' || $txnId === '') {
        return null;
    }
    $url = 'https://app.pakasir.com/api/v2/transaction-status/' . rawurlencode($cfg['pakasir_slug']) . '/' . rawurlencode($txnId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $cfg['pakasir_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        return null;
    }
    $data = json_decode((string) $response, true);
    return is_array($data) ? $data : null;
}

/** Buat link pembayaran Pakasir baru untuk satu tagihan. Return URL atau null. */
function adfsub_create_payment(PDO $pdo, string $period): ?string
{
    $cfg = adfsub_config($pdo);
    adfsub_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM adf_subscription_invoices WHERE period = ? AND status = 'unpaid'");
    $stmt->execute([$period]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return null;
    }
    $bizCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'BIZ')));
    $orderId = 'SUB' . substr($bizCode, 0, 6) . str_replace('-', '', substr($period, 0, 20)) . strtoupper(bin2hex(random_bytes(3)));
    $result = adfsub_pakasir_create_link($cfg, $orderId, (int) round((float) $inv['total_amount']));
    if (!$result) {
        return null;
    }
    $pdo->prepare("UPDATE adf_subscription_invoices SET order_id = ?, txn_id = ?, payment_link = ? WHERE id = ?")
        ->execute([$orderId, $result['txn_id'], $result['payment_link'], $inv['id']]);
    return $result['payment_link'];
}

/** Cek ke Pakasir apakah tagihan yang punya transaksi sudah lunas; kalau ya tandai lunas + catat ke ADF. */
function adfsub_reconcile(PDO $pdo, array $inv): bool
{
    if (($inv['status'] ?? '') !== 'unpaid' || empty($inv['txn_id'])) {
        return false;
    }
    $cfg = adfsub_config($pdo);
    $status = adfsub_pakasir_status($cfg, (string) $inv['txn_id']);
    $txnStatus = strtolower((string) ($status['status'] ?? $status['transaction']['status'] ?? ''));
    if ($txnStatus !== 'completed') {
        return false;
    }
    $paidAt = (string) ($status['completed_at'] ?? $status['transaction']['completed_at'] ?? '') ?: date('c');
    $paidAtSql = date('Y-m-d H:i:s', strtotime($paidAt) ?: time());
    $pdo->prepare("UPDATE adf_subscription_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'unpaid'")
        ->execute([$paidAtSql, $inv['id']]);

    // ADF: catat di halaman Transaksi + kirim email lunas (PDF invoice) ke klien.
    adfsub_email_paid($pdo, array_merge($inv, ['status' => 'paid', 'paid_at' => $paidAtSql]));
    adfsub_push($pdo, '✅ Pembayaran Berhasil', 'Tagihan ADF System (' . ($inv['description'] ?: $inv['period']) . ') sebesar Rp ' . number_format((float) $inv['total_amount'], 0, ',', '.') . ' sudah lunas.');
    return true;
}

function adfsub_unpaid_invoices(PDO $pdo): array
{
    adfsub_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM adf_subscription_invoices WHERE status = 'unpaid' ORDER BY (due_date IS NULL), due_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function adfsub_all_invoices(PDO $pdo, int $limit = 24): array
{
    adfsub_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM adf_subscription_invoices ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
}

/** Push notif ke owner/admin/developer bisnis aktif. Tidak pernah membuat halaman gagal. */
function adfsub_push(PDO $pdo, string $title, string $body): void
{
    try {
        require_once __DIR__ . '/PushNotificationHelper.php';
        (new PushNotificationHelper())->sendToAdmins($title, $body, [
            'type' => 'subscription',
            'url' => (defined('BASE_URL') ? BASE_URL : '') . '/modules/subscription/index.php',
        ]);
    } catch (Throwable $e) {
        error_log('adfsub_push error: ' . $e->getMessage());
    }
}

/**
 * Dipanggil dari header tiap halaman: sinkron (maks. 5 menit), buat tagihan bulan ini, cek pembayaran
 * yang tertunda (maks. 1 menit). Return status untuk banner / kunci layar.
 */
function adfsub_tick(?PDO $pdo = null): array
{
    $state = ['connected' => false, 'locked' => false, 'reminder' => null, 'unpaid' => [], 'due_soon' => [], 'active_until' => ''];
    try {
        $pdo = $pdo ?? adfsub_pdo();
        $cfg = adfsub_config($pdo);
        if (!adfsub_is_connected($cfg)) {
            return $state;
        }
        $state['connected'] = true;
        adfsub_sync($pdo);
        $cfg = adfsub_config($pdo);

        // Tagihan bulanan lama yang periodenya sebelum jatuh tempo pertama (aturan lama) dibatalkan otomatis.
        $firstPeriod = adfsub_first_period($cfg);
        if ($firstPeriod !== '') {
            adfsub_ensure_schema($pdo);
            $pdo->prepare("UPDATE adf_subscription_invoices SET status = 'cancelled' WHERE type = 'recurring' AND status = 'unpaid' AND period < ?")
                ->execute([$firstPeriod]);
        }

        $current = adfsub_get_or_refresh_invoice($pdo, date('Y-m'));
        // Tagihan bulan depan dibuat 7 hari sebelum jatuh temponya, supaya pengingat 7 hari tetap berjalan.
        $nextPeriod = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        if (strtotime(adfsub_due_date($cfg, $nextPeriod)) - strtotime(date('Y-m-d')) <= 7 * 86400) {
            adfsub_get_or_refresh_invoice($pdo, $nextPeriod);
        }

        // Cek pembayaran Pakasir yang belum terkonfirmasi, paling sering 1x per menit per sesi.
        if (($_SESSION['adfsub_reconcile_at'] ?? 0) < time() - 60) {
            $_SESSION['adfsub_reconcile_at'] = time();
            foreach (adfsub_unpaid_invoices($pdo) as $inv) {
                adfsub_reconcile($pdo, $inv);
            }
        }

        $state['locked'] = $cfg['locked'];
        $state['unpaid'] = adfsub_unpaid_invoices($pdo);
        // Tagihan yang ditampilkan: tagihan manual selalu langsung tampil; tagihan bulanan mulai H-7 jatuh tempo.
        $state['due_soon'] = array_values(array_filter($state['unpaid'], static function ($inv) {
            return ($inv['type'] ?? '') === 'manual'
                || empty($inv['due_date'])
                || (strtotime($inv['due_date']) - strtotime(date('Y-m-d'))) <= 7 * 86400;
        }));
        $nearest = $state['unpaid'][0] ?? null;
        $shown = $state['due_soon'][0] ?? null;
        if ($shown) {
            $daysLeft = !empty($shown['due_date'])
                ? (int) floor((strtotime($shown['due_date']) - strtotime(date('Y-m-d'))) / 86400)
                : null;
            $state['reminder'] = ['invoice' => $shown, 'days_left' => $daysLeft];
            if ($daysLeft !== null && $daysLeft < 0 && ($shown['overdue_notified_at'] ?? '') !== date('Y-m-d')) {
                $pdo->prepare("UPDATE adf_subscription_invoices SET overdue_notified_at = CURDATE() WHERE id = ?")->execute([$shown['id']]);
                adfsub_push($pdo, '⚠️ Tagihan Langganan Terlambat', 'Tagihan ' . ($shown['description'] ?: $shown['period']) . ' sudah lewat jatuh tempo. Segera lakukan pembayaran.');
            }
        }

        // Langganan aktif sampai jatuh tempo berikutnya (untuk label "Subscribe Pro").
        $activeUntil = $nearest['due_date'] ?? '';
        if ($activeUntil === '') {
            $activeUntil = adfsub_due_date($cfg, date('Y-m'));
            if ($activeUntil < date('Y-m-d') || ($current['status'] ?? '') === 'paid') {
                $activeUntil = adfsub_due_date($cfg, date('Y-m', strtotime(date('Y-m-01') . ' +1 month')));
            }
            $anchor = adfsub_anchor_due_date($cfg);
            if ($anchor !== '' && $anchor > $activeUntil) {
                $activeUntil = $anchor;
            }
        }
        $state['active_until'] = $activeUntil;
        unset($current);
    } catch (Throwable $e) {
        error_log('adfsub_tick error: ' . $e->getMessage());
    }
    return $state;
}
