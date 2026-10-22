#!/usr/bin/env bash
# Pasang website publik Narayana di VPS (Nginx + SSL), dari template ops/nginx/narayana-website.conf.
#   sudo bash /srv/adf/app/ops/add-website.sh web-staging.adfsystem.online     (uji, data staging)
#   sudo bash /srv/adf/app/ops/add-website.sh narayanakarimunjawa.com         (hari pindahan: + www bila DNS-nya ada)
#   --test : pintu http saja tanpa cek DNS & SSL
set -euo pipefail

DOMAIN="${1:-}"
TEST=0; [[ "${2:-}" == "--test" ]] && TEST=1
DOMAIN="${DOMAIN,,}"; DOMAIN="${DOMAIN#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN%%/*}"

[[ $EUID -eq 0 ]] || { echo "Jalankan dengan sudo."; exit 1; }
[[ "$DOMAIN" =~ ^([a-z0-9-]+\.)+[a-z]{2,}$ ]] || { echo "Domain tidak valid: '$DOMAIN'"; exit 1; }

NAMES="$DOMAIN"
if [[ $TEST -eq 0 ]]; then
  MY_IPS=" $(hostname -I) "
  ip_ok() { [[ -n "$1" && "$MY_IPS" == *" $1 "* ]]; }
  RES=$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}' || true)
  if ! ip_ok "$RES"; then
    echo "DNS $DOMAIN belum mengarah ke VPS ini (sekarang: ${RES:-belum ada}). Buat A record → $(hostname -I | awk '{print $1}') dulu."
    exit 1
  fi
  # www hanya untuk domain utama (bukan subdomain seperti web-staging.adfsystem.online)
  if [[ $(grep -o '\.' <<< "$DOMAIN" | wc -l) -eq 1 ]] && ip_ok "$(getent ahostsv4 "www.$DOMAIN" | awk '{print $1; exit}' || true)"; then
    NAMES="$DOMAIN www.$DOMAIN"
  fi
fi

DEST="/etc/nginx/sites-available/website-$DOMAIN"
if [[ -e "$DEST" ]]; then
  echo "Pintu Nginx untuk $DOMAIN sudah ada — dipakai."
else
  sed -E "s/^([[:space:]]*server_name[[:space:]]+).*;/\1$NAMES;/" /srv/adf/app/ops/nginx/narayana-website.conf > "$DEST"
  ln -sf "$DEST" /etc/nginx/sites-enabled/
  echo "Pintu Nginx dibuat: $DEST"
fi
nginx -t
systemctl reload nginx

if [[ $TEST -eq 1 ]]; then
  echo "Mode uji: http saja. Jalankan lagi tanpa --test setelah DNS mengarah ke VPS."
  exit 0
fi
CERT_ARGS=(); for n in $NAMES; do CERT_ARGS+=(-d "$n"); done
certbot --nginx --non-interactive --redirect "${CERT_ARGS[@]}"
echo "Selesai: https://$DOMAIN"
