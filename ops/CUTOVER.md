# Runbook hari pindahan: sistem ADF dari hosting (Rumahweb) ke VPS

Ruang lingkup: **adfsystem.online** (sistem Nasita Group: Narayana Hotel, Gudang Nasita, Bens Cafe, Eat Meet) dan
**narayanakarimunjawa.com** (website hotel). Line Wisata sudah di VPS. PWF, CQC, dan adfsystem.store menyusul nanti.
Hosting **tidak dihapus**; ia tetap jadi cadangan selama 2–4 minggu.

Perkiraan waktu: **±2 jam**. Sistem berhenti untuk input **±30–45 menit** (langkah 2–5).
Waktu terbaik: malam, saat tamu check-in/out sedikit (mis. 22.00).

---

## H-1 (sehari sebelumnya)
- [ ] Beri tahu staf: jam berapa sistem berhenti sebentar, dan bahwa setelah itu **login sama seperti biasa**.
- [ ] Di Cloudflare, untuk `adfsystem.online`, `www`, `narayanakarimunjawa.com`, dan `www`: TTL record A = **Auto** (sudah, karena Cloudflare memakai 5 menit).
- [ ] Unduh dari cPanel **file rahasia** yang tidak ada di git (lihat lampiran A), lalu `scp` ke VPS dan simpan di tempat yang sama.
- [ ] Siapkan cron VPS: `sudo bash /srv/adf/app/ops/legacy-cron-setup.sh prepare` (cron belum aktif, lihat lampiran B).
- [ ] Google Cloud Console → OAuth: pastikan alamat callback yang sama berlaku (domain tidak berubah → biasanya tidak perlu diubah).
- [ ] Backup lengkap hosting (cPanel → Backup → Full Backup), simpan di laptop.
- [ ] Fitur **Maintenance** sudah ada di hosting (cPanel → Git → Update from Remote + Deploy HEAD Commit) dan dicoba sekali di staging: Developer Panel → Maintenance → aktifkan → buka di jendela InPrivate → tampil hitung mundur → Selesai.

## Hari H

### 1. Persiapan (sistem masih jalan di hosting)
- [ ] Di VPS: `cd /srv/adf/app && sudo -u adf git pull && sudo -u adf git -C /srv/adf/legacy/public_html pull`
- [ ] Di VPS: `sudo -u adf php /srv/adf/app/cli/console.php migrate && sudo systemctl reload php8.2-fpm`

### 2. Hentikan input di hosting
- [ ] Hosting: `adfsystem.online/developer` → **Maintenance** → durasi ±90 menit, centang *Website hotel ikut ditutup* → Aktifkan.
  Staf langsung melihat pemberitahuan + hitung mundur (input otomatis terhenti). Browser Anda tetap bisa masuk.
- [ ] VPS: `staging.adfsystem.online/developer` → **Maintenance** → aktifkan juga (setelah alamat pindah, staf tetap melihat pemberitahuan sampai uji selesai).
- [ ] Di hosting: **matikan integrasi**:
  - **Cloudbeds:** auto-sync & push dimatikan
  - **WA Fonnte:** kosongkan token atau matikan
  - **Fingerspot:** biarkan, webhook diarahkan di langkah 6
- [ ] cPanel → Cron Jobs: **nonaktifkan semua cron** (beri tanda `#` di depan atau hapus; catat dulu).

### 3. Ambil data terakhir dari hosting
cPanel → Backup → *Download a MySQL Database Backup*:
- [ ] `adfb2574_adf` (master)
- [ ] `adfb2574_narayana_hotel`
- [ ] `adfb2574_Adf_Bens`
- [ ] `adfb2574_eat_meet`
- [ ] `adfb2574_web_narayana`
- [ ] Folder `uploads/` (File Manager → compress → download; **hapus zip-nya dari hosting** setelah terunduh)

Kirim ke VPS: `scp $env:USERPROFILE\Downloads\adfb2574_*.sql.gz adfarif@103.89.5.50:~/`

### 4. Import di VPS (mode LIVE: token integrasi dipertahankan)
```bash
for DB in adfb2574_narayana_hotel adfb2574_Adf_Bens adfb2574_eat_meet adfb2574_web_narayana; do
  sudo bash /srv/adf/app/ops/legacy-db.sh import ~/$DB.sql.gz $DB --live
done
sudo bash /srv/adf/app/ops/master-merge.sh ~/adfb2574_adf.sql.gz --live
sudo unzip -n ~/uploads.zip -d /srv/adf/legacy/public_html/ && sudo chown -R www-data:adf /srv/adf/legacy/public_html/uploads
sudo bash /srv/adf/app/ops/legacy-db.sh list
```
- [ ] Hapus file uji website: `sudo rm /srv/adf/legacy/public_html/website/config/secrets.php`
  (website kembali mengirim notifikasi ke adfsystem.online)

### 5. Alihkan alamat (Cloudflare → DNS)
- [ ] `adfsystem.online` A → **103.89.5.50** (`www` mengikuti, CNAME)
- [ ] `narayanakarimunjawa.com` A → **103.89.5.50** (`www` mengikuti)
- [ ] Jangan ubah MX/TXT (email tetap seperti sekarang).
- Tunggu 1–5 menit, lalu di VPS:
```bash
sudo bash /srv/adf/app/ops/add-domain.sh adfsystem.online          # pintu sistem + SSL
sudo bash /srv/adf/app/ops/add-website.sh narayanakarimunjawa.com   # website hotel + SSL
```

### 6. Nyalakan di VPS
- [ ] Cron VPS diaktifkan: `sudo bash /srv/adf/app/ops/legacy-cron-setup.sh enable`
- [ ] **Cloudbeds** dinyalakan lagi di sistem (VPS): auto-sync & push.
- [ ] **WA Fonnte**: token diisi di sistem (VPS); webhook Fonnte (bila ada) ke `https://adfsystem.online/…`.
- [ ] **Fingerspot**: webhook tetap `https://adfsystem.online/api/fingerprint-webhook.php`, karena domainnya sama, otomatis ke VPS.
- [ ] **Pakasir**: callback tetap di domain yang sama → tidak perlu diubah.
- [ ] **Langganan**: di setiap bisnis, menu Langganan → alamat sinkron `https://console.adfsystem.store/api/subscription-config.php` + client key/token dari Console.
- [ ] **Email Kantor Narayana**: Pengaturan Email → "Kirim lewat layanan lain" diisi SMTP Brevo; pengirim `reservasi@narayanakarimunjawa.com`.

### 7. Uji (15 menit)
Buka dulu `adfsystem.online/developer` → **Maintenance** (sekarang sudah di VPS) supaya browser Anda dapat akses lewat; staf masih melihat pemberitahuan.
- [ ] Login System & Owner Login, pindah bisnis (4 bisnis)
- [ ] Narayana: kalender, buat 1 reservasi uji lalu batalkan, buku kas
- [ ] Bens / Eat Meet: transaksi kasir uji
- [ ] Gudang: buka stok
- [ ] Website: buka beranda, kamar, 1 pemesanan uji → masuk ke sistem
- [ ] Staff Portal & absen dari HP
- [ ] Console → Beranda: server normal, backup jalan

### 8. Buka kembali
- [ ] VPS: Developer Panel → Maintenance → **Selesai — buka sistem**. Layar staf terbuka sendiri dalam ±30 detik.
- [ ] Butuh waktu lebih? Klik **+15 menit**.
- [ ] Alamat baru untuk staf: Developer Panel → Maintenance → **Pindah alamat** → `adfsystem.online` → `nasita.biz.id`, mode **Info dulu**.
  Staf yang membuka alamat lama melihat alamat baru + cara menyimpannya, lalu pindah otomatis. Webhook/API tetap di alamat lama.
  (Sebelumnya: Google Cloud → OAuth → tambahkan redirect URI `https://nasita.biz.id/google-callback.php`.)
- [ ] Umumkan ke staf: **sistem sudah normal**. Bila HP masih membuka versi lama, tutup aplikasi lalu buka lagi.
- [ ] Pantau 1–2 hari: laporan Telegram pagi, Cloudbeds tidak dobel, WA terkirim sekali.

---

## Jalan kembali (kalau ada masalah besar)
1. Cloudflare: kembalikan A `adfsystem.online` & `narayanakarimunjawa.com` ke **202.10.43.81** (hosting).
2. Hosting: nyalakan lagi cron & integrasi (Cloudbeds, WA), lalu Developer Panel → Maintenance → Selesai.
3. Data yang diinput di VPS selama masa gangguan dicatat manual, lalu diinput ulang di hosting.

## Setelah 2–4 minggu stabil
- Pindah alamat → mode **Langsung pindah** (tanpa halaman info).
- Hapus subdomain uji: `web-staging.adfsystem.online` (Cloudflare + `sudo rm /etc/nginx/sites-enabled/website-web-staging.adfsystem.online`).
- Lanjutkan rencana lepas Rumahweb (adfsystem.store, PWF, domain lain, lalu hapus hosting dengan persetujuan).

---

## Lampiran A: file rahasia di hosting (tidak ada di git)
Salin dengan struktur folder yang sama ke `/srv/adf/legacy/public_html/…` (dan `/srv/adf/legacy/` untuk file di luar public_html):
- `config/backup-secrets.php`, `config/email-narayana.php` (`config/mayar.php` tidak perlu: Mayar tidak dipakai)
- `website/config/secrets.php` (versi hosting, bila ada)
- File di folder home hosting: `adf-google-oauth.php`, `adf-sso-secret.php`, `adf-dev-login.json` → `/srv/adf/legacy/`
- Setelah disalin: `sudo chown -R www-data:adf` dan `chmod 640`.

## Lampiran B: cron VPS (sama dengan 4 cron cPanel hosting per 2026-10-22)
| Hosting (cPanel) | VPS (`ops/cron/adf-legacy`, user www-data) |
|---|---|
| `*/10` curl fingerprint-cron-sync | `*/10` php api/fingerprint-cron-sync.php --token=… --days=2 |
| `*/30` php cron/adf-telegram-report.php billing | sama |
| `*/5` php cron/cloudbeds-sync.php | sama |
| `*/10 5-9` php cron/breakfast-auto-onspot.php | sama |

- **H-1 (boleh lebih awal):** `sudo bash /srv/adf/app/ops/legacy-cron-setup.sh prepare`
  (folder tmp bersama supaya kunci sinkron Cloudbeds cron ↔ web sama; log `/var/log/adf-legacy` dirotasi mingguan)
- **Hari H langkah 6, SETELAH cron hosting dimatikan:** `sudo bash /srv/adf/app/ops/legacy-cron-setup.sh enable`
- **Jalan kembali:** `sudo bash /srv/adf/app/ops/legacy-cron-setup.sh disable`, lalu nyalakan lagi cron di cPanel.
