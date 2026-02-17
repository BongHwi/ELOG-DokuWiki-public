#!/bin/bash
set -euo pipefail

# Guard: ensure setup.sh has been run before starting
if [ ! -f "conf/local.php" ]; then
  echo "ERROR: conf/local.php not found."
  echo "       Please run ./setup.sh first to initialize configuration files."
  echo "       See QUICKSTART.md for details."
  exit 1
fi

if [[ "${1:-}" == "--build" ]]; then
  docker compose up -d --build
else
  docker compose up -d
fi
