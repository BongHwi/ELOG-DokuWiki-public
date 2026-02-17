# Quick Start Guide

Get ELOG-DokuWiki running in 5 minutes.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/)

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/BongHwi/ELOG-DokuWiki.git
cd ELOG-DokuWiki
```

### 2. Build and start

```bash
./start.sh --build    # Build image and start container
```

### 3. Run setup

```bash
./setup.sh            # Create admin account and initial logbook
```

The setup script will:
1. Start the Docker container if not already running
2. Create an admin account (username, password, email)
3. Set up default ACL rules
4. Optionally create an initial logbook

### 4. Access ELOG

Open [http://127.0.0.1:8081](http://127.0.0.1:8081) in your browser and log in with the admin credentials you created.

## Manual Installation (without Docker)

1. Install DokuWiki: https://www.dokuwiki.org/install
2. Copy `lib/tpl/elog/` to your DokuWiki's `lib/tpl/` directory
3. Set `$conf['template'] = 'elog';` in `conf/local.php`
4. Install the plugins listed in `plugins.conf`
5. See `conf/*.dist` files for recommended configuration

## Common Operations

### Create a logbook

1. Log in as admin
2. Click **Admin** in the top navigation
3. Under "Logbook Administration", enter a name and click **Create**

### Create a log entry

1. Navigate to a logbook
2. Click **New Log**
3. Fill in the type, tags, subject, and content
4. Click **Save**

### Stop the application

```bash
docker compose down
```

### Rebuild after template changes

```bash
./start.sh --build
```

## Troubleshooting

**Permission errors on data/ directory**
The Docker container runs as `www-data` (UID 33). Set ownership accordingly:
```bash
sudo chown -R 33:33 data/
```

**Port 8081 already in use**
Edit `docker-compose.yml` and change the port mapping (e.g., `8082:80`).

**Reset configuration**
Configuration is stored in a Docker named volume (`elog-conf`). To reset:
```bash
docker compose down -v    # removes volumes
./start.sh --build        # rebuilds with fresh config
./setup.sh                # reconfigure admin account
```
