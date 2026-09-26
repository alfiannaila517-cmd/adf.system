<?php
/**
 * Minimal client for the Pakasir payment gateway (API v2).
 * Docs: https://pakasir.com/p/docs
 */

require_once __DIR__ . '/content-store.php';

$GLOBALS['adf_pakasir_last_error'] = '';

function adf_pakasir_last_error(): string
{
    return (string) ($GLOBALS['adf_pakasir_last_error'] ?? '');
}

function adf_pakasir_set_last_error(string $message): void
{
    $GLOBALS['adf_pakasir_last_error'] = $message;
}

function adf_pakasir_config(): array
{
    $content = adf_load_content();
    return $content['payment'] ?? [];
}

function adf_pakasir_is_configured(): bool
{
    $cfg = adf_pakasir_config();
    return !empty($cfg['project_slug']) && !empty($cfg['api_key']);
}

/**
 * Create (or find-existing) a Pakasir transaction and return a hosted payment link.
 * Returns ['txn_id' => ..., 'payment_link' => ...] on success, or null on failure.
 */
function adf_pakasir_create_payment_link(string $orderId, int $amount): ?array
{
    $cfg = adf_pakasir_config();
    if (empty($cfg['project_slug']) || empty($cfg['api_key'])) {
        adf_pakasir_set_last_error('Slug proyek atau API Key Pakasir belum diisi.');
        return null;
    }

    $slug = rawurlencode($cfg['project_slug']);
    $orderIdEncoded = rawurlencode($orderId);
    $url = "https://app.pakasir.com/api/v2/create-transaction/{$slug}/{$orderIdEncoded}";

    $body = json_encode([
        'method' => 'payment_link',
        'amount' => $amount,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Api-Key: ' . $cfg['api_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        $message = $curlError !== '' ? $curlError : 'Pakasir menolak permintaan (' . $httpCode . ').';
        if (is_string($response)) {
            $payload = json_decode($response, true);
            if (is_array($payload)) {
                $message = (string) ($payload['message'] ?? $payload['error'] ?? $message);
            }
        }
        adf_pakasir_set_last_error($message);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['payment_link']) || empty($data['txn_id'])) {
        adf_pakasir_set_last_error('Respons Pakasir tidak berisi tautan pembayaran.');
        return null;
    }

    adf_pakasir_set_last_error('');
    return [
        'txn_id' => $data['txn_id'],
        'payment_link' => $data['payment_link'],
    ];
}

/**
 * Check a transaction's status via GET /api/v2/transaction-status/{slug}/{txn_id}.
 * Returns the decoded response array, or null on failure.
 */
function adf_pakasir_transaction_status(string $txnId): ?array
{
    $cfg = adf_pakasir_config();
    if (empty($cfg['project_slug']) || empty($cfg['api_key'])) {
        return null;
    }

    $slug = rawurlencode($cfg['project_slug']);
    $txnIdEncoded = rawurlencode($txnId);
    $url = "https://app.pakasir.com/api/v2/transaction-status/{$slug}/{$txnIdEncoded}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $cfg['api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}
