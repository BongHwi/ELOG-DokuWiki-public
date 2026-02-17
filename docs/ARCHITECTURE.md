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
| `edit_save` | Save edits to an existing entry |

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

`docker-compose.yml` mounts three volumes for live editing:
- `./data` → `/var/www/html/data`
- `./conf` → `/var/www/html/conf`
- `./lib/tpl/elog` → `/var/www/html/lib/tpl/elog`

## Log Entry Types

Hardcoded in `elog_render_log_form()`:
General, Shift Summary, Maintenance, Error/Fault, Standard Run, Calibration, Test.
