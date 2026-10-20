<?php

declare(strict_types=1);

/**
 * Katalog modul & fitur platform — satu sumber kebenaran untuk Console (menyalakan per bisnis) dan nanti untuk
 * sistem bisnis (menu & izin). Kode modul sama dengan menu_code sistem lama agar data lama bisa dipetakan apa adanya.
 *
 * Modul  = menu / bagian sistem yang bisa diberi izin per peran (Buku Kas, Frontdesk, Gaji, …)
 * Fitur  = kemampuan tambahan yang dinyalakan per bisnis (Cloudbeds, hold kamar 24 jam, …), tanpa izin per orang
 */
final class Catalog
{
    /** Urutan kelompok tampilan */
    public const GROUPS = [
        'inti' => 'Inti',
        'hotel' => 'Hotel',
        'penjualan' => 'Penjualan & Resto',
        'gudang' => 'Pembelian & Gudang',
        'keuangan' => 'Keuangan & Proyek',
        'sdm' => 'SDM',
        'lain' => 'Lainnya',
    ];

    /**
     * kode => [label, kelompok, jenis bisnis yang memakai (null = semua), jenis yang menyalakan secara bawaan (null = semua), wajib?]
     * Jenis: hotel, restaurant, warehouse, website, travel, manufacture, contractor, other
     */
    public const MODULES = [
        'dashboard' => ['Dashboard', 'inti', null, null, true],
        'cashbook' => ['Buku Kas Besar', 'inti', null, ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor', 'other'], false],
        'reports' => ['Laporan', 'inti', null, ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor', 'other'], false],
        'finance' => ['Manajemen Keuangan', 'inti', null, [], false],
        'owner' => ['Owner Monitoring', 'inti', null, ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor'], false],
        'settings' => ['Pengaturan', 'inti', null, null, true],
        'frontdesk' => ['Frontdesk (reservasi & kamar)', 'hotel', ['hotel'], ['hotel'], false],
        'sales_invoice' => ['Sales Invoice', 'penjualan', null, [], false],
        'cafe_invoice' => ['Invoice Kafe', 'penjualan', ['restaurant'], ['restaurant'], false],
        'menu_book' => ['Buku Menu', 'penjualan', ['restaurant'], ['restaurant'], false],
        'procurement' => ['PO & Belanja', 'gudang', null, ['hotel', 'restaurant'], false],
        'procurement_po' => ['PO Internal', 'gudang', null, ['hotel', 'restaurant'], false],
        'procurement_stock' => ['Stok Masuk', 'gudang', null, ['hotel', 'restaurant', 'warehouse'], false],
        'warehouse' => ['Gudang', 'gudang', ['warehouse', 'hotel', 'restaurant'], ['warehouse'], false],
        'warehouse_transfers' => ['Transfer Gudang', 'gudang', ['warehouse'], ['warehouse'], false],
        'divisions' => ['Kelola Divisi', 'keuangan', null, ['hotel', 'restaurant'], false],
        'bills' => ['Tagihan Bulanan', 'keuangan', null, ['hotel', 'restaurant', 'warehouse'], false],
        'investor' => ['Investor', 'keuangan', null, [], false],
        'project' => ['Proyek', 'keuangan', null, ['contractor'], false],
        'production' => ['Produksi', 'keuangan', ['manufacture'], ['manufacture'], false],
        'payroll' => ['Gaji & Karyawan', 'sdm', null, ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor'], false],
        'email' => ['Email Kantor', 'lain', null, [], false],
        'staff_chat' => ['Chat Staf', 'lain', null, [], false],
        'database' => ['Database Master', 'lain', null, [], false],
    ];

    /** kode => [label, keterangan, jenis bisnis yang memakai (null = semua), bawaan nyala untuk jenis (null = semua)] */
    public const FEATURES = [
        'cloudbeds' => ['Cloudbeds', 'Sinkron 2 arah reservasi & ketersediaan dengan Cloudbeds (OTA)', ['hotel'], []],
        'quotation_hold' => ['Hold kamar 24 jam', 'Penawaran memblok kamar 24 jam, lepas otomatis bila tidak dikonfirmasi', ['hotel'], ['hotel']],
        'web_reservations' => ['Reservasi dari website', 'Permintaan reservasi website masuk sebagai penawaran + popup admin', ['hotel'], []],
        'guest_services' => ['Layanan tamu', 'Link layanan (trip, sewa motor, WiFi) di pesan welcome WhatsApp', ['hotel'], ['hotel']],
        'breakfast' => ['Sarapan', 'Pesanan & pengingat sarapan tamu', ['hotel', 'restaurant'], []],
        'wa_notifications' => ['Notifikasi WhatsApp', 'Pesan otomatis lewat WhatsApp (Fonnte)', null, null],
        'fingerprint' => ['Absensi fingerprint', 'Tarik data absensi dari mesin fingerprint', null, []],
    ];

    public static function moduleAvailable(string $code, string $type): bool
    {
        $m = self::MODULES[$code] ?? null;
        return $m !== null && ($m[2] === null || in_array($type, $m[2], true));
    }

    public static function moduleDefault(string $code, string $type): bool
    {
        $m = self::MODULES[$code] ?? null;
        return $m !== null && ($m[4] || ($m[3] === null ? true : in_array($type, $m[3], true))) && self::moduleAvailable($code, $type);
    }

    public static function featureAvailable(string $code, string $type): bool
    {
        $f = self::FEATURES[$code] ?? null;
        return $f !== null && ($f[2] === null || in_array($type, $f[2], true));
    }

    public static function featureDefault(string $code, string $type): bool
    {
        $f = self::FEATURES[$code] ?? null;
        return $f !== null && ($f[3] === null ? true : in_array($type, $f[3], true)) && self::featureAvailable($code, $type);
    }
}
