<?php

declare(strict_types=1);

/**
 * Kondisi server (tanpa butuh akses root) — dipakai bersama oleh cli/monitor.php (Telegram) dan public/monitor/ (portal).
 * Pengaturan di secrets/monitor.env: SERVER_NAME, SITE_HOST, VPS_NEXT_DUE, VPS_CYCLE, VPS_PRICE, VPS_PLAN.
 */
final class ServerHealth
{
    public const DISK_WARN = 80;       // % terpakai
    public const MEM_WARN = 90;        // % terpakai (tanpa cache)
    public const SSL_WARN_DAYS = 14;
    public const SERVICES = ['nginx' => 'Nginx (web)', 'php8.2-fpm' => 'PHP', 'mariadb' => 'MariaDB (database)', 'fail2ban' => 'fail2ban'];
    public const LABELS = ['disk' => 'Disk', 'mem' => 'RAM', 'load' => 'Beban CPU', 'svc' => 'Layanan', 'ssl' => 'Sertifikat SSL', 'reboot' => 'Perlu restart', 'web' => 'Website'];

    public static function serverName(): string
    {
        return Secrets::get('SERVER_NAME', gethostname() ?: 'Server');
    }

    /** File status hasil cek otomatis terakhir (ditulis cron monitor). */
    public static function stateFile(): string
    {
        return ADF_STORAGE . '/_platform/monitor-state.json';
    }

    /** @return array{problems?:array, checked?:int} */
    public static function lastState(): array
    {
        $file = self::stateFile();
        return is_readable($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    }

    /** Kondisi server saat ini. */
    public static function facts(): array
    {
        $f = [];
        $total = (float)@disk_total_space('/');
        $free = (float)@disk_free_space('/');
        $f['disk_pct'] = $total > 0 ? (int)round(($total - $free) / $total * 100) : 0;
        $f['disk_free_gb'] = round($free / 1073741824, 1);
        $f['disk_total_gb'] = round($total / 1073741824, 1);

        $mem = [];
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (int)$m[2];
        }
        $memTotal = $mem['MemTotal'] ?? 0;
        $f['mem_pct'] = $memTotal > 0 ? (int)round(($memTotal - ($mem['MemAvailable'] ?? 0)) / $memTotal * 100) : 0;
        $f['mem_total_gb'] = round($memTotal / 1048576, 1);
        $f['swap_used_mb'] = (int)round((($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0)) / 1024);

        $f['load'] = function_exists('sys_getloadavg') ? (float)(sys_getloadavg()[1] ?? 0) : 0.0; // rata-rata 5 menit (Linux)
        $f['cpus'] = max(1, (int)trim((string)@shell_exec('nproc')));
        $f['uptime_days'] = round((float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0] / 86400, 1);

        $f['services'] = [];
        foreach (self::SERVICES as $unit => $label) {
            $f['services'][$unit] = trim((string)@shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null')) ?: 'unknown';
        }

        $f['reboot'] = is_file('/var/run/reboot-required');
        $apt = trim((string)@shell_exec('/usr/lib/update-notifier/apt-check 2>&1'));
        [$f['updates'], $f['security_updates']] = preg_match('/^(\d+);(\d+)$/', $apt, $m) ? [(int)$m[1], (int)$m[2]] : [null, null];

        $f['site_host'] = Secrets::get('SITE_HOST', 'vps.adfsystem.online');
        [$f['ssl_days'], $f['web_ok']] = self::sslAndWeb($f['site_host']);
        return $f;
    }

    /** @return array<string,string> kunci-unik => pesan */
    public static function problems(array $f): array
    {
        $p = [];
        if ($f['disk_pct'] >= self::DISK_WARN) $p['disk'] = "Disk terpakai {$f['disk_pct']}% (sisa {$f['disk_free_gb']} GB)";
        if ($f['mem_pct'] >= self::MEM_WARN) $p['mem'] = "RAM terpakai {$f['mem_pct']}% (swap {$f['swap_used_mb']} MB)";
        if ($f['load'] > $f['cpus'] * 1.5) $p['load'] = 'Beban CPU tinggi: ' . number_format($f['load'], 2) . " ({$f['cpus']} core)";
        foreach ($f['services'] as $unit => $state) {
            if ($state !== 'active') $p["svc:{$unit}"] = self::SERVICES[$unit] . " tidak berjalan ({$state})";
        }
        if (!$f['web_ok']) $p['web'] = "Website {$f['site_host']} tidak menjawab";
        if ($f['ssl_days'] !== null && $f['ssl_days'] <= self::SSL_WARN_DAYS) $p['ssl'] = "Sertifikat SSL habis dalam {$f['ssl_days']} hari";
        if ($f['reboot']) $p['reboot'] = 'Server perlu di-restart setelah update (sudo reboot)';
        return $p;
    }

    /**
     * Tagihan VPS berikutnya. Tanggal di VPS_NEXT_DUE maju otomatis per siklus bila sudah lewat (dianggap sudah dibayar).
     * @return array{due:DateTimeImmutable, days:int, price:int, plan:string, cycle:string, level:string}|null level: ok|soon|today
     */
    public static function billing(): ?array
    {
        $raw = Secrets::get('VPS_NEXT_DUE');
        if (!$raw || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
        $cycle = Secrets::get('VPS_CYCLE', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $due = new DateTimeImmutable($raw);
        $today = new DateTimeImmutable('today');
        while ($due < $today) $due = $due->modify($cycle === 'yearly' ? '+1 year' : '+1 month');
        $days = (int)$today->diff($due)->days;
        return [
            'due' => $due, 'days' => $days, 'price' => (int)Secrets::get('VPS_PRICE', '0'), 'plan' => (string)Secrets::get('VPS_PLAN', ''),
            'cycle' => $cycle, 'level' => $days === 0 ? 'today' : ($days <= 7 ? 'soon' : 'ok'),
        ];
    }

    /** Sisa hari sertifikat + apakah website menjawab, lewat koneksi HTTPS biasa dari dalam server. */
    private static function sslAndWeb(string $host): array
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
}
