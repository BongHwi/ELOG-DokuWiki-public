#!/bin/bash
set -e

DATA_DIR="/var/www/html/data"

# Ensure required data subdirectories exist (host bind-mount may be empty)
for dir in attic cache index locks media media_attic media_meta meta pages tmp log; do
  mkdir -p "${DATA_DIR}/${dir}"
done

# Copy default pages if pages dir is empty (first run)
if [ ! -f "${DATA_DIR}/pages/start.txt" ] && [ -d "/docker-defaults/pages" ]; then
  cp -rn /docker-defaults/pages/* "${DATA_DIR}/pages/" 2>/dev/null || true
fi

# Fix ownership for www-data
chown -R www-data:www-data "$DATA_DIR"

exec apache2-foreground
