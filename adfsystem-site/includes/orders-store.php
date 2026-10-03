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
