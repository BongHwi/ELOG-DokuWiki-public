[English](README.md) | [한국어](README_ko.md) | [日本語](README_ja.md)

# ELOG-DokuWiki

A DokuWiki-based electronic logbook system for physics experiment shift logging. Built on DokuWiki with a Bootstrap 5 frontend and flat-file storage (no database required).

**Live Demo:** [global-coreen-bonghwi-54a0b2e5.koyeb.app](https://global-coreen-bonghwi-54a0b2e5.koyeb.app/)

## Features

- **Shift Logging** — Create, edit, and browse log entries with metadata (type, category, tags, author)
- **Logbook Management** — Multiple isolated logbooks with per-group ACL permissions
- **Image Attachments** — Drag-and-drop image upload with gallery view
- **Search & Filter** — Full-text search, tag filtering, and pagination
- **PDF Export** — Export individual entries or entire logbooks via dw2pdf
- **XML-RPC Automation** — Post entries programmatically from scripts or CI pipelines
- **User Registration** — Self-registration with admin approval workflow (pending group)
- **Docker Deployment** — Single-command setup with Docker Compose
- **Responsive UI** — Bootstrap 5.3.3 layout, works on desktop and mobile

## Quick Start (Docker)

```bash
git clone https://github.com/BongHwi/ELOG-DokuWiki.git
cd ELOG-DokuWiki
./start.sh --build   # Build DokuWiki image with ELOG overlay
./setup.sh           # Create admin account
# Open http://127.0.0.1:8081
```

See [QUICKSTART.md](QUICKSTART.md) for detailed instructions.

## Architecture

All custom logic lives in the DokuWiki template at `lib/tpl/elog/`:

| File | Purpose |
|------|---------|
| `elog_functions.php` | Core business logic: action routing, form rendering, log listing, image uploads, logbook management |
| `main.php` | Bootstrap layout template with navigation and metadata display |
| `css/screen.css` | Screen styles |
| `css/print.css` | Print styles |

Log entries are plain text files at `data/pages/[logbook]/[id].txt` with metadata headers:

```
Type: Shift Summary
Category: Operation
Tags: startup, beam
Subject: Morning Shift Report
====== Morning Shift Report ======

Content in DokuWiki syntax...
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for details.

## Directory Structure

```
ELOG-DokuWiki/
├── Dockerfile              # Installs DokuWiki + plugins, applies ELOG overlay
├── docker-compose.yml      # Container orchestration (named volume for conf)
├── entrypoint.sh           # Ensures data dirs exist, starts Apache
├── setup.sh                # Interactive setup: admin account + logbook
├── start.sh                # Start containers
├── plugins.conf            # Pinned plugin versions
├── install-plugins.sh      # Plugin installer (runs during Docker build)
├── conf/                   # Reference configs for manual (non-Docker) installs
│   ├── *.dist              # Template files
│   └── userstyle.css       # Custom stylesheet (copied into image)
├── data/pages/             # Wiki content (flat files, bind-mounted)
├── lib/tpl/elog/           # Custom template — core of the project
│   ├── elog_functions.php
│   ├── main.php
│   └── css/
├── bin/                    # XML-RPC automation scripts
│   ├── elog_post.sh
│   └── elog_xmlrpc_helpers.sh
└── docs/                   # Documentation
```

## Plugins

All plugins are pinned to specific commits/tags in `plugins.conf` for reproducible builds:

| Plugin | Purpose | License |
|--------|---------|---------|
| blog | Blog-style listing | GPL-2.0 |
| bloglinks | Blog navigation links | GPL-2.0 |
| discussion | Entry comments | GPL-2.0 |
| commonmark | CommonMark markdown support | Apache-2.0 / GPL-2.0+ |
| tag | Tag management | GPL-2.0 |
| addnewpage | Page creation helper | GPL-2.0+ |
| move | Page/namespace renaming | GPL-2.0 |
| searchindex | Search index manager | GPL-2.0 |
| captcha | CAPTCHA for registration | GPL-2.0 |
| dw2pdf | PDF export | GPL-2.0 |

## Documentation

- [QUICKSTART.md](QUICKSTART.md) — 5-minute setup guide
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — System architecture
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Production deployment guide
- [docs/XMLRPC.md](docs/XMLRPC.md) — XML-RPC automation API
- [CONTRIBUTING.md](CONTRIBUTING.md) — How to contribute

## Author

**BongHwi Lim** (임봉휘)
Center for Nuclear Study (CNS), The University of Tokyo
[lim@cns.s.u-tokyo.ac.jp](mailto:lim@cns.s.u-tokyo.ac.jp)

## License

This project is licensed under the [GPL-2.0](LICENSE) — built on [DokuWiki](https://www.dokuwiki.org/).
See [NOTICE](NOTICE) for third-party license information.
