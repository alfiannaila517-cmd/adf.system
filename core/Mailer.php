<?php

declare(strict_types=1);

/**
 * Kirim email lewat SMTP tanpa library tambahan. Pengaturan di secrets/smtp.env:
 *   SMTP_HOST=mail.domainmu.com  SMTP_PORT=465  SMTP_ENCRYPTION=ssl|tls  SMTP_USER=…  SMTP_PASS=…  SMTP_FROM_NAME=ADF Console
 *
 *   Mailer::send('a@b.com', 'Judul', '<p>Isi</p>') → true/false; alasan gagal di Mailer::lastError()
 */
final class Mailer
{
    private static string $lastError = '';

    public static function configured(): bool
    {
        return Secrets::get('SMTP_HOST') !== null && Secrets::get('SMTP_USER') !== null && Secrets::get('SMTP_PASS') !== null;
    }

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function send(string $to, string $subject, string $html, ?string $fromName = null): bool
    {
        self::$lastError = '';
        if (!self::configured()) {
            self::$lastError = 'SMTP belum diatur (secrets/smtp.env)';
            return false;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Alamat email tidak valid';
            return false;
        }
        $host = (string)Secrets::get('SMTP_HOST');
        $port = (int)Secrets::get('SMTP_PORT', '465');
        $enc = Secrets::get('SMTP_ENCRYPTION', $port === 465 ? 'ssl' : 'tls');
        $user = (string)Secrets::get('SMTP_USER');
        $fromName ??= Secrets::get('SMTP_FROM_NAME', 'ADF Platform');
        try {
            $sock = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $errno, $errstr, 15);
            if (!$sock) {
                throw new RuntimeException("Tidak bisa terhubung ke {$host}:{$port} ({$errstr})");
            }
            stream_set_timeout($sock, 15);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            self::expect($sock, [220]);
            self::cmd($sock, $ehlo, [250]);
            if ($enc === 'tls') {
                self::cmd($sock, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Gagal mengaktifkan TLS');
                }
                self::cmd($sock, $ehlo, [250]);
            }
            self::cmd($sock, 'AUTH LOGIN', [334]);
            self::cmd($sock, base64_encode($user), [334]);
            self::cmd($sock, base64_encode((string)Secrets::get('SMTP_PASS')), [235]);
            self::cmd($sock, "MAIL FROM:<{$user}>", [250]);
            self::cmd($sock, "RCPT TO:<{$to}>", [250, 251]);
            self::cmd($sock, 'DATA', [354]);
            $domain = substr((string)strrchr($user, '@'), 1) ?: 'localhost';
            $headers = [
                'From: ' . self::header($fromName) . " <{$user}>",
                "To: <{$to}>",
                'Subject: ' . self::header($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$domain}>",
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $body = (string)preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $html)));
            fwrite($sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
            self::expect($sock, [250]);
            fwrite($sock, "QUIT\r\n");
            fclose($sock);
            return true;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('Mailer: ' . $e->getMessage());
            return false;
        }
    }

    private static function header(string $v): string
    {
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    /** @param resource $sock */
    private static function cmd($sock, string $line, array $codes): void
    {
        fwrite($sock, $line . "\r\n");
        self::expect($sock, $codes);
    }

    /** @param resource $sock */
    private static function expect($sock, array $codes): void
    {
        $resp = '';
        while (($line = fgets($sock, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if (!in_array((int)substr($resp, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP: ' . trim($resp));
        }
    }
}
