#!/bin/bash
set -euo pipefail

echo "============================================"
echo "  ELOG-DokuWiki Setup"
echo "============================================"
echo ""

# Detect if running inside Docker or on host
CONTAINER_NAME="elog-dokuwiki"
IN_DOCKER=false
if [ -f /.dockerenv ]; then
  IN_DOCKER=true
fi

# --- Helper: run a PHP command (inside container or locally) ---
run_php() {
  if [ "$IN_DOCKER" = true ]; then
    php -r "$1"
  elif docker compose ps --status running 2>/dev/null | grep -q "$CONTAINER_NAME"; then
    docker compose exec -T dokuwiki php -r "$1"
  elif command -v php &>/dev/null; then
    php -r "$1"
  else
    return 1
  fi
}

# --- Step 1: Build and start containers ---
echo "Step 1: Docker containers"

if [ "$IN_DOCKER" = true ]; then
  echo "  [skip] Running inside container"
elif docker compose ps --status running 2>/dev/null | grep -q "$CONTAINER_NAME"; then
  echo "  [skip] Container already running"
else
  read -rp "  Build and start Docker containers? [Y/n]: " do_docker
  if [[ ! "$do_docker" =~ ^[Nn] ]]; then
    echo "  Building and starting..."
    docker compose up -d --build
    echo "  Waiting for container to be ready..."
    sleep 3
  else
    echo "  Skipped. Run 'docker compose up -d --build' manually."
    echo ""
    echo "  For manual (non-Docker) installation:"
    echo "  1. Install DokuWiki: https://www.dokuwiki.org/install"
    echo "  2. Copy lib/tpl/elog/ to your DokuWiki's lib/tpl/ directory"
    echo "  3. Set \$conf['template'] = 'elog'; in conf/local.php"
    echo "  4. Install plugins listed in plugins.conf"
    echo ""
    exit 0
  fi
fi
echo ""

# --- Step 2: Create admin account ---
echo "Step 2: Admin account setup"

# Check if admin user already exists
has_users=false
if [ "$IN_DOCKER" = true ]; then
  [ -f /var/www/html/conf/users.auth.php ] && has_users=true
else
  existing=$(docker compose exec -T dokuwiki cat /var/www/html/conf/users.auth.php 2>/dev/null || true)
  if [ -n "$existing" ] && echo "$existing" | grep -qv '^#'; then
    has_users=true
  fi
fi

if [ "$has_users" = true ]; then
  echo "  [skip] Users already configured"
else
  while true; do
    read -rp "  Admin username [admin]: " admin_user
    admin_user="${admin_user:-admin}"
    if [[ "$admin_user" =~ ^[a-zA-Z0-9_-]+$ ]]; then
      break
    fi
    echo "  Invalid username. Use only letters, digits, hyphens, and underscores."
  done

  while true; do
    read -rp "  Admin display name [Admin User]: " admin_name
    admin_name="${admin_name:-Admin User}"
    if [[ "$admin_name" != *":"* && "$admin_name" != *$'\n'* ]]; then
      break
    fi
    echo "  Invalid name. Colons and newlines are not allowed."
  done

  while true; do
    read -rp "  Admin email [admin@example.com]: " admin_email
    admin_email="${admin_email:-admin@example.com}"
    if [[ "$admin_email" != *":"* && "$admin_email" != *$'\n'* ]]; then
      break
    fi
    echo "  Invalid email. Colons and newlines are not allowed."
  done

  while true; do
    read -rsp "  Admin password: " admin_pass
    echo ""
    read -rsp "  Confirm password: " admin_pass2
    echo ""
    if [ "$admin_pass" = "$admin_pass2" ]; then
      break
    fi
    echo "  Passwords do not match. Try again."
  done

  # Generate bcrypt hash via container PHP
  echo "  Generating password hash..."
  hash=$(printf '%s' "$admin_pass" | run_php "echo password_hash(trim(fgets(STDIN)), PASSWORD_BCRYPT);" 2>/dev/null || true)

  if [ -z "$hash" ]; then
    echo "  WARNING: Could not generate hash. Create admin via DokuWiki web admin instead."
  else
    user_line="${admin_user}:${hash}:${admin_name}:${admin_email}:admin,user"
    if [ "$IN_DOCKER" = true ]; then
      echo "$user_line" > /var/www/html/conf/users.auth.php
      chown www-data:www-data /var/www/html/conf/users.auth.php
    else
      echo "$user_line" | docker compose exec -T dokuwiki sh -c 'cat > /var/www/html/conf/users.auth.php && chown www-data:www-data /var/www/html/conf/users.auth.php'
    fi
    echo "  [created] Admin account '${admin_user}'"
  fi

  # Set up default ACL
  acl_content="*               @ALL         1
*               @admin       255"
  if [ "$IN_DOCKER" = true ]; then
    echo "$acl_content" > /var/www/html/conf/acl.auth.php
    chown www-data:www-data /var/www/html/conf/acl.auth.php
  else
    echo "$acl_content" | docker compose exec -T dokuwiki sh -c 'cat > /var/www/html/conf/acl.auth.php && chown www-data:www-data /var/www/html/conf/acl.auth.php'
  fi
  echo "  [created] Default ACL rules"
fi
echo ""

# --- Step 3: Create initial logbook (optional) ---
echo "Step 3: Initial logbook setup (optional)"
read -rp "  Create an initial logbook? (name, or Enter to skip): " logbook_name

if [ -n "$logbook_name" ]; then
  if [[ ! "$logbook_name" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "  Invalid logbook name. Use only letters, digits, hyphens, and underscores."
    logbook_name=""
  fi
fi

if [ -n "$logbook_name" ]; then
  logbook_dir="data/pages/${logbook_name}"
  if [ -d "$logbook_dir" ]; then
    echo "  [skip] Logbook '${logbook_name}' already exists"
  else
    mkdir -p "$logbook_dir"
    cat > "${logbook_dir}/start.txt" <<EOF
====== ${logbook_name} ======

Logbook created on $(date +%Y-%m-%d).
EOF
    echo "  [created] Logbook '${logbook_name}'"
  fi
fi
echo ""

echo "============================================"
echo "  Setup complete!"
echo "  Access ELOG at: http://127.0.0.1:8081"
echo "============================================"
