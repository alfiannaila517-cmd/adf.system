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
        'admin' => ['Panel Admin', 'inti', null, [], false],
        'frontdesk' => ['Frontdesk (reservasi & kamar)', 'hotel', ['hotel'], ['hotel'], false],
        'sales_invoice' => ['Sales Invoice', 'penjualan', null, [], false],
        'cafe_invoice' => ['Invoice Kafe', 'penjualan', ['restaurant'], ['restaurant'], false],
        'menu_book' => ['Buku Menu', 'penjualan', null, ['restaurant'], false],
        'procurement' => ['PO & Belanja', 'gudang', null, ['hotel', 'restaurant'], false],
        'procurement_po' => ['Pembelian › PO Gudang', 'gudang', null, ['hotel', 'restaurant'], false],
        'procurement_stock' => ['Pembelian › Stock Gudang', 'gudang', null, ['hotel', 'restaurant', 'warehouse'], false],
        'warehouse' => ['Gudang', 'gudang', ['warehouse', 'hotel', 'restaurant'], ['warehouse'], false],
        'warehouse_transfers' => ['Transfer Gudang', 'gudang', ['warehouse'], ['warehouse'], false],
        'gudang_nasita' => ['Gudang Nasita', 'gudang', null, [], false],
        'divisions' => ['Kelola Divisi', 'keuangan', null, ['hotel', 'restaurant'], false],
        'bills' => ['Tagihan Bulanan', 'keuangan', null, ['hotel', 'restaurant', 'warehouse'], false],
        'investor' => ['Investor', 'keuangan', null, [], false],
        'project' => ['Proyek', 'keuangan', null, ['contractor'], false],
        'production' => ['Produksi', 'keuangan', ['manufacture'], ['manufacture'], false],
        'cqc-projects' => ['Proyek CQC', 'keuangan', null, [], false],
        'payroll' => ['Payroll (Gaji & Karyawan)', 'sdm', null, ['hotel', 'restaurant', 'warehouse', 'travel', 'manufacture', 'contractor'], false],
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

    /**
     * Tingkat izin per modul = 4 tanda izin sistem lama (lihat, tambah, ubah, hapus) yang selalu bertingkat:
     *   Tidak 0000 · Lihat 1000 · Input 1100 · Ubah 1110 (tanpa hapus) · Kelola 1111
     */
    public const LEVELS = [0 => 'Tidak', 1 => 'Lihat', 2 => 'Input', 3 => 'Ubah', 4 => 'Kelola'];
    public const MAX_LEVEL = 4;

    /**
     * Templat peran per jenis bisnis: [kode, nama, keterangan, pemilik?, izin {modul: level, '*': level bawaan}].
     * Disalin ke bisnis saat pertama dipakai, lalu bisa diubah khusus bisnis itu.
     * procurement_po & procurement_stock diberikan ke peran harian hotel/kafe karena sistem lama membukanya
     * untuk semua orang di Narayana Hotel, Bens Cafe & Eatmeet (perilaku dipertahankan).
     */
    public const ROLE_TEMPLATES = [
        'hotel' => [
            ['owner', 'Owner', 'Akses penuh + portal owner', true, ['*' => 4]],
            ['manager', 'Manager', 'Mengelola operasional, pengaturan hanya lihat', false, ['*' => 4, 'settings' => 1, 'database' => 0, 'admin' => 0, 'investor' => 1]],
            ['front_desk', 'Front Desk', 'Reservasi, check-in/out, input kas', false, ['dashboard' => 1, 'frontdesk' => 4, 'cashbook' => 2, 'procurement' => 2, 'procurement_po' => 2, 'procurement_stock' => 2]],
            ['staff', 'Staff', 'Akses dasar + PO & stok', false, ['dashboard' => 1, 'procurement_po' => 2, 'procurement_stock' => 2]],
        ],
        'restaurant' => [
            ['owner', 'Owner', 'Akses penuh + portal owner', true, ['*' => 4]],
            ['manager', 'Manager', 'Mengelola operasional, pengaturan hanya lihat', false, ['*' => 4, 'settings' => 1, 'database' => 0, 'admin' => 0, 'investor' => 1]],
            ['cashier', 'Kasir', 'Invoice kafe, buku menu, input kas', false, ['dashboard' => 1, 'cafe_invoice' => 4, 'menu_book' => 1, 'cashbook' => 2, 'procurement_po' => 2, 'procurement_stock' => 2]],
            ['staff', 'Staff', 'Akses dasar + PO & stok', false, ['dashboard' => 1, 'procurement_po' => 2, 'procurement_stock' => 2]],
        ],
        'warehouse' => [
            ['owner', 'Owner', 'Akses penuh + portal owner', true, ['*' => 4]],
            ['warehouse_admin', 'Admin Gudang', 'Kelola stok, transfer & barang masuk', false, ['dashboard' => 1, 'warehouse' => 4, 'warehouse_transfers' => 4, 'procurement_stock' => 4, 'cashbook' => 2, 'reports' => 1]],
            ['staff', 'Staff Gudang', 'Lihat stok & input barang masuk', false, ['dashboard' => 1, 'warehouse' => 1, 'procurement_stock' => 2]],
        ],
        'website' => [
            ['owner', 'Owner', 'Akses penuh', true, ['*' => 4]],
            ['editor', 'Editor', 'Ubah isi website', false, ['dashboard' => 1, 'settings' => 2]],
        ],
        '*' => [
            ['owner', 'Owner', 'Akses penuh + portal owner', true, ['*' => 4]],
            ['manager', 'Manager', 'Mengelola operasional, pengaturan hanya lihat', false, ['*' => 4, 'settings' => 1, 'database' => 0, 'admin' => 0, 'investor' => 1]],
            ['staff', 'Staff', 'Akses dasar', false, ['dashboard' => 1]],
        ],
    ];

    public static function roleTemplates(string $type): array
    {
        return self::ROLE_TEMPLATES[$type] ?? self::ROLE_TEMPLATES['*'];
    }

    /** Level satu modul dari peta izin (kunci '*' = level bawaan untuk modul yang tidak disebut). */
    public static function levelOf(array $perms, string $module): int
    {
        return max(0, min(self::MAX_LEVEL, (int)($perms[$module] ?? $perms['*'] ?? 0)));
    }

    /** Level → 4 tanda izin sistem lama (can_view, can_create, can_edit, can_delete). */
    public static function legacyFlags(int $level): array
    {
        return [$level >= 1 ? 1 : 0, $level >= 2 ? 1 : 0, $level >= 3 ? 1 : 0, $level >= 4 ? 1 : 0];
    }

    /**
     * 4 tanda izin sistem lama → level. Kombinasi tidak bertingkat (mis. ubah tanpa tambah) dibulatkan ke bawah
     * pada tanda pertama yang mati; $exact = false memberi tahu pemanggil bahwa ada yang dibulatkan.
     */
    public static function levelFromFlags(int $view, int $create, int $edit, int $delete, ?bool &$exact = null): int
    {
        $flags = [$view ? 1 : 0, $create ? 1 : 0, $edit ? 1 : 0, $delete ? 1 : 0];
        $level = 0;
        while ($level < 4 && $flags[$level] === 1) {
            $level++;
        }
        $exact = $flags === self::legacyFlags($level);
        return $level;
    }

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
