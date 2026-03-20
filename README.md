# rsyslog-webui

`rsyslog-webui` is a web interface for browsing syslog events written by rsyslog into MySQL.  
It includes live search, live tail, role-based access, optional Microsoft Entra ID sign-in, and MFA options (TOTP and passkeys).

## Quick Start (Docker)

1. Start services:

   ```bash
   docker compose up --build -d
   ```

2. Open:

   ```text
   http://localhost:8080
   ```

3. Sign in with seeded local admin:
   - Username: `admin`
   - Password: `admin123`

4. Send a test syslog line to the collector:

   ```bash
   logger -n 127.0.0.1 -P 5514 -d -t rsyslog-webui-test "hello from host"
   ```

Collector notes:

- Development and TLS/production stacks both accept network syslog on `5514` (TCP/UDP).
- The collector only forwards incoming network messages to MySQL; it does not seed demo logs.
- Producers can alternatively write directly to MySQL (`3308` in TLS stack) via `ommysql`.

PowerShell port override example:

```powershell
$env:WEB_PORT='8888'
docker compose up --build -d
```

## What You Get

- Live syslog table with debounced keyword/filterset search.
- Live polling (tail-like updates) with manual refresh controls.
- Admin directory for local and Microsoft-backed users.
- Optional Entra ID OIDC + PKCE authentication flow.
- MFA with TOTP and WebAuthn passkeys.
- Password reset / email verification via Microsoft Graph mail.

## Documentation Map

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Authentication and MFA](docs/authentication.md)
- [Deployment and TLS](docs/deployment.md)
- [Architecture](docs/architecture.md)
- [Testing](docs/testing.md)
- [Security](docs/security.md)
- [Troubleshooting](docs/troubleshooting.md)
- [History Remediation Runbook](docs/history-remediation.md)

## Security Notice

- Never commit real domains, personal emails, API tokens, client secrets, or production credentials.
- Keep `config.php`, `.env*`, and any private secrets outside version control.
- If credentials were ever committed, rotate them immediately and follow the history remediation runbook.

## Screenshots

### Mobile

![Mobile](/images/mobile-screenshot.jpg?raw=true "Mobile")

### Desktop

![Desktop](/images/screenshot.jpg?raw=true "Desktop")

### Admin Panel

![Desktop](/images/admin.jpg?raw=true "Admin Panel")

---

Original upstream inspiration: [hmsdao/bootstrap-rsyslog-ui](https://github.com/hmsdao/bootstrap-rsyslog-ui)
