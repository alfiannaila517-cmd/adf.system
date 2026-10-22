<?php
/** Kamus EN: Pengaturan Email Kantor — bagian "kirim lewat layanan lain" (modules/email/settings.php). */
return [
    'text' => [
        'Kirim lewat layanan lain (opsional, mis. Brevo)' => 'Send through another service (optional, e.g. Brevo)',
        'Isi bila email keluar (konfirmasi reservasi tamu, notifikasi) dikirim lewat layanan pengiriman seperti Brevo, bukan lewat akun email di atas. Kosongkan Login SMTP untuk kembali memakai akun email di atas. Untuk Brevo: Outgoing Server' => 'Fill in when outgoing emails (guest reservation confirmations, notifications) are sent through a sending service such as Brevo instead of the email account above. Leave SMTP Login empty to use the email account above again. For Brevo: Outgoing Server',
        'Login SMTP' => 'SMTP login',
        'Key / password SMTP' => 'SMTP key / password',
        'SMTP key dari layanan pengiriman' => 'SMTP key from the sending service',
        'Email pengirim' => 'Sender email',
        'Harus alamat yang sudah diverifikasi di layanan pengiriman.' => 'Must be an address verified in the sending service.',
        'Balasan tamu masuk ke' => 'Guest replies go to',
        // placeholder
        'mis. xxxx@smtp-brevo.com' => 'e.g. xxxx@smtp-brevo.com',
        'mis. reservasi@narayanakarimunjawa.com' => 'e.g. reservasi@narayanakarimunjawa.com',
        'mis. narayanahotelkarimunjawa@gmail.com' => 'e.g. narayanahotelkarimunjawa@gmail.com',
    ],
];
