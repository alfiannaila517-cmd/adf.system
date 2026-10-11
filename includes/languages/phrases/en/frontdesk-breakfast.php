<?php
/** Kamus EN: Sarapan Front Desk (modules/frontdesk/breakfast.php). Portal tamu (breakfast-guest.php) memakai bahasa tamu. */
return [
    'text' => [
        '(invoice Hotel Service)' => '(Hotel Service invoice)', '(untuk kitchen)' => '(for the kitchen)', 'Belum ada order hari ini' => 'No orders today yet', 'Buka WhatsApp' => 'Open WhatsApp',
        'Grup · kamar' => 'Group · rooms', 'Jam *' => 'Time *', 'Jatah sarapan (pax)' => 'Breakfast allowance (pax)', 'Jumlah Pax *' => 'Number of Pax *', 'Kids di bawah 7 tahun · gratis' => 'Kids under 7 years · free',
        'Kirim' => 'Send', 'Kirim 1 link gabungan' => 'Send 1 combined link', 'Kirim lagi' => 'Resend', 'Link sarapan sudah dikirim' => 'Breakfast link already sent', 'Link terkirim' => 'Link sent', 'Lokasi *' => 'Location *',
        'Nomor WhatsApp tamu' => 'Guest WhatsApp number', 'Salin link' => 'Copy link', 'Salin link saja' => 'Copy link only', 'Simpan nomor ke data tamu' => 'Save the number to guest data',
        'Tambahkan item extra breakfast yang tidak ada di daftar menu. Isi nama dan harga manual.' => 'Add an extra breakfast item that is not in the menu list. Enter the name and price manually.',
        'WhatsApp belum terkirim' => 'WhatsApp not sent yet', 'centang beberapa untuk 1 link gabungan' => 'tick several for 1 combined link', 'otomatis dari menu' => 'automatic from the menu',
        'Waktu & Detail' => 'Time & Details', 'Pilih Menu' => 'Select Menu', 'Semua tamu in-house sudah order sarapan hari ini!' => 'All in-house guests have ordered breakfast today!', 'Tamu In-House' => 'In-House Guests',
        'Rekap Total per Menu' => 'Total Summary per Menu', 'disetel' => 'set', 'dari reservasi' => 'from reservation', 'Extra Breakfast (Manual)' => 'Extra Breakfast (Manual)',
        // dokumen cetak (PDF) yang dirangkai di JavaScript
        'REKAP PESANAN SARAPAN' => 'BREAKFAST ORDER SUMMARY', 'Catatan Khusus' => 'Special Notes', 'Harga' => 'Price', 'Catatan' => 'Notes',
        // atribut
        '08xx atau +62…' => '08xx or +62…', 'Alergi, permintaan khusus, dll' => 'Allergies, special requests, etc.', 'Buka link' => 'Open link', 'Buka link sarapan tamu' => 'Open the guest breakfast link',
        'Catatan: pedas/tidak, dll' => 'Note: spicy or not, etc.', 'Cetak Rekap PDF' => 'Print Summary PDF', 'Kirim link via WhatsApp' => 'Send link via WhatsApp', 'Klik untuk ubah nomor WhatsApp' => 'Click to change the WhatsApp number',
        'Kurangi' => 'Decrease', 'Nama item, cth: Extra Nasi' => 'Item name, e.g. Extra Rice', 'Pilih menu' => 'Select menu', 'Salin link sarapan tamu' => 'Copy the guest breakfast link',
    ],
    'js' => [
        '<br>Kirim lagi ke tamu?' => '<br>Send again to the guest?', '<br>Nomor tujuan:' => '<br>Destination number:', 'Error koneksi' => 'Connection error', 'Gagal membuat link' => 'Failed to create the link',
        'Gagal menyimpan nomor' => 'Failed to save the number', 'Gateway belum aktif' => 'Gateway is not active yet', 'Gateway menolak' => 'Gateway rejected', 'Hapus order sarapan "' => 'Delete breakfast order "',
        'Isi jam sarapan!' => 'Enter the breakfast time!', 'Jatah tersimpan' => 'Allowance saved', 'Kids (< 7 th) gratis: 1 pancake/waffle + 1 minuman per anak — jangan dihitung juga di pax.' => 'Kids (< 7 yrs) are free: 1 pancake/waffle + 1 drink per child — do not count them in the pax too.',
        'Kirim 1 link gabungan' => 'Send 1 combined link', 'Kirim 1 link gabungan ·' => 'Send 1 combined link ·', 'Kirim link ·' => 'Send link ·', 'Link disalin' => 'Link copied', 'Link sarapan terkirim' => 'Breakfast link sent',
        'Link tidak tersedia' => 'Link not available', 'Nomor WhatsApp' => 'WhatsApp number', 'Nomor tersimpan' => 'Number saved', 'Pilih minimal 1 menu atau tambahkan extra manual!' => 'Select at least 1 menu or add a manual extra!',
        'Pilih minimal 1 menu makanan atau minuman!' => 'Select at least 1 food or drink menu!', 'Pilih minimal 1 tamu' => 'Select at least 1 guest', 'Pilih minimal 1 tamu!' => 'Select at least 1 guest!',
        'Salin link berikut:' => 'Copy the following link:', 'Tempel ke chat tamu' => 'Paste into the guest chat', 'Tidak diketahui' => 'Unknown',
        'belum punya nomor WhatsApp. Masukkan nomor untuk mengirim link.' => 'does not have a WhatsApp number yet. Enter a number to send the link.', 'menu utama' => 'main dish', 'minuman' => 'drink',
        'otomatis ditambahkan ke Hotel Service Invoice.' => 'automatically added to the Hotel Service Invoice.', 'tamu dipilih' => 'guests selected', 'tamu ·' => 'guests ·', '⏳ Menyimpan order...' => '⏳ Saving order...',
        '⚠️ Melebihi jatah sarapan:' => '⚠️ Exceeds breakfast allowance:', '✓ Simpan Order' => '✓ Save Order', '❌ Error koneksi:' => '❌ Connection error:', 'ke <b>' => 'to <b>', 'order,' => 'orders,',
    ],
    'patterns' => [
        '/(\d+) (?:tamu|guests) dipilih/u' => '$1 guests selected',
    ],
];
