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

- If you use direct MySQL ingestion (`ommysql`), verify `3308` reachability from sender and credentials (`rsyslog` / `rsyslog` / `rsyslogdb`).

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

- Confirm ports 80/443 are free.
- Validate Cloudflare token scope (DNS edit on target zone).
- Check Traefik logs for ACME/DNS errors.
