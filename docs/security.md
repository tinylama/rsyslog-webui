# Security

## Secrets and Sensitive Data

Never commit:
- Real domains tied to private infrastructure.
- Personal emails.
- API tokens and OAuth secrets.
- Production credentials.

Use placeholders in docs and samples:
- `logs.example.com`
- `you@example.com`
- `replace-with-secret`

## App Hardening

- Set strong values for:
  - `RSYSLOG_SESSION_SECRET`
  - `RSYSLOG_APP_SECRET`
- Keep `config.php` and `.env*` out of source control.
- Restrict admin access and review role assignments.
- Enforce HTTPS in production.

## Incident Response (Credential Exposure)

1. Revoke exposed API tokens.
2. Rotate Entra app secrets and any affected passwords.
3. Audit logs for misuse.
4. Apply history remediation if leaked values were committed.

See: [History Remediation Runbook](history-remediation.md).
