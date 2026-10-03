<?php

/**
 * Simple JSON-backed store for subscription/checkout orders (Pakasir payments).
 */

function adf_orders_path(): string
{
    return __DIR__ . '/../data/orders.json';
}

function adf_orders_load(): array
{
    $path = adf_orders_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function adf_orders_save(array $orders): bool
{
    $path = adf_orders_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function adf_orders_add(array $order): bool
{
    $orders = adf_orders_load();
    $orders[] = $order;
    return adf_orders_save($orders);
}

function adf_orders_update_status(string $orderId, string $status, ?string $completedAt = null): bool
{
    $orders = adf_orders_load();
    $found = false;
    foreach ($orders as &$order) {
        if (($order['order_id'] ?? '') === $orderId) {
            $order['status'] = $status;
            if ($completedAt !== null) {
                $order['completed_at'] = $completedAt;
            }
            $found = true;
            break;
        }
    }
    unset($order);
    if (!$found) {
        return false;
    }
    return adf_orders_save($orders);
}

/**
 * Catat pembayaran yang sudah berhasil dari sumber mana pun (webhook Pakasir untuk order yang
 * tidak lewat checkout, atau notifikasi lunas tagihan klien langganan) supaya halaman Transaksi
 * menampilkan SEMUA uang yang benar-benar masuk. Kalau order_id sudah ada, statusnya diperbarui saja.
 */
function adf_orders_record_completed(array $order): bool
{
    $orderId = (string) ($order['order_id'] ?? '');
    if ($orderId === '') {
        return false;
    }
    $orders = adf_orders_load();
    foreach ($orders as &$existing) {
        if (($existing['order_id'] ?? '') === $orderId) {
            $existing['status'] = 'completed';
            $existing['completed_at'] = $order['completed_at'] ?? ($existing['completed_at'] ?? date('c'));
            if (!empty($order['payment_method'])) {
                $existing['payment_method'] = $order['payment_method'];
            }
            unset($existing);
            return adf_orders_save($orders);
        }
    }
    unset($existing);

    $orders[] = array_merge([
        'order_id' => $orderId,
        'txn_id' => '',
        'product_title' => '-',
        'amount' => 0,
        'name' => '-',
        'email' => '',
        'whatsapp' => '',
        'status' => 'completed',
        'source' => 'pakasir',
        'created_at' => $order['completed_at'] ?? date('c'),
        'completed_at' => date('c'),
    ], $order, ['status' => 'completed']);
    return adf_orders_save($orders);
}

/**
 * Catat satu tagihan langganan klien yang sudah lunas (dipakai subscription-payment-notify.php
 * dan subscription-payment-record.php). Return order_id yang dicatat.
 */
function adf_subscription_payment_record(array $client, array $payload): string
{
    $clientKey = (string) ($client['client_key'] ?? '');
    $period = trim((string) ($payload['period'] ?? ''));
    $paidAtTs = strtotime((string) ($payload['paid_at'] ?? '')) ?: time();
    $orderId = trim((string) ($payload['order_id'] ?? '')) ?: ('SUB-' . strtoupper($clientKey) . '-' . $period);
    $label = trim((string) ($payload['description'] ?? ''));

    adf_orders_record_completed([
        'order_id' => $orderId,
        'product_title' => $label !== '' ? $label : (stripos($period, 'MANUAL-') === 0 ? 'Tagihan Manual' : 'Langganan ' . $period),
        'amount' => (int) round((float) ($payload['total_amount'] ?? 0)),
        'name' => (string) ($client['client_name'] ?? $clientKey),
        'source' => 'subscription',
        'client_key' => $clientKey,
        'created_at' => date('c', $paidAtTs),
        'completed_at' => date('c', $paidAtTs),
    ]);
    return $orderId;
}

/**
 * Cek ke Pakasir semua tagihan manual yang dibuat dari admin ini (order_id 'manual-<id>') dan
 * catat yang sudah lunas, supaya muncul di halaman Transaksi walau notifikasi dari klien tidak masuk.
 * Return jumlah pembayaran baru yang tercatat.
 */
function adf_sync_manual_invoice_payments(): int
{
    require_once __DIR__ . '/pakasir-client.php';
    require_once __DIR__ . '/subscription-clients-store.php';
    require_once __DIR__ . '/subscription-manual-invoices-store.php';

    $completedIds = [];
    foreach (adf_orders_load() as $order) {
        if (strtolower((string) ($order['status'] ?? '')) === 'completed') {
            $completedIds[(string) ($order['order_id'] ?? '')] = true;
        }
    }

    $globalCfg = adf_pakasir_config();
    $recorded = 0;
    foreach (adf_manual_invoices_load() as $inv) {
        $orderId = 'manual-' . ($inv['id'] ?? '');
        if (isset($completedIds[$orderId])) {
            continue;
        }
        $client = adf_subscription_client_find((string) ($inv['client_key'] ?? '')) ?? [];
        $slug = trim((string) ($client['pakasir_slug'] ?? '')) ?: (string) ($globalCfg['project_slug'] ?? '');
        $apiKey = trim((string) ($client['pakasir_api_key'] ?? '')) ?: (string) ($globalCfg['api_key'] ?? '');
        $txn = adf_pakasir_transaction_detail($slug, $apiKey, $orderId, (int) round((float) ($inv['amount'] ?? 0)));
        if ($txn && strtolower((string) ($txn['status'] ?? '')) === 'completed') {
            $paidAt = (string) ($txn['completed_at'] ?? '') ?: date('c');
            adf_orders_record_completed([
                'order_id' => $orderId,
                'product_title' => (string) ($inv['description'] ?? 'Tagihan Manual'),
                'amount' => (int) round((float) ($txn['amount'] ?? $inv['amount'] ?? 0)),
                'name' => (string) ($client['client_name'] ?? ($inv['client_key'] ?? '-')),
                'payment_method' => (string) ($txn['payment_method'] ?? ''),
                'source' => 'subscription',
                'client_key' => (string) ($inv['client_key'] ?? ''),
                'created_at' => (string) ($inv['created_at'] ?? $paidAt),
                'completed_at' => $paidAt,
            ]);
            $recorded++;
        }
    }
    return $recorded;
}

function adf_orders_delete(string $orderId): bool
{
    $orders = adf_orders_load();
    $filtered = array_values(array_filter($orders, static function (array $order) use ($orderId): bool {
        return ($order['order_id'] ?? '') !== $orderId;
    }));
    if (count($filtered) === count($orders)) {
        return false;
    }
    return adf_orders_save($filtered);
}
