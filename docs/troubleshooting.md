# Troubleshooting

## App Loads but No Logs

- Confirm rsyslog is writing to `SystemEvents`.
- Check DB connectivity values in config/env.
- Verify `rsyslog` collector service is running:

  ```bash
  docker compose ps rsyslog
  ```

- Verify collector logs for MySQL errors:

  ```bash
  docker compose logs --tail=100 rsyslog
  ```

- Run a test event:

  ```bash
  logger -n 127.0.0.1 -P 5514 -d -t rsyslog-webui-test "test event"
  ```

- If sending from another host fails, validate network path to collector:
  - host firewall allows `5514/tcp` and/or `5514/udp`
  - sender targets Docker host IP, not `localhost`
  - collector port is published in compose `ps` output

- If you use direct MySQL ingestion (`ommysql`), verify the **host** MySQL port is reachable from the sender (default `3306`, or whatever you set in `MYSQL_HOST_PORT`) and credentials match (`rsyslog` / `rsyslog` / `rsyslogdb`). Inside Docker, the `rsyslog` service always uses `db:3306`.

## Maintenance / Retention

- If rows disappear unexpectedly, check Admin -> Maintenance:
  - live retention enabled
  - mode (`days`, `rows`, `both`)
  - thresholds (`keep_days`, `max_rows`)
- Run "cleanup now" in Admin -> Maintenance to confirm policy behavior.
- Disable live retention temporarily for troubleshooting ingestion volume.

## Login or MFA Issues

- Verify `Users/Roles/UserRoles` tables exist and are populated.
- Confirm session secret is set and stable across requests.
- Check clock skew for TOTP failures.

## Passkey Registration Errors

- Ensure HTTPS (or localhost exception).
- Confirm browser/credential manager supports WebAuthn.
- If provider-specific (for example, password manager passkey wrappers), inspect `register-verify` error details.
- Dashlane and similar wrappers may serialize fields differently; current server-side normalization accepts string and byte-array binary field representations.

## Entra Sign-In Failures

- Validate tenant/client IDs and secret.
- Confirm redirect URI matches exactly.
- Verify app has required API permissions and admin consent.

## TLS Problems

- Confirm ports `80` and `443` are free on the Docker host.
- **Automated ACME** (default: `docker-compose.yml` + `.env.tls` with `COMPOSE_PROFILES=tls`, or `docker-compose.tls.yml`): validate Cloudflare token scope (`Zone:DNS:Edit` on the zone for `TLS_DOMAIN`). Check Traefik logs for ACME/DNS errors (`docker compose ... logs traefik`).
- **Manual stack** (`docker-compose.tls-manual.yml`): confirm `docker/certs/tls.crt` and `docker/certs/tls.key` exist, are readable inside the container mount, and match the hostname clients use. Concatenate intermediate CAs into `tls.crt` if browsers show chain errors. After replacing certs, restart Traefik.
- Wrong compose file: manual certs require `docker-compose.tls-manual.yml`; ACME uses the main compose + `.env.tls` (or `docker-compose.tls.yml`).
