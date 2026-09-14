<?php

/**
 * Minimal Mayar.id Headless API v2 client.
 * Docs: https://docs.mayar.id/api-reference-v2/introduction
 */

class MayarClient
{
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = (defined('MAYAR_MODE') && MAYAR_MODE === 'production')
            ? 'https://api.mayar.id/hl/v2'
            : 'https://api.mayar.io/hl/v2';
    }

    /**
     * Create an invoice. $params: name, email, mobile, items[]({quantity,rate,description}),
     * description, expiredAt (ISO8601 UTC string), extraData (array, optional).
     */
    public function createInvoice(array $params)
    {
        return $this->request('POST', '/invoices/create', $params);
    }

    public function getInvoiceDetail($invoiceId)
    {
        return $this->request('GET', '/invoices/' . urlencode($invoiceId));
    }

    private function request($method, $path, array $payload = [])
    {
        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . MAYAR_API_KEY,
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
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
            return ['statusCode' => 0, 'messages' => 'cURL error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['statusCode' => $httpCode, 'messages' => 'Invalid response', 'raw' => $response];
        }

        return $decoded;
    }
}
