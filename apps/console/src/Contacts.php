<?php

declare(strict_types=1);

/**
 * Kontak WhatsApp satu bisnis: nama + nomor HP dari semua tabel sistem lama (staf, user, mitra, guide, supplier,
 * kontraktor, pekerja, penawaran/agen, tamu, pelanggan) → file kontak untuk HP bisnis (Google CSV atau vCard).
 * Fonnte tidak bisa menyimpan nama kontak lewat API; nama di WhatsApp diambil dari buku kontak HP.
 * Nomor dirapikan ke +62…, nomor sama hanya sekali (grup dengan urutan lebih atas menang).
 */
final class Contacts
{
    /** Grup => [label, query (kolom: name, phone, note)]. Tabel yang tidak ada di bisnis ini dilewati. */
    private const SOURCES = [
        'staf' => ['Staf', "SELECT full_name AS name, phone, position AS note FROM payroll_employees WHERE is_active = 1"],
        'user' => ['User sistem', "SELECT COALESCE(NULLIF(full_name, ''), username) AS name, phone, 'User sistem' AS note FROM users WHERE is_active = 1"],
        'mitra' => ['Mitra', "SELECT partner_name AS name, phone, category AS note FROM hotel_service_partners WHERE is_active = 1"],
        'guide' => ['Guide', "SELECT guide_name AS name, phone, 'Guide' AS note FROM narayana_trip_guides WHERE is_active = 1"],
        'supplier' => ['Supplier', "SELECT COALESCE(NULLIF(contact_person, ''), supplier_name) AS name, phone, supplier_name AS note FROM suppliers WHERE is_active = 1"],
        'kontraktor' => ['Kontraktor', "SELECT COALESCE(NULLIF(pic_name, ''), name) AS name, phone, name AS note FROM project_contractors"],
        'pekerja' => ['Pekerja', "SELECT name, phone, 'Pekerja proyek' AS note FROM project_workers"],
        'agen' => ['Penawaran / Agen', "SELECT guest_name AS name, guest_phone AS phone, 'Penawaran' AS note FROM quotations ORDER BY id DESC"],
        'tamu' => ['Tamu', "SELECT guest_name AS name, phone, nationality AS note FROM guests ORDER BY id DESC"],
        'tamu_inv' => ['Tamu', "SELECT guest_name AS name, guest_phone AS phone, 'Invoice hotel' AS note FROM hotel_invoices ORDER BY id DESC"],
        'tamu_motor' => ['Tamu', "SELECT guest_name AS name, guest_phone AS phone, 'Sewa motor' AS note FROM rental_motor_bookings ORDER BY id DESC"],
        'pelanggan' => ['Pelanggan', "SELECT customer_name AS name, customer_phone AS phone, 'Pelanggan' AS note FROM sales_invoices_header ORDER BY id DESC"],
    ];

    /** Nomor HP → +62… (08…, 8…, 62…, +kode negara lain); null bila bukan nomor HP yang masuk akal. */
    public static function phone(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        $intl = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $d = substr($d, 2);
        }
        if (!$intl) {
            if (str_starts_with($d, '0')) {
                $d = '62' . substr($d, 1);
            } elseif (str_starts_with($d, '8')) {
                $d = '62' . $d;
            }
        }
        $len = strlen($d);
        return $len >= 10 && $len <= 15 ? '+' . $d : null;
    }

    /**
     * Semua kontak, dikelompokkan: ['groups' => [label => jumlah], 'rows' => [[name, phone, label, note], …]].
     * $only = daftar label yang diambil (kosong = semua).
     */
    public static function collect(PDO $db, array $only = []): array
    {
        $seen = [];
        $rows = [];
        $groups = [];
        foreach (self::SOURCES as [$label, $sql]) {
            try {
                $list = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                continue; // tabel / kolom tidak ada di bisnis ini
            }
            $groups[$label] ??= 0;
            foreach ($list as $r) {
                $name = trim((string)preg_replace('/\s+/u', ' ', (string)$r['name']));
                $phone = self::phone($r['phone'] ?? null);
                if ($name === '' || !$phone || isset($seen[$phone])) {
                    continue;
                }
                $seen[$phone] = true;
                $groups[$label]++;
                if (!$only || in_array($label, $only, true)) {
                    $rows[] = [$name, $phone, $label, trim((string)($r['note'] ?? ''))];
                }
            }
        }
        return ['groups' => array_filter($groups), 'rows' => $rows];
    }

    /** Nama yang tampil di HP: "Budi · Tamu Narayana" (atau nama saja). */
    public static function display(array $row, string $bizShort, bool $suffix): string
    {
        return $suffix ? "{$row[0]} · {$row[2]} {$bizShort}" : $row[0];
    }

    /** CSV format Google Contacts (contacts.google.com → Impor). */
    public static function googleCsv(array $rows, string $bizShort, bool $suffix): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['First Name', 'Last Name', 'Labels', 'Phone 1 - Label', 'Phone 1 - Value', 'Notes'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [self::display($r, $bizShort, $suffix), '', "{$bizShort} {$r[2]} ::: * myContacts", 'Mobile', $r[1], $r[3]], ',', '"', '');
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /** vCard 3.0 (dibuka langsung di HP → Impor ke Kontak). */
    public static function vcard(array $rows, string $bizShort, bool $suffix): string
    {
        $esc = fn (string $s) => str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $s);
        $out = '';
        foreach ($rows as $r) {
            $fn = $esc(self::display($r, $bizShort, $suffix));
            $out .= "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:{$fn}\r\nN:;{$fn};;;\r\nTEL;TYPE=CELL:{$r[1]}\r\n"
                . 'CATEGORIES:' . $esc("{$bizShort} {$r[2]}") . "\r\n"
                . ($r[3] !== '' ? 'NOTE:' . $esc($r[3]) . "\r\n" : '')
                . "END:VCARD\r\n";
        }
        return $out;
    }
}
