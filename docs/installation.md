# Installation

This guide covers Docker-first deployment (development and TLS/production), remote syslog ingestion, and manual installation.

## Prerequisites

- Docker Desktop (recommended), or:
  - PHP with required extensions
  - Apache/Nginx
  - MySQL/MariaDB
  - rsyslog with MySQL output support (`ommysql`)
- Node.js (for Playwright tests)

## Docker Stacks at a Glance

- `docker-compose.yml` (development):
  - Web UI on `8080` (default)
  - MySQL host port `3307` (default)
  - Collector on `5514` UDP/TCP
- `docker-compose.tls.yml` (production/TLS):
  - Traefik on `80/443`
  - MySQL host port `3308` (default)
  - Collector on `5514` UDP/TCP

Collector behavior (both stacks):

- Accepts incoming network syslog only
- Writes to `SystemEvents` in MySQL
- Does not generate demo/fake logs

## Option A: Development Docker

1. Start:

   ```bash
   docker compose up --build -d
   ```

2. Open `http://localhost:8080`.
3. Sign in with seeded admin:
   - `admin`
   - `admin123`
4. Send a local test event:

   ```bash
   logger -n 127.0.0.1 -P 5514 -d -t rsyslog-webui-test "dev collector test"
   ```

5. Verify DB insert:

   ```bash
   docker exec rsyslog-webui-db-1 mysql -ursyslog -prsyslog rsyslogdb -e "SELECT ID, FromHost, SysLogTag, LEFT(Message,120) AS msg FROM SystemEvents ORDER BY ID DESC LIMIT 10;"
   ```

### Development Port Overrides

- `WEB_PORT` default: `8080`
- `MYSQL_HOST_PORT` default: `3307`
- `RSYSLOG_INGEST_PORT` default: `5514`

PowerShell example:

```powershell
$env:WEB_PORT='8888'
$env:MYSQL_HOST_PORT='3307'
$env:RSYSLOG_INGEST_PORT='5515'
docker compose up --build -d
```

## Option B: TLS/Production Docker

1. Copy and edit environment file:

   ```bash
   cp docker/tls.env.example .env.tls
   ```

2. Start TLS stack:

   ```bash
   docker compose --env-file .env.tls -f docker-compose.tls.yml up -d --build
   ```

3. Verify services/ports:

   ```bash
   docker compose --env-file .env.tls -f docker-compose.tls.yml ps
   ```

Expected:

- `traefik` on `80/443`
- `db` on `3308` (default)
- `rsyslog` on `5514` UDP/TCP

### Remote Sender Quick Test

From another Linux host on the same network:

```bash
logger -n <docker-host-ip> -P 5514 -d -t remote-test "hello from remote sender"
```

Then verify on the Docker host:

```bash
docker exec rsyslog-webui-db-1 mysql -ursyslog -prsyslog rsyslogdb -e "SELECT ID, FromHost, SysLogTag, LEFT(Message,120) AS msg FROM SystemEvents ORDER BY ID DESC LIMIT 10;"
```

### Sender Forwarding Config (Same Port `5514`)

On sender host, create `/etc/rsyslog.d/60-rsyslog-webui.conf`:

```rsyslog
# UDP forwarding to collector
*.* @<docker-host-ip>:5514

# TCP alternative (more reliable)
# *.* @@<docker-host-ip>:5514
```

Then reload:

```bash
sudo systemctl restart rsyslog
sudo systemctl status rsyslog --no-pager
```

### Generate Natural Logs (Real System Activity)

Use one or more of these on the sender host:

```bash
# 1) Service lifecycle event
sudo systemctl restart cron || sudo systemctl restart crond

# 2) Authentication/security event (failed sudo attempt)
sudo -k
sudo -n true || true

# 3) SSH/auth activity (if SSH enabled, from another host)
ssh invalid-user@<sender-host-ip>

# 4) Package manager activity
sudo apt update || sudo dnf check-update || sudo yum check-update
```

You can still use explicit test lines when needed:

```bash
logger -p user.err -t remote-test "rsyslog forward test"
```

Check sender-side logs to confirm events were generated:

```bash
sudo journalctl -n 50 --no-pager
```

## Direct MySQL Ingestion (Alternative)

If you prefer senders writing directly to MySQL with `ommysql`, use:

- host: `<docker-host-ip>`
- port: `3308` (TLS stack default)
- db/user/password: `rsyslogdb` / `rsyslog` / `rsyslog`

## Option C: Manual/Bare-Metal

1. Clone:

   ```bash
   git clone https://github.com/Tiny-Lama/rsyslog-webui.git /var/www/html/rsyslog-webui
   ```

2. Create config:

   ```bash
   cp /var/www/html/rsyslog-webui/config-template.php /var/www/html/rsyslog-webui/config.php
   ```

3. Edit `config.php` with DB/auth values.
4. Install dependencies:

   ```bash
   composer install --no-dev
   ```

5. Ensure schema exists (see `docker/mysql/init/01-schema.sql`).
6. Open app URL.

## rsyslog `ommysql` Example (Manual/External Sender)

```rsyslog
module(load="ommysql")

template(
  name="systemevents"
  type="string"
  string="INSERT INTO SystemEvents
    (ReceivedAt, DeviceReportedTime, Facility, Priority, FromHost, SysLogTag, Messagetype, processid, Message)
    VALUES
    ('%timegenerated:::date-mysql%', '%timereported:::date-mysql%', '%syslogfacility%', '%syslogseverity%', '%HOSTNAME%', '%syslogtag%', '%syslogtag%', '0', '%msg%')"
)

action(
  type="ommysql"
  server="<db-host>"
  serverport="3306"
  db="rsyslogdb"
  username="rsyslog"
  password="rsyslog"
  template="systemevents"
)
```

Then restart rsyslog.

## Retention and Cleanup

Use Admin -> Maintenance for:

- Live retention policy (`days`, `rows`, or `both`)
- Run-now cleanup
- Clear logs / clear database data actions

Optional cron example (legacy/manual operation):

```cron
1 0 * * * cd /var/www/html/rsyslog-webui/maintenance && /usr/bin/php ./db-maintenance.php
```
