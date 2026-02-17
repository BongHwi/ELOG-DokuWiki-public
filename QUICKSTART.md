# Quick Start Guide

Get ELOG-DokuWiki running in 5 minutes.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/)
- One of the following for password hashing: Docker (recommended), PHP 8.x, or Python 3 with bcrypt

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/BongHwi/ELOG-DokuWiki.git
cd ELOG-DokuWiki
```

### 2. Run setup

**You must run `setup.sh` before starting the containers.**

```bash
./setup.sh
```

The setup script will:
1. Create configuration files from `.dist` templates
2. Prompt you to create an admin account (username, password, email)
3. Optionally create an initial logbook
4. Optionally build and start Docker containers

### 3. Start the application

If you skipped the Docker step during setup:

```bash
./start.sh --build    # First time (builds the image)
./start.sh            # Subsequent starts
```

### 4. Access ELOG

Open [http://127.0.0.1:8081](http://127.0.0.1:8081) in your browser and log in with the admin credentials you created.

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

### Rebuild after changes

```bash
./start.sh --build
```

## Troubleshooting

**"conf/local.php not found" error when running start.sh**
Run `./setup.sh` first to initialize the configuration files.

**Permission errors on data/ directory**
The Docker container runs as `www-data` (UID 33). Set ownership accordingly:
```bash
sudo chown -R 33:33 data/
```

**Port 8081 already in use**
Edit `docker-compose.yml` and change the port mapping (e.g., `8082:80`).
