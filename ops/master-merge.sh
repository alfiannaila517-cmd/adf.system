#!/usr/bin/env bash
# Hari pindahan: ganti database master sistem lama (adfb2574_adf) dengan salinan TERBARU dari hosting, tanpa kehilangan
# data yang hanya ada di VPS. Jalankan dengan sudo.
#   sudo bash /srv/adf/app/ops/master-merge.sh ~/adfb2574_adf.sql.gz            (uji: token integrasi dikosongkan)
#   sudo bash /srv/adf/app/ops/master-merge.sh ~/adfb2574_adf.sql.gz --live     (hari H)
#
# Yang dipertahankan dari VPS (ditulis ulang setelah import):
#   - settings: login_brands (login perusahaan per domain, mis. nasita.biz.id) + KEEP_SETTINGS tambahan
#   - bisnis yang sudah LIVE di VPS (database di LIVE_DBS, mis. Line Wisata) beserta menu, kas & penugasan user-nya
# Setelah itu data orang & akses Console disegarkan dari master baru (console.php legacy-import --from-hosting).
set -euo pipefail

MASTER=adfb2574_adf
KEEP_SETTINGS=(login_brands)
LIVE_DBS=(adfb2574_sn1_copy)
CONSOLE_CLIENT="NASITA GROUP"   # nama grup di Console untuk 4 bisnis Nasita (supaya tidak dibuat grup baru)
FILE="${1:-}"; MODE="${2:-}"

[[ $EUID -eq 0 ]] || { echo "Jalankan dengan sudo."; exit 1; }
[[ -f "$FILE" ]] || { echo "pakai: master-merge.sh <dump master hosting .sql/.sql.gz> [--live]"; exit 1; }

KEEP=/srv/adf/backups/pre-import/master-keep-$(date +%Y%m%d-%H%M%S).sql
mkdir -p /srv/adf/backups/pre-import; chmod 700 /srv/adf/backups/pre-import

# 1) Simpan baris khusus VPS (REPLACE supaya bisa ditulis ulang di atas data hosting)
in_list() { local out='' x; for x in "$@"; do out+="${out:+,}'$x'"; done; echo "$out"; }
SET_WHERE="setting_key IN ($(in_list "${KEEP_SETTINGS[@]}"))"
BIZ_IDS=$(mariadb -N "$MASTER" -e "SELECT GROUP_CONCAT(id) FROM businesses WHERE database_name IN ($(in_list "${LIVE_DBS[@]}"))")
DUMP=(mariadb-dump --no-create-info --replace --skip-triggers --compact "$MASTER")
{
  echo "SET FOREIGN_KEY_CHECKS=0;"
  "${DUMP[@]}" settings --where="$SET_WHERE"
  if [[ -n "$BIZ_IDS" && "$BIZ_IDS" != "NULL" ]]; then
    "${DUMP[@]}" businesses --where="id IN ($BIZ_IDS)"
    for T in business_menu_config cash_accounts user_business_assignment; do
      if [[ -n $(mariadb -N "$MASTER" -e "SHOW TABLES LIKE '$T'") ]]; then
        "${DUMP[@]}" "$T" --where="business_id IN ($BIZ_IDS)"
      fi
    done
  fi
  echo "SET FOREIGN_KEY_CHECKS=1;"
} > "$KEEP"
echo "1) Data khusus VPS disimpan: $KEEP (bisnis live: ${BIZ_IDS:-tidak ada})"

# 2) Import master dari hosting (isi lama otomatis dicadangkan oleh legacy-db.sh; Line Wisata tidak ikut dikunci di sini)
ARGS=(import "$FILE" "$MASTER"); [[ "$MODE" == "--live" ]] && ARGS+=(--live)
bash /srv/adf/app/ops/legacy-db.sh "${ARGS[@]}"

# 3) Tulis ulang data khusus VPS
mariadb "$MASTER" < "$KEEP"
echo "3) Data khusus VPS dikembalikan (login perusahaan, bisnis yang sudah live di VPS)."

# 4) Data orang & akses di Console mengikuti master baru
if sudo -u adf php /srv/adf/app/cli/console.php legacy-import --from-hosting --client="$CONSOLE_CLIENT"; then
  echo "4) Console disegarkan dari master baru (grup: $CONSOLE_CLIENT)."
else
  echo "4) PERINGATAN: penyegaran Console gagal — jalankan manual:"
  echo "   sudo -u adf php /srv/adf/app/cli/console.php legacy-import --from-hosting --client=\"$CONSOLE_CLIENT\""
fi
