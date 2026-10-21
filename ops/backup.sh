#!/usr/bin/env bash
# Backup harian ADF di VPS (jalan sebagai root lewat /etc/cron.d/adf-backup, 02:30 WIB).
#   Manual : sudo bash /srv/adf/app/ops/backup.sh
#   Isi    : setiap database adf_* dan adfb2574_* (mariadb-dump, terkompres) + file penting (secrets, config bisnis,
#            file rahasia sistem lama, Nginx). Upload/foto hanya hari Minggu (besar & jarang berubah).
#   Rotasi : 7 harian · 4 mingguan (Minggu) · 3 bulanan (tgl 1). Mingguan/bulanan = hardlink → tidak makan ruang tambahan.
#   Penjaga: tidak jalan bila disk ≥ 85% terpakai atau sisa < 3 GB (monitor Telegram memberi tahu).
#   Status : /srv/adf/backups/last.json (dibaca monitor → laporan pagi & peringatan bila gagal / terlewat).
# Pulihkan satu database:  zcat /srv/adf/backups/daily/<tanggal>/db/<nama>.sql.gz | sudo mariadb <nama>
set -uo pipefail

ROOT=/srv/adf/backups
DAY=$(date +%F)
DEST="$ROOT/daily/$DAY"
STATUS="$ROOT/last.json"
START=$(date +%s)
LEGACY=/srv/adf/legacy

status() { # status ok|failed "pesan" [jumlah_db] [ukuran_mb]
  printf '{"ok":%s,"date":"%s","finished":"%s","message":"%s","databases":%s,"size_mb":%s,"seconds":%s}\n' \
    "$([[ $1 == ok ]] && echo true || echo false)" "$DAY" "$(date '+%F %T')" "${2//\"/\'}" "${3:-0}" "${4:-0}" "$(( $(date +%s) - START ))" > "$STATUS"
  chmod 644 "$STATUS"
}

if [[ $EUID -ne 0 ]]; then echo "Jalankan dengan sudo."; exit 1; fi
mkdir -p "$ROOT/daily" "$ROOT/weekly" "$ROOT/monthly"
chmod 750 "$ROOT"

# --- penjaga disk ---
USED=$(df --output=pcent / | tail -1 | tr -dc '0-9')
FREE_GB=$(( $(df --output=avail -k / | tail -1) / 1048576 ))
if (( USED >= 85 || FREE_GB < 3 )); then
  status failed "Disk penuh (${USED}% terpakai, sisa ${FREE_GB} GB) — backup ditunda"
  echo "$(date '+%F %T') DITUNDA: disk ${USED}% / sisa ${FREE_GB} GB"; exit 1
fi

rm -rf "$DEST"; mkdir -p "$DEST/db" "$DEST/files"; chmod 700 "$DEST"
FAILED=()
COUNT=0

# --- database ---
for DB in $(mariadb -N -e "SHOW DATABASES" | grep -E '^(adf_|adfb2574_)'); do
  if mariadb-dump --single-transaction --quick --routines --triggers --events --hex-blob "$DB" 2>>"$DEST/errors.log" | gzip -6 > "$DEST/db/$DB.sql.gz" \
     && [[ ${PIPESTATUS[0]} -eq 0 ]]; then
    COUNT=$((COUNT + 1))
  else
    FAILED+=("$DB")
  fi
done

# --- file penting (kecil): secrets, config bisnis & file rahasia sistem lama, Nginx ---
tar -czf "$DEST/files/config.tar.gz" --ignore-failed-read \
  /srv/adf/secrets \
  "$LEGACY/public_html/config/businesses" "$LEGACY/public_html/config/local-db-config.php" \
  "$LEGACY"/*.php "$LEGACY"/*.json \
  /etc/nginx/sites-available /etc/cron.d 2>>"$DEST/errors.log" || FAILED+=("config")

# --- upload/foto: hanya hari Minggu ---
if [[ $(date +%u) -eq 7 && -d "$LEGACY/public_html/uploads" ]]; then
  tar -czf "$DEST/files/uploads.tar.gz" -C "$LEGACY/public_html" uploads 2>>"$DEST/errors.log" || FAILED+=("uploads")
fi

# --- rotasi (hardlink untuk mingguan & bulanan) ---
if [[ $(date +%u) -eq 7 ]]; then rm -rf "$ROOT/weekly/$DAY"; cp -al "$DEST" "$ROOT/weekly/$DAY"; fi
if [[ $(date +%d) == "01" ]]; then rm -rf "$ROOT/monthly/$DAY"; cp -al "$DEST" "$ROOT/monthly/$DAY"; fi
keep() { ls -1d "$1"/*/ 2>/dev/null | sort | head -n -"$2" | xargs -r rm -rf; }
keep "$ROOT/daily" 7
keep "$ROOT/weekly" 4
keep "$ROOT/monthly" 3

SIZE_MB=$(( $(du -sk "$DEST" | cut -f1) / 1024 ))
[[ -s "$DEST/errors.log" ]] || rm -f "$DEST/errors.log"
if (( ${#FAILED[@]} )); then
  status failed "Gagal: ${FAILED[*]}" "$COUNT" "$SIZE_MB"
  echo "$(date '+%F %T') SEBAGIAN GAGAL: ${FAILED[*]} ($COUNT database OK, ${SIZE_MB} MB)"; exit 1
fi
status ok "OK" "$COUNT" "$SIZE_MB"
echo "$(date '+%F %T') OK: $COUNT database, ${SIZE_MB} MB → $DEST"
