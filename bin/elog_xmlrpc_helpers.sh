#!/usr/bin/env bash
set -euo pipefail

__elog_auto_cookiejar=0

elog_die() {
  echo "error: $*" >&2
  exit 1
}

elog_require_cmd() {
  command -v "$1" >/dev/null 2>&1 || elog_die "missing required command: $1"
}

elog_require_env() {
  : "${ELOG_URL:?missing env var: ELOG_URL}"
  # Auth modes:
  # - basic (default): use HTTP Basic via ELOG_NETRC or ELOG_USER/ELOG_PASS
  # - cookie: login via DokuWiki form, then reuse the session cookie for XML-RPC
  #
  # cookie mode is needed when nginx/php-fpm does not pass the Authorization header
  # through to PHP, breaking Basic auth for xmlrpc.php.
  local mode="${ELOG_AUTH:-basic}"
  if [[ "$mode" == "cookie" ]]; then
    # In cookie mode, credentials are required for dokuwiki.login.
    # Allow loading them from ELOG_NETRC to avoid keeping passwords in env/argv.
    if [[ -z "${ELOG_USER:-}" || -z "${ELOG_PASS:-}" ]]; then
      if [[ -n "${ELOG_NETRC:-}" ]]; then
        [[ -f "${ELOG_NETRC}" ]] || elog_die "ELOG_NETRC not found: ${ELOG_NETRC}"
        local host
        host="$(elog_url_host)"
        local creds
        creds="$(elog_netrc_lookup "$host" "${ELOG_NETRC}")" || true
        if [[ -n "$creds" ]]; then
          ELOG_USER="$(printf '%s\n' "$creds" | sed -n '1p')"
          ELOG_PASS="$(printf '%s\n' "$creds" | sed -n '2p')"
          export ELOG_USER ELOG_PASS
        fi
      fi
    fi

    : "${ELOG_USER:?missing env var: ELOG_USER (required for ELOG_AUTH=cookie; or set ELOG_NETRC)}"
    : "${ELOG_PASS:?missing env var: ELOG_PASS (required for ELOG_AUTH=cookie; or set ELOG_NETRC)}"
    # Pre-create a cookie jar in the main shell so subshells share it.
    # Without this, each $(elog_xmlrpc_call_raw ...) subshell would create
    # its own temp jar and the login cookies would be lost.
    if [[ -z "${ELOG_COOKIEJAR:-}" ]]; then
      ELOG_COOKIEJAR="$(mktemp "${TMPDIR:-/tmp}/elog_cookies.XXXXXX")"
      export ELOG_COOKIEJAR
      __elog_auto_cookiejar=1
    fi
    return
  fi

  # basic mode
  if [[ -n "${ELOG_NETRC:-}" ]]; then
    [[ -f "${ELOG_NETRC}" ]] || elog_die "ELOG_NETRC not found: ${ELOG_NETRC}"
  else
    : "${ELOG_USER:?missing env var: ELOG_USER (or set ELOG_NETRC)}"
    : "${ELOG_PASS:?missing env var: ELOG_PASS (or set ELOG_NETRC)}"
  fi
}

elog_url_host() {
  # Extract hostname from ELOG_URL without requiring external tools.
  local u="${ELOG_URL#*://}"
  local hostport="${u%%/*}"
  local host="${hostport%%:*}"
  [[ -n "$host" ]] || elog_die "failed to parse host from ELOG_URL=${ELOG_URL}"
  echo "$host"
}

elog_netrc_lookup() {
  # Print login and password for "machine <host>" from a netrc file.
  # Output format (2 lines): login\npassword
  local host="$1"
  local netrc="$2"

  # netrc allows tokens to appear on the same line or split across lines.
  # Parse token stream rather than line structure.
  awk -v host="$host" '
    BEGIN{
      cur=""; want=""; login=""; pass="";
    }
    function done() {
      if (cur == host && login != "" && pass != "") {
        print login;
        print pass;
        exit 0;
      }
    }
    {
      for (i = 1; i <= NF; i++) {
        t = $i;
        if (want != "") {
          if (want == "machine") { cur = t; login=""; pass=""; }
          else if (cur == host && want == "login") { login = t; }
          else if (cur == host && want == "password") { pass = t; }
          want = "";
          done();
          continue;
        }

        if (t == "machine") { want = "machine"; continue; }
        if (t == "login") { want = "login"; continue; }
        if (t == "password") { want = "password"; continue; }
        if (t == "default") { cur = "default"; login=""; pass=""; continue; }
      }
    }
    END {
      if (cur == host && login != "" && pass != "") {
        print login;
        print pass;
        exit 0;
      }
      exit 1;
    }
  ' "$netrc"
}

elog_xml_escape_stdin() {
  # Escape text from stdin for inclusion inside <string> ... </string>.
  php -r 'echo htmlspecialchars(stream_get_contents(STDIN), ENT_XML1|ENT_QUOTES);'
}

elog_xml_escape_arg() {
  php -r 'echo htmlspecialchars($argv[1], ENT_XML1|ENT_QUOTES);' "$1"
}

elog_xmlrpc_endpoint() {
  local base="${ELOG_URL%/}"
  echo "${base}/lib/exe/xmlrpc.php"
}

__elog_cookiejar=""

elog_cookiejar_path() {
  if [[ -n "${ELOG_COOKIEJAR:-}" ]]; then
    echo "${ELOG_COOKIEJAR}"
    return
  fi
  if [[ -z "${__elog_cookiejar}" ]]; then
    __elog_cookiejar="$(mktemp)"
  fi
  echo "${__elog_cookiejar}"
}

elog_login_cookie() {
  elog_require_cmd curl
  elog_require_cmd php

  local jar
  jar="$(elog_cookiejar_path)"

  local dbg="${ELOG_DEBUG:-0}"
  local endpoint
  endpoint="$(elog_xmlrpc_endpoint)"

  if [[ "$dbg" == "1" ]]; then
    echo "debug: logging in via dokuwiki.login XML-RPC (jar=${jar})" >&2
  fi

  local user_esc pass_esc
  user_esc="$(elog_xml_escape_arg "$ELOG_USER")"
  pass_esc="$(elog_xml_escape_arg "$ELOG_PASS")"

  # Authenticate via the dokuwiki.login XML-RPC method.
  # This avoids the web form login which can fail when sectok/session is broken.
  local resp
  resp="$(
    curl -sS --fail-with-body -c "$jar" -b "$jar" \
      -H "Content-Type: text/xml" \
      --data-binary "<?xml version=\"1.0\"?>
<methodCall>
  <methodName>dokuwiki.login</methodName>
  <params>
    <param><value><string>${user_esc}</string></value></param>
    <param><value><string>${pass_esc}</string></value></param>
  </params>
</methodCall>" \
      "$endpoint"
  )" || elog_die "dokuwiki.login request failed"

  # Check for boolean 1 (success) in the response.
  local ok
  ok="$(php -r '
$sx = @simplexml_load_string($argv[1]);
if ($sx === false) { echo "error"; exit; }
$val = $sx->params->param->value->boolean ?? "";
echo (string)$val;
' "$resp")"

  if [[ "$ok" != "1" ]]; then
    elog_die "dokuwiki.login failed (check ELOG_USER/ELOG_PASS credentials)"
  fi

  if [[ "$dbg" == "1" ]]; then
    echo "debug: dokuwiki.login succeeded" >&2
    echo "debug: cookie jar (names only):" >&2
    awk 'BEGIN{FS="\t"} /^#HttpOnly_/{sub(/^#HttpOnly_/,"",$1)} $0 !~ /^#/ && NF>=7 {printf("  domain=%s path=%s name=%s\n",$1,$3,$6)}' "$jar" >&2 || true
  fi
}

elog_xmlrpc_call_raw() {
  local method="$1"
  local params_xml="$2"

  elog_require_env
  elog_require_cmd curl
  elog_require_cmd php

  local endpoint
  endpoint="$(elog_xmlrpc_endpoint)"

  # Basic auth is only secure over HTTPS (or a trusted local connection).
  if [[ "${ELOG_URL}" == http://* && "${ELOG_URL}" != http://127.0.0.1* && "${ELOG_URL}" != http://localhost* ]]; then
    echo "warning: ELOG_URL is http:// (not https://); credentials can be exposed in transit" >&2
  fi

  local mode="${ELOG_AUTH:-basic}"
  local -a curl_auth=()
  if [[ "$mode" == "cookie" ]]; then
    local jar
    jar="$(elog_cookiejar_path)"
    # Ensure we're logged in. If the cookie is invalid/expired, login again.
    if [[ ! -s "$jar" ]]; then
      elog_login_cookie
    fi
    curl_auth+=(-b "$jar" -c "$jar")
  else
    if [[ -n "${ELOG_NETRC:-}" ]]; then
      curl_auth+=(--netrc-file "${ELOG_NETRC}")
    else
      # NOTE: This puts credentials in the curl argv; prefer ELOG_NETRC to avoid leaking via ps.
      curl_auth+=(-u "${ELOG_USER}:${ELOG_PASS}")
    fi
  fi

  # Note: DokuWiki XML-RPC requires proper Content-Type and HTTP auth.
  curl -sS --fail-with-body \
    "${curl_auth[@]}" \
    -H "Content-Type: text/xml" \
    --data-binary @- \
    "$endpoint" <<EOF
<?xml version="1.0"?>
<methodCall>
  <methodName>$(elog_xml_escape_arg "$method")</methodName>
  <params>
${params_xml}
  </params>
</methodCall>
EOF
}

elog_xmlrpc_assert_ok() {
  # Reads XML-RPC response from stdin, exits non-zero on fault, otherwise echoes input back to stdout.
  php -r '
$xml = stream_get_contents(STDIN);
$sx = @simplexml_load_string($xml);
if ($sx === false) { fwrite(STDERR, "error: invalid XML-RPC response\n"); exit(2); }
if ($sx->getName() !== "methodResponse") {
  fwrite(STDERR, "error: unexpected non-XML-RPC response (root=" . $sx->getName() . ")\n");
  exit(2);
}
if (isset($sx->fault)) {
  $fault = $sx->fault->value->struct->member;
  $code = "";
  $msg = "";
  foreach ($fault as $m) {
    $name = (string)$m->name;
    if ($name === "faultCode") $code = (string)$m->value->int;
    if ($name === "faultString") $msg = (string)$m->value->string;
  }
  fwrite(STDERR, "error: XML-RPC fault $code: $msg\n");
  exit(1);
}
echo $xml;
'
}

elog_xmlrpc_pagelist_ids() {
  local ns="$1"

  local ns_esc
  ns_esc="$(elog_xml_escape_arg "$ns")"

  local params_xml
  params_xml="$(
    cat <<EOF
    <param><value><string>${ns_esc}</string></value></param>
    <param><value><struct>
      <member><name>depth</name><value><int>1</int></value></member>
    </struct></value></param>
EOF
  )"

  # Keep execution in the current shell (no $(...) subshell), otherwise `exit`
  # inside helper functions would not terminate the main script.
  local resp_file ok_file
  resp_file="$(mktemp)"
  ok_file="$(mktemp)"
  if ! elog_xmlrpc_call_raw "dokuwiki.getPagelist" "$params_xml" >"$resp_file"; then
    rm -f "$resp_file" "$ok_file"
    return 1
  fi
  if ! elog_xmlrpc_assert_ok <"$resp_file" >"$ok_file"; then
    rm -f "$resp_file" "$ok_file"
    return 1
  fi

  # Print one page id per line.
  php -r '
$sx = simplexml_load_string($argv[1]);
$values = $sx->params->param->value->array->data->value ?? [];
foreach ($values as $v) {
  $members = $v->struct->member ?? [];
  foreach ($members as $m) {
    if ((string)$m->name === "id") {
      $id = (string)$m->value->string;
      if ($id !== "") echo $id, "\n";
    }
  }
}
' "$(cat "$ok_file")"

  rm -f "$resp_file" "$ok_file"
}

elog_xmlrpc_put_page() {
  local pageid="$1"
  local file="$2"
  local summary="${3:-}"

  [[ -f "$file" ]] || elog_die "body file not found: $file"

  local pageid_esc summary_esc text_esc
  pageid_esc="$(elog_xml_escape_arg "$pageid")"
  summary_esc="$(elog_xml_escape_arg "$summary")"
  text_esc="$(elog_xml_escape_stdin <"$file")"

  local params_xml
  params_xml="$(
    cat <<EOF
    <param><value><string>${pageid_esc}</string></value></param>
    <param><value><string>${text_esc}</string></value></param>
    <param><value><struct>
      <member><name>sum</name><value><string>${summary_esc}</string></value></member>
      <member><name>minor</name><value><boolean>0</boolean></value></member>
    </struct></value></param>
EOF
  )"

  local resp_file
  resp_file="$(mktemp)"
  if ! elog_xmlrpc_call_raw "wiki.putPage" "$params_xml" >"$resp_file"; then
    rm -f "$resp_file"
    return 1
  fi
  elog_xmlrpc_assert_ok <"$resp_file" >/dev/null
  rm -f "$resp_file"
}

elog_xmlrpc_put_attachment() {
  local mediaid="$1"
  local file="$2"
  local overwrite="${3:-1}"

  [[ -f "$file" ]] || elog_die "image not found: $file"

  local mediaid_esc b64 ow
  mediaid_esc="$(elog_xml_escape_arg "$mediaid")"
  b64="$(php -r 'echo base64_encode(file_get_contents($argv[1]));' "$file")"
  ow="0"
  [[ "$overwrite" == "1" ]] && ow="1"

  local params_xml
  params_xml="$(
    cat <<EOF
    <param><value><string>${mediaid_esc}</string></value></param>
    <param><value><base64>${b64}</base64></value></param>
    <param><value><struct>
      <member><name>ow</name><value><boolean>${ow}</boolean></value></member>
    </struct></value></param>
EOF
  )"

  local resp_file
  resp_file="$(mktemp)"
  if ! elog_xmlrpc_call_raw "wiki.putAttachment" "$params_xml" >"$resp_file"; then
    rm -f "$resp_file"
    return 1
  fi
  elog_xmlrpc_assert_ok <"$resp_file" >/dev/null
  rm -f "$resp_file"
}
