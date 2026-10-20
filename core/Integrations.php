<?php

declare(strict_types=1);

/**
 * Katalog integrasi pihak ketiga per bisnis + tes koneksi (hanya membaca; tidak mengirim pesan / mengubah data di layanan luar).
 *
 * Tiap field: [label, jenis text|secret|textarea|select|number, tujuan di sistem lama, petunjuk/pilihan]
 * "Tujuan di sistem lama" = tempat nilai itu ditulis saat bisnis dipindah dari adfsystem.online (database bisnis):
 *   settings:<setting_key>                       tabel settings
 *   settings:<setting_key>|enc-cloudbeds         dienkripsi ulang dengan format lama CloudbedsClient (enc: + AES-256-CBC, kunci DB_PASS|adf-cloudbeds)
 *   settings:<setting_key>|enc-email             format lama EmailHelper (AES-256-CBC, kunci DB_PASS|adf-email-narayana)
 *   payroll_attendance_config.<kolom>            baris id = 1
 * Status "aktif" ikut ditulis ke 'enabled_target' bila sistem lama punya saklarnya.
 */
final class Integrations
{
    public const DEFS = [
        'whatsapp' => [
            'label' => 'WhatsApp (Fonnte)',
            'desc' => 'Kirim pesan otomatis: welcome tamu, laporan harian, notifikasi.',
            'feature' => 'wa_notifications',
            'types' => null,
            'enabled_target' => null,
            'fields' => [
                'token' => ['Token perangkat Fonnte', 'secret', 'settings:wa_token', 'Dari dashboard fonnte.com → Device → Token'],
                'report_targets' => ['Nomor / grup laporan', 'textarea', 'settings:wa_report_targets', 'Satu per baris: 0812… atau ID grup …@g.us'],
            ],
        ],
        'cloudbeds' => [
            'label' => 'Cloudbeds',
            'desc' => 'Sinkron reservasi & ketersediaan kamar dengan OTA lewat Cloudbeds.',
            'feature' => 'cloudbeds',
            'types' => ['hotel'],
            'enabled_target' => null,
            'fields' => [
                'api_key' => ['API key (cbat_…)', 'secret', 'settings:cloudbeds_api_key|enc-cloudbeds', 'Cloudbeds → Account → API Credentials'],
                'property_id' => ['Property ID', 'text', 'settings:cloudbeds_property_id', 'Terisi otomatis saat tes koneksi berhasil'],
                'api_base' => ['Alamat API', 'text', 'settings:cloudbeds_api_base', 'Kosongkan = https://api.cloudbeds.com/api/v1.2'],
            ],
        ],
        'fingerprint' => [
            'label' => 'Mesin absensi (Fingerspot.io)',
            'desc' => 'Tarik data absen dari mesin fingerprint lewat cloud Fingerspot.',
            'feature' => 'fingerprint',
            'types' => ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor', 'other'],
            'enabled_target' => 'payroll_attendance_config.fingerspot_enabled',
            'fields' => [
                'cloud_id' => ['Cloud ID mesin', 'text', 'payroll_attendance_config.fingerspot_cloud_id', 'Tertera di developer.fingerspot.io'],
                'token' => ['API token', 'secret', 'payroll_attendance_config.fingerspot_token', 'Bearer token dari developer.fingerspot.io'],
            ],
        ],
        'email' => [
            'label' => 'Email bisnis (IMAP / SMTP)',
            'desc' => 'Kotak masuk & kirim email dari dalam sistem (mis. reservasi@…).',
            'feature' => null,
            'types' => ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor', 'other'],
            'enabled_target' => null,
            'fields' => [
                'user' => ['Alamat email', 'text', 'settings:email_imap_user', 'mis. reservasi@narayanakarimunjawa.com'],
                'pass' => ['Password email', 'secret', 'settings:email_imap_pass|enc-email', ''],
                'imap_host' => ['Server IMAP', 'text', 'settings:email_imap_host', 'mis. mail.domain.com'],
                'imap_port' => ['Port IMAP', 'number', 'settings:email_imap_port', '993'],
                'imap_encryption' => ['Enkripsi IMAP', 'select', 'settings:email_imap_encryption', ['ssl' => 'SSL', 'tls' => 'TLS']],
                'smtp_host' => ['Server SMTP', 'text', 'settings:email_imap_smtp_host', 'Kosong = sama dengan IMAP'],
                'smtp_port' => ['Port SMTP', 'number', 'settings:email_imap_smtp_port', '465'],
                'smtp_encryption' => ['Enkripsi SMTP', 'select', 'settings:email_imap_smtp_encryption', ['ssl' => 'SSL', 'tls' => 'TLS (STARTTLS)']],
            ],
        ],
        'openai' => [
            'label' => 'OpenAI (asisten AI)',
            'desc' => 'Fitur AI di sistem hotel (ringkasan, balasan otomatis).',
            'feature' => null,
            'types' => ['hotel'],
            'enabled_target' => 'settings:openai_active',
            'fields' => [
                'api_key' => ['API key', 'secret', 'settings:openai_api_key', 'platform.openai.com → API keys'],
                'model' => ['Model', 'text', 'settings:openai_model', 'mis. gpt-4o-mini'],
            ],
        ],
    ];

    public static function available(string $code, string $type): bool
    {
        if (!isset(self::DEFS[$code])) {
            return false;
        }
        $t = self::DEFS[$code]['types']; // null = semua jenis
        return $t === null || in_array($type, $t, true);
    }

    /** Field rahasia (disimpan terenkripsi, tidak pernah ditampilkan ulang). */
    public static function secretFields(string $code): array
    {
        return array_keys(array_filter(self::DEFS[$code]['fields'] ?? [], fn ($f) => $f[1] === 'secret'));
    }

    /**
     * Tes koneksi. $v = nilai lengkap (rahasia sudah dibuka).
     * @return array{ok:bool, msg:string, auto?:array<string,string>}  auto = nilai yang bisa diisi otomatis (mis. property_id)
     */
    public static function test(string $code, array $v): array
    {
        try {
            return match ($code) {
                'whatsapp' => self::testWhatsapp($v),
                'cloudbeds' => self::testCloudbeds($v),
                'fingerprint' => self::testFingerprint($v),
                'email' => self::testSmtp($v),
                'openai' => self::testOpenai($v),
                default => ['ok' => false, 'msg' => 'Tes belum tersedia'],
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /* ---------------- tes per layanan ---------------- */

    /** Status perangkat (POST /device) — sama dengan WhatsAppHelper::deviceStatus di sistem lama. */
    private static function testWhatsapp(array $v): array
    {
        $token = trim((string)($v['token'] ?? ''), " \t\n\r\0\x0B\"'");
        if ($token === '') {
            return ['ok' => false, 'msg' => 'Token belum diisi'];
        }
        [$code, $json] = self::http('POST', 'https://api.fonnte.com/device', ['Authorization: ' . $token], []);
        if (empty($json['status'])) {
            return ['ok' => false, 'msg' => (string)($json['reason'] ?? $json['detail'] ?? "Ditolak (HTTP {$code})")];
        }
        $state = strtolower((string)($json['device_status'] ?? ''));
        $connected = in_array($state, ['connect', 'connected', 'online', 'open', 'ready'], true);
        $info = array_filter([$json['name'] ?? null, isset($json['quota']) ? 'kuota ' . $json['quota'] : null, isset($json['expired']) ? 'aktif s/d ' . $json['expired'] : null]);
        return ['ok' => $connected, 'msg' => ($connected ? 'Perangkat tersambung' : "Token benar, tapi perangkat {$state} — scan ulang QR di Fonnte") . ($info ? ' · ' . implode(' · ', $info) : '')];
    }

    /** getHotels — panggilan baca paling ringan; property ID diisi otomatis. */
    private static function testCloudbeds(array $v): array
    {
        $key = trim((string)($v['api_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'msg' => 'API key belum diisi'];
        }
        $base = rtrim(trim((string)($v['api_base'] ?? '')) ?: 'https://api.cloudbeds.com/api/v1.2', '/');
        [$code, $json] = self::http('GET', $base . '/getHotels', ['x-api-key: ' . $key]);
        if (empty($json['success'])) {
            return ['ok' => false, 'msg' => (string)($json['message'] ?? "Ditolak (HTTP {$code})")];
        }
        $list = array_values((array)($json['data'] ?? []));
        $prop = $list[0] ?? [];
        $pid = (string)($prop['propertyID'] ?? '');
        return ['ok' => $pid !== '', 'msg' => $pid !== '' ? 'Tersambung · ' . ($prop['propertyName'] ?? 'properti') . " (ID {$pid})" . (count($list) > 1 ? ' · ' . count($list) . ' properti' : '') : 'Tersambung tapi tidak ada properti', 'auto' => $pid !== '' ? ['property_id' => $pid] : []];
    }

    /** get_attlog hari ini (rentang 1 hari) — membaca log saja; menguji cloud ID + token sekaligus. */
    private static function testFingerprint(array $v): array
    {
        $cloud = trim((string)($v['cloud_id'] ?? ''));
        $token = trim((string)($v['token'] ?? ''));
        if ($cloud === '' || $token === '') {
            return ['ok' => false, 'msg' => 'Cloud ID dan token wajib diisi'];
        }
        $today = date('Y-m-d');
        [$code, $json] = self::http('POST', 'https://developer.fingerspot.io/api/get_attlog', ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            json_encode(['trans_id' => 'tes_' . bin2hex(random_bytes(4)), 'cloud_id' => $cloud, 'start_date' => $today, 'end_date' => $today]));
        if (($json['success'] ?? null) === true) {
            return ['ok' => true, 'msg' => 'Tersambung · ' . count((array)($json['data'] ?? [])) . ' absen hari ini'];
        }
        return ['ok' => false, 'msg' => (string)($json['message'] ?? $json['error'] ?? "Ditolak (HTTP {$code})")];
    }

    /** SMTP: sambung → EHLO → (STARTTLS) → AUTH LOGIN → QUIT. Tidak mengirim email. */
    private static function testSmtp(array $v): array
    {
        $host = trim((string)($v['smtp_host'] ?? '')) ?: trim((string)($v['imap_host'] ?? ''));
        $port = (int)($v['smtp_port'] ?? 0) ?: 465;
        $enc = (string)($v['smtp_encryption'] ?? 'ssl');
        $user = trim((string)($v['user'] ?? ''));
        if ($host === '' || $user === '' || (string)($v['pass'] ?? '') === '') {
            return ['ok' => false, 'msg' => 'Server, alamat email, dan password wajib diisi'];
        }
        $sock = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $errno, $errstr, 12);
        if (!$sock) {
            return ['ok' => false, 'msg' => "Tidak bisa tersambung ke {$host}:{$port} — {$errstr}"];
        }
        stream_set_timeout($sock, 12);
        $say = function (?string $cmd, int $expect) use ($sock): string {
            if ($cmd !== null) {
                fwrite($sock, $cmd . "\r\n");
            }
            $resp = '';
            while (($line = fgets($sock, 515)) !== false) {
                $resp .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            if ((int)substr($resp, 0, 3) !== $expect) {
                throw new RuntimeException('Server SMTP menolak: ' . trim(mb_substr($resp, 0, 160)));
            }
            return $resp;
        };
        try {
            $say(null, 220);
            $say('EHLO adf-console', 250);
            if ($enc === 'tls') {
                $say('STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Gagal mengaktifkan TLS');
                }
                $say('EHLO adf-console', 250);
            }
            $say('AUTH LOGIN', 334);
            $say(base64_encode($user), 334);
            $say(base64_encode((string)$v['pass']), 235);
            fwrite($sock, "QUIT\r\n");
            return ['ok' => true, 'msg' => "Login SMTP berhasil ({$host}:{$port})"];
        } finally {
            fclose($sock);
        }
    }

    private static function testOpenai(array $v): array
    {
        $key = trim((string)($v['api_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'msg' => 'API key belum diisi'];
        }
        [$code, $json] = self::http('GET', 'https://api.openai.com/v1/models', ['Authorization: Bearer ' . $key]);
        if ($code === 200 && isset($json['data'])) {
            return ['ok' => true, 'msg' => 'Tersambung · ' . count((array)$json['data']) . ' model tersedia'];
        }
        return ['ok' => false, 'msg' => (string)($json['error']['message'] ?? "Ditolak (HTTP {$code})")];
    }

    /** @return array{0:int,1:array} kode HTTP + JSON (array kosong bila bukan JSON) */
    private static function http(string $method, string $url, array $headers, array|string|null $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi PHP cURL tidak aktif di server');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('Tidak bisa menghubungi ' . parse_url($url, PHP_URL_HOST) . ': ' . $err);
        }
        $json = json_decode((string)$resp, true);
        return [$code, is_array($json) ? $json : []];
    }
}
