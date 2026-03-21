# Configuration

This document centralizes config keys used by `config.php` and environment variables.

## Core Database

| Setting | Description | Example |
| --- | --- | --- |
| `RSYSLOG_DB_HOST` | MySQL host | `db` |
| `RSYSLOG_DB_NAME` | Database name | `rsyslogdb` |
| `RSYSLOG_DB_USER` | DB username | `rsyslog` |
| `RSYSLOG_DB_PASSWORD` | DB password | `change-me` |

## Application

| Setting | Description | Example |
| --- | --- | --- |
| `RSYSLOG_SITE_NAME` | UI title | `System Logs` |
| `RSYSLOG_KEEP_DAYS` | Retention days | `90` |
| `RSYSLOG_AUTH_ENABLED` | Enable app auth | `1` |
| `RSYSLOG_SESSION_SECRET` | Session signing secret | `long-random-value` |
| `RSYSLOG_APP_SECRET` | Encryption key for protected values | `long-random-value` |
| `RSYSLOG_LOGIN_RATE_LIMIT` | Failed-login lockout in `LoginRateLimit` table (`1` default). Set `0` for local Playwright / dev compose only. | `1` |
| `RSYSLOG_E2E_ENABLED` | Enables `json/e2e-bootstrap.php` when **also** running inside Docker (`/.dockerenv`). Set `0` or omit in production-like stacks. Dev compose sets `1`. | `0` |
| `RSYSLOG_E2E_TOKEN_FILE` | Optional override path to the Playwright token file inside the container (default: `tests/e2e/.e2e-bootstrap-token` under the app root). | _(unset)_ |

## Collector / Ingestion Ports

| Setting | Description | Example |
| --- | --- | --- |
| `RSYSLOG_INGEST_PORT` | Host port mapped to collector listener (`5514` TCP/UDP) | `5514` |
| `MYSQL_HOST_PORT` | Host port mapped to MySQL in Docker stacks (container always listens on `3306`) | `3306` (default; change if another service uses `3306` on the host) |

## Maintenance (Admin UI)

Retention policy is configurable in Admin -> Maintenance and stored in `AppSettings`.

| Key | Description |
| --- | --- |
| `logs_retention_live_enabled` | Run retention continuously on API traffic |
| `logs_retention_mode` | `off`, `days`, `rows`, or `both` |
| `logs_retention_days` | Days threshold when days mode is active |
| `logs_retention_max_rows` | Max `SystemEvents` rows to keep when rows mode is active |
| `logs_retention_last_run_ts` | Last retention execution timestamp |

## Local Admin Bootstrap

| Setting | Description | Example |
| --- | --- | --- |
| `RSYSLOG_ADMIN_USERNAME` | Initial admin user | `admin` |
| `RSYSLOG_ADMIN_PASSWORD` | Initial admin password | `change-me` |

## Microsoft Entra ID (Optional)

| Setting | Description |
| --- | --- |
| `RSYSLOG_AZURE_ENABLED` | Show Microsoft sign-in and enable OIDC flow |
| `RSYSLOG_AZURE_TENANT_ID` | Directory (tenant) ID |
| `RSYSLOG_AZURE_CLIENT_ID` | Application (client) ID |
| `RSYSLOG_AZURE_CLIENT_SECRET` | Client secret |
| `RSYSLOG_AZURE_REDIRECT_URI` | Optional callback override |
| `RSYSLOG_AZURE_ADMIN_GROUP_ID` | Optional group object ID for first-login admin mapping |
| `RSYSLOG_GRAPH_MAIL_SENDER` | Mailbox UPN/object used for Graph sendMail |

## HTTPS Allowlist (Optional)

| Setting | Description |
| --- | --- |
| `RSYSLOG_HTTPS_WHITELIST_ENABLED` | Enable IP/CIDR allowlist checks for HTTPS traffic |
| `RSYSLOG_HTTPS_WHITELIST_CIDRS` | Comma/newline list of allowed IPs/CIDRs |

## Docker TLS (`.env.tls` + compose)

Used with `docker compose --env-file .env.tls -f <compose-file> …`.

| Variable | When needed | Description |
| --- | --- | --- |
| `TLS_DOMAIN` | `docker-compose.tls.yml` | Hostname on the certificate (DNS must resolve here) |
| `ACME_EMAIL` | `docker-compose.tls.yml` | Let's Encrypt registration email |
| `CF_DNS_API_TOKEN` | `docker-compose.tls.yml` only | Cloudflare token with `Zone:DNS:Edit` for DNS-01 |
| Manual files | `docker-compose.tls-manual.yml` | Host paths `docker/certs/tls.crt` and `docker/certs/tls.key` (not env vars) |

See [Deployment](deployment.md) for full procedures.

## Safe Defaults and Hygiene

- Keep all secrets in env vars, not in tracked files.
- Do not use personal domains/emails in committed examples.
- Use placeholder-safe examples only:
  - `logs.example.com`
  - `you@example.com`
  - `replace-with-secret`
