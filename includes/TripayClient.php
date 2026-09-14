<?php
/**
 * Minimal Tripay API client (Closed Payment / transaction endpoint).
 * Docs: https://tripay.co.id/developer
 */

class TripayClient
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = (defined('TRIPAY_MODE') && TRIPAY_MODE === 'production')
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';
    }

    /**
     * Create a closed payment transaction (one payment_url, any channel picked by customer or fixed channel).
     * $params: merchant_ref, amount, customer_name, customer_email, customer_phone, order_items (array), method (channel code), expired_time (unix ts)
     */
    public function createTransaction(array $params)
    {
        $merchantCode = TRIPAY_MERCHANT_CODE;
        $signature = hash_hmac(
            'sha256',
            $merchantCode . $params['merchant_ref'] . $params['amount'],
            TRIPAY_PRIVATE_KEY
        );

        $payload = array_merge($params, [
            'method' => $params['method'] ?? TRIPAY_DEFAULT_CHANNEL,
            'merchant_code' => $merchantCode,
            'signature' => $signature,
            'callback_url' => TRIPAY_CALLBACK_URL,
        ]);

        return $this->request('POST', '/transaction/create', $payload);
    }

    public function getTransactionDetail($reference)
    {
        return $this->request('GET', '/transaction/detail?reference=' . urlencode($reference));
    }

    public function getPaymentChannels()
    {
        return $this->request('GET', '/merchant/payment-channel');
    }

    /**
     * Verify X-Callback-Signature header against the raw request body.
     */
    public function verifyCallbackSignature($rawBody, $signatureHeader)
    {
        $expected = hash_hmac('sha256', $rawBody, TRIPAY_PRIVATE_KEY);
        return hash_equals($expected, (string)$signatureHeader);
    }

    private function request($method, $path, array $payload = [])
    {
        $ch = curl_init();
        $headers = ['Authorization: Bearer ' . TRIPAY_API_KEY];

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        } else {
            curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'cURL error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Invalid response (HTTP ' . $httpCode . ')', 'raw' => $response];
        }

        return $decoded;
    }
}
