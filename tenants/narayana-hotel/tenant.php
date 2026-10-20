<?php

/**
 * Narayana Hotel Karimunjawa — konfigurasi bisnis.
 * Semua yang berbeda dari bisnis lain diatur DI SINI (atau di hooks.php), bukan dengan if-nama-bisnis di kode inti.
 */
return [
    'slug' => 'narayana-hotel',
    'name' => 'Narayana Hotel Karimunjawa',
    'type' => 'hotel',
    'database' => 'adf_narayana_hotel',

    // Modul yang tampil di menu. 'project' sengaja tidak ada (dulu disembunyikan lewat if-nama-bisnis di header).
    'modules' => ['cashbook', 'auth', 'settings', 'reports', 'frontdesk', 'divisions', 'procurement', 'sales', 'investor', 'bills', 'payroll'],

    // Fitur on/off — fitur ada di kode inti, bisnis ini memilih memakainya atau tidak
    'features' => [
        'cloudbeds' => true,          // sinkron 2 arah dengan Cloudbeds (OTA)
        'quotation_hold' => true,     // penawaran memblok kamar 24 jam
        'web_reservations' => true,   // reservasi dari website masuk sebagai penawaran
        'guest_services' => true,     // halaman layanan tamu di pesan welcome
        'breakfast' => true,
    ],

    'theme' => [
        'color_primary' => '#4338ca',
        'color_secondary' => '#1e1b4b',
        'icon' => '🏨',
    ],

    'cashbook_columns' => [
        'room_number' => ['label' => 'Room #', 'type' => 'text'],
        'guest_name' => ['label' => 'Guest Name', 'type' => 'text'],
    ],

    // Website publik bisnis ini (nanti dilayani dari tenants/narayana-hotel/website/)
    'domains' => ['narayanakarimunjawa.com', 'www.narayanakarimunjawa.com'],
];
