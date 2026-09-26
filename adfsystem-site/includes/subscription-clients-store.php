<?php
/**
 * JSON-backed store for subscription clients (customer sites like Karimunjawa
 * Explore that pay ADF System a monthly base fee + per-guest fee). Pricing is
 * controlled ONLY here by the ADF System admin; the client site only fetches
 * (read-only) the current numbers via api/subscription-config.php.
 */

function adf_subscription_clients_path(): string
{
    return __DIR__ . '/../data/subscription-clients.json';
}

function adf_subscription_clients_load(): array
{
    $path = adf_subscription_clients_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function adf_subscription_clients_save(array $clients): bool
{
    $path = adf_subscription_clients_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function adf_subscription_client_find(string $clientKey): ?array
{
    foreach (adf_subscription_clients_load() as $client) {
        if (($client['client_key'] ?? '') === $clientKey) {
            return $client;
        }
    }
    return null;
}

function adf_subscription_client_upsert(array $client): bool
{
    $clients = adf_subscription_clients_load();
    $client['updated_at'] = date('c');
    $found = false;
    foreach ($clients as &$existing) {
        if (($existing['client_key'] ?? '') === $client['client_key']) {
            $existing = array_merge($existing, $client);
            $found = true;
            break;
        }
    }
    unset($existing);
    if (!$found) {
        $clients[] = $client;
    }
    return adf_subscription_clients_save($clients);
}

function adf_subscription_client_delete(string $clientKey): bool
{
    $clients = adf_subscription_clients_load();
    $filtered = array_values(array_filter($clients, static function (array $client) use ($clientKey): bool {
        return ($client['client_key'] ?? '') !== $clientKey;
    }));
    if (count($filtered) === count($clients)) {
        return false;
    }
    return adf_subscription_clients_save($filtered);
}
