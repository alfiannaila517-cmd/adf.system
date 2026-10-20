#!/usr/bin/env bash
# Pasang domain sendiri sebuah bisnis di VPS: pintu Nginx ke sistem lama (kode yang sama dengan staging) + SSL.
#   sudo bash /srv/adf/app/ops/add-domain.sh sunseakarimunjawa.com
#   sudo bash /srv/adf/app/ops/add-domain.sh linewisatakarimunjawa.com --test   (uji sebelum DNS dialihkan: http saja,
#        tanpa cek DNS & SSL; buka dari laptop yang file hosts-nya diarahkan ke VPS)
# Syarat: DNS domain (A record) sudah mengarah ke VPS ini, dan domain sudah diisi di Console (Bisnis → Ringkasan),
# supaya sistem tahu domain ini milik bisnis mana (domain/ = website/login bisnis itu, domain/admin = login sistem).
set -euo pipefail

DOMAIN="${1:-}"
TEST=0; [[ "${2:-}" == "--test" ]] && TEST=1
DOMAIN="${DOMAIN,,}"
DOMAIN="${DOMAIN#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN#www.}"; DOMAIN="${DOMAIN%%/*}"

if [[ $EUID -ne 0 ]]; then echo "Jalankan dengan sudo."; exit 1; fi
if [[ ! "$DOMAIN" =~ ^([a-z0-9-]+\.)+[a-z]{2,}$ ]]; then echo "Domain tidak valid: '$DOMAIN'"; exit 1; fi

if [[ $TEST -eq 1 ]]; then
  NAMES="$DOMAIN www.$DOMAIN"
else
# DNS harus sudah mengarah ke server ini (kalau belum, sertifikat SSL pasti gagal)
MY_IPS=" $(hostname -I) "
ip_ok() { [[ -n "$1" && "$MY_IPS" == *" $1 "* ]]; }
RES=$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}' || true)
if ! ip_ok "$RES"; then
  echo "DNS $DOMAIN belum mengarah ke VPS ini (sekarang: ${RES:-belum ada}; IP VPS:$(hostname -I))."
  echo "Buat A record di tempat domain dibeli, tunggu beberapa menit–jam, lalu jalankan lagi."
  exit 1
fi
NAMES="$DOMAIN"
WWW=$(getent ahostsv4 "www.$DOMAIN" | awk '{print $1; exit}' || true)
if ip_ok "$WWW"; then NAMES="$DOMAIN www.$DOMAIN"; else echo "Catatan: www.$DOMAIN belum mengarah ke VPS — dipasang tanpa www."; fi
fi

TPL=/srv/adf/app/ops/nginx/adfsystem-legacy.conf
DEST="/etc/nginx/sites-available/domain-$DOMAIN"
if [[ -e "$DEST" ]]; then
  echo "Pintu Nginx untuk $DOMAIN sudah ada — dipakai."
else
  # Salinan aturan sistem lama (semua larangan file sensitif ikut), hanya nama domainnya yang diganti
  sed -E "s/^([[:space:]]*server_name[[:space:]]+).*;/\1$NAMES;/" "$TPL" > "$DEST"
  ln -sf "$DEST" /etc/nginx/sites-enabled/
  echo "Pintu Nginx dibuat: $DEST"
fi
nginx -t
systemctl reload nginx

if [[ $TEST -eq 1 ]]; then
  echo "Mode uji: pintu http dipasang, SSL dilewati. Jalankan lagi TANPA --test setelah DNS dialihkan."
  exit 0
fi

CERT_ARGS=()
for n in $NAMES; do CERT_ARGS+=(-d "$n"); done
certbot --nginx --non-interactive --redirect "${CERT_ARGS[@]}"

echo
echo "Selesai. Coba: https://$DOMAIN  dan  https://$DOMAIN/admin"
