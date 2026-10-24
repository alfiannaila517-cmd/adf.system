<?php

declare(strict_types=1);

/**
 * Mayar.id Headless API v2 (https://docs.mayar.id/api-reference-v2/introduction).
 *   MAYAR_API_KEY  (Payment Gateway di Console, terenkripsi — SecureSettings) key "Read & Write"
 *   MAYAR_MODE     production (bawaan) | sandbox
 * Webhook Mayar tidak bertanda tangan → status selalu dicek ulang lewat invoice() sebelum menandai lunas (lihat Pay).
 */
final class Mayar
{
    public static function configured(): bool
    {
        return (string)Secrets::get('MAYAR_API_KEY', '') !== '';
    }

    public static function sandbox(): bool
    {
        return Secrets::get('MAYAR_MODE', 'production') === 'sandbox';
    }

    private static function base(): string
    {
        return self::sandbox() ? 'https://api.mayar.io/hl/v2' : 'https://api.mayar.id/hl/v2';
    }

    /** @return array jawaban JSON Mayar (statusCode, messages, data) */
    private static function request(string $method, string $path, array $body = []): array
    {
        if (!self::configured()) {
            throw new RuntimeException('Mayar belum diatur (Pengaturan → Payment Gateway).');
        }
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . Secrets::get('MAYAR_API_KEY'), 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ] + ($method === 'POST' ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Tidak bisa terhubung ke Mayar: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json) || $code < 200 || $code >= 300 || (int)($json['statusCode'] ?? 200) >= 300) {
            $msg = is_array($json) ? (is_array($json['messages'] ?? null) ? implode(', ', $json['messages']) : (string)($json['messages'] ?? '')) : '';
            // Isi balasan dicatat (log PHP) supaya penyebab penolakan bisa dilihat; potongan pendek ikut di pesan
            error_log('Mayar ' . $method . ' ' . $path . ' HTTP ' . $code . ': ' . substr((string)$raw, 0, 800));
            if ($msg === '') {
                $msg = trim(substr(strip_tags((string)$raw), 0, 160));
            }
            throw new RuntimeException('Mayar menolak (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . $msg : ''));
        }
        return $json;
    }

    /**
     * Buat invoice Mayar (satu item). @return array{id:string, transactionId:string, link:string, expiredAt:?int}
     */
    public static function createInvoice(string $name, string $email, string $mobile, string $description, int $amount, int $expiresAt, array $extra = []): array
    {
        $mobile = preg_replace('/\D/', '', $mobile) ?: '081234567890';
        if (str_starts_with($mobile, '62')) {
            $mobile = '0' . substr($mobile, 2);
        }
        $res = self::request('POST', '/invoices/create', [
            'name' => mb_substr($name, 0, 100) ?: 'Pelanggan ADF',
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'billing@adfsystem.store',
            'mobile' => $mobile,
            'description' => mb_substr($description, 0, 250),
            'expiredAt' => gmdate('Y-m-d\TH:i:s.000\Z', $expiresAt),
            'items' => [['quantity' => 1, 'rate' => $amount, 'description' => mb_substr($description, 0, 250)]],
            'extraData' => (object)$extra,
        ]);
        $d = $res['data'] ?? [];
        if (empty($d['id']) || empty($d['link'])) {
            throw new RuntimeException('Jawaban Mayar tidak berisi link pembayaran.');
        }
        return ['id' => (string)$d['id'], 'transactionId' => (string)($d['transactionId'] ?? ''), 'link' => (string)$d['link'], 'expiredAt' => isset($d['expiredAt']) ? (int)$d['expiredAt'] : null];
    }

    /** Detail invoice Mayar: status (paid / unpaid / closed), amount, transactionId */
    public static function invoice(string $id): array
    {
        return (array)(self::request('GET', '/invoices/' . rawurlencode($id))['data'] ?? []);
    }

    /** Daftarkan alamat webhook akun Mayar ini */
    public static function registerWebhook(string $url): void
    {
        self::request('POST', '/webhooks/update', ['urlHook' => $url]);
    }

    /** Tes kunci: ambil daftar invoice (1 baris) */
    public static function ping(): void
    {
        self::request('GET', '/invoices?page=1&pageSize=1');
    }
}
