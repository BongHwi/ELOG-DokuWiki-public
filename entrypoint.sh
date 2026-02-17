#!/bin/bash
set -e

CONF_DIR="/var/www/html/conf"
DATA_DIR="/var/www/html/data"
DEFAULTS_DIR="/docker-defaults/conf"

# --- Restore DokuWiki default config files hidden by volume mount ---
if [ -d "$DEFAULTS_DIR" ]; then
  for file in "$DEFAULTS_DIR"/*; do
    basename="$(basename "$file")"
    target="${CONF_DIR}/${basename}"
    if [ ! -f "$target" ]; then
      cp "$file" "$target"
      echo "entrypoint: restored default config -> ${basename}"
    fi
  done
fi

# --- Ensure required data subdirectories exist ---
for dir in attic cache index locks media media_attic media_meta meta pages tmp log; do
  mkdir -p "${DATA_DIR}/${dir}"
done

# --- Fix ownership for www-data ---
chown -R www-data:www-data "$CONF_DIR" "$DATA_DIR"

# --- Start Apache ---
exec apache2-foreground
