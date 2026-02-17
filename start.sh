#!/bin/bash
set -euo pipefail

if [[ "${1:-}" == "--build" ]]; then
  docker compose up -d --build
else
  docker compose up -d
fi
