<?php
/**
 * Simple JSON-backed content store for editable website sections.
 */

function adf_content_path(): string
{
    return __DIR__ . '/../data/content.json';
}

function adf_default_content(): array
{
    return [
        'branding' => [
            'logo' => '',
        ],
        'hero' => [
            'title' => 'Satu Sistem untuk Menjalankan Seluruh Bisnis Anda',
            'subtitle' => 'ADF System adalah platform manajemen bisnis all-in-one untuk hotel, penginapan, biro perjalanan (travel/trip), kafe, dan multi-bisnis lainnya — mulai dari booking, invoice otomatis, keuangan, hingga website resmi bisnis Anda.',
            'background' => '',
        ],
        'contact' => [
            'email' => 'office@adfsystem.id',
            'whatsapp' => '',
            'address' => 'Indonesia',
        ],
        'modules' => [],
        'portfolio' => [],
        'clients' => [],
        'layanan' => [
            'hero' => [
                'title' => 'Layanan & Modul ADF System',
                'subtitle' => 'Penjelasan lengkap setiap modul yang tersedia dalam platform ADF System.',
            ],
            'items' => [],
        ],
        'products' => [],
        'payment' => [
            'provider' => 'pakasir',
            'project_slug' => '',
            'api_key' => '',
            'webhook_secret' => '',
            'sandbox' => false,
        ],
    ];
}

function adf_load_content(): array
{
    $path = adf_content_path();
    $defaults = adf_default_content();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_replace_recursive($defaults, $data);
}

function adf_save_content(array $data): bool
{
    $path = adf_content_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}
