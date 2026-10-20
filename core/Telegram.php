<?php

declare(strict_types=1);

/**
 * Kirim pesan ke Telegram pemilik platform (bot & chat di secrets/telegram.env: TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID).
 * Teks memakai format HTML Telegram (<b>, <i>, <code>); isi dari luar harus di-escape dengan Telegram::esc().
 */
final class Telegram
{
    public static function configured(): bool
    {
        return Secrets::get('TELEGRAM_BOT_TOKEN') !== null && Secrets::get('TELEGRAM_CHAT_ID') !== null;
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES);
    }

    public static function send(string $html): bool
    {
        if (!self::configured()) {
            return false;
        }
        $ch = curl_init('https://api.telegram.org/bot' . Secrets::get('TELEGRAM_BOT_TOKEN') . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['chat_id' => Secrets::get('TELEGRAM_CHAT_ID'), 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        return !empty($res['ok']);
    }

    /** Peringatan keamanan ringkas: ikon + judul + pasangan label/nilai. */
    public static function security(string $icon, string $title, array $fields = []): void
    {
        $lines = ["{$icon} <b>" . self::esc($title) . '</b>'];
        foreach ($fields + ['IP' => (string)($_SERVER['REMOTE_ADDR'] ?? '-'), 'Waktu' => date('d M Y H:i:s')] as $k => $v) {
            $lines[] = self::esc((string)$k) . ': ' . self::esc((string)$v);
        }
        self::send(implode("\n", $lines));
    }
}
