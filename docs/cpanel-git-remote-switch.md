# Panduan Ganti Remote Repo di cPanel Git Version Control

Panduan ini untuk kasus saat repo lama masih tertanam di cPanel, lalu Anda ingin pindah ke remote baru:

`https://github.com/arifnarayana88-collab/adf.system.git`

## Tujuan

- Mengganti remote lama ke repo baru.
- Tetap deploy pakai Git version control.
- Tidak menyentuh database.

## Yang perlu disiapkan

- Akses cPanel.
- Repo GitHub baru yang aktif.
- Branch utama yang dipakai, biasanya `main`.

## Urutan klik di cPanel

1. Login ke cPanel.
2. Buka menu **Git Version Control**.
3. Cari repository `adf_system`.
4. Klik repository itu untuk masuk ke detailnya.
5. Lihat bagian **Remote URL**.
6. Pastikan URL mengarah ke repo baru:
   - `https://github.com/arifnarayana88-collab/adf.system.git`
7. Kalau ada tombol **Edit** atau **Manage**, klik lalu ganti remote URL ke repo baru.
8. Simpan perubahan.
9. Jika ada pilihan **Update from Remote** atau **Pull**, jalankan setelah remote diganti.
10. Setelah selesai, cek apakah branch yang aktif adalah `main`.

## Jika cPanel tidak menyediakan edit remote

Kalau halaman repository hanya menampilkan repo lama dan tidak ada opsi ubah remote, biasanya ada 2 kemungkinan:

- Repository perlu dihapus lalu dibuat ulang dengan URL baru.
- Atau repository perlu di-reconnect dari awal ke repo baru.

## Perintah alternatif via SSH/Terminal

Kalau server punya terminal atau SSH, jalankan:

```bash
cd /home/adfb2574/public_html
git remote set-url origin https://github.com/arifnarayana88-collab/adf.system.git
git remote -v
git pull origin main
```

## Alur deploy harian yang sederhana

### Dari lokal

```bash
git add -A
git commit -m "update"
git push origin main
```

### Di server

```bash
cd /home/adfb2574/public_html
git pull origin main
```

## Catatan aman

- Jangan jalankan perintah reset database.
- Jangan gunakan `drop`, `truncate`, atau migration destruktif di production.
- Kalau sistem dipakai setiap hari, lakukan deploy saat trafik rendah.

## Tanda sukses

- `git remote -v` menampilkan repo baru.
- `git pull origin main` selesai tanpa error.
- File kode di server berubah sesuai commit terbaru.
- Database tetap aman dan tidak berubah.
