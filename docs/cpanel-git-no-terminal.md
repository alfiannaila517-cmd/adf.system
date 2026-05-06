# Panduan Paling Aman Ganti Remote Git di cPanel Tanpa Terminal

Panduan ini dipakai jika:

- folder website sudah berisi file,
- file `.git` sudah dihapus,
- dan menu edit remote tidak muncul di cPanel Git Version Control.

Tujuan:

- tetap deploy pakai Git,
- tanpa Terminal/SSH,
- dan tanpa mengubah database.

## Penting dulu

Jangan klik **Create Repository** langsung ke folder `/home/adfb2574/public_html` yang sudah berisi file, karena cPanel akan menolak clone ke folder yang tidak kosong.

## Cara paling aman tanpa Terminal

### Opsi A - Paling aman untuk kondisi sekarang

1. Buka **cPanel**.
2. Masuk ke **File Manager**.
3. Buka folder `/home/adfb2574/public_html`.
4. Buat folder baru kosong, misalnya:
   - `repo-new`
5. Buka **Git Version Control**.
6. Klik **Create** / **Create Repository**.
7. Isi **Clone URL** dengan:
   - `https://github.com/arifnarayana88-collab/adf.system.git`
8. Isi **Repository Path** ke folder kosong tadi, misalnya:
   - `/home/adfb2574/public_html/repo-new`
9. Isi **Repository Name** bebas, misalnya:
   - `adf_system`
10. Klik **Create**.
11. Tunggu sampai clone selesai.
12. Setelah selesai, pastikan remote/branch sudah muncul.

### Setelah clone berhasil

Karena folder live lama masih ada file-file website, Anda perlu pilih salah satu:

- pindahkan isi folder clone ke lokasi live secara manual lewat File Manager,
- atau gunakan folder clone itu sebagai folder kerja baru untuk deploy berikutnya.

## Opsi B - Kalau Anda tetap mau pakai folder lama

Kalau Anda ingin tetap memakai `/home/adfb2574/public_html` yang lama, maka:

1. Jangan hapus file website.
2. Cek apakah ada opsi **Delete Repository** di Git Version Control.
3. Kalau ada, hapus **repository Git-nya saja**, bukan file di File Manager.
4. Setelah itu, buat repo baru di folder kosong atau reconnect dari awal.

## Kenapa edit remote tidak muncul

Itu biasanya karena:

- `.git` sudah dihapus,
- repository Git belum terdaftar lagi,
- atau cPanel hanya mendeteksi folder biasa, bukan repo Git aktif.

## Hal yang tidak boleh dilakukan

- Jangan klik delete folder di File Manager.
- Jangan hapus `public_html`.
- Jangan jalankan reset database.
- Jangan clone ke folder yang sudah penuh file.

## Kalau ingin deploy harian yang sederhana

Setelah repo baru berhasil aktif, alurnya menjadi:

1. dari lokal: commit + push ke remote baru,
2. di server: pull dari repo baru.

## Tanda aman

- file website tetap ada,
- database tetap tidak berubah,
- repo Git baru muncul di cPanel,
- dan deploy berikutnya tinggal update dari remote baru.
