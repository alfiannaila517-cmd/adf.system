<?php

/**
 * Monitor server ADF → Telegram. Dijalankan cron sebagai user `adf` (lihat /etc/cron.d/adf-monitor):
 *   php cli/monitor.php              cek tiap 5 menit; kirim pesan HANYA saat ada masalah baru / pulih / masalah berlanjut tiap 6 jam
 *   php cli/monitor.php --daily      laporan pagi: ringkasan kondisi + pengingat tagihan VPS
 *   php cli/monitor.php --test       kirim pesan uji
 *   php cli/monitor.php --preview    tampilkan laporan pagi di layar (tidak dikirim)
 *   php cli/monitor.php --find-chat  cari chat id Telegram setelah kamu mengirim /start ke bot, lalu simpan ke secrets
 *
 * Rahasia & pengaturan di /srv/adf/secrets (tidak di git):
 *   telegram.env  TELEGRAM_BOT_TOKEN=…  TELEGRAM_CHAT_ID=…
 *   monitor.env   SERVER_NAME=VPS Biznet  SITE_HOST=vps.adfsystem.online
 *                 VPS_NEXT_DUE=2026-11-20  VPS_CYCLE=monthly|yearly  VPS_PRICE=198690  VPS_PLAN=NEO Lite MS 4.4
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

const DISK_WARN = 80;        // % terpakai
const MEM_WARN = 90;         // % terpakai (tanpa cache)
const SSL_WARN_DAYS = 14;
const REPEAT_HOURS = 6;      // masalah yang belum pulih diingatkan lagi setiap 6 jam
const PROBLEM_LABELS = ['disk' => 'Disk', 'mem' => 'RAM', 'load' => 'Beban CPU', 'svc' => 'Layanan', 'ssl' => 'Sertifikat SSL', 'reboot' => 'Perlu restart', 'web' => 'Website'];
const SERVICES = ['nginx' => 'Nginx (web)', 'php8.2-fpm' => 'PHP', 'mariadb' => 'MariaDB (database)', 'fail2ban' => 'fail2ban'];

$mode = $argv[1] ?? '';
$stateDir = ADF_STORAGE . '/_platform';
$stateFile = $stateDir . '/monitor-state.json';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0750, true);
}

if ($mode === '--find-chat') {
    findChat();
    exit;
}
if ($mode === '--test') {
    $ok = telegram("✅ <b>Monitor ADF tersambung</b>\n" . esc(serverName()) . ' · ' . date('d M Y H:i') . ' WIB');
    echo $ok ? "Pesan uji terkirim.\n" : "Gagal mengirim — cek TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID.\n";
    exit($ok ? 0 : 1);
}

$facts = collectFacts();
$problems = findProblems($facts);

if ($mode === '--preview') {
    echo strip_tags(dailyReport($facts, $problems)) . PHP_EOL;
    exit;
}
if ($mode === '--daily') {
    telegram(dailyReport($facts, $problems));
    exit;
}

// ---- Cek rutin: kirim hanya bila ada perubahan ----
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$prev = $state['problems'] ?? [];
$now = time();
$lines = [];
foreach ($problems as $key => $msg) {
    if (!isset($prev[$key])) {
        $lines[] = "⚠️ {$msg}";
        $prev[$key] = ['since' => $now, 'notified' => $now];
    } elseif ($now - (int)$prev[$key]['notified'] >= REPEAT_HOURS * 3600) {
        $lines[] = "⏳ Masih bermasalah sejak " . date('d M H:i', (int)$prev[$key]['since']) . ": {$msg}";
        $prev[$key]['notified'] = $now;
    }
}
foreach (array_keys($prev) as $key) {
    if (!isset($problems[$key])) {
        $lines[] = '✅ Pulih: ' . (PROBLEM_LABELS[explode(':', $key)[0]] ?? $key);
        unset($prev[$key]);
    }
}
if ($lines) {
    telegram('<b>' . esc(serverName()) . "</b>\n" . implode("\n", array_map('esc', $lines)));
}
file_put_contents($stateFile, json_encode(['problems' => $prev, 'checked' => $now]), LOCK_EX);

// ======================================================================


function serverName(): string
{
    return Secrets::get('SERVER_NAME', gethostname() ?: 'Server');
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES);
}

/** Kondisi server saat ini (tanpa butuh akses root). */
function collectFacts(): array
{
    $f = [];
    $total = (float)@disk_total_space('/');
    $free = (float)@disk_free_space('/');
    $f['disk_pct'] = $total > 0 ? round(($total - $free) / $total * 100) : 0;
    $f['disk_free_gb'] = round($free / 1073741824, 1);

    $mem = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (int)$m[2];
    }
    $memTotal = $mem['MemTotal'] ?? 0;
    $f['mem_pct'] = $memTotal > 0 ? round(($memTotal - ($mem['MemAvailable'] ?? 0)) / $memTotal * 100) : 0;
    $f['swap_used_mb'] = round((($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0)) / 1024);

    $f['load'] = function_exists('sys_getloadavg') ? (float)(sys_getloadavg()[1] ?? 0) : 0.0; // rata-rata 5 menit (Linux)
    $f['cpus'] = max(1, (int)trim((string)@shell_exec('nproc')));
    $f['uptime_days'] = round((float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0] / 86400, 1);

    $f['services'] = [];
    foreach (SERVICES as $unit => $label) {
        $f['services'][$unit] = trim((string)@shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) ?: 'unknown';
    }

    $f['reboot'] = is_file('/var/run/reboot-required');
    $apt = trim((string)@shell_exec('/usr/lib/update-notifier/apt-check 2>&1'));
    [$f['updates'], $f['security_updates']] = preg_match('/^(\d+);(\d+)$/', $apt, $m) ? [(int)$m[1], (int)$m[2]] : [null, null];

    $host = Secrets::get('SITE_HOST', 'vps.adfsystem.online');
    $f['site_host'] = $host;
    [$f['ssl_days'], $f['web_ok']] = sslAndWeb($host);
    return $f;
}

/** Sisa hari sertifikat + apakah website menjawab, dilihat dari dalam server lewat koneksi HTTPS biasa. */
function sslAndWeb(string $host): array
{
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $sock = @stream_socket_client("ssl://{$host}:443", $errno, $err, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return [null, false];
    }
    $cert = openssl_x509_parse(stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? '');
    $days = $cert ? (int)floor(($cert['validTo_time_t'] - time()) / 86400) : null;
    fwrite($sock, "HEAD / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
    $status = (string)fgets($sock);
    fclose($sock);
    return [$days, (bool)preg_match('#^HTTP/\S+ [23]\d\d#', $status)];
}

/** @return array<string,string> kunci-unik => pesan */
function findProblems(array $f): array
{
    $p = [];
    if ($f['disk_pct'] >= DISK_WARN) $p['disk'] = "Disk terpakai {$f['disk_pct']}% (sisa {$f['disk_free_gb']} GB)";
    if ($f['mem_pct'] >= MEM_WARN) $p['mem'] = "RAM terpakai {$f['mem_pct']}% (swap {$f['swap_used_mb']} MB)";
    if ($f['load'] > $f['cpus'] * 1.5) $p['load'] = 'Beban CPU tinggi: ' . number_format($f['load'], 2) . " ({$f['cpus']} core)";
    foreach ($f['services'] as $unit => $stateTxt) {
        if ($stateTxt !== 'active') $p["svc:{$unit}"] = SERVICES[$unit] . " tidak berjalan ({$stateTxt})";
    }
    if (!$f['web_ok']) $p['web'] = "Website {$f['site_host']} tidak menjawab";
    if ($f['ssl_days'] !== null && $f['ssl_days'] <= SSL_WARN_DAYS) $p['ssl'] = "Sertifikat SSL habis dalam {$f['ssl_days']} hari";
    if ($f['reboot']) $p['reboot'] = 'Server perlu di-restart setelah update (sudo reboot)';
    return $p;
}

/** Jatuh tempo VPS berikutnya: maju otomatis per siklus bila tanggal tersimpan sudah lewat (dianggap sudah dibayar). */
function nextDue(): ?DateTimeImmutable
{
    $raw = Secrets::get('VPS_NEXT_DUE');
    if (!$raw || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
    $due = new DateTimeImmutable($raw);
    $step = Secrets::get('VPS_CYCLE', 'monthly') === 'yearly' ? '+1 year' : '+1 month';
    $today = new DateTimeImmutable('today');
    while ($due < $today) $due = $due->modify($step);
    return $due;
}

function billingLine(): ?string
{
    $due = nextDue();
    if (!$due) return null;
    $days = (int)(new DateTimeImmutable('today'))->diff($due)->days;
    $price = (int)Secrets::get('VPS_PRICE', '0');
    $txt = '💳 Tagihan VPS' . (Secrets::get('VPS_PLAN') ? ' (' . Secrets::get('VPS_PLAN') . ')' : '') . ': ' . $due->format('d M Y')
        . ($price > 0 ? ' · Rp ' . number_format($price, 0, ',', '.') : '');
    if ($days === 0) return "🔴 {$txt} — JATUH TEMPO HARI INI. Bayar di dashboard Biznet.";
    if (in_array($days, [1, 3, 7], true)) return "🟠 {$txt} — {$days} hari lagi. Siapkan pembayaran.";
    return "{$txt} ({$days} hari lagi)";
}

function dailyReport(array $f, array $problems): string
{
    $svc = [];
    foreach ($f['services'] as $unit => $s) $svc[] = ($s === 'active' ? '🟢 ' : '🔴 ') . SERVICES[$unit];
    $l = [];
    $l[] = '☀️ <b>Laporan pagi · ' . esc(serverName()) . '</b>';
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
        $c = $u['message']['chat'] ?? null;
        if ($c) {
            $chat = $c;
            break;
        }
    }
    if (!$chat) {
        echo "Belum ada pesan. Buka bot-mu di Telegram, kirim /start, lalu jalankan lagi.\n";
        return;
    }
    $file = ADF_SECRETS . '/telegram.env';
    $lines = array_filter(@file($file, FILE_IGNORE_NEW_LINES) ?: [], fn ($l) => !str_starts_with(trim($l), 'TELEGRAM_CHAT_ID='));
    $lines[] = 'TELEGRAM_CHAT_ID=' . $chat['id'];
    file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
    echo 'Chat ditemukan: ' . ($chat['first_name'] ?? $chat['title'] ?? '') . ' (id ' . $chat['id'] . ") — tersimpan di telegram.env\n";
}
