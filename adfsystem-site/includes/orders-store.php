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
