<?php
/** Kamus EN: Monitoring Rental Motor (modules/frontdesk/rental-motor-dashboard.php). */
return [
    'text' => [
        '10 rental terakhir yang sudah kembali' => 'Last 10 rentals already returned', 'Belum ada motor yang dikembalikan.' => 'No motorbikes returned yet.', 'Belum ada motor. Tambahkan lewat' => 'No motorbikes yet. Add one via',
        'Jadwal sewa' => 'Rental schedule', 'Klik motor untuk melihat detail atau menyewakan' => 'Click a motorbike to view details or rent it out', 'Monitoring Rental' => 'Rental Monitoring',
        'Motor Hotel' => 'Hotel Motorbikes', 'Okupansi armada' => 'Fleet occupancy', 'Pendapatan Bulan Ini' => 'Revenue This Month', 'Pengembalian Terakhir' => 'Latest Returns', 'Sewa Motor' => 'Rent Motorbike',
        'Tidak ada motor yang sedang disewa.' => 'No motorbikes currently rented.', 'Urut dari yang paling dekat jadwal kembalinya' => 'Sorted by the nearest return schedule', 'aktif' => 'active', 'rental aktif' => 'active rentals',
        'terlambat' => 'late', 'dari sedang disewa' => 'of currently rented', 'Siap Disewa' => 'Ready to Rent', 'Sedang Disewa' => 'Currently Rented', 'Terlambat' => 'Late', 'Disewa' => 'Rented',
        'Perbaikan' => 'Maintenance', 'Tersedia' => 'Available', 'Semua tepat waktu' => 'All on time', 'Lewat lebih dari 24 jam dari jadwal kembali' => 'More than 24 hours past the return schedule',
        'Tidak ada yang dalam perbaikan' => 'None under maintenance', 'Dalam perbaikan' => 'Under maintenance', 'Siap disewa' => 'Ready to rent', 'Sedang disewa' => 'Currently rented', 'Jadwal kembali' => 'Return schedule',
        'Mitra' => 'Partner', 'Tarif' => 'Rate', 'dihitung saat kembali' => 'calculated on return', 'Sewakan motor ini' => 'Rent out this motorbike',
    ],
    'js' => [
        'Dalam perbaikan' => 'Under maintenance', 'Siap disewa' => 'Ready to rent', 'Sedang disewa' => 'Currently rented', 'Terlambat' => 'Late', 'Jadwal kembali' => 'Return schedule', 'Mitra' => 'Partner',
        'Mulai' => 'Start', 'Status' => 'Status', 'Tarif' => 'Rate', 'dihitung saat kembali' => 'calculated on return', '>Sewakan motor ini</a>' => '>Rent out this motorbike</a>', ' / hari' => ' / day',
    ],
    'patterns' => [
        '/(\d+) dari (\d+) sedang disewa/u' => '$1 of $2 currently rented', '/\b(\d+) motor\b/u' => '$1 motorbikes', '/\b(\d+) transaksi\b/u' => '$1 transactions',
        '/\bTersedia (\d+)/u' => 'Available $1', '/\bDisewa (\d+)/u' => 'Rented $1', '/\bPerbaikan (\d+)/u' => 'Maintenance $1',
        '/\bSisa (?=\d)/u' => 'Remaining ', '/\bTerlambat (?=\d)/u' => 'Late ', '/\b(\d+) jam\b/u' => '$1 hrs', '/\b(\d+) menit\b/u' => '$1 min',
        '/\bkembali (\d{1,2} [A-Za-z]{3})/u' => 'returns $1', '/ \/ hari\b/u' => ' / day',
        '/ · status armada motor & penyewaan berjalan$/u' => ' · motorbike fleet status & ongoing rentals',
    ],
];
