# Architecture

## Overview

ELOG-DokuWiki is a template-driven application built on DokuWiki. There is no separate backend API or controller layer — the DokuWiki template intercepts actions and handles all custom logic.

## Template-Driven Design

All custom code lives in `lib/tpl/elog/`:

- **`elog_functions.php`** — Core business logic (~1400 lines). Handles action interception, form rendering, log listing, image uploads, logbook management, and ACL checks.
- **`main.php`** — Bootstrap 5 layout template (~280 lines). Header navigation, action bar, metadata display, and logbook listing.
- **`css/screen.css`** / **`css/print.css`** — Styling.

## Action Interception

`elog_handle_actions()` intercepts custom `do=` parameters to provide custom form handling:

| Action | Purpose |
|--------|---------|
| `newlog` | Display the new log entry form |
| `edit` | Display the edit form for an existing entry |
| `newlog_save` | Save a new log entry via `saveWikiText()` |
| `edit_save` | Save edits to an existing entry (administrators only) |
| `attach` | Display the form that adds attachments to an existing entry |
| `attach_save` | Store the added attachments; the entry text is not changed |
| `upload_root_temp` | Store a ROOT file temporarily while an entry is being written |

`edit` is also administrators only. `newlog`, `reply`, `attach` and the ROOT upload require a logged-in user with create rights on the logbook, and the save actions require a valid security token.

## Append-Only Rule

DokuWiki ACL levels are cumulative (upload > create > edit), so the ACL alone cannot express "may create and upload, but may not edit". The `elogguard` plugin (`lib/plugins/elogguard/`) adds that rule for everyone who is not an administrator:

- A page save is allowed only when it creates a new page and the user is logged in with create rights. Edits, deletions and reverts are refused. This covers the web editor, the template forms and XML-RPC, because all of them save through `saveWikiText()`.
- The editor actions (`edit`, `save`, `preview`, drafts, `revert`) are turned into `show`, and section edit buttons are not rendered.
- Command line tools (`bin/*.php`) are not restricted.

## Entry Numbers and Attachments

- A new entry gets the highest number ever used in the logbook plus one. The number is chosen and the page is saved under a per-logbook lock, so simultaneous posts get different numbers.
- Numbers of deleted entries are not reused. Attachments are found by their `<number>_` file name prefix, so a reused number would show the deleted entry's files. The highest number is therefore taken from the pages, the change logs, the old revisions and the stored attachments.
- Attachments are stored as `data/media/[logbook]/<number>_<time>_<index>_<name>`. Only images and `.root` files with a type known to DokuWiki (`conf/mime.conf`, `conf/mime.local.conf`) are accepted, and each upload is recorded in the media change log.
- Temporary ROOT files are named `temp_<user key>_<time>_<name>`. On save, only the files that the form lists and that carry the current user's key are attached. Temporary files older than 7 days are removed.

## Data Model

### Log Entries

Plain text files stored at `data/pages/[logbook]/[numeric-id].txt`.

Each file contains metadata headers followed by DokuWiki syntax content:

```
Type: Shift Summary
Category: Operation
Tags: startup, beam
Author: John Doe
Subject: Morning Shift Report
====== Morning Shift Report ======

Content here...
```

Headers are parsed via regex in `elog_functions.php`.

### Images

Stored at `data/media/[logbook]/[id]_[timestamp]_[index]_[filename]`.

### ID Generation

New log IDs are auto-incremented by scanning existing files in the namespace.

## Key Functions

| Function | Purpose |
|----------|---------|
| `elog_handle_actions()` | Action routing for create/edit/save |
| `elog_render_log_form()` | Unified create/edit form with EasyMDE and image upload |
| `elog_render_list()` | Log table with filtering, pagination, view modes |
| `elog_render_attachments()` | Image display and admin deletion |
| `elog_render_gallery()` | Photo gallery view |
| `elog_handle_logbook_admin()` | Create/delete logbooks (admin only) |
| `elog_get_logbooks()` | List logbooks with ACL filtering |

## Authentication & ACL

Uses DokuWiki's built-in ACL system. Permission levels:

| Level | Meaning |
|-------|---------|
| 0 | None |
| 1 | Read |
| 2 | Edit |
| 4 | Create |
| 8 | Upload |
| 16 | Delete |
| 255 | Admin (full access) |

Configuration files:
- `conf/users.auth.php` — User credentials (bcrypt hashed)
- `conf/acl.auth.php` — Namespace permission rules
- `conf/local.php` — Main config (ACL enabled, superuser group, registration settings)

## Docker Architecture

The `Dockerfile` builds a PHP 8.2-Apache image with:
- GD extension for image processing
- mod_rewrite for pretty URLs
- 50MB upload limit
- DokuWiki 2024-02-06a "Kaos"
- Plugins installed from pinned versions in `plugins.conf`
- The `elogguard` plugin and `conf/mime.local.conf` copied from this repository

`docker-compose.yml` mounts three volumes for live editing:
- `./data` → `/var/www/html/data`
- `./conf` → `/var/www/html/conf`
- `./lib/tpl/elog` → `/var/www/html/lib/tpl/elog`

## Log Entry Types

Hardcoded in `elog_render_log_form()`:
General, Shift Summary, Maintenance, Error/Fault, Standard Run, Calibration, Test.
