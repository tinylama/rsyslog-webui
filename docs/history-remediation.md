# History Remediation Runbook

Use this runbook if sensitive data (domains, emails, tokens, secrets) was committed.

## Immediate Response

1. Revoke and rotate exposed credentials first.
2. Record impacted systems and exposure window.
3. Freeze non-essential merges until cleanup is complete.

## Identify Sensitive History

Scan full history for known patterns:

```bash
git log --all --name-only --pretty=format:
git grep -n "sensitive-domain.example" $(git rev-list --all)
```

Use additional targeted searches for email domains and token formats.

## Rewrite History (filter-repo Recommended)

> Coordinate with collaborators before rewriting.

1. Create backup mirror:
   ```bash
   git clone --mirror <repo-url> repo-backup.git
   ```
2. Rewrite with `git filter-repo` (examples):
   ```bash
   git filter-repo --replace-text replacements.txt
   ```
3. Validate rewritten history:
   - No sensitive strings in `git rev-list --all` search.
   - Repository still builds/tests.

## Force Push and Team Coordination

1. Force push rewritten refs:
   ```bash
   git push --force --all
   git push --force --tags
   ```
2. Notify all contributors to re-clone or hard-reset to rewritten history.
3. Invalidate old forks/caches where possible.

## Post-Remediation Checklist

- [ ] Credentials rotated and documented.
- [ ] CI/CD secrets updated.
- [ ] History scans clean.
- [ ] README/docs updated with safe examples only.
- [ ] Preventive guardrails in place (`.gitignore`, secret scanning, pre-commit checks).
