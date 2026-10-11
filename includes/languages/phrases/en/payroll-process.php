<?php
/** Kamus EN: Process Salary - Setup Service & menu (modules/payroll/process.php). */
return [
    'text' => [
        "Bagi service dari pendapatan hotel ke karyawan terpilih" => "Split service from hotel income among the selected employees", "Persentase dari income" => "Percentage of income",
        "Atur total manual (opsional)" => "Set total manually (optional)", "Penerima service" => "Service recipients", "dipilih" => "selected", "Cari karyawan..." => "Search employee...",
        "Otomatis sinkron dengan pemasukan" => "Auto-sync with income", "Service di Process Salary diperbarui sendiri setiap ada pemasukan baru di bulan ini. Selama opsi ini aktif, edit manual kolom Service akan tertimpa." => "Service in Process Salary updates by itself whenever new income arrives this month. While this option is on, manual edits to the Service column will be overwritten.",
        "Dihitung dari total income buku kas bulan ini (tidak termasuk modal pemilik), dibagi rata ke penerima terpilih. Karyawan yang tidak dipilih mendapat Service 0." => "Calculated from this month total cash book income (excluding owner capital), split equally among the selected recipients. Unselected employees get Service 0.",
        "Tutup" => "Close",
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
        '/^Service diterapkan: Rp ([\d.,]+) dibagi (\d+) penerima \(Rp ([\d.,]+) per orang\) — otomatis diperbarui saat ada pemasukan baru\.$/u' => 'Service applied: Rp $1 split among $2 recipients (Rp $3 each) — automatically updated when new income arrives.',
        '/^Setup Service — (.+)$/u' => 'Service Setup — $1', '/^Service diterapkan: Rp ([\d.,]+) dibagi (\d+) penerima \(Rp ([\d.,]+) per orang\)\.$/u' => 'Service applied: Rp $1 split among $2 recipients (Rp $3 each).',
        '/^(\d+) dipilih$/u' => '$1 selected', '/^Penerima service \((\d+) dipilih\)$/u' => 'Service recipients ($1 selected)',
    ],
];
