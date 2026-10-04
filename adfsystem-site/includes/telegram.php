<?php

/**
 * Notifikasi Telegram untuk developer/admin ADF Store.
 *
 * Bot token & chat ID disimpan di folder home hosting (di luar public_html, tidak ikut git):
 *   adf-telegram.php — diisi lewat menu Pengaturan → Notifikasi Telegram.
 * Semua pengiriman memakai timeout pendek dan tidak pernah membuat halaman/webhook gagal.
 */

function adf_tg_config_path(): string
{
    return dirname(__DIR__, 3) . '/adf-telegram.php'; // includes → store → public_html → home
}

/** @return array{bot_token: string, chat_id: string}|null */
function adf_tg_config(): ?array
{
    $path = adf_tg_config_path();
    $cfg = is_file($path) ? include $path : null;
    if (!is_array($cfg) || empty($cfg['bot_token'])) {
        return null;
    }
    return ['bot_token' => (string) $cfg['bot_token'], 'chat_id' => (string) ($cfg['chat_id'] ?? '')];
}

function adf_tg_save_config(string $token, string $chatId): bool
{
    $content = "<?php\n// Notifikasi Telegram ADF Store. JANGAN dibagikan / di-commit.\nreturn " . var_export([
        'bot_token' => $token,
        'chat_id' => $chatId,
        'updated_at' => date('c'),
    ], true) . ";\n";
    $ok = file_put_contents(adf_tg_config_path(), $content, LOCK_EX) !== false;
    if ($ok) {
        @chmod(adf_tg_config_path(), 0600);
    }
    return $ok;
}

/** Panggil Bot API. Return array respons atau null. */
function adf_tg_api(string $token, string $method, array $params = [], int $timeout = 5): ?array
{
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
        return null;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $resp, true);
    return is_array($data) ? $data : null;
}

/** Kirim pesan (HTML) ke chat yang terdaftar. */
function adf_tg_send(string $html): bool
{
    $cfg = adf_tg_config();
    if (!$cfg || $cfg['chat_id'] === '') {
        return false;
    }
    $res = adf_tg_api($cfg['bot_token'], 'sendMessage', [
        'chat_id' => $cfg['chat_id'],
        'text' => $html,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);
    if (empty($res['ok'])) {
        $GLOBALS['adf_tg_last_error'] = (string) ($res['description'] ?? 'tidak bisa menghubungi Telegram');
        error_log('telegram send failed: ' . substr(json_encode($res), 0, 200));
        return false;
    }
    return true;
}

function adf_tg_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Kirim notifikasi untuk transaksi yang BARU berstatus completed.
 * Pembayaran lama (dicatat ulang saat sinkron/backfill, > 2 hari) tidak dikirim satu per satu.
 */
function adf_tg_notify_completed(array $newlyCompleted, array $allOrders): void
{
    if (!$newlyCompleted || !adf_tg_config()) {
        return;
    }
    $recent = array_values(array_filter($newlyCompleted, static function ($o) {
        $ts = strtotime((string) ($o['completed_at'] ?? '')) ?: time();
        return $ts >= time() - 2 * 86400;
    }));
    if (!$recent) {
        return;
    }

    // Ringkasan hari ini (semua transaksi completed tanggal hari ini)
    $today = date('Y-m-d');
    $todayTotal = 0;
    $todayCount = 0;
    foreach ($allOrders as $o) {
        if (strtolower((string) ($o['status'] ?? '')) === 'completed' && date('Y-m-d', strtotime((string) ($o['completed_at'] ?? '')) ?: 0) === $today) {
            $todayTotal += (int) ($o['amount'] ?? 0);
            $todayCount++;
        }
    }
    $rp = static fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $typeOf = static function (array $o): string {
        if (($o['source'] ?? '') !== 'subscription') {
            return ($o['source'] ?? '') === 'website' ? 'Checkout Website' : 'Pakasir';
        }
        $id = strtolower((string) ($o['order_id'] ?? ''));
        return (strpos($id, 'manual') !== false || stripos((string) ($o['product_title'] ?? ''), 'tagihan manual') !== false) ? 'Tagihan Manual' : 'Tagihan Bulanan';
    };

    foreach (array_slice($recent, 0, 5) as $o) {
        $msg = "💰 <b>Transaksi Masuk</b>\n"
            . '<b>' . adf_tg_e($rp($o['amount'] ?? 0)) . "</b>\n"
            . adf_tg_e((string) ($o['name'] ?? '-')) . ' · ' . adf_tg_e($typeOf($o)) . "\n"
            . adf_tg_e((string) ($o['product_title'] ?? '-')) . "\n"
            . '🕒 ' . date('d M Y H:i', strtotime((string) ($o['completed_at'] ?? '')) ?: time())
            . (!empty($o['payment_method']) ? ' · ' . adf_tg_e(strtoupper((string) $o['payment_method'])) : '') . "\n"
            . '<code>' . adf_tg_e((string) ($o['order_id'] ?? '')) . "</code>\n\n"
            . '📊 Hari ini: <b>' . adf_tg_e($rp($todayTotal)) . '</b> (' . $todayCount . " transaksi)\n"
            . '<a href="https://adfsystem.store/admin/orders.php">Buka Transaksi</a>';
        adf_tg_send($msg);
    }
    if (count($recent) > 5) {
        adf_tg_send('➕ ' . (count($recent) - 5) . ' transaksi lain juga baru masuk. Lihat di halaman Transaksi.');
    }
}
