#!/usr/bin/env bash
# Siapkan cron sistem lama di VPS. Jalankan dengan sudo.
#   sudo bash /srv/adf/app/ops/legacy-cron-setup.sh prepare   (boleh SEKARANG: folder tmp bersama, log, logrotate — cron belum jalan)
#   sudo bash /srv/adf/app/ops/legacy-cron-setup.sh enable    (HARI PINDAHAN, setelah cron di hosting dimatikan)
#   sudo bash /srv/adf/app/ops/legacy-cron-setup.sh disable   (jalan kembali ke hosting)
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Jalankan dengan sudo."; exit 1; }

case "${1:-}" in
  prepare)
    # Kunci sinkron Cloudbeds ada di sys_get_temp_dir(); php-fpm memakai /tmp pribadi (PrivateTmp), cron memakai /tmp
    # sistem → kunci tidak saling terlihat. Satu folder tmp bersama untuk FPM & CLI menyelesaikannya tanpa ubah kode.
    install -d -o www-data -g adf -m 2770 /srv/adf/legacy/tmp
    for SAPI in fpm cli; do
      printf '; ADF: folder sementara bersama (kunci sinkron Cloudbeds cron ↔ web)\nsys_temp_dir = /srv/adf/legacy/tmp\n' > "/etc/php/8.2/$SAPI/conf.d/98-adf-tmp.ini"
    done
    systemctl reload php8.2-fpm
    install -d -o www-data -g adf -m 2750 /var/log/adf-legacy
    cat > /etc/logrotate.d/adf-legacy <<'EOF'
/var/log/adf-legacy/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
EOF
    echo "Siap: tmp bersama /srv/adf/legacy/tmp, log /var/log/adf-legacy (dirotasi mingguan). Cron BELUM aktif."
    echo "Cek: php -r 'echo sys_get_temp_dir(), PHP_EOL;'   → /srv/adf/legacy/tmp"
    ;;
  enable)
    [[ -d /var/log/adf-legacy ]] || { echo "Jalankan 'prepare' dulu."; exit 1; }
    cp /srv/adf/app/ops/cron/adf-legacy /etc/cron.d/adf-legacy
    chmod 644 /etc/cron.d/adf-legacy
    echo "Cron sistem lama AKTIF di VPS. Pastikan cron di cPanel hosting sudah dimatikan."
    ;;
  disable)
    rm -f /etc/cron.d/adf-legacy
    echo "Cron sistem lama di VPS dimatikan."
    ;;
  *) sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//' ;;
esac
