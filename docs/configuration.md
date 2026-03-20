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

## Collector / Ingestion Ports

| Setting | Description | Example |
| --- | --- | --- |
| `RSYSLOG_INGEST_PORT` | Host port mapped to collector listener (`5514` TCP/UDP) | `5514` |
| `MYSQL_HOST_PORT` | Host port mapped to MySQL in Docker stacks | `3307` (dev), `3308` (tls/prod) |

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

## Safe Defaults and Hygiene

- Keep all secrets in env vars, not in tracked files.
- Do not use personal domains/emails in committed examples.
- Use placeholder-safe examples only:
  - `logs.example.com`
  - `you@example.com`
  - `replace-with-secret`
