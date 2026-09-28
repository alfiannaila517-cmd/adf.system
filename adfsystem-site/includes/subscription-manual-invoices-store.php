<?php

/**
 * JSON-backed store for ad-hoc "manual" subscription charges an ADF System
 * admin creates for a client outside the regular monthly recurring invoice
 * (e.g. a one-off addon/module fee). Client sites pull these via
 * api/subscription-manual-invoices.php and turn each into a normal invoice
 * on their side (same pay/banner/lock flow as the recurring bill).
 */

function adf_manual_invoices_path(): string
{
    return __DIR__ . '/../data/subscription-manual-invoices.json';
}

function adf_manual_invoices_load(): array
{
    $path = adf_manual_invoices_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function adf_manual_invoices_save(array $invoices): bool
{
    $path = adf_manual_invoices_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($invoices, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function adf_manual_invoices_for_client(string $clientKey): array
{
    return array_values(array_filter(adf_manual_invoices_load(), static function (array $inv) use ($clientKey): bool {
        return ($inv['client_key'] ?? '') === $clientKey;
    }));
}

function adf_manual_invoice_create(string $clientKey, string $description, float $amount, string $dueDate): array
{
    $invoices = adf_manual_invoices_load();
    $entry = [
        'id' => uniqid('mi', true),
        'client_key' => $clientKey,
        'description' => $description,
        'amount' => $amount,
        'due_date' => $dueDate,
        'created_at' => date('c'),
    ];
    $invoices[] = $entry;
    adf_manual_invoices_save($invoices);
    return $entry;
}

function adf_manual_invoice_delete(string $id): bool
{
    $invoices = adf_manual_invoices_load();
    $filtered = array_values(array_filter($invoices, static function (array $inv) use ($id): bool {
        return ($inv['id'] ?? '') !== $id;
    }));
    if (count($filtered) === count($invoices)) {
        return false;
    }
    return adf_manual_invoices_save($filtered);
}
