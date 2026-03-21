# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-03-21

### Added

- **TLS / deployment:** Optional `docker-compose.tls-manual.yml` with Traefik and PEM files under `docker/certs/` (see `docker/TLS.md`). Apache static asset caching config; Traefik dynamic snippet for manual TLS.
- **Admin:** `admin/logs.php` (sign-in, access, email audit views); `admin/collector-config.php` (collector reference); selective **clear audit logs** on maintenance with a surviving `AccessLogs` row describing the purge.
- **Log maintenance:** Shared truncate + **OPTIMIZE / ANALYZE** after `SystemEvents` clears so `information_schema` sizes match empty tables; same pattern for audit tables when cleared.
- **Syslog viewer (dev):** Severity quick-filter chips for all syslog priorities (Emergency → Debug), toggle to clear filter, visibility from **unfiltered** summary (what exists in the log stream), while the severity strip stays tied to the current search.
- **Dark mode:** `dark-mode-switch.js` + Bootstrap theme on **HTTPS whitelist** admin page; **Dark** toggle in `navbar-admin.php` for all admin screens.
- **Docs site:** Shared docs nav partial, landing public CSS build script, expanded installation/deployment/testing content.
- **E2E:** Playwright global setup/teardown, auth helpers, `json/e2e-bootstrap.php` for authenticated API smoke tests.

### Changed

- **Docker:** Default compose profile documentation aligned with `docker/tls.env.example` (`COMPOSE_PROFILES=tls`); compose and `Dockerfile` refinements.
- **Auth / MFA:** Hardening and compatibility updates across `auth.php`, Azure callback, WebAuthn assert, login and MFA challenge flows.
- **Database schema (init):** Audit log tables (`SignInLogs`, `AccessLogs`, `EmailLogs`) and related indexes in `docker/mysql/init/01-schema.sql`.
- **Dependencies:** Composer and npm lockfile updates for PHP 8.3+ and Playwright/Lighthouse tooling.

### Fixed

- **Maintenance UI:** Misleading “table size after clear” when `SystemEvents` was empty but MB still showed pre-truncate allocation.
- **Markdown:** `docker/TLS.md` ordered-list style for markdownlint (MD029).

### Documentation

- README quick start for public HTTPS path and TLS file references.
- Removed public index links to the internal history-remediation runbook; security guidance generalized in `docs/security.md`.

[1.1.0]: https://github.com/tinylama/rsyslog-webui/releases/tag/v1.1.0

## [1.0.0] — prior

Initial tagged release: secure rsyslog web UI with live search, auth, MFA options, and admin directory.

[1.0.0]: https://github.com/tinylama/rsyslog-webui/releases/tag/v1.0.0
