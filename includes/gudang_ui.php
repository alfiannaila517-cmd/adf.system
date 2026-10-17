<?php

/**
 * Helper tampilan bersama untuk semua halaman Gudang Nasita (dipakai bersama assets/css/gudang.css, prefix gx-).
 * Semua fungsi aman dipanggil berulang (function_exists guard).
 *
 *   gxEn()                       → true bila bahasa sistem Inggris
 *   gxDate($d, $withTime=false)  → "17 Okt 2026" / "17 Oct 2026" (mengikuti bahasa sistem; '' bila kosong/tidak valid)
 *   gxDateLong($ts=null)         → "Sabtu, 17 Oktober 2026" / "Saturday, 17 October 2026"
 *   gxRp($n)                     → "Rp 1.250.000"
 *   gxQty($n, $dec=null)         → angka qty rapi: 12 / 12,5 (tanpa nol di belakang)
 *   gxStatusChip($status)        → HTML <span class="gx-chip ..."> untuk status PO / transfer / pembayaran
 *   gxE($s)                      → htmlspecialchars singkat
 */
if (!function_exists('gxE')) {
    function gxE($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('gxEn')) {
    function gxEn(): bool
    {
        return function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
    }
}
if (!function_exists('gxDate')) {
    function gxDate($d, bool $withTime = false): string
    {
        if ($d === null || $d === '' || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
            return '';
        }
        $t = is_numeric($d) ? (int)$d : strtotime((string)$d);
        if (!$t) {
            return '';
        }
        $m = gxEn()
            ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            : ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $out = date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
        return $withTime ? $out . ' ' . date('H:i', $t) : $out;
    }
}
if (!function_exists('gxDateLong')) {
    function gxDateLong($ts = null): string
    {
        $t = $ts === null ? time() : (is_numeric($ts) ? (int)$ts : strtotime((string)$ts));
        $days = gxEn() ? ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] : ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $months = gxEn()
            ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
            : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return $days[(int)date('w', $t)] . ', ' . date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}
if (!function_exists('gxRp')) {
    function gxRp($n): string
    {
        return 'Rp ' . number_format((float)$n, 0, ',', '.');
    }
}
if (!function_exists('gxQty')) {
    function gxQty($n, ?int $dec = null): string
    {
        $v = (float)$n;
        if ($dec !== null) {
            return number_format($v, $dec, ',', '.');
        }
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }
}
if (!function_exists('gxStatusChip')) {
    /** Lencana status standar. Label berbahasa Indonesia (diterjemahkan lewat kamus bila sistem EN). */
    function gxStatusChip($status): string
    {
        $k = strtolower(trim((string)$status));
        $map = [
            'draft'              => ['gray', 'Draft'],
            'submitted'          => ['warn', 'Menunggu'],
            'pending'            => ['warn', 'Menunggu'],
            'waiting'            => ['warn', 'Menunggu'],
            'approved'           => ['info', 'Disetujui'],
            'partially_received' => ['info', 'Sebagian diterima'],
            'completed'          => ['ok', 'Selesai'],
            'received'           => ['ok', 'Selesai'],
            'paid'               => ['ok', 'Lunas'],
            'sent'               => ['info', 'Terkirim'],
            'cancelled'          => ['bad', 'Dibatalkan'],
            'rejected'           => ['bad', 'Ditolak'],
            'active'             => ['ok', 'Aktif'],
            'inactive'           => ['gray', 'Nonaktif'],
        ];
        [$cls, $label] = $map[$k] ?? ['gray', $k !== '' ? ucfirst(str_replace('_', ' ', $k)) : '-'];
        return '<span class="gx-chip ' . $cls . '">' . gxE($label) . '</span>';
    }
}
