<?php

/**
 * Aturan bersama sarapan (portal tamu & order front desk).
 *
 * Jatah sarapan = jumlah pax (dewasa + anak) di reservasi, bisa diubah lewat Setup.
 * 1 pax = 1 makanan + 1 minuman. Pilihan di luar jatah dihitung per PAKET
 * (1 makanan + 1 minuman): jumlah paket = max(kelebihan makanan, kelebihan minuman).
 */

if (!function_exists('bf_extra_package_price')) {
    /** Harga 1 paket Extra Breakfast (setting breakfast_extra_price, bawaan Rp 82.500). */
    function bf_extra_package_price($db): float
    {
        static $price = null;
        if ($price !== null) return $price;
        $price = 82500.0;
        try {
            $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_extra_price' LIMIT 1");
            $v = (float)($row['setting_value'] ?? 0);
            if ($v > 0) $price = $v;
        } catch (\Throwable $e) {
        }
        return $price;
    }
}

if (!function_exists('bf_extra_packages')) {
    /** Jumlah paket extra dari kelebihan makanan & minuman. */
    function bf_extra_packages(int $extraMain, int $extraDrink): int
    {
        return max(0, $extraMain, $extraDrink);
    }
}
