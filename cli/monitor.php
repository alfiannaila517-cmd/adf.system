<?php

/**
 * Monitor server ADF → Telegram. Dijalankan cron sebagai user `adf` (lihat /etc/cron.d/adf-monitor):
 *   php cli/monitor.php                cek tiap 5 menit; kirim pesan HANYA saat ada masalah baru / pulih / masalah berlanjut tiap 6 jam
 *   php cli/monitor.php --daily        laporan pagi: ringkasan kondisi + pengingat tagihan VPS
 *   php cli/monitor.php --test         kirim pesan uji
 *   php cli/monitor.php --preview      tampilkan laporan pagi di layar (tidak dikirim)
 *   php cli/monitor.php --find-chat    cari chat id Telegram setelah kamu mengirim /start ke bot, lalu simpan ke secrets
 *   php cli/monitor.php --set-password buat / ganti login portal https://<SITE_HOST>/monitor/
 *
 * Rahasia & pengaturan di /srv/adf/secrets (tidak di git):
 *   telegram.env  TELEGRAM_BOT_TOKEN=…  TELEGRAM_CHAT_ID=…
 *   monitor.env   SERVER_NAME, SITE_HOST, VPS_NEXT_DUE=2026-11-20, VPS_CYCLE=monthly|yearly, VPS_PRICE, VPS_PLAN
 *   portal.env    MONITOR_USER, MONITOR_PASS_HASH (dibuat oleh --set-password)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';
require_once ADF_CORE . '/ServerHealth.php';

const REPEAT_HOURS = 6; // masalah yang belum pulih diingatkan lagi setiap 6 jam

$mode = $argv[1] ?? '';
$stateFile = ServerHealth::stateFile();
if (!is_dir(dirname($stateFile))) {
    @mkdir(dirname($stateFile), 0770, true);
}

switch ($mode) {
    case '--find-chat':
        findChat();
        exit;
    case '--set-password':
        setPortalPassword();
        exit;
    case '--test':
        $ok = telegram("✅ <b>Monitor ADF tersambung</b>\n" . esc(ServerHealth::serverName()) . ' · ' . date('d M Y H:i') . ' WIB');
        echo $ok ? "Pesan uji terkirim.\n" : "Gagal mengirim — cek TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID.\n";
        exit($ok ? 0 : 1);
}

$facts = ServerHealth::facts();
$problems = ServerHealth::problems($facts);

if ($mode === '--preview') {
    echo strip_tags(dailyReport($facts, $problems)) . PHP_EOL;
    exit;
}
if ($mode === '--daily') {
    telegram(dailyReport($facts, $problems));
    exit;
}

// ---- Cek rutin: kirim hanya bila ada perubahan ----
$prev = ServerHealth::lastState()['problems'] ?? [];
$now = time();
$lines = [];
foreach ($problems as $key => $msg) {
    if (!isset($prev[$key])) {
        $lines[] = "⚠️ {$msg}";
        $prev[$key] = ['since' => $now, 'notified' => $now, 'msg' => $msg];
    } else {
        $prev[$key]['msg'] = $msg;
        if ($now - (int)$prev[$key]['notified'] >= REPEAT_HOURS * 3600) {
            $lines[] = '⏳ Masih bermasalah sejak ' . date('d M H:i', (int)$prev[$key]['since']) . ": {$msg}";
            $prev[$key]['notified'] = $now;
        }
    }
}
foreach (array_keys($prev) as $key) {
    if (!isset($problems[$key])) {
        $lines[] = '✅ Pulih: ' . (ServerHealth::LABELS[explode(':', $key)[0]] ?? $key);
        unset($prev[$key]);
    }
}
if ($lines) {
    telegram('<b>' . esc(ServerHealth::serverName()) . "</b>\n" . implode("\n", array_map('esc', $lines)));
}
file_put_contents($stateFile, json_encode(['problems' => $prev, 'checked' => $now]), LOCK_EX);
@chmod($stateFile, 0660); // portal (www-data, grup adf) ikut membaca

// ======================================================================

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES);
}

function billingLine(): ?string
{
    $b = ServerHealth::billing();
    if (!$b) return null;
    $txt = '💳 Tagihan VPS' . ($b['plan'] !== '' ? " ({$b['plan']})" : '') . ': ' . $b['due']->format('d M Y')
        . ($b['price'] > 0 ? ' · Rp ' . number_format($b['price'], 0, ',', '.') : '');
    if ($b['days'] === 0) return "🔴 {$txt} — JATUH TEMPO HARI INI. Bayar di dashboard Biznet.";
    if (in_array($b['days'], [1, 3, 7], true)) return "🟠 {$txt} — {$b['days']} hari lagi. Siapkan pembayaran.";
    return "{$txt} ({$b['days']} hari lagi)";
}

function dailyReport(array $f, array $problems): string
{
    $svc = [];
    foreach ($f['services'] as $unit => $s) $svc[] = ($s === 'active' ? '🟢 ' : '🔴 ') . ServerHealth::SERVICES[$unit];
    $l = [];
    $l[] = '☀️ <b>Laporan pagi · ' . esc(ServerHealth::serverName()) . '</b>';
    $l[] = esc(date('l, d M Y'));
    $l[] = '';
    $l[] = $problems ? '⚠️ <b>' . count($problems) . ' perlu perhatian</b>' : '✅ <b>Semua normal</b>';
    foreach ($problems as $msg) $l[] = '• ' . esc($msg);
    $l[] = '';
    $l[] = "💾 Disk {$f['disk_pct']}% (sisa {$f['disk_free_gb']} GB)";
    $l[] = "🧠 RAM {$f['mem_pct']}% · swap {$f['swap_used_mb']} MB";
    $l[] = '⚙️ Beban ' . number_format($f['load'], 2) . " · aktif {$f['uptime_days']} hari";
    $l[] = implode('  ', $svc);
    $l[] = '🔒 SSL ' . ($f['ssl_days'] === null ? 'tidak terbaca' : "sisa {$f['ssl_days']} hari");
    if ($f['security_updates'] !== null) {
        $l[] = '🛡️ Update: ' . $f['updates'] . ' tersedia' . ($f['security_updates'] > 0 ? " ({$f['security_updates']} keamanan — biasanya terpasang otomatis)" : '');
    }
    $bill = billingLine();
    if ($bill) {
        $l[] = '';
        $l[] = esc($bill);
    }
    $l[] = '';
    $l[] = '📊 https://' . esc($f['site_host']) . '/monitor/';
    return implode("\n", $l);
}

function telegram(string $html): bool
{
    $token = Secrets::get('TELEGRAM_BOT_TOKEN');
    $chat = Secrets::get('TELEGRAM_CHAT_ID');
    if (!$token || !$chat) {
        fwrite(STDERR, "TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID belum diisi di " . ADF_SECRETS . "/telegram.env\n");
        return false;
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    return !empty($res['ok']);
}

/** Setelah kamu mengirim /start ke bot: ambil chat id dari pesan terakhir dan simpan ke telegram.env. */
function findChat(): void
{
    $token = Secrets::get('TELEGRAM_BOT_TOKEN');
    if (!$token) {
        echo "Isi dulu TELEGRAM_BOT_TOKEN di " . ADF_SECRETS . "/telegram.env\n";
        return;
    }
    $res = json_decode((string)@file_get_contents("https://api.telegram.org/bot{$token}/getUpdates"), true);
    $chat = null;
    foreach (array_reverse($res['result'] ?? []) as $u) {
        if ($c = $u['message']['chat'] ?? null) {
            $chat = $c;
            break;
        }
    }
    if (!$chat) {
        echo "Belum ada pesan. Buka bot-mu di Telegram, kirim /start, lalu jalankan lagi.\n";
        return;
    }
    writeSecret('telegram.env', 'TELEGRAM_CHAT_ID', (string)$chat['id']);
    echo 'Chat ditemukan: ' . ($chat['first_name'] ?? $chat['title'] ?? '') . ' (id ' . $chat['id'] . ") — tersimpan di telegram.env\n";
}

/** Login portal monitor: username + password (disimpan sebagai hash, tidak pernah teks asli). */
function setPortalPassword(): void
{
    echo 'Username portal [admin]: ';
    $user = trim((string)fgets(STDIN)) ?: 'admin';
    if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $user)) {
        echo "Username hanya huruf/angka/titik/minus, 3–32 karakter.\n";
        return;
    }
    $read = function (string $label): string {
        echo $label;
        @shell_exec('stty -echo');
        $v = trim((string)fgets(STDIN));
        @shell_exec('stty echo');
        echo PHP_EOL;
        return $v;
    };
    $pass = $read('Password baru (min. 10 karakter, tidak tampil): ');
    if (strlen($pass) < 10) {
        echo "Terlalu pendek — minimal 10 karakter.\n";
        return;
    }
    if ($read('Ulangi password: ') !== $pass) {
        echo "Tidak sama — ulangi perintahnya.\n";
        return;
    }
    writeSecret('portal.env', 'MONITOR_USER', $user);
    writeSecret('portal.env', 'MONITOR_PASS_HASH', password_hash($pass, PASSWORD_DEFAULT));
    echo "Login portal tersimpan. Buka https://" . Secrets::get('SITE_HOST', 'vps.adfsystem.online') . "/monitor/\n";
}

/** Tulis/ganti satu KUNCI=nilai di file rahasia (mode 640, grup adf). */
function writeSecret(string $file, string $key, string $value): void
{
    $path = ADF_SECRETS . '/' . $file;
    $lines = array_filter(@file($path, FILE_IGNORE_NEW_LINES) ?: [], fn ($l) => trim($l) !== '' && !str_starts_with(trim($l), $key . '='));
    $lines[] = $key . '=' . $value;
    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    @chmod($path, 0640);
}
