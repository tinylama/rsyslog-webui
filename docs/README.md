# Documentation Index

- [Installation](installation.md)
- [Configuration](configuration.md)
- [Authentication and MFA](authentication.md)
- [Deployment](deployment.md)
- [Architecture](architecture.md)
- [Testing](testing.md)
- [Security](security.md)
- [Troubleshooting](troubleshooting.md)

Operational additions included in these guides:

- Production TLS: `docker-compose.tls.yml` (ACME + Cloudflare DNS-01) or `docker-compose.tls-manual.yml` (your own `tls.crt` / `tls.key` in `docker/certs/`)
- Network collector ingestion (`5514` TCP/UDP) for Docker stacks
- Admin maintenance controls (live retention policy, clear logs/database data)
- Passkey registration compatibility handling for broader credential providers
