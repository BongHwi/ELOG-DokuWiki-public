# XML-RPC Automation

ELOG-DokuWiki supports automated posting via DokuWiki's XML-RPC interface using the scripts in `bin/`.

## Prerequisites

1. Enable XML-RPC in `conf/local.php`:
   ```php
   $conf['remote'] = 1;
   $conf['remoteuser'] = 'automation_user';
   ```

2. Create an automation user in `conf/users.auth.php` with the `automation` group.

3. Required tools: `curl`, `php` (CLI)

## Usage

### Post a new entry

```bash
export ELOG_URL="http://127.0.0.1:8081"
export ELOG_USER="automation_user"
export ELOG_PASS="your_password"

bin/elog_post.sh \
  --ns mylogbook \
  --type "General" \
  --tags "automated, test" \
  --subject "Automated Entry" \
  --body "This entry was created via XML-RPC."
```

### Post with images

```bash
bin/elog_post.sh \
  --ns mylogbook \
  --type "Shift Summary" \
  --tags "shift, morning" \
  --subject "Morning Shift" \
  --body-file /path/to/report.txt \
  --image /path/to/screenshot1.png \
  --image /path/to/plot.png
```

### Using a config file

Create a config file (e.g., `~/.elog.conf`):
```bash
ELOG_URL="http://127.0.0.1:8081"
ELOG_USER="automation_user"
ELOG_PASS="your_password"
```

Secure it and use:
```bash
chmod 600 ~/.elog.conf
bin/elog_post.sh --config ~/.elog.conf --ns mylogbook ...
```

### Using netrc (recommended)

To avoid exposing credentials in environment variables or process listings:

Create `~/.netrc`:
```
machine 127.0.0.1
  login automation_user
  password your_password
```

```bash
chmod 600 ~/.netrc
export ELOG_URL="http://127.0.0.1:8081"
export ELOG_NETRC="$HOME/.netrc"

bin/elog_post.sh --ns mylogbook ...
```

## Authentication Modes

### Basic Auth (default)

Uses HTTP Basic authentication. Works when Apache/PHP passes the `Authorization` header through to `xmlrpc.php`.

### Cookie Auth

For setups where the `Authorization` header is stripped (e.g., some nginx/php-fpm configurations):

```bash
export ELOG_AUTH="cookie"
export ELOG_URL="http://127.0.0.1:8081"
export ELOG_USER="automation_user"
export ELOG_PASS="your_password"

bin/elog_post.sh --ns mylogbook ...
```

This authenticates via `dokuwiki.login` XML-RPC method and reuses session cookies.

## Full Options

```
Usage:
  elog_post.sh [--config FILE] --ns NS --type TYPE --tags TAGS \
    --subject SUBJECT (--body TEXT | --body-file FILE) \
    [--category CAT] [--author NAME] [--image PATH ...]

Environment:
  ELOG_URL       Base URL of DokuWiki (e.g. http://127.0.0.1:8081)
  ELOG_AUTH       Auth mode: basic (default) or cookie
  ELOG_NETRC      Path to a curl netrc file (recommended)
  ELOG_USER       Username (if not using ELOG_NETRC)
  ELOG_PASS       Password (if not using ELOG_NETRC)
  ELOG_COOKIEJAR  Path to a curl cookie jar (for cookie mode)
  ELOG_AUTHOR     Default author line embedded in page text
```

## How It Works

1. `elog_post.sh` computes the next numeric page ID by listing existing pages in the namespace via `dokuwiki.getPagelist`
2. Constructs the page content with metadata headers (Type, Category, Tags, Author, Subject)
3. Creates the page via `wiki.putPage`
4. Uploads any images via `wiki.putAttachment` with naming convention `[id]_[timestamp]_[index]_[filename]`
