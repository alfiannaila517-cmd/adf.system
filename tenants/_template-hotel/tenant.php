<?php

/**
 * CETAKAN untuk client hotel baru. Cara pakai:
 *   1. Salin folder ini menjadi tenants/<slug-hotel-baru>/  (huruf kecil, angka, tanda minus)
 *   2. Ubah slug, name, database (awali dengan adf_) dan domain di bawah
 *   3. Nyalakan / matikan fitur sesuai kebutuhan hotel itu
 *   4. Logika yang benar-benar berbeda ditulis di hooks.php, bukan di kode inti
 * Folder berawalan "_" tidak pernah dimuat sebagai bisnis aktif.
 */
return [
    'slug' => '_template-hotel',
    'name' => 'Nama Hotel',
    'type' => 'hotel',
    'database' => 'adf_nama_hotel',

    'modules' => ['cashbook', 'auth', 'settings', 'reports', 'frontdesk', 'divisions', 'sales', 'bills', 'payroll'],

    'features' => [
        'cloudbeds' => false,
        'quotation_hold' => true,
        'web_reservations' => false,
        'guest_services' => true,
        'breakfast' => false,
    ],

    'theme' => [
        'color_primary' => '#4338ca',
        'color_secondary' => '#1e1b4b',
        'icon' => '🏨',
    ],

    'domains' => [],
];
