#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=bin/elog_xmlrpc_helpers.sh
source "${HERE}/elog_xmlrpc_helpers.sh"

load_config() {
  local f="$1"
  [[ -f "$f" ]] || elog_die "config file not found: $f"

  # Warn (best-effort) if config looks too-permissive.
  local perm=""
  perm="$(stat -f '%Lp' "$f" 2>/dev/null || true)"
  if [[ -z "$perm" ]]; then
    perm="$(stat -c '%a' "$f" 2>/dev/null || true)"
  fi
  if [[ -n "$perm" ]]; then
    # last 2 digits are group/other
    go="${perm#?}"
    if [[ "$go" != "00" ]]; then
      echo "warning: config file permissions are ${perm}; recommend chmod 600 ${f}" >&2
    fi
  fi

  # shellcheck disable=SC1090
  set -a
  source "$f"
  set +a
}

usage() {
  cat <<'EOF' >&2
Usage:
  elog_post.sh [--config FILE] --ns NS --type TYPE --tags TAGS --subject SUBJECT (--body TEXT | --body-file FILE) [--category CAT] [--author NAME] [--image PATH ...]

Environment:
  ELOG_URL   Base URL of DokuWiki (e.g. http://127.0.0.1:8081)
  ELOG_AUTH  Auth mode: basic (default) or cookie
  ELOG_NETRC Path to a curl netrc file (recommended; avoids leaking secrets via ps)
  ELOG_USER  Username (if not using ELOG_NETRC)
  ELOG_PASS  Password (if not using ELOG_NETRC)
  ELOG_COOKIEJAR Path to a curl cookie jar (used when ELOG_AUTH=cookie)
  ELOG_AUTHOR Default author line to embed in page text (fallback when wiki metadata lacks creator)
EOF
}

config_file=""
ns=""
type=""
category=""
author=""
tags=""
subject=""
body=""
body_file=""
images=()

# Preload config before parsing arguments (so env vars take effect).
args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
  if [[ "${args[i]}" == "--config" ]]; then
    config_file="${args[i+1]:-}"
    break
  fi
done
if [[ -n "$config_file" ]]; then
  load_config "$config_file"
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config) shift 2;; # handled above
    --ns) ns="${2:-}"; shift 2;;
    --type) type="${2:-}"; shift 2;;
    --category) category="${2:-}"; shift 2;;
    --author) author="${2:-}"; shift 2;;
    --tags) tags="${2:-}"; shift 2;;
    --subject) subject="${2:-}"; shift 2;;
    --body) body="${2:-}"; shift 2;;
    --body-file) body_file="${2:-}"; shift 2;;
    --image) images+=("${2:-}"); shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "error: unknown arg: $1" >&2; usage; exit 2;;
  esac
done

[[ -n "$ns" ]] || { echo "error: --ns required" >&2; exit 2; }
[[ -n "$type" ]] || { echo "error: --type required" >&2; exit 2; }
[[ -n "$tags" ]] || { echo "error: --tags required" >&2; exit 2; }
[[ -n "$subject" ]] || { echo "error: --subject required" >&2; exit 2; }

if [[ -n "$body" && -n "$body_file" ]]; then
  echo "error: use only one of --body or --body-file" >&2
  exit 2
fi
if [[ -z "$body" && -z "$body_file" ]]; then
  echo "error: one of --body or --body-file is required" >&2
  exit 2
fi

elog_require_env

tmpdir="$(mktemp -d)"
cleanup() { rm -rf "$tmpdir"; [[ "${__elog_auto_cookiejar:-}" == "1" ]] && rm -f "${ELOG_COOKIEJAR:-}" || true; }
trap cleanup EXIT

content_file="${tmpdir}/content.txt"
if [[ -n "$body_file" ]]; then
  [[ -f "$body_file" ]] || elog_die "body file not found: $body_file"
  cp "$body_file" "${tmpdir}/body.txt"
else
  printf '%s\n' "$body" > "${tmpdir}/body.txt"
fi

# Embed an Author: line into the page text. This is used as a fallback by the
# ELOG template when DokuWiki metadata does not populate creator/last_change user
# (common with remote/XML-RPC or "external edit" changes).
author_line="$author"
if [[ -z "$author_line" ]]; then
  author_line="${ELOG_AUTHOR:-}"
fi
if [[ -z "$author_line" ]]; then
  author_line="${USER:-${LOGNAME:-${ELOG_USER:-}}}"
fi

{
  printf 'Type: %s\n' "$type"
  if [[ -n "$category" ]]; then
    printf 'Category: %s\n' "$category"
  fi
  printf 'Tags: %s\n' "$tags"
  if [[ -n "$author_line" ]]; then
    printf 'Author: %s\n' "$author_line"
  fi
  printf 'Subject: %s\n' "$subject"
  printf '\n====== %s ======\n\n' "$subject"
  cat "${tmpdir}/body.txt"
  printf '\n'
} >"$content_file"

# Compute next numeric page id under namespace.
max=0
ids="$(elog_xmlrpc_pagelist_ids "$ns")"
while IFS= read -r id; do
  [[ -n "$id" ]] || continue
  # id is like NS:123 or ns:sub:123. We only care about last component.
  last="${id##*:}"
  if [[ "$last" =~ ^[0-9]+$ ]]; then
    n="$last"
    if (( n > max )); then max="$n"; fi
  fi
done <<<"$ids"

next=$((max + 1))
pageid="${ns}:${next}"

elog_xmlrpc_put_page "$pageid" "$content_file" "xmlrpc client post"

epoch="$(date +%s)"
i=0
for img in "${images[@]+"${images[@]}"}"; do
  [[ -f "$img" ]] || elog_die "image not found: $img"
  i=$((i + 1))
  base="$(basename "$img")"
  fname="${next}_${epoch}_${i}_${base}"
  mediaid="${ns}:${fname}"
  elog_xmlrpc_put_attachment "$mediaid" "$img" 1
done

echo "Created: ${pageid}"
echo "URL: ${ELOG_URL%/}/doku.php?id=${pageid}"
