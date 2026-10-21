#!/usr/bin/env bash
# Salinan backup di LUAR VPS (offsite) → Biznet NEO Object Storage (S3), TERENKRIPSI (rclone crypt).
# Dipanggil otomatis di akhir ops/backup.sh bila /srv/adf/secrets/offsite.env ada. Jalankan dengan sudo.
#
#   sudo bash /srv/adf/app/ops/offsite.sh test            cek koneksi & enkripsi (tulis-baca file kecil)
#   sudo bash /srv/adf/app/ops/offsite.sh push            kirim semua backup yang belum ada di sana
#   sudo bash /srv/adf/app/ops/offsite.sh list            daftar backup di penyimpanan offsite
#   sudo bash /srv/adf/app/ops/offsite.sh get <tanggal> [folder]
#        ambil backup satu tanggal (mis. daily/2026-10-21) ke folder (default /srv/adf/restore/<tanggal>),
#        lalu pulihkan:  sudo bash legacy-db.sh import <folder>/db/<database>.sql.gz <database> --live
#
# /srv/adf/secrets/offsite.env (chmod 600) berisi:
#   S3_ENDPOINT=https://…   S3_ACCESS_KEY=…   S3_SECRET_KEY=…   S3_BUCKET=adf-backup
#   CRYPT_PASSWORD=…  CRYPT_SALT=…   ← SIMPAN JUGA DI LUAR VPS (catatan pribadi). Tanpa ini backup tidak bisa dibuka.
set -euo pipefail

ENV=/srv/adf/secrets/offsite.env
ROOT=/srv/adf/backups
STATUS=$ROOT/offsite.json
KEEP_DAYS=120   # berkas di offsite lebih tua dari ini dihapus (lokal hanya simpan ±3 bulan)

die() { echo "GAGAL: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "jalankan dengan sudo."
command -v rclone >/dev/null || die "rclone belum terpasang:  sudo apt install -y rclone"
[[ -f $ENV ]] || die "$ENV belum ada (lihat bagian atas skrip)."
# shellcheck disable=SC1090
source "$ENV"
for v in S3_ENDPOINT S3_ACCESS_KEY S3_SECRET_KEY S3_BUCKET CRYPT_PASSWORD CRYPT_SALT; do
  [[ -n ${!v:-} ]] || die "$v kosong di $ENV"
done

# Konfigurasi rclone lewat variabel lingkungan → tidak ada file config berisi kunci di disk
exportRCLONE_CONFIG_NOS_TYPE=s3 RCLONE_CONFIG_NOS_PROVIDER=Other RCLONE_CONFIG_NOS_ENDPOINT=$S3_ENDPOINT \
       RCLONE_CONFIG_NOS_ACCESS_KEY_ID=$S3_ACCESS_KEY RCLONE_CONFIG_NOS_SECRET_ACCESS_KEY=$S3_SECRET_KEY \
       RCLONE_CONFIG_NOS_ACL=private RCLONE_CONFIG_NOS_NO_CHECK_BUCKET=true
export RCLONE_CONFIG_SAFE_TYPE=crypt RCLONE_CONFIG_SAFE_REMOTE="nos:$S3_BUCKET/adf" \
       RCLONE_CONFIG_SAFE_PASSWORD="$(rclone obscure "$CRYPT_PASSWORD")" \
       RCLONE_CONFIG_SAFE_PASSWORD2="$(rclone obscure "$CRYPT_SALT")"

status() { # ok|failed "pesan"
  printf '{"ok":%s,"finished":"%s","message":"%s"}\n' "$([[ $1 == ok ]] && echo true || echo false)" "$(date '+%F %T')" "${2//\"/\'}" > "$STATUS"
  chmod 644 "$STATUS"
}

case "${1:-}" in
  test)
    T=$(mktemp); echo "adf offsite test $(date '+%F %T')" > "$T"
    # cepat gagal (bukan mencoba ulang berkali-kali) supaya salah endpoint/kunci langsung kelihatan
    FAST=(--contimeout 15s --timeout 30s --retries 1 --low-level-retries 1 -v)
    OK=0
    rclone copyto "$T" safe:_test.txt "${FAST[@]}" && rclone cat safe:_test.txt "${FAST[@]}" && rclone deletefile safe:_test.txt "${FAST[@]}" && OK=1
    rm -f "$T"
    (( OK )) || die "tes offsite gagal — lihat pesan rclone di atas (endpoint / kunci / nama bucket)."
    echo "OK: koneksi + enkripsi berfungsi (isi file di atas terbaca kembali)."
    echo "Di panel Biznet isi bucket terlihat acak — itu tanda terenkripsi."
    ;;
  push)
    # copy (bukan sync): bila backup lokal dihapus peretas, salinan di offsite TIDAK ikut terhapus
    if rclone copy "$ROOT" safe: --exclude 'offsite.json' --exclude 'pre-import/**' --transfers 4 --log-level NOTICE; then
      rclone delete safe: --min-age "${KEEP_DAYS}d" --log-level NOTICE || true
      rclone rmdirs safe: --leave-root --log-level NOTICE || true
      status ok "OK"; echo "$(date '+%F %T') OFFSITE OK"
    else
      status failed "Kirim ke offsite gagal"; die "kirim ke offsite gagal."
    fi
    ;;
  list)
    rclone lsf safe: --dirs-only -R --max-depth 2 | sort
    ;;
  get)
    [[ -n ${2:-} && $2 =~ ^(daily|weekly|monthly)/[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "pakai: offsite.sh get daily/2026-10-21 [folder]"
    DEST=${3:-/srv/adf/restore/$2}
    mkdir -p "$DEST"; chmod 700 "$DEST"
    rclone copy "safe:$2" "$DEST" --transfers 4
    echo "OK: $DEST"; ls -la "$DEST/db" 2>/dev/null || true
    ;;
  *) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
