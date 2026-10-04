<?php

/**
 * Laporan Telegram terjadwal untuk developer:
 *   - "summary" ≈ 21:00 : ringkasan harian (uang masuk, tagihan belum dibayar, bisnis terkunci / gagal sinkron)
 *   - "due"     ≈ 08:00 : pengingat tagihan jatuh tempo hari ini, besok, dan yang sudah lewat
 *
 * Dijalankan oleh cron (cron/adf-telegram-report.php) DAN sebagai cadangan otomatis saat ada
 * halaman ADF yang dibuka (adf_report_maybe_run), masing-masing paling banyak sekali per hari.
 * Bot & chat memakai pengaturan "Notifikasi Telegram" di ADF Store.
 */

require_once dirname(__DIR__) . '/developer/includes/adfstore_bridge.php';

const ADF_REPORT_SUMMARY_HOUR = 21;
const ADF_REPORT_DUE_HOUR = 8;

function adf_report_state_path(): string
{
    return dirname(__DIR__, 2) . '/adf-tg-report-state.json'; // folder home (di luar public_html)
}

/**
 * Cadangan tanpa cron: dipanggil dari header halaman ADF. Hanya membaca satu file; kalau ada pekerjaan
 * (laporan / penagihan otomatis), dikerjakan SETELAH halaman terkirim ke browser supaya tidak lambat.
 */
function adf_report_maybe_run(): void
{
    try {
        $hour = (int) date('G');
        $today = date('Y-m-d');
        $state = is_file(adf_report_state_path()) ? (json_decode((string) file_get_contents(adf_report_state_path()), true) ?: []) : [];
        $jobs = [];
        if ((int) ($state['billing_at'] ?? 0) < time() - 1800) {
            $jobs[] = 'billing';
        }
        if ($hour >= ADF_REPORT_DUE_HOUR && ($state['due'] ?? '') !== $today) {
            $jobs[] = 'due';
        }
        if ($hour >= ADF_REPORT_SUMMARY_HOUR && ($state['summary'] ?? '') !== $today) {
            $jobs[] = 'summary';
        }
        if (!$jobs) {
            return;
        }
        register_shutdown_function(static function () use ($jobs) {
            // Lepas sesi & kirim halaman ke browser dulu, baru kerjakan di belakang.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            @set_time_limit(120);
            foreach ($jobs as $job) {
                try {
                    $job === 'billing' ? adf_billing_sweep() : adf_report_run($job);
                } catch (Throwable $e) {
                    error_log('adf background job ' . $job . ': ' . $e->getMessage());
                }
            }
        });
    } catch (Throwable $e) {
        error_log('adf_report_maybe_run: ' . $e->getMessage());
    }
}

/**
 * Penagihan otomatis untuk SEMUA bisnis yang terhubung ke ADF Store, tanpa perlu ada yang membuka
 * sistem bisnis itu: sinkron tarif/kunci, terbitkan tagihan bulanan, kirim email tagihan baru,
 * cek pembayaran Pakasir, kirim email lunas. Maks. sekali per 25 menit (dikunci file).
 */
function adf_billing_sweep(bool $force = false): string
{
    $fh = @fopen(adf_report_state_path(), 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return 'sedang dijalankan proses lain';
    }
    $state = json_decode((string) stream_get_contents($fh), true) ?: [];
    if (!$force && (int) ($state['billing_at'] ?? 0) > time() - 1500) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return 'baru saja dijalankan';
    }
    $state['billing_at'] = time();
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    flock($fh, LOCK_UN);
    fclose($fh);

    require_once __DIR__ . '/subscription_client.php';
    $GLOBALS['adfsub_no_push'] = true;
    $done = [];
    try {
        $master = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        foreach ($master->query("SELECT * FROM businesses WHERE is_active = 1")->fetchAll() as $biz) {
            $bizPdo = adfstore_biz_pdo($biz);
            if (!$bizPdo || adfstore_biz_setting($bizPdo, 'subscription_client_key') === '') {
                continue;
            }
            $_SESSION['adfsub_reconcile_at'] = 0; // izinkan cek pembayaran untuk tiap bisnis
            adfsub_tick($bizPdo);
            $done[] = $biz['business_name'];
        }
    } catch (Throwable $e) {
        error_log('adf_billing_sweep: ' . $e->getMessage());
    }
    $GLOBALS['adfsub_no_push'] = false;
    return 'diproses: ' . ($done ? implode(', ', $done) : '-');
}

/**
 * Jalankan satu laporan, paling banyak sekali per hari (dikunci dengan flock supaya
 * cron dan cadangan otomatis tidak mengirim dua kali bersamaan). $force = abaikan "sudah terkirim".
 */
function adf_report_run(string $mode, bool $force = false): string
{
    $root = adfstore_root();
    if (!$root || !is_file($root . '/includes/telegram.php')) {
        return 'ADF Store tidak ditemukan';
    }
    require_once $root . '/includes/telegram.php';
    if (!adf_tg_config()) {
        return 'Telegram belum diatur';
    }

    $fh = @fopen(adf_report_state_path(), 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return 'sedang dijalankan proses lain';
    }
    $state = json_decode((string) stream_get_contents($fh), true) ?: [];
    $today = date('Y-m-d');
    if (!$force && ($state[$mode] ?? '') === $today) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return 'sudah terkirim hari ini';
    }
    // Gagal kirim (mis. Telegram gangguan) → coba lagi paling cepat 30 menit kemudian, supaya halaman tidak lambat.
    if (!$force && ($state[$mode . '_try'] ?? 0) > time() - 1800) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return 'menunggu percobaan ulang';
    }
    $state[$mode . '_try'] = time();

    $data = adf_report_collect($root);
    $msg = $mode === 'due' ? adf_report_due_message($data) : adf_report_summary_message($data);
    $sent = $msg === '' ? true : adf_tg_send($msg); // pengingat kosong (tidak ada jatuh tempo) = tidak kirim

    if ($sent) {
        $state[$mode] = $today;
    }
    ftruncate($fh, 0); // simpan juga waktu percobaan (untuk jeda 30 menit bila gagal)
    rewind($fh);
    fwrite($fh, json_encode($state));
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod(adf_report_state_path(), 0600);
    return $msg === '' ? 'tidak ada yang perlu dikirim' : ($sent ? 'terkirim' : 'gagal kirim');
}

/** Kumpulkan data: transaksi ADF Store + tagihan/status tiap bisnis. */
function adf_report_collect(string $storeRoot): array
{
    $data = ['orders' => [], 'bills' => [], 'locked' => [], 'sync_error' => [], 'not_connected' => []];

    $ordersFile = $storeRoot . '/data/orders.json';
    $data['orders'] = is_file($ordersFile) ? (json_decode((string) file_get_contents($ordersFile), true) ?: []) : [];

    try {
        $master = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $businesses = $master->query("SELECT * FROM businesses WHERE is_active = 1 ORDER BY business_name")->fetchAll();
    } catch (Throwable $e) {
        error_log('adf_report_collect master: ' . $e->getMessage());
        $businesses = [];
    }

    foreach ($businesses as $biz) {
        $st = adfstore_status($biz);
        $name = (string) $biz['business_name'];
        if ($st['code'] === 'locked') {
            $data['locked'][] = $name;
        }
        if (!empty($st['sync_error'])) {
            $data['sync_error'][] = $name;
        }
        if (in_array($st['code'], ['not_connected', 'not_in_store', 'token_mismatch'], true)) {
            $data['not_connected'][] = $name;
        }
        if (!in_array($st['code'], ['active', 'locked'], true)) {
            continue;
        }
        $bizPdo = adfstore_biz_pdo($biz);
        if (!$bizPdo) {
            continue;
        }
        try {
            $rows = $bizPdo->query("SELECT description, period, total_amount, due_date FROM adf_subscription_invoices WHERE status = 'unpaid' ORDER BY due_date")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $data['bills'][] = [
                    'business' => $name,
                    'label' => (string) ($r['description'] ?: $r['period']),
                    'amount' => (float) $r['total_amount'],
                    'due' => (string) ($r['due_date'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // tabel tagihan belum ada di bisnis ini
        }
    }
    return $data;
}

function adf_report_rp($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function adf_report_type(array $o): string
{
    if (($o['source'] ?? '') !== 'subscription') {
        return 'website';
    }
    $id = strtolower((string) ($o['order_id'] ?? ''));
    return (strpos($id, 'manual') !== false || stripos((string) ($o['product_title'] ?? ''), 'tagihan manual') !== false) ? 'manual' : 'bulanan';
}

function adf_report_summary_message(array $d): string
{
    $today = date('Y-m-d');
    $month = date('Y-m');
    $sum = ['bulanan' => 0, 'manual' => 0, 'website' => 0];
    $count = 0;
    $monthTotal = 0;
    foreach ($d['orders'] as $o) {
        if (strtolower((string) ($o['status'] ?? '')) !== 'completed') {
            continue;
        }
        $ts = strtotime((string) ($o['completed_at'] ?? '')) ?: 0;
        if (date('Y-m', $ts) === $month) {
            $monthTotal += (int) ($o['amount'] ?? 0);
        }
        if (date('Y-m-d', $ts) === $today) {
            $sum[adf_report_type($o)] += (int) ($o['amount'] ?? 0);
            $count++;
        }
    }
    $todayTotal = array_sum($sum);
    $hari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'][(int) date('w')];

    $m = '📊 <b>Ringkasan Harian</b> — ' . $hari . ', ' . date('d M Y') . "\n\n";
    $m .= '💰 Uang masuk hari ini: <b>' . adf_report_rp($todayTotal) . '</b> (' . $count . " transaksi)\n";
    if ($todayTotal > 0) {
        $m .= '   Bulanan ' . adf_report_rp($sum['bulanan']) . ' · Manual ' . adf_report_rp($sum['manual']) . ' · Website ' . adf_report_rp($sum['website']) . "\n";
    }
    $m .= '📅 Total bulan ini: <b>' . adf_report_rp($monthTotal) . "</b>\n";

    $unpaid = array_sum(array_column($d['bills'], 'amount'));
    $m .= "\n🧾 Belum dibayar: <b>" . adf_report_rp($unpaid) . '</b> (' . count($d['bills']) . " tagihan)\n";
    foreach (array_slice($d['bills'], 0, 8) as $b) {
        $late = $b['due'] !== '' && $b['due'] < $today;
        $m .= '   • ' . adf_tg_e($b['business']) . ' — ' . adf_report_rp($b['amount'])
            . ($b['due'] !== '' ? ' (' . ($late ? '⚠️ lewat ' : 'jatuh tempo ') . date('d M', strtotime($b['due'])) . ')' : '') . "\n";
    }
    if (count($d['bills']) > 8) {
        $m .= '   • +' . (count($d['bills']) - 8) . " tagihan lain\n";
    }
    if ($d['locked']) {
        $m .= "\n🔒 Terkunci: " . adf_tg_e(implode(', ', $d['locked'])) . "\n";
    }
    if ($d['sync_error']) {
        $m .= '⚠️ Gagal sinkron: ' . adf_tg_e(implode(', ', $d['sync_error'])) . "\n";
    }
    if ($d['not_connected']) {
        $m .= '🔗 Belum terhubung ADF Store: ' . adf_tg_e(implode(', ', $d['not_connected'])) . "\n";
    }
    if (!$d['locked'] && !$d['sync_error'] && !$d['not_connected']) {
        $m .= "\n✅ Semua bisnis normal.\n";
    }
    return $m . "\n<a href=\"https://adfsystem.store/admin/orders.php\">Transaksi</a> · <a href=\"https://adfsystem.store/admin/subscription-clients.php\">Klien Langganan</a>";
}

/** Pengingat pagi: hanya dikirim kalau ada tagihan yang jatuh tempo hari ini/besok atau sudah lewat. */
function adf_report_due_message(array $d): string
{
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $groups = ['today' => [], 'tomorrow' => [], 'late' => []];
    foreach ($d['bills'] as $b) {
        if ($b['due'] === '') {
            continue;
        }
        if ($b['due'] === $today) {
            $groups['today'][] = $b;
        } elseif ($b['due'] === $tomorrow) {
            $groups['tomorrow'][] = $b;
        } elseif ($b['due'] < $today) {
            $groups['late'][] = $b;
        }
    }
    if (!$groups['today'] && !$groups['tomorrow'] && !$groups['late']) {
        return '';
    }
    $line = static fn($b, $extra = '') => '   • ' . adf_tg_e($b['business']) . ' — ' . adf_report_rp($b['amount']) . ' · ' . adf_tg_e($b['label']) . $extra . "\n";
    $m = "⏰ <b>Pengingat Tagihan</b> — " . date('d M Y') . "\n";
    if ($groups['today']) {
        $m .= "\n📌 <b>Jatuh tempo HARI INI</b>\n";
        foreach ($groups['today'] as $b) {
            $m .= $line($b);
        }
    }
    if ($groups['tomorrow']) {
        $m .= "\n🗓️ <b>Jatuh tempo BESOK</b>\n";
        foreach ($groups['tomorrow'] as $b) {
            $m .= $line($b);
        }
    }
    if ($groups['late']) {
        $m .= "\n⚠️ <b>Sudah lewat jatuh tempo</b>\n";
        foreach ($groups['late'] as $b) {
            $days = (int) floor((strtotime($today) - strtotime($b['due'])) / 86400);
            $m .= $line($b, ' (' . $days . ' hari)');
        }
        $m .= "\nPertimbangkan menghubungi klien atau <b>Kunci</b> sistemnya di Klien Langganan.\n";
    }
    return $m . "\n<a href=\"https://adfsystem.store/admin/subscription-clients.php\">Buka Klien Langganan</a>";
}
