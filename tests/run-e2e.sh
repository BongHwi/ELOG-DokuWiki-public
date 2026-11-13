#!/bin/bash
# Build the image, start a throwaway container on a copy of data/, create test
# accounts, run tests/e2e-checks.sh inside it and remove the container again.
# The repository's data/ directory is not touched.
# usage: tests/run-e2e.sh [report file]      (default: tests/e2e-report.txt)
set -euo pipefail
cd "$(dirname "$0")/.."
REPORT=${1:-tests/e2e-report.txt}
NAME=elog-e2e-$$; IMAGE=elog-dokuwiki-e2e
WORK=$(mktemp -d); trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

docker build -q -t "$IMAGE" . >/dev/null
docker run -d --name "$NAME" "$IMAGE" >/dev/null
docker cp data/pages/. "$NAME":/var/www/html/data/pages/
docker cp data/media/. "$NAME":/var/www/html/data/media/
docker cp lib/tpl/elog "$NAME":/var/www/html/lib/tpl/
docker cp tests/e2e-checks.sh "$NAME":/tmp/e2e-checks.sh

PW="e2e-$(head -c 12 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9')"
docker exec -i -e E2E_PW="$PW" "$NAME" bash -s <<'SETUP'
set -e
cd /var/www/html
hash=$(php -r 'echo password_hash(getenv("E2E_PW"), PASSWORD_BCRYPT);')
printf 'e2e_admin:%s:E2E Admin:admin@example.com:admin,user\ne2e_member:%s:E2E Member:member@example.com:member\ne2e_pending:%s:E2E Pending:pending@example.com:pending\n' "$hash" "$hash" "$hash" > conf/users.auth.php
printf '*\t@ALL\t1\n*\t@admin\t255\ndemo:*\t@member\t8\nprivate:*\t@ALL\t0\nprivate:*\t@admin\t255\n' > conf/acl.auth.php
printf "<?php\n\$conf['userewrite'] = 0;\n\$conf['mailfrom'] = '';\n" > conf/local.protected.php
mkdir -p data/pages/private && printf '====== private ======\n' > data/pages/private/start.txt
chown -R www-data:www-data conf data lib/tpl/elog
for i in $(seq 1 30); do curl -sf -o /dev/null http://127.0.0.1/doku.php && break; sleep 1; done
SETUP

set +e
docker exec -e E2E_PW="$PW" "$NAME" bash /tmp/e2e-checks.sh http://127.0.0.1/doku.php /var/www/html /tmp/e2e-report.txt
rc=$?
set -e
docker cp "$NAME":/tmp/e2e-report.txt "$REPORT"
echo "PHP errors in the container log: $(docker logs "$NAME" 2>&1 | grep -cE 'PHP (Fatal|Parse|Warning)' || true)" | tee -a "$REPORT"
echo "report: $REPORT"
exit $rc
