<?php

/**
 * Layanan hotel untuk website — dibaca dari data yang sama dengan halaman "Layanan Tamu" di pesan welcome
 * (settings gs_*) dan katalog Hotel Service ADF (hotel_service_catalog). Ubah di ADF → website ikut berubah.
 * Yang TIDAK pernah ditampilkan: password WiFi, tarif driver, mitra.
 */

if (!function_exists('nrServices')) {
    /** Satuan katalog (sering berbahasa Indonesia) → teks Inggris. */
    function nrsUnit(string $u): string
    {
        $k = strtolower(trim($u));
        $map = ['hari' => 'day', 'day' => 'day', 'jam' => 'hour', 'hour' => 'hour', 'malam' => 'night', 'night' => 'night', 'orang' => 'person', 'pax' => 'person',
            'person' => 'person', 'trip' => 'trip', 'kali' => 'trip', 'perjalanan' => 'trip', 'minggu' => 'week', 'week' => 'week', 'kg' => 'kg', 'unit' => ''];
        return $map[$k] ?? $u;
    }

    function nrsImg(string $path): string
    {
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        // Unggahan Layanan Tamu ada di server ADF, bukan di folder website
        return rtrim(defined('ADF_SYSTEM_URL') ? ADF_SYSTEM_URL : BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    function nrsLines(string $txt): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|\\\\n/', $txt))));
    }

    /**
     * @return array<int,array{key:string,icon:string,eyebrow:string,name:string,intro:string,points:string[],price:float,unit:string,images:string[],items:array,wa:string}>
     */
    function nrServices(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $s = [];
        try {
            foreach (dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'gs\\_%'") as $r) $s[$r['setting_key']] = (string)$r['setting_value'];
        } catch (Throwable $e) {
        }
        $get = fn($k, $d = '') => trim((string)($s[$k] ?? '')) !== '' ? (string)$s[$k] : $d;

        // Katalog Hotel Service aktif (hanya nama, harga jual, satuan)
        $catalog = [];
        try {
            $catalog = dbFetchAll("SELECT c.service_type, c.item_name, c.default_price, c.unit, COALESCE(t.type_label, c.service_type) type_label
                FROM hotel_service_catalog c LEFT JOIN hotel_service_types t ON t.type_key = c.service_type AND t.business_id = c.business_id
                WHERE c.is_active = 1 AND c.default_price > 0 ORDER BY c.service_type, c.sort_order, c.default_price");
        } catch (Throwable $e) {
        }
        $items = function (array $words) use (&$catalog): array {
            $out = [];
            foreach ($catalog as $i => $c) {
                $hay = strtolower($c['service_type'] . ' ' . $c['item_name'] . ' ' . $c['type_label']);
                foreach ($words as $w) {
                    if (strpos($hay, $w) !== false) {
                        $out[] = ['name' => (string)$c['item_name'], 'price' => (float)$c['default_price'], 'unit' => nrsUnit((string)$c['unit'])];
                        unset($catalog[$i]); // satu item hanya muncul di satu layanan
                        break;
                    }
                }
            }
            return $out;
        };
        $flyers = [];
        foreach ((array)json_decode($get('gs_flyers', '[]'), true) as $f) {
            if (!empty($f['path'])) $flyers[(string)($f['cat'] ?? 'trip')][] = nrsImg((string)$f['path']);
        }
        $minPrice = fn(array $list, float $manual) => $manual > 0 ? $manual : ($list ? min(array_column($list, 'price')) : 0.0);
        $num = fn($v) => (float)preg_replace('/[^\d.]/', '', (string)$v);

        $out = [];

        // 1. Trip laut / open trip
        $tripItems = $items(['trip', 'snorkel', 'island', 'hopping', 'tour']);
        $out[] = [
            'key' => 'trips', 'icon' => 'fa-ship', 'eyebrow' => 'Ocean Adventures',
            'name' => $get('gs_trip_title_en', $get('gs_trip_title', 'Island Hopping & Snorkelling Trips')),
            'intro' => 'Discover crystal-clear lagoons, coral gardens and secluded white-sand islands with our own trusted boat crew — arranged directly by our front office.',
            'points' => array_values(array_filter(array_map('trim', explode(',', $get('gs_trip_chips_en', $get('gs_trip_chips', 'Island Hopping, Snorkelling, Sunset')))))),
            'price' => $minPrice($tripItems, 0), 'unit' => $tripItems ? $tripItems[0]['unit'] : 'person',
            'images' => $flyers['trip'] ?? [], 'items' => $tripItems,
            'wa' => 'Hello Narayana, I would like to ask about your island trips.',
        ];

        // 2. Sewa motor
        if ($get('gs_motor_enabled', '1') === '1') {
            $motorItems = $items(['motor', 'scooter', 'bike']);
            $out[] = [
                'key' => 'motorbike', 'icon' => 'fa-motorcycle', 'eyebrow' => 'Explore at Your Pace',
                'name' => 'Motorbike Rental',
                'intro' => 'Well-maintained scooters ready at the hotel — the easiest way to reach hidden beaches, viewpoints and local warungs around the island.',
                'points' => nrsLines($get('gs_motor_notes_en', 'Helmet provided')),
                'price' => $minPrice($motorItems, $num($get('gs_motor_price'))), 'unit' => nrsUnit($get('gs_motor_unit', $motorItems[0]['unit'] ?? 'day')),
                'images' => array_values(array_filter(array_merge([nrsImg($get('gs_motor_photo'))], $flyers['motor'] ?? []))), 'items' => $motorItems,
                'wa' => 'Hello Narayana, I would like to rent a motorbike.',
            ];
        }

        // 3. Taxi / mobil & antar-jemput
        $transferItems = $items(['taxi', 'mobil', 'car', 'airport', 'harbor', 'harbour', 'pelabuhan', 'bandara', 'drop', 'jemput']);
        if ($get('gs_taxi_enabled', '0') === '1' || $transferItems) {
            $out[] = [
                'key' => 'transfers', 'icon' => 'fa-van-shuttle', 'eyebrow' => 'Seamless Arrivals',
                'name' => 'Car, Taxi & Harbour Transfers',
                'intro' => 'Private pick-up and drop-off between the harbour, Dewadaru airport and the hotel, plus car hire with driver for island sightseeing.',
                'points' => nrsLines($get('gs_taxi_notes_en', 'Harbour & airport pick-up')),
                'price' => $minPrice($transferItems, $num($get('gs_taxi_price'))), 'unit' => nrsUnit($get('gs_taxi_unit', $transferItems[0]['unit'] ?? 'trip')),
                'images' => $flyers['taxi'] ?? [], 'items' => $transferItems,
                'wa' => 'Hello Narayana, I would like to arrange a transfer / car.',
            ];
        }

        // 4. Layanan tambahan dari pengaturan (mis. Laundry)
        $iconMap = ['🧺' => 'fa-shirt', '🍽️' => 'fa-utensils', '💆' => 'fa-spa', '🚤' => 'fa-ship', '🎁' => 'fa-gift', '🛎️' => 'fa-bell-concierge',
            '🧴' => 'fa-pump-soap', '🏊' => 'fa-person-swimming', '🎣' => 'fa-fish', '🛒' => 'fa-basket-shopping', '⭐' => 'fa-star'];
        foreach ((array)json_decode($get('gs_custom', '[]'), true) as $c) {
            if (empty($c['id']) || ($c['enabled'] ?? '1') !== '1') continue;
            $name = trim((string)($c['name_en'] ?? '')) ?: trim((string)($c['name'] ?? ''));
            if ($name === '') continue;
            $ci = $items([strtolower(trim((string)($c['name'] ?? $name)))]);
            $out[] = [
                'key' => 'x' . preg_replace('/[^a-z0-9]/', '', (string)$c['id']), 'icon' => $iconMap[$c['icon'] ?? ''] ?? 'fa-star', 'eyebrow' => 'Guest Service',
                'name' => $name, 'intro' => '',
                'points' => nrsLines((string)(($c['notes_en'] ?? '') !== '' ? $c['notes_en'] : ($c['notes'] ?? ''))),
                'price' => $minPrice($ci, $num($c['price'] ?? '')), 'unit' => nrsUnit((string)($c['unit'] ?? '')),
                'images' => $flyers['x_' . $c['id']] ?? [], 'items' => $ci,
                'wa' => 'Hello Narayana, I would like to ask about ' . $name . '.',
            ];
        }

        // 5. Sisa katalog yang belum masuk layanan mana pun → dikelompokkan per tipe
        $rest = [];
        foreach ($catalog as $c) $rest[(string)$c['type_label']][] = ['name' => (string)$c['item_name'], 'price' => (float)$c['default_price'], 'unit' => nrsUnit((string)$c['unit'])];
        foreach ($rest as $label => $list) {
            if (in_array(strtolower($label), ['lain-lain', 'other', 'others', 'service'], true)) continue;
            $out[] = ['key' => 'c' . substr(md5($label), 0, 6), 'icon' => 'fa-bell-concierge', 'eyebrow' => 'Guest Service', 'name' => $label, 'intro' => '', 'points' => [],
                'price' => min(array_column($list, 'price')), 'unit' => $list[0]['unit'], 'images' => [], 'items' => $list, 'wa' => 'Hello Narayana, I would like to ask about ' . $label . '.'];
        }

        return $cache = $out;
    }

    /** Nomor WhatsApp Front Office untuk tombol layanan. */
    function nrsWhatsApp(): string
    {
        try {
            $r = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'gs_fo_wa'");
            $n = preg_replace('/\D/', '', (string)($r['setting_value'] ?? ''));
            if ($n !== '') return strpos($n, '0') === 0 ? '62' . substr($n, 1) : $n;
        } catch (Throwable $e) {
        }
        return BUSINESS_WHATSAPP;
    }

    function nrsHours(): string
    {
        try {
            $r = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'gs_fo_hours'");
            return trim((string)($r['setting_value'] ?? '')) ?: '07.00–22.00';
        } catch (Throwable $e) {
            return '07.00–22.00';
        }
    }

    function nrsPrice(float $p): string
    {
        return 'IDR ' . number_format($p, 0, ',', '.');
    }
}
