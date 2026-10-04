<?php

/**
 * Penghubung Developer Panel ↔ adfsystem.store (Klien Langganan).
 *
 * adfsystem.store berjalan di hosting yang sama (public_html/adfsystem.store), jadi data klien
 * langganan (data/subscription-clients.json) dibaca/ditulis langsung lewat fungsi store-nya sendiri —
 * tanpa API atau kunci tambahan. Lokal: memakai folder adfsystem-site.
 *
 * Koneksi di sisi bisnis disimpan di tabel `settings` DB bisnis (subscription_client_key / _token / _sync_url),
 * sama persis dengan yang diisi manual di halaman Tagihan Langganan.
 */

const ADFSTORE_DEFAULT_FEE = 350000;
const ADFSTORE_SYNC_URL = 'https://adfsystem.store/api/subscription-config.php';

/** Folder root adfsystem.store yang dipakai live (null kalau tidak ditemukan). */
function adfstore_root(): ?string
{
    static $root = false;
    if ($root !== false) {
        return $root;
    }
    $base = dirname(__DIR__, 2);
    $root = null;
    // adfsystem.store (folder live addon domain) diutamakan; adfsystem-site hanya sumber repo / lokal.
    foreach ([$base . '/adfsystem.store', $base . '/adfsystem-site'] as $dir) {
        if (is_file($dir . '/includes/subscription-clients-store.php')) {
            $root = $dir;
            break;
        }
    }
    if ($root) {
        require_once $root . '/includes/subscription-clients-store.php';
    }
    return $root;
}

/**
 * Koneksi PDO ke DB bisnis (null kalau DB tidak bisa dibuka).
 * Nama DB dicari dengan cara yang sama seperti aplikasi: database di config/businesses/{slug}.php
 * lewat getDbName() (mis. adf_benscafe → adfb2574_Adf_Bens), lalu nama di tabel businesses.
 */
function adfstore_biz_pdo(array $biz): ?PDO
{
    $candidates = [];
    $slug = (string) ($biz['slug'] ?? '');
    $cfgFile = dirname(__DIR__, 2) . '/config/businesses/' . $slug . '.php';
    if ($slug !== '' && is_file($cfgFile)) {
        $cfg = include $cfgFile;
        if (is_array($cfg) && !empty($cfg['database'])) {
            $candidates[] = function_exists('getDbName') ? getDbName($cfg['database']) : $cfg['database'];
        }
    }
    $raw = (string) ($biz['database_name'] ?? '');
    if ($raw !== '') {
        $candidates[] = $raw;
        if (function_exists('getDbName')) {
            $candidates[] = getDbName($raw);
        }
    }
    foreach (array_unique($candidates) as $dbName) {
        try {
            return new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            // coba kandidat berikutnya
        }
    }
    return null;
}

function adfstore_biz_setting(PDO $bizPdo, string $key): string
{
    try {
        $st = $bizPdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        return (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function adfstore_biz_set_setting(PDO $bizPdo, string $key, string $value): void
{
    $bizPdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT,
        setting_type VARCHAR(20) DEFAULT 'string', description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $bizPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

/**
 * Status langganan satu bisnis untuk kolom di halaman Bisnis.
 * code: no_store | no_db | not_connected | not_in_store | token_mismatch | active | locked
 */
function adfstore_status(array $biz): array
{
    if (!adfstore_root()) {
        return ['code' => 'no_store', 'label' => 'ADF Store tidak ditemukan'];
    }
    $bizPdo = adfstore_biz_pdo($biz);
    if (!$bizPdo) {
        return ['code' => 'no_db', 'label' => 'DB belum siap'];
    }
    $key = adfstore_biz_setting($bizPdo, 'subscription_client_key');
    $token = adfstore_biz_setting($bizPdo, 'subscription_client_token');
    if ($key === '' || $token === '') {
        return ['code' => 'not_connected', 'label' => 'Belum terhubung'];
    }
    $client = adf_subscription_client_find($key);
    if (!$client) {
        return ['code' => 'not_in_store', 'label' => 'Tidak ada di ADF Store', 'key' => $key];
    }
    if (!hash_equals((string) ($client['client_token'] ?? ''), $token)) {
        return ['code' => 'token_mismatch', 'label' => 'Token tidak cocok', 'key' => $key, 'client' => $client];
    }

    // Tagihan belum dibayar dari DB bisnis (tabel dibuat otomatis oleh modul langganan).
    $unpaid = 0;
    $unpaidCount = 0;
    try {
        $row = $bizPdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) t FROM adf_subscription_invoices WHERE status = 'unpaid'")->fetch();
        $unpaidCount = (int) $row['c'];
        $unpaid = (float) $row['t'];
    } catch (Throwable $e) {
    }

    return [
        'code' => !empty($client['locked']) ? 'locked' : 'active',
        'label' => !empty($client['locked']) ? 'Terkunci' : 'Aktif',
        'key' => $key,
        'client' => $client,
        'fee' => (float) ($client['base_fee'] ?? 0),
        'unpaid' => $unpaid,
        'unpaid_count' => $unpaidCount,
        'sync_error' => adfstore_biz_setting($bizPdo, 'subscription_last_sync_error'),
    ];
}

/**
 * Daftarkan bisnis sebagai klien langganan di adfsystem.store dan isi koneksinya di DB bisnis.
 * Kalau klien sudah ada (key sama, termasuk beda spasi/huruf), token yang ada dipakai ulang —
 * sekalian memperbaiki "token tidak cocok".
 */
function adfstore_connect(array $biz, float $fee = ADFSTORE_DEFAULT_FEE): array
{
    if (!adfstore_root()) {
        return [false, 'Folder adfsystem.store tidak ditemukan di hosting.'];
    }
    $bizPdo = adfstore_biz_pdo($biz);
    if (!$bizPdo) {
        return [false, 'Database bisnis belum bisa dibuka. Selesaikan Setup dulu.'];
    }

    $existingKey = adfstore_biz_setting($bizPdo, 'subscription_client_key');
    $slug = (string) (!empty($biz['slug']) ? $biz['slug'] : strtolower($biz['business_code'] ?? ''));
    $client = ($existingKey !== '' ? adf_subscription_client_find($existingKey) : null) ?: adf_subscription_client_find($slug);

    if (!$client) {
        $client = [
            'client_key' => $slug,
            'client_name' => (string) ($biz['business_name'] ?? $slug),
            'base_fee' => $fee,
            'per_guest_fee' => 0,
            'subscription_start_date' => date('Y-m-d'),
            'due_date_override' => '',
            'pakasir_slug' => '',
            'pakasir_api_key' => '',
            'pakasir_webhook_secret' => '',
            'notify_email' => '',
            'client_token' => bin2hex(random_bytes(24)),
            'created_at' => date('c'),
        ];
        // Akun Pakasir disamakan dengan klien lain yang sudah ada, supaya tombol Bayar langsung jalan.
        foreach (adf_subscription_clients_load() as $other) {
            if (!empty($other['pakasir_slug']) && !empty($other['pakasir_api_key'])) {
                $client['pakasir_slug'] = $other['pakasir_slug'];
                $client['pakasir_api_key'] = $other['pakasir_api_key'];
                break;
            }
        }
        if (!adf_subscription_client_upsert($client)) {
            return [false, 'Gagal menyimpan klien di adfsystem.store (cek izin tulis folder data/).'];
        }
        $msg = 'Klien langganan baru dibuat di ADF Store (Rp ' . number_format($fee, 0, ',', '.') . '/bulan) dan terhubung.';
    } else {
        $msg = 'Terhubung ke klien ADF Store yang sudah ada: ' . ($client['client_name'] ?? $client['client_key']) . '.';
    }

    adfstore_biz_set_setting($bizPdo, 'subscription_client_key', (string) $client['client_key']);
    adfstore_biz_set_setting($bizPdo, 'subscription_client_token', (string) $client['client_token']);
    adfstore_biz_set_setting($bizPdo, 'subscription_sync_url', ADFSTORE_SYNC_URL);
    // Paksa sinkron di kunjungan berikutnya.
    adfstore_biz_set_setting($bizPdo, 'subscription_last_sync_at', '');
    adfstore_biz_set_setting($bizPdo, 'subscription_last_sync_error', '');

    return [true, $msg];
}
