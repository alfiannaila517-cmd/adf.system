<?php
/** Kamus EN: Tagihan (modules/bills/*): daftar tagihan, template, tagihan Bisnis & Gudang, laporan pencairan. */
return [
    'text' => [
        // ---- halaman & judul ----
        'Daftar Tagihan' => 'Bill List', 'Tagihan Bisnis & Gudang' => 'Business & Warehouse Bills', 'Template Tagihan Rutin' => 'Routine Bill Templates', 'Template Tagihan' => 'Bill Template',
        'Edit Tagihan' => 'Edit Bill', 'Pembayaran Tagihan' => 'Bill Payment', 'Bayar Tagihan' => 'Pay Bill', 'Tambah Tagihan Manual' => 'Add Manual Bill', 'Total Tagihan' => 'Total Bills',
        'Laporan Pencairan Tagihan' => 'Bill Disbursement Report', 'Ajukan pencairan dana untuk pembayaran tagihan' => 'Request fund disbursement to pay bills', 'Input tagihan baru secara manual' => 'Enter a new bill manually',
        'Kelola template tagihan rutin' => 'Manage routine bill templates', 'Ubah detail tagihan' => 'Edit bill details', 'Ubah detail tagihan periode' => 'Edit bill details for the period',
        'Buat tagihan baru berdasarkan template yang sudah ada' => 'Create a new bill based on an existing template', 'Template digunakan untuk generate tagihan otomatis setiap bulan' => 'Templates are used to generate bills automatically every month',
        'Buat template di atas untuk mulai generate tagihan otomatis' => 'Create a template above to start generating bills automatically', 'Belum ada template tagihan' => 'No bill templates yet', 'Buat template dulu' => 'Create a template first',
        'Tidak ada tagihan rutin yang perlu dibayar' => 'No routine bills to pay', 'Tidak ada tagihan' => 'No bills',
        // ---- form ----
        'Batalkan Bayar' => 'Cancel Payment', 'Kategori Kas' => 'Cash Category', 'Metode Bayar Default' => 'Default Payment Method', 'Kas Tunai (default)' => 'Cash (default)', 'Nama Customer / Toko' => 'Customer / Store Name',
        'Nama Item' => 'Item Name', 'Nama Kategori' => 'Category Name', 'Nama Supplier / Toko' => 'Supplier / Store Name', 'Nomor Rekening Pembayaran' => 'Payment Account Number', 'Sumber Dana / Rekening' => 'Fund Source / Account',
        'Tanggal Jatuh Tempo' => 'Due Date', 'hari sebelum' => 'days before', 'Periode Tagihan:' => 'Bill Period:', 'Semua periode' => 'All periods', 'Semua tagihan periode' => 'All bills of the period',
        'Tidak ditagih bulanan' => 'Not billed monthly', 'Buku Kas:' => 'Cash Book:', 'Dicetak dari' => 'Printed from', 'Jumlah yang dibayar' => 'Amount paid',
        'Mohon pencairan dana untuk pembayaran tagihan di atas. Dana akan digunakan untuk operasional bisnis sesuai dengan tagihan yang tertera.' => 'Please disburse funds for the bill payments above. The funds will be used for business operations according to the listed bills.',
        'TOTAL PENCAIRAN' => 'TOTAL DISBURSEMENT', 'Total harus dibayar ke Gudang' => 'Total to be paid to the Warehouse', 'Total kiriman' => 'Total shipments', 'estimasi harga' => 'estimated price',
        'dari' => 'from', 'dibayar' => 'paid', 'sudah lunas' => 'fully paid',
        // ---- bulanan / template ----
        'Contoh: Tagihan bulanan dari PLN...' => 'e.g. Monthly bill from PLN...', 'Contoh: Toko Sembako Pak Joko' => 'e.g. Pak Joko Grocery Store', 'Contoh: Toko Sinar Jaya' => 'e.g. Toko Sinar Jaya',
        'Nama barang/item' => 'Item name', 'No. meter / akun' => 'Meter no. / account', 'Tambah Kategori' => 'Add Category', 'Tambah item' => 'Add item', 'Bulan berikutnya' => 'Next month',
        // ---- gudang ----
        'Bagian biaya TKBM (bongkar muat) — ÷ 3 bisnis' => 'Share of TKBM (loading/unloading) costs — ÷ 3 businesses', 'Belum ada uang masuk dari Gudang pada .' => 'No money received from the Warehouse yet on .',
        'Bisnis ini tidak termasuk tagihan bulanan Gudang.' => 'This business is not included in the Warehouse monthly bills.', 'Gudang masih berhutang ke kami' => 'The Warehouse still owes us',
        'Kiriman ke Gudang atau ke bisnis lain, termasuk barang yang diambil langsung oleh Gudang. Suplai/pembelian dibayar Gudang (pendapatan kami);' => 'Shipments to the Warehouse or other businesses, including goods taken directly by the Warehouse. Supplies/purchases paid by the Warehouse (our income);',
        'Pembayaran Gudang yang sudah masuk ke rekening .' => 'Warehouse payments already received in the account .', 'Penjualan · refund retur Penjualan ke Gudang (pendapatan)' => 'Sales · return refund Sales to Warehouse (income)',
        'Refund retur · mengurangi biaya' => 'Return refund · reduces costs', 'Retur ke Gudang (mengurangi tagihan) · −' => 'Return to Warehouse (reduces the bill) · −',
        'Rincian sekarang Rp (ada perubahan setelah dibayar) — hubungi Gudang.' => 'Current breakdown Rp (changed after payment) — contact the Warehouse.', 'Tagihan dari Gudang yang harus dibayar .' => 'Bills from the Warehouse that must be paid .',
        'Tidak ada barang yang kami kirim pada .' => 'No items we sent on .', 'Tidak ada tagihan dari Gudang pada .' => 'No bills from the Warehouse on .', 'Uang yang sudah masuk dari Gudang.' => 'Money already received from the Warehouse.',
        'barang yang dulu dari Gudang tidak dibayar tunai, tapi mengurangi tagihan Gudang kami.' => 'goods that came from the Warehouse earlier were not paid in cash, but reduce our Warehouse bill.', 'Retur · potong tagihan' => 'Return · deduct from the bill',
        'Dipotong tagihan' => 'Deducted from the bill', 'Harga tersimpan.' => 'Price saved.',
        // ---- status ----
        'Belum Dibayar' => 'Unpaid', 'Belum dibayar' => 'Unpaid', 'Dibayar Sebagian' => 'Partially Paid', 'Dibayar sebagian' => 'Partially paid', 'Lunas' => 'Paid', 'Semua lunas' => 'All paid', 'Tamu belum bayar' => 'Guest unpaid',
        'Tamu cicil' => 'Guest paying in installments', 'Tamu lunas' => 'Guest paid', 'Sudah Dibayar' => 'Already Paid',
        // ---- teks di dalam template JS ----
        'Nama Mitra Drop Default' => 'Default Drop Partner Name', 'Dipakai untuk Airport/Harbor Drop yang tidak punya nama mitra spesifik.' => 'Used for Airport/Harbor Drops that have no specific partner name.',
        'Total tarif' => 'Total rate', 'Komisi hotel' => 'Hotel commission', 'Total tarif tamu' => 'Total guest rate', 'Bagian Mitra' => 'Partner Share', 'Tarif Tamu' => 'Guest Rate',
        'Mitra,' => 'Partner,', 'Dibuat oleh,' => 'Prepared by,', 'Disetujui oleh,' => 'Approved by,', 'Tamu' => 'Guest', 'Mitra' => 'Partner', 'Layanan' => 'Service', 'Bayar' => 'Pay', 'Keterangan' => 'Description',
        // ---- pesan sistem (flash) ----
        'Data pembayaran tidak lengkap!' => 'Payment data is incomplete!', 'ID tagihan tidak valid' => 'Invalid bill ID', 'Lengkapi semua field yang wajib!' => 'Complete all required fields!', 'Nama tagihan wajib diisi!' => 'Bill name is required!',
        'Nominal dan jatuh tempo wajib diisi' => 'Amount and due date are required', 'Tagihan berhasil dihapus' => 'Bill deleted successfully', 'Tagihan berhasil ditambahkan' => 'Bill added successfully', 'Tagihan ini sudah dibayar' => 'This bill has already been paid',
        'Tagihan tidak ditemukan' => 'Bill not found', 'Tagihan untuk template dan periode ini sudah ada!' => 'A bill for this template and period already exists!', 'Template tagihan berhasil dihapus' => 'Bill template deleted successfully',
        'Template tidak bisa dihapus karena sudah ada pembayaran yang tercatat' => 'The template cannot be deleted because payments have been recorded', 'Tidak bisa menghapus tagihan yang sudah dibayar' => 'Cannot delete a bill that has been paid',
    ],
    'js' => [
        '<p class="dr-empty">Tidak ada rental dengan filter ini</p>' => '<p class="dr-empty">No rentals with this filter</p>', '<p class="dr-empty">Tidak ada transaksi dengan filter ini</p>' => '<p class="dr-empty">No transactions with this filter</p>',
        'Pilih bulan terlebih dahulu' => 'Select a month first', 'Tidak ada tagihan bulan ini' => 'No bills this month', 'Jatuh tempo tanggal <strong>' => 'Due on day <strong>', 'Mitra,' => 'Partner,',
        'Bagian mitra tidak boleh melebihi total' => 'The partner share cannot exceed the total', 'Bagian mitra tidak valid' => 'Invalid partner share', 'Bagian pemilik tidak boleh melebihi total tarif' => 'The owner share cannot exceed the total rate',
        'Bagian pemilik tidak valid' => 'Invalid owner share', 'Bayar Tagihan Gudang Nasita' => 'Pay Nasita Warehouse Bill', 'Belum Dibayar' => 'Unpaid', 'Belum dibayar' => 'Unpaid', 'Cetak Pengajuan Pembayaran' => 'Print Payment Request',
        'Dari rekening mana? (1=Kas Tunai, 2=Bank Utama, dst)\nBiarkan kosong jika default' => 'From which account? (1=Cash, 2=Main Bank, etc.)\nLeave empty for the default', 'Data tagihan tidak ditemukan, silakan muat ulang daftar.' => 'Bill data not found, please reload the list.',
        'Dibayar Sebagian' => 'Partially Paid', 'Driver / Mitra' => 'Driver / Partner', 'Gagal menyimpan nama mitra' => 'Failed to save the partner name', 'Gagal update status pembayaran' => 'Failed to update the payment status',
        'Hapus Tagihan' => 'Delete Bill', 'Izinkan pop-up untuk mencetak laporan.' => 'Allow pop-ups to print the report.', 'Jumlah tidak valid' => 'Invalid amount', 'Layanan' => 'Service', 'Lunas' => 'Paid',
        'Masukkan nama kategori!' => 'Enter the category name!', 'Masukkan nama supplier/toko!' => 'Enter the supplier/store name!', 'Masukkan nominal tagihan!' => 'Enter the bill amount!',
        'Metode pembayaran? (cash, transfer, card, other)' => 'Payment method? (cash, transfer, card, other)', 'Motor' => 'Motorbike', 'Motor Rental' => 'Motorbike Rental', 'Nama barang/item' => 'Item name',
        'Nama mitra tidak boleh kosong' => 'The partner name cannot be empty', 'Pilih minimal 1 tagihan!' => 'Select at least 1 bill!', 'Rekap Tagihan Guide Trip' => 'Trip Guide Bill Summary',
        'Rekap Tagihan Mitra Motor' => 'Motorbike Partner Bill Summary', 'Rekap Tagihan Mitra Transport' => 'Transport Partner Bill Summary', 'Rental Mobil' => 'Car Rental', 'Semua lunas' => 'All paid',
        'Set Belum' => 'Set Unpaid', 'Set Lunas' => 'Set Paid', 'Set pembayaran mitra menjadi BELUM dibayar?' => 'Set the partner payment to UNPAID?', 'Set pembayaran mitra menjadi LUNAS?' => 'Set the partner payment to PAID?',
        'Simpan Nama' => 'Save Name', 'Tamu belum bayar' => 'Guest unpaid', 'Tamu cicil' => 'Guest paying in installments', 'Tamu lunas' => 'Guest paid',
        'Tanda terima hanya tersedia untuk pembayaran driver yang sudah lunas.' => 'The receipt is only available for driver payments that are fully paid.', 'Tanpa Nama Customer' => 'No Customer Name', 'Tanpa Pemilik' => 'No Owner',
        'Tidak ada tagihan untuk dicetak. Pilih bulan/kategori yang memiliki data terlebih dahulu.' => 'No bills to print. Select a month/category that has data first.', 'Total tarif tidak valid' => 'Invalid total rate',
        'Transfer Bank' => 'Bank Transfer', 'tagihan driver/mitra' => 'driver/partner bills', 'tagihan trip' => 'trip bills', '✅ Bayar & Catat ke Kas' => '✅ Pay & Record in Cash Book',
        '✏️ Edit Tagihan' => '✏️ Edit Bill', '➕ Tambah Tagihan Baru' => '➕ Add New Bill', '💾 Simpan' => '💾 Save', '💾 Simpan Perubahan' => '💾 Save Changes', '💾 Simpan Tagihan' => '💾 Save Bill',
        '</strong> setiap bulan · Metode: <strong>' => '</strong> every month · Method: <strong>',
    ],
    // fragmen yang dirangkai JavaScript (kotak ringkasan & tabel daftar mitra/driver)
    'jsraw' => [
        '>Bagian mitra (${scopedAvgPct}%)</div>' => '>Partner share (${scopedAvgPct}%)</div>',
        '>Belum dibayar (${scopedUnpaidTrips})</div>' => '>Unpaid (${scopedUnpaidTrips})</div>',
        '>Sudah dibayar (${scopedPaidTrips})</div>' => '>Already paid (${scopedPaidTrips})</div>',
        '>Bagian Mitra</th>' => '>Partner Share</th>', '>Tarif Tamu</th>' => '>Guest Rate</th>', '>Total tarif</div>' => '>Total rate</div>',
        '>Dibuat oleh,</div>' => '>Prepared by,</div>', '>Disetujui oleh,</div>' => '>Approved by,</div>', '>Mitra,</div>' => '>Partner,</div>',
    ],
    'patterns' => [],
];
