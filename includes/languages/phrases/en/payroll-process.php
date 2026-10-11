<?php
/** Kamus EN: Process Salary - Setup Service & menu (modules/payroll/process.php). */
return [
    'text' => [
        'Setup Service' => 'Service Setup', 'Menu' => 'Menu', 'Income bulan ini' => 'Income this month', 'Total service' => 'Total service', 'Per orang' => 'Per person',
        'Persentase dari income (%)' => 'Percentage of income (%)', 'Atur total manual (opsional, Rp)' => 'Set total manually (optional, Rp)', 'Pilih / kosongkan semua' => 'Select / clear all',
        'Penerima service' => 'Service recipients', 'Batal' => 'Cancel', 'Simpan Pengaturan' => 'Save Settings', 'Simpan & Terapkan ke Slip' => 'Save & Apply to Slips',
        'Atur penerima & persentase service dari pendapatan hotel' => 'Set service recipients & percentage from hotel income', 'Kosong = otomatis dari %' => 'Empty = automatic from %',
        'Service = persentase dari total income (buku kas, tidak termasuk modal pemilik) bulan ini, dibagi rata ke penerima terpilih. Saat' => 'Service = a percentage of total income (cash book, excluding owner capital) this month, split equally among the selected recipients. When you',
        'Terapkan' => 'Apply', ', kolom Service penerima diisi nominal per orang dan karyawan yang tidak dipilih menjadi 0. Setelah itu Anda masih bisa mengubah angka per karyawan secara manual.' => ', each recipient Service column is filled with the per-person amount and unselected employees become 0. You can still edit each employee manually afterwards.',
        'Pengaturan service tersimpan.' => 'Service settings saved.',
    ],
    'js' => [
        'Terapkan service ke semua slip bulan ini? Nilai Service yang ada akan ditimpa.' => 'Apply service to all slips this month? Existing Service values will be overwritten.',
    ],
    'patterns' => [
        '/^Setup Service — (.+)$/u' => 'Service Setup — $1', '/^Service diterapkan: Rp ([\d.,]+) dibagi (\d+) penerima \(Rp ([\d.,]+) per orang\)\.$/u' => 'Service applied: Rp $1 split among $2 recipients (Rp $3 each).',
        '/^(\d+) dipilih$/u' => '$1 selected', '/^Penerima service \((\d+) dipilih\)$/u' => 'Service recipients ($1 selected)',
    ],
];
