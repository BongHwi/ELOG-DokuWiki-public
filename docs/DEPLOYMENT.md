# Deployment Guide

## Docker Deployment (Recommended)

### Prerequisites

- Docker Engine 20.10+
- Docker Compose v2

### Steps

1. Run the setup script:
   ```bash
   ./setup.sh
   ```

2. Build and start:
   ```bash
   ./start.sh --build
   ```

3. Access at `http://127.0.0.1:8081`

### Custom Port

Edit `docker-compose.yml`:
```yaml
ports:
  - "127.0.0.1:YOUR_PORT:80"
```

### Reverse Proxy (nginx)

```nginx
server {
    listen 443 ssl;
    server_name elog.example.com;

    ssl_certificate     /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        client_max_body_size 55M;
    }
}
```

### Backups

Back up these directories regularly:
- `data/` — All wiki content and media
- `conf/` — Configuration files (contains password hashes)

```bash
tar czf elog-backup-$(date +%Y%m%d).tar.gz data/ conf/
```

### Upgrades

1. Pull the latest changes:
   ```bash
   git pull
   ```

2. Rebuild the image:
   ```bash
   ./start.sh --build
   ```

Your `data/` and `conf/` directories are preserved across rebuilds via Docker volumes.

## Production Checklist

- [ ] Change default admin password
- [ ] Set up HTTPS (reverse proxy or load balancer)
- [ ] Configure email settings in `conf/local.php` for password reset
- [ ] Set up automated backups for `data/` and `conf/`
- [ ] Review ACL rules in `conf/acl.auth.php`
- [ ] Consider disabling open registration (`$conf['openregister'] = 0`)
- [ ] Set appropriate file permissions (`chmod 600 conf/users.auth.php`)

## XML-RPC Setup

To enable automated posting via XML-RPC:

1. Edit `conf/local.php`:
   ```php
   $conf['remote'] = 1;
   $conf['remoteuser'] = 'automation_user';
   ```

2. Create the automation user in `conf/users.auth.php` with the `automation` group.

3. See [docs/XMLRPC.md](XMLRPC.md) for usage details.
