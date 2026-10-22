#!/usr/bin/env bash
# Pindah data database sistem lama (adfb2574_*) — TANPA Console. Jalankan dengan sudo.
#
#   sudo bash /srv/adf/app/ops/legacy-db.sh list
#       Daftar database + jumlah tabel + ukuran.
#
#   sudo bash /srv/adf/app/ops/legacy-db.sh export <database>
#       Simpan database ke /srv/adf/transfer/<database>-<tanggal-jam>.sql.gz (untuk dibawa ke tempat lain).
#
#   sudo bash /srv/adf/app/ops/legacy-db.sh import <file> <database> [--live] [--force]
#       Ganti ISI database dengan file dump (.sql / .sql.gz / .zip dari cPanel atau phpMyAdmin).
#       - Sebelum diganti, isi lama otomatis disimpan ke /srv/adf/backups/pre-import/ (bisa dikembalikan).
#       - Default = mode STAGING: token integrasi (Cloudbeds, WA, Fingerspot, email, OpenAI, Pakasir, langganan)
#         dikosongkan & push_subscriptions dikosongkan → VPS tidak ikut sync/kirim pesan selama hosting masih jalan.
#       - --live  : hanya saat pindah rumah sungguhan (cutover) — token TIDAK dikosongkan.
#       - --force : wajib untuk database yang sudah LIVE di VPS (adfb2574_sn1_copy = Line Wisata).
#
#   Mengembalikan dari backup otomatis:  sudo bash legacy-db.sh import /srv/adf/backups/pre-import/<file>.sql.gz <database> --force
set -euo pipefail

PREFIX=adfb2574_
TRANSFER=/srv/adf/transfer
PRE=/srv/adf/backups/pre-import
LIVE_ON_VPS=(adfb2574_sn1_copy)   # database yang sudah dipakai sungguhan di VPS — jangan tertimpa tanpa --force

die() { echo "GAGAL: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "jalankan dengan sudo."

db_exists() { [[ -n $(mariadb -N -e "SHOW DATABASES LIKE '$1'") ]]; }
count_tables() { mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }
size_mb() { mariadb -N -e "SELECT ROUND(COALESCE(SUM(data_length+index_length),0)/1048576,1) FROM information_schema.tables WHERE table_schema='$1'"; }
dump() { mariadb-dump --single-transaction --quick --routines --triggers --events --hex-blob "$1"; }

check_name() {
  [[ $1 =~ ^${PREFIX}[A-Za-z0-9_]+$ ]] || die "nama database harus diawali ${PREFIX} (contoh ${PREFIX}narayana_hotel)."
}

cmd_list() {
  printf '%-32s %7s %9s\n' DATABASE TABEL "UKURAN MB"
  for DB in $(mariadb -N -e "SHOW DATABASES" | grep "^${PREFIX}"); do
    printf '%-32s %7s %9s\n' "$DB" "$(count_tables "$DB")" "$(size_mb "$DB")"
  done
}

cmd_export() {
  local DB=$1; check_name "$DB"; db_exists "$DB" || die "database $DB tidak ada."
  mkdir -p "$TRANSFER"; chmod 750 "$TRANSFER"
  local OUT="$TRANSFER/$DB-$(date +%Y%m%d-%H%M).sql.gz"
  dump "$DB" | gzip -6 > "$OUT"
  echo "OK: $OUT ($(du -h "$OUT" | cut -f1), $(count_tables "$DB") tabel)"
  echo "Ambil ke laptop:  scp <user>@103.89.5.50:$OUT ."
}

# Tulis isi file dump (apa pun formatnya) ke stdout, tanpa CREATE DATABASE/USE (database tujuan ditentukan skrip)
# dan tanpa DEFINER (user hosting tidak ada di VPS → view/trigger gagal dibuat).
read_dump() {
  case "$1" in
    *.sql.gz|*.gz) zcat "$1" ;;
    *.zip) unzip -p "$1" '*.sql' ;;
    *.sql) cat "$1" ;;
    *) die "format tidak dikenal: $1 (pakai .sql, .sql.gz, atau .zip)" ;;
  esac | sed -E '/^CREATE DATABASE /d; /^USE `/d; s/DEFINER=`[^`]+`@`[^`]+`//g
                 s/^CREATE TABLE (`[^`]+`)/DROP TABLE IF EXISTS \1;\nCREATE TABLE \1/'
}

# Sistem lama membuat tabel yang hilang sendiri setiap halaman dibuka (config/database.php auto-create).
# Selama import, akses aplikasi ke database ini dicabut supaya tidak ada tabel kosong yang "mendahului" isi dump.
APP_USER="'adfb2574_adfsystem'@'localhost'"
app_block() {
  local DB=$1
  mariadb -e "REVOKE ALL PRIVILEGES ON \`$DB\`.* FROM $APP_USER" 2>/dev/null || true
  for ID in $(mariadb -N -e "SELECT id FROM information_schema.processlist WHERE user='adfb2574_adfsystem' AND db='$DB'"); do
    mariadb -e "KILL $ID" 2>/dev/null || true
  done
  # izin wildcard (mis. adfb2574\_%) tidak ikut tercabut → beri tahu saja, DROP TABLE IF EXISTS tetap melindungi
  if [[ -n $(mariadb -N -e "SELECT 1 FROM mysql.db WHERE User='adfb2574_adfsystem' AND '$DB' LIKE Db LIMIT 1") ]]; then
    echo "  Catatan: aplikasi punya izin wildcard — tutup dulu tab staging bila import gagal lagi."
  fi
}
app_unblock() {
  mariadb -e "GRANT ALL PRIVILEGES ON \`$1\`.* TO $APP_USER" || echo "PERINGATAN: izin aplikasi ke $1 belum kembali — jalankan ulang skrip." >&2
}

# Mode staging: kosongkan token & sakelar integrasi supaya VPS tidak berebut dengan hosting.
scrub() {
  local DB=$1
  if [[ -n $(mariadb -N -e "SHOW TABLES FROM \`$DB\` LIKE 'settings'") ]]; then
    local N
    N=$(mariadb -N "$DB" -e "
      UPDATE settings SET setting_value = '' WHERE setting_value <> '' AND (
           setting_key IN ('cloudbeds_api_key','cloudbeds_auto_sync','cloudbeds_push_enabled','cloudbeds_pay_enabled',
                           'wa_token','wa_checkin_enabled','email_imap_pass','email_imap_smtp_pass','fingerspot_token','fingerspot_enabled',
                           'openai_api_key','openai_active','subscription_client_key','subscription_client_token',
                           'subscription_sync_url','subscription_pakasir_api_key')
        OR setting_key LIKE 'pakasir\_%' OR setting_key LIKE 'smtp\_pass%');
      SELECT ROW_COUNT();")
    echo "  Mode staging: $N pengaturan integrasi dikosongkan."
  fi
  if [[ -n $(mariadb -N -e "SHOW TABLES FROM \`$DB\` LIKE 'push_subscriptions'") ]]; then
    mariadb "$DB" -e "TRUNCATE push_subscriptions"
    echo "  Mode staging: push_subscriptions dikosongkan (HP staf tidak dapat notifikasi dari VPS)."
  fi
}

cmd_import() {
  local FILE=$1 DB=$2; shift 2
  local LIVE=0 FORCE=0
  for a in "$@"; do case $a in --live) LIVE=1 ;; --force) FORCE=1 ;; *) die "opsi tidak dikenal: $a" ;; esac; done
  check_name "$DB"
  [[ -f $FILE ]] || die "file $FILE tidak ditemukan."
  if [[ " ${LIVE_ON_VPS[*]} " == *" $DB "* && $FORCE -eq 0 ]]; then
    die "$DB sudah LIVE di VPS — tidak ditimpa. Tambahkan --force bila memang sengaja."
  fi
  [[ -s $FILE ]] || die "file $FILE kosong."

  # 1) simpan isi lama dulu
  if db_exists "$DB" && (( $(count_tables "$DB") > 0 )); then
    mkdir -p "$PRE"; chmod 700 "$PRE"
    local SAVE="$PRE/$DB-$(date +%Y%m%d-%H%M%S).sql.gz"
    dump "$DB" | gzip -6 > "$SAVE"
    echo "1) Isi lama disimpan: $SAVE"
    ls -1t "$PRE"/"$DB"-*.sql.gz | tail -n +6 | xargs -r rm -f   # simpan 5 terakhir per database
  else
    echo "1) Database $DB belum ada / kosong — tidak perlu disimpan."
  fi

  # 2) kosongkan & isi ulang (bila gagal di tengah: kembalikan dari file langkah 1)
  trap 'echo "Import GAGAL di tengah jalan. Kembalikan isi lama dengan file di $PRE (perintah ada di bagian atas skrip)." >&2' ERR
  app_block "$DB"
  trap "app_unblock '$DB'" EXIT   # izin aplikasi selalu kembali, berhasil maupun gagal
  mariadb -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\`;"
  { echo "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"; read_dump "$FILE"; echo "SET FOREIGN_KEY_CHECKS=1;"; } | mariadb "$DB"
  echo "2) Import selesai: $DB — $(count_tables "$DB") tabel, $(size_mb "$DB") MB."

  # 3) pengaman staging
  if (( LIVE )); then
    echo "3) Mode LIVE: token integrasi dibiarkan (pastikan integrasi di hosting sudah DIMATIKAN)."
  else
    scrub "$DB"
  fi
}

case "${1:-}" in
  list) cmd_list ;;
  export) [[ $# -eq 2 ]] || die "pakai: legacy-db.sh export <database>"; cmd_export "$2" ;;
  import) [[ $# -ge 3 ]] || die "pakai: legacy-db.sh import <file> <database> [--live] [--force]"; shift; cmd_import "$@" ;;
  *) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
